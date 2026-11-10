//! A seekable HTTP source for symphonia: the file is fetched in Range chunks on demand,
//! with read-ahead, so playback starts after the first chunk and an MP4 whose index is
//! at the end only costs one more chunk.
use crate::api::Api;
use std::{
    collections::{HashMap, HashSet},
    io::{self, Read, Seek, SeekFrom},
    sync::{Arc, Mutex},
    thread,
};
use symphonia::core::io::MediaSource;

const CHUNK: u64 = 256 * 1024;
const AHEAD: u64 = 3;

#[derive(Default)]
struct Cache {
    chunks: HashMap<u64, Arc<Vec<u8>>>,
    fetching: HashSet<u64>,
}

pub struct HttpSource {
    api: Api,
    url: String,
    len: u64,
    pos: u64,
    cache: Arc<Mutex<Cache>>,
}

fn fetch(api: &Api, url: &str, index: u64) -> io::Result<(Vec<u8>, Option<u64>)> {
    let (from, to) = (index * CHUNK, index * CHUNK + CHUNK - 1);
    let r = ureq::get(url).set("Authorization", &api.auth()).set("Range", &format!("bytes={from}-{to}")).call().map_err(|e| io::Error::other(e.to_string()))?;
    let total = r.header("Content-Range").and_then(|h| h.rsplit('/').next()).and_then(|t| t.parse().ok());
    let status = r.status();
    let mut v = Vec::new();
    r.into_reader().read_to_end(&mut v)?;
    if status == 200 && index > 0 {
        return Err(io::Error::other("the server ignored the Range header"));
    }
    // a server that answers 200 sent the whole file: it is one chunk of unknown size
    let total = total.or(if status == 200 { Some(v.len() as u64) } else { None });
    Ok((v, total))
}

impl HttpSource {
    /// Fetches the first chunk (which also tells the file size).
    pub fn open(api: &Api, url: &str) -> io::Result<Self> {
        let (first, total) = fetch(api, url, 0)?;
        let len = total.ok_or_else(|| io::Error::other("the server did not say how large the file is"))?;
        let mut cache = Cache::default();
        if first.len() as u64 <= CHUNK || len <= CHUNK {
            cache.chunks.insert(0, Arc::new(first));
        } else {
            // a 200 with the whole body: keep it as chunk-sized pieces
            for (i, c) in first.chunks(CHUNK as usize).enumerate() {
                cache.chunks.insert(i as u64, Arc::new(c.to_vec()));
            }
        }
        let s = HttpSource { api: api.clone(), url: url.to_string(), len, pos: 0, cache: Arc::new(Mutex::new(cache)) };
        s.readahead(0);
        Ok(s)
    }

    fn readahead(&self, from: u64) {
        let last = (self.len.saturating_sub(1)) / CHUNK;
        for i in from + 1..=(from + AHEAD).min(last) {
            {
                let mut c = self.cache.lock().unwrap();
                if c.chunks.contains_key(&i) || !c.fetching.insert(i) {
                    continue;
                }
            }
            let (api, url, cache) = (self.api.clone(), self.url.clone(), self.cache.clone());
            thread::spawn(move || {
                let got = fetch(&api, &url, i);
                let mut c = cache.lock().unwrap();
                c.fetching.remove(&i);
                if let Ok((v, _)) = got {
                    c.chunks.insert(i, Arc::new(v));
                }
            });
        }
    }

    fn chunk(&self, i: u64) -> io::Result<Arc<Vec<u8>>> {
        loop {
            {
                let c = self.cache.lock().unwrap();
                if let Some(v) = c.chunks.get(&i) {
                    return Ok(v.clone());
                }
                if c.fetching.contains(&i) {
                    drop(c);
                    thread::sleep(std::time::Duration::from_millis(20));
                    continue;
                }
            }
            let (v, _) = fetch(&self.api, &self.url, i)?;
            let v = Arc::new(v);
            self.cache.lock().unwrap().chunks.insert(i, v.clone());
            return Ok(v);
        }
    }
}

impl Read for HttpSource {
    fn read(&mut self, buf: &mut [u8]) -> io::Result<usize> {
        if self.pos >= self.len || buf.is_empty() {
            return Ok(0);
        }
        let i = self.pos / CHUNK;
        let chunk = self.chunk(i)?;
        let off = (self.pos % CHUNK) as usize;
        let n = buf.len().min(chunk.len().saturating_sub(off));
        buf[..n].copy_from_slice(&chunk[off..off + n]);
        self.pos += n as u64;
        self.readahead(i);
        Ok(n)
    }
}

impl Seek for HttpSource {
    fn seek(&mut self, to: SeekFrom) -> io::Result<u64> {
        let p = match to {
            SeekFrom::Start(n) => n as i128,
            SeekFrom::Current(d) => self.pos as i128 + d as i128,
            SeekFrom::End(d) => self.len as i128 + d as i128,
        };
        if p < 0 {
            return Err(io::Error::new(io::ErrorKind::InvalidInput, "seek before the start"));
        }
        self.pos = p as u64;
        Ok(self.pos)
    }
}

impl MediaSource for HttpSource {
    fn is_seekable(&self) -> bool {
        true
    }
    fn byte_len(&self) -> Option<u64> {
        Some(self.len)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Against a real server: `MAGNETITE_LIVE_URL=https://host/stream/1 cargo test live -- --ignored --nocapture` (token from the saved config).
    #[test]
    #[ignore]
    fn live_stream_starts_after_the_first_chunks() {
        let cfg = crate::config::load().expect("signed in");
        let api = Api::new(&cfg.server, &cfg.token);
        let url = std::env::var("MAGNETITE_LIVE_URL").expect("MAGNETITE_LIVE_URL");
        let t = std::time::Instant::now();
        let src = HttpSource::open(&api, &url).unwrap();
        println!("opened after {:?}, {} bytes", t.elapsed(), src.byte_len().unwrap());
        let s = crate::audio::test_shared();
        let h = std::thread::spawn({ let s = s.clone(); move || crate::audio::decode_for_test(&s, Box::new(src)) });
        while s.queue_len() == 0 && t.elapsed().as_secs() < 100 {
            std::thread::sleep(std::time::Duration::from_millis(100));
        }
        println!("first samples after {:?}", t.elapsed());
        assert!(s.queue_len() > 0);
        s.stop();
        h.join().unwrap().unwrap();
    }
}
