//! Native playback: download, decode with symphonia, resample, hand to cpal.
//! The output callback also keeps a short tap of the most recent frames for the meters.
use crate::{api::Api, stream::HttpSource};
use cpal::traits::{DeviceTrait, HostTrait, StreamTrait};
use std::{
    collections::VecDeque,
    sync::{
        atomic::{AtomicBool, AtomicU64, Ordering::SeqCst},
        Arc, Mutex, OnceLock,
    },
    thread,
    time::Duration,
};
use symphonia::core::{
    codecs::{
        audio::{AudioCodecParameters, AudioDecoder, AudioDecoderOptions},
        registry::CodecRegistry,
        CodecParameters,
    },
    errors::Error as SymError,
    formats::{probe::Hint, FormatOptions, SeekMode, SeekTo, TrackType},
    io::{MediaSource, MediaSourceStream, MediaSourceStreamOptions},
    meta::MetadataOptions,
    units::Time,
};

pub const TAP: usize = 4096; // frames kept for the meters

struct Shared {
    queue: Mutex<VecDeque<f32>>, // interleaved stereo at the device rate
    tap: Mutex<VecDeque<f32>>,   // last TAP stereo frames
    pending: Mutex<Vec<f32>>,    // every frame played since the UI last asked (loudness needs all of them)
    paused: AtomicBool,
    decoded: AtomicBool, // the decoder has pushed everything
    ended: AtomicBool,   // decoded and drained: the UI starts the next track
    generation: AtomicU64,
    frames: AtomicU64,       // frames played since the last (re)start or seek
    base: AtomicU64,         // frame offset of that start, so position survives a seek
    seek: Mutex<Option<f64>>, // pending seek target in seconds
    prefetch: Mutex<Option<(String, HttpSource)>>,
    loading: AtomicBool,
    error: Mutex<Option<String>>,
    #[cfg(test)]
    collected: Mutex<Vec<f32>>,
}

pub struct Player {
    shared: Arc<Shared>,
    rate: u32,
    _stream: cpal::Stream,
}

impl Player {
    pub fn new() -> Result<Self, String> {
        let device = cpal::default_host().default_output_device().ok_or("no audio output device")?;
        let cfg = device.default_output_config().map_err(|e| e.to_string())?;
        if cfg.sample_format() != cpal::SampleFormat::F32 {
            return Err(format!("unsupported output format {:?} (f32 needed)", cfg.sample_format()));
        }
        let (rate, channels) = (cfg.sample_rate().0, cfg.channels() as usize);
        let shared = Arc::new(Shared {
            queue: Mutex::new(VecDeque::new()),
            tap: Mutex::new(VecDeque::with_capacity(TAP * 2)),
            pending: Mutex::new(Vec::new()),
            paused: AtomicBool::new(false),
            decoded: AtomicBool::new(true),
            ended: AtomicBool::new(false),
            generation: AtomicU64::new(0),
            frames: AtomicU64::new(0),
            base: AtomicU64::new(0),
            seek: Mutex::new(None),
            prefetch: Mutex::new(None),
            loading: AtomicBool::new(false),
            error: Mutex::new(None),
            #[cfg(test)]
            collected: Mutex::new(Vec::new()),
        });
        let s = shared.clone();
        let stream = device
            .build_output_stream(
                &cfg.config(),
                move |out: &mut [f32], _| {
                    let silent = s.paused.load(SeqCst);
                    let mut q = s.queue.lock().unwrap();
                    let mut tap = s.tap.try_lock().ok();
                    let mut played: Vec<f32> = Vec::with_capacity(out.len() / channels * 2);
                    for frame in out.chunks_mut(channels) {
                        let (mut l, mut r) = (0.0f32, 0.0f32);
                        if !silent {
                            if let (Some(a), Some(b)) = (q.pop_front(), q.pop_front()) {
                                (l, r) = (a, b);
                                s.frames.fetch_add(1, SeqCst);
                                played.extend([a, b]);
                            }
                        }
                        for (i, o) in frame.iter_mut().enumerate() {
                            *o = if i == 0 { l } else if i == 1 { r } else { 0.0 };
                        }
                        if let Some(t) = tap.as_mut() {
                            t.push_back(l);
                            t.push_back(r);
                            while t.len() > TAP * 2 {
                                t.pop_front();
                            }
                        }
                    }
                    if !played.is_empty() {
                        let mut p = s.pending.lock().unwrap();
                        p.extend(played);
                        let cap = 48_000 * 2 * 3; // never more than a few seconds if the UI stalls
                        if p.len() > cap {
                            let drop = p.len() - cap;
                            p.drain(..drop);
                        }
                    }
                    if !silent && q.is_empty() && s.decoded.load(SeqCst) && !s.loading.load(SeqCst) {
                        s.ended.store(true, SeqCst);
                    }
                },
                |e| eprintln!("audio error: {e}"),
                None,
            )
            .map_err(|e| e.to_string())?;
        stream.play().map_err(|e| e.to_string())?;
        Ok(Player { shared, rate, _stream: stream })
    }

    pub fn play(&self, api: &Api, url: String) {
        let s = self.shared.clone();
        let gen = s.generation.fetch_add(1, SeqCst) + 1;
        s.queue.lock().unwrap().clear();
        s.frames.store(0, SeqCst);
        s.base.store(0, SeqCst);
        s.pending.lock().unwrap().clear();
        *s.seek.lock().unwrap() = None;
        s.ended.store(false, SeqCst);
        s.decoded.store(false, SeqCst);
        s.loading.store(true, SeqCst);
        s.paused.store(false, SeqCst);
        *s.error.lock().unwrap() = None;
        let (api, rate) = (api.clone(), self.rate);
        thread::spawn(move || {
            let cached = s.prefetch.lock().unwrap().take().filter(|(u, _)| *u == url).map(|(_, src)| src);
            let src = match cached {
                Some(src) => Ok(src),
                None => HttpSource::open(&api, &url).map_err(|e| e.to_string()),
            };
            let result = src.and_then(|src| {
                if s.generation.load(SeqCst) != gen {
                    return Ok(());
                }
                s.loading.store(false, SeqCst);
                decode(&s, gen, Box::new(src), rate)
            });
            if let Err(e) = result {
                *s.error.lock().unwrap() = Some(e);
            }
            s.loading.store(false, SeqCst);
            if s.generation.load(SeqCst) == gen {
                s.decoded.store(true, SeqCst);
            }
        });
    }

    pub fn stop(&self) {
        self.shared.generation.fetch_add(1, SeqCst);
        self.shared.queue.lock().unwrap().clear();
        self.shared.decoded.store(true, SeqCst);
        self.shared.loading.store(false, SeqCst);
        self.shared.frames.store(0, SeqCst);
    }

    /// Open the next track in the background (first chunk and read-ahead) so it starts at once.
    pub fn prefetch(&self, api: &Api, url: String) {
        if self.shared.prefetch.lock().unwrap().as_ref().is_some_and(|(u, _)| *u == url) {
            return;
        }
        let (s, api) = (self.shared.clone(), api.clone());
        thread::spawn(move || {
            if let Ok(src) = HttpSource::open(&api, &url) {
                *s.prefetch.lock().unwrap() = Some((url, src));
            }
        });
    }

    /// Jump by `delta` seconds (clamped at the start; past the end it is ignored).
    pub fn seek_by(&self, delta: f64) {
        let target = (self.position() + delta).max(0.0);
        *self.shared.seek.lock().unwrap() = Some(target);
    }

    /// Jump to `t` seconds; call it right after `play` to start a track part-way (`play` clears any earlier seek).
    pub fn seek_to(&self, t: f64) {
        *self.shared.seek.lock().unwrap() = Some(t.max(0.0));
    }

    pub fn toggle_pause(&self) {
        self.shared.paused.fetch_xor(true, SeqCst);
    }
    pub fn paused(&self) -> bool {
        self.shared.paused.load(SeqCst)
    }
    pub fn loading(&self) -> bool {
        self.shared.loading.load(SeqCst)
    }
    pub fn ended(&self) -> bool {
        self.shared.ended.swap(false, SeqCst)
    }
    pub fn position(&self) -> f64 {
        (self.shared.base.load(SeqCst) + self.shared.frames.load(SeqCst)) as f64 / self.rate as f64
    }
    pub fn error(&self) -> Option<String> {
        self.shared.error.lock().unwrap().clone()
    }
    pub fn rate(&self) -> u32 {
        self.rate
    }
    /// Every interleaved sample played since the last call.
    pub fn take_played(&self) -> Vec<f32> {
        std::mem::take(&mut *self.shared.pending.lock().unwrap())
    }
    /// The most recent stereo frames as interleaved samples.
    pub fn tap(&self) -> Vec<f32> {
        self.shared.tap.lock().map(|t| t.iter().copied().collect()).unwrap_or_default()
    }
}

/// symphonia's codecs plus Opus (pure-Rust `ruopus` through the adapter), registered once.
fn codecs() -> &'static CodecRegistry {
    static R: OnceLock<CodecRegistry> = OnceLock::new();
    R.get_or_init(|| {
        let mut r = CodecRegistry::new();
        symphonia::default::register_enabled_codecs(&mut r);
        r.register_audio_decoder::<symphonia_adapter_opus_rs::OpusAdapter>();
        r
    })
}

fn decode(s: &Shared, gen: u64, source: Box<dyn MediaSource>, out_rate: u32) -> Result<(), String> {
    let mss = MediaSourceStream::new(source, MediaSourceStreamOptions::default());
    let mut format = symphonia::default::get_probe()
        .probe(&Hint::new(), mss, FormatOptions::default(), MetadataOptions::default())
        .map_err(|e| format!("unknown format: {e}"))?;
    let track = format.default_track(TrackType::Audio).ok_or("no audio track")?;
    let id = track.id;
    let time_base = track.time_base;
    let params: AudioCodecParameters = match &track.codec_params {
        Some(CodecParameters::Audio(p)) => p.clone(),
        _ => return Err("no audio track".into()),
    };
    let in_rate = params.sample_rate.ok_or("no sample rate")?;
    let mut decoder: Box<dyn AudioDecoder> = codecs().make_audio_decoder(&params, &AudioDecoderOptions::default()).map_err(|e| format!("codec: {e}"))?;

    let step = in_rate as f64 / out_rate as f64; // linear resampling, per channel pair
    let mut pos = 0.0f64;
    let mut prev = (0.0f32, 0.0f32);
    let mut out: Vec<f32> = Vec::new();
    let mut samples: Vec<f32> = Vec::new();
    let mut eof = false;
    let mut skip = 0usize; // interleaved samples still to drop after a seek

    loop {
        if s.generation.load(SeqCst) != gen {
            return Ok(()); // another track was started
        }
        if let Some(t) = s.seek.lock().unwrap().take() {
            let to = SeekTo::Time { time: Time::try_from_secs_f64(t).unwrap_or(Time::ZERO), track_id: Some(id) };
            if let Ok(seeked) = format.seek(SeekMode::Accurate, to) {
                decoder.reset();
                // an accurate seek lands at or before the target: decode from there and drop the frames before it
                let at = time_base.and_then(|tb| tb.calc_time(seeked.actual_ts)).map_or(t, |x| x.as_secs_f64());
                skip = ((t - at).max(0.0) * out_rate as f64) as usize * 2;
                s.queue.lock().unwrap().clear();
                s.base.store((t.max(0.0) * out_rate as f64) as u64, SeqCst);
                s.frames.store(0, SeqCst);
                (pos, prev, eof) = (0.0, (0.0, 0.0), false);
                s.ended.store(false, SeqCst);
                s.decoded.store(false, SeqCst);
            }
        }
        if eof {
            // wait for the queue to drain; a seek can still bring us back
            thread::sleep(Duration::from_millis(30));
            continue;
        }
        let packet = match format.next_packet() {
            Ok(Some(p)) => p,
            Ok(None) | Err(_) => {
                eof = true;
                s.decoded.store(true, SeqCst);
                continue;
            }
        };
        if packet.track_id != id {
            continue;
        }
        let ch = match decoder.decode(&packet) {
            Ok(buf) => {
                buf.copy_to_vec_interleaved::<f32>(&mut samples);
                buf.spec().channels().count().max(1)
            }
            Err(SymError::DecodeError(_)) => continue,
            Err(e) => return Err(e.to_string()),
        };
        out.clear();
        for f in samples.chunks(ch) {
            let cur = (f[0], if ch > 1 { f[1] } else { f[0] });
            if in_rate == out_rate {
                out.extend([cur.0, cur.1]);
            } else {
                while pos < 1.0 {
                    let t = pos as f32;
                    out.extend([prev.0 + (cur.0 - prev.0) * t, prev.1 + (cur.1 - prev.1) * t]);
                    pos += step;
                }
                pos -= 1.0;
                prev = cur;
            }
        }
        let drop = skip.min(out.len());
        out.drain(..drop);
        skip -= drop;
        // back-pressure: keep about two seconds queued, but react to a seek at once
        while s.queue.lock().unwrap().len() > out_rate as usize * 4 {
            if s.generation.load(SeqCst) != gen || s.seek.lock().unwrap().is_some() {
                break;
            }
            thread::sleep(Duration::from_millis(20));
        }
        if s.seek.lock().unwrap().is_none() {
            s.queue.lock().unwrap().extend(out.iter());
        }
    }
}

#[cfg(test)]
pub struct TestShared(Arc<Shared>);
#[cfg(test)]
impl Clone for TestShared {
    fn clone(&self) -> Self {
        TestShared(self.0.clone())
    }
}
#[cfg(test)]
impl TestShared {
    pub fn queue_len(&self) -> usize {
        self.0.queue.lock().unwrap().len()
    }
    pub fn stop(&self) {
        self.0.generation.store(99, SeqCst);
    }
}
#[cfg(test)]
pub fn test_shared() -> TestShared {
    TestShared(Arc::new(tests::shared()))
}
#[cfg(test)]
pub fn decode_for_test(s: &TestShared, src: Box<dyn MediaSource>) -> Result<(), String> {
    decode(&s.0, 1, src, 48000)
}

#[cfg(test)]
mod tests {
    use super::*;

    pub fn shared() -> Shared {
        Shared {
            queue: Mutex::new(VecDeque::new()),
            tap: Mutex::new(VecDeque::new()),
            pending: Mutex::new(Vec::new()),
            paused: AtomicBool::new(false),
            decoded: AtomicBool::new(false),
            ended: AtomicBool::new(false),
            generation: AtomicU64::new(1),
            frames: AtomicU64::new(0),
            base: AtomicU64::new(0),
            seek: Mutex::new(None),
            prefetch: Mutex::new(None),
            loading: AtomicBool::new(false),
            error: Mutex::new(None),
            collected: Mutex::new(Vec::new()),
        }
    }

    #[test]
    fn decodes_resamples_and_seeks_the_fixtures() {
        for f in ["Tagged/Album/02-second.m4a", "Compilation/01.mp3"] {
            let path = format!("{}/../tests/Fixtures/music/{f}", env!("CARGO_MANIFEST_DIR"));
            let s = Arc::new(shared());
            let bytes = std::fs::read(&path).unwrap();
            let (s2, h) = (s.clone(), thread::spawn({ let s = s.clone(); move || decode(&s, 1, Box::new(std::io::Cursor::new(bytes)), 48000) }));
            let deadline = std::time::Instant::now() + Duration::from_secs(10);
            while !s2.decoded.load(SeqCst) && std::time::Instant::now() < deadline {
                thread::sleep(Duration::from_millis(10));
            }
            let n = s2.queue.lock().unwrap().len();
            assert!(n > 0 && n % 2 == 0, "{f}: {n} samples");
            // a seek restarts from the target and the position follows it
            *s2.seek.lock().unwrap() = Some(0.5);
            thread::sleep(Duration::from_millis(300));
            assert!(s2.base.load(SeqCst) >= 24000 - 1, "{f}: base after seek {}", s2.base.load(SeqCst));
            s2.generation.store(99, SeqCst); // stop the thread
            h.join().unwrap().unwrap();
        }
    }

    #[test]
    fn a_newer_generation_stops_the_decoder() {
        let s = shared();
        s.generation.store(2, SeqCst);
        let path = format!("{}/../tests/Fixtures/music/Compilation/01.mp3", env!("CARGO_MANIFEST_DIR"));
        decode(&s, 1, Box::new(std::io::Cursor::new(std::fs::read(path).unwrap())), 44100).unwrap();
        assert!(s.queue.lock().unwrap().is_empty());
    }

    #[test]
    fn decodes_opus_with_the_right_length_pitch_and_level() {
        // 2 s of 440 Hz, ffmpeg's sine (-18 dBFS) lowered by 12 dB = -30 dBFS peak = -33 dBFS rms, encoded by libopus: 96000 frames
        let bytes = std::fs::read(format!("{}/fixtures/tone-440.opus", env!("CARGO_MANIFEST_DIR"))).unwrap();
        let s = Arc::new(shared());
        let h = thread::spawn({ let s = s.clone(); move || decode(&s, 1, Box::new(std::io::Cursor::new(bytes)), 48000) });
        let deadline = std::time::Instant::now() + Duration::from_secs(20);
        while !s.decoded.load(SeqCst) && std::time::Instant::now() < deadline {
            thread::sleep(Duration::from_millis(10));
        }
        assert!(s.decoded.load(SeqCst), "decoding did not finish");
        let q: Vec<f32> = s.queue.lock().unwrap().iter().copied().collect();
        // seek to 1.0 s: what is left is the second second, exactly (the decoder drops what it decoded before the target)
        *s.seek.lock().unwrap() = Some(1.0);
        thread::sleep(Duration::from_millis(400));
        let left_after = s.queue.lock().unwrap().len() / 2;
        // Ogg timestamps count the 312-sample pre-skip, so an Opus seek is 6.5 ms off at most
        assert!((left_after as i64 - 48000).abs() < 400, "{left_after} frames left after seeking to 1.0 s of 2.0 s");
        assert_eq!(s.base.load(SeqCst), 48000);
        s.generation.store(99, SeqCst); // stop the thread
        h.join().unwrap().unwrap_or_else(|e| panic!("{e}"));
        let frames = q.len() / 2;
        assert!((frames as i64 - 96000).abs() < 1500, "{frames} frames"); // up to one packet of padding at the end
        let left: Vec<f32> = q.chunks(2).skip(2000).take(40000).map(|f| f[0]).collect();
        let crossings = left.windows(2).filter(|w| w[0] < 0.0 && w[1] >= 0.0).count();
        let hz = crossings as f32 / (40000.0 / 48000.0);
        assert!((hz - 440.0).abs() < 5.0, "pitch {hz}");
        let rms = (left.iter().map(|v| v * v).sum::<f32>() / left.len() as f32).sqrt();
        let db = 20.0 * rms.log10();
        assert!((db - -33.0).abs() < 1.0, "level {db} dBFS rms");
        assert!(q[..2].iter().all(|v| v.abs() < 0.2), "the pre-skip was dropped, the start is not a click");
    }

    /// Against ffmpeg (Opus or any 48 kHz file): `ffmpeg -i f.opus -f f32le -ar 48000 -ac 2 ref.f32`, then
    /// `MAGNETITE_OPUS=f.opus MAGNETITE_REF=ref.f32 cargo test --release matches_ffmpeg -- --ignored --nocapture`. Set MAGNETITE_RATE to the file's rate to compare without resampling. 2026-11-09: Opus 75 dB SNR, Vorbis 138 dB.
    #[test]
    #[ignore]
    fn matches_ffmpeg_on_a_real_file() {
        let (file, reference) = (std::env::var("MAGNETITE_OPUS").unwrap(), std::env::var("MAGNETITE_REF").unwrap());
        let s = Arc::new(shared());
        let bytes = std::fs::read(file).unwrap();
        let h = thread::spawn({ let s = s.clone(); move || decode(&s, 1, Box::new(std::io::Cursor::new(bytes)), std::env::var("MAGNETITE_RATE").ok().and_then(|r| r.parse().ok()).unwrap_or(48000)) });
        while !s.decoded.load(SeqCst) {
            thread::sleep(Duration::from_millis(20));
            // drain as the output would, so back-pressure does not stall the decoder
            if s.queue.lock().unwrap().len() > 40_000 {
                s.collected.lock().unwrap().extend(s.queue.lock().unwrap().drain(..));
            }
        }
        s.generation.store(99, SeqCst);
        h.join().unwrap().unwrap();
        let mut ours: Vec<f32> = std::mem::take(&mut *s.collected.lock().unwrap());
        ours.extend(s.queue.lock().unwrap().iter());
        let refd: Vec<f32> = std::fs::read(reference).unwrap().chunks(4).map(|b| f32::from_le_bytes([b[0], b[1], b[2], b[3]])).collect();
        println!("ours {} samples, ffmpeg {}", ours.len(), refd.len());
        // align by the best offset within 6000 frames (ffmpeg trims the encoder delay of an m4a, about 2112 frames)
        let n = ours.len().min(refd.len()) - 8000;
        let (mut best, mut best_off) = (f64::MAX, 0i64);
        for off in -6000i64..=6000 {
            let (a, b) = if off >= 0 { (off as usize * 2, 0) } else { (0, (-off) as usize * 2) };
            let mut e = 0.0f64;
            for i in (0..200_000).step_by(7) {
                let d = (ours[a + 200_000 + i] - refd[b + 200_000 + i]) as f64;
                e += d * d;
            }
            if e < best {
                (best, best_off) = (e, off);
            }
        }
        let (a, b) = if best_off >= 0 { (best_off as usize * 2, 0) } else { (0, (-best_off) as usize * 2) };
        let (mut sig, mut err) = (0.0f64, 0.0f64);
        for i in 0..n.saturating_sub(a.max(b)) {
            let (x, y) = (refd[b + i] as f64, ours[a + i] as f64);
            sig += x * x;
            err += (x - y) * (x - y);
        }
        let snr = 10.0 * (sig / err.max(1e-20)).log10();
        println!("offset {best_off} frames, SNR vs ffmpeg {snr:.1} dB");
        assert!(snr > 40.0, "SNR {snr}");
    }
}
