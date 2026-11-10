use serde::Deserialize;
use serde_json::{json, Value};
use std::{thread::sleep, time::Duration};

#[derive(Deserialize, Clone, Debug)]
pub struct Album {
    pub id: u64,
    pub title: String,
    pub artist: Option<String>,
    /// The album artist, else its tracks' single artist, else "Various artists" (older servers do not send it).
    pub display_artist: Option<String>,
    pub year: Option<i64>,
    pub track_count: Option<u64>,
    pub dr: Option<f64>,
    #[serde(default)]
    pub tracks: Vec<Track>,
}

impl Album {
    /// What to show as the artist: the server's `display_artist`, else the tagged one, else the single artist of the tracks when they are loaded.
    pub fn shown_artist(&self) -> String {
        self.display_artist
            .clone()
            .or_else(|| self.artist.clone())
            .or_else(|| {
                let names: Vec<&str> = self.tracks.iter().filter_map(|t| t.artist.as_deref()).collect::<std::collections::BTreeSet<_>>().into_iter().collect();
                match names.len() {
                    0 => None,
                    1 => Some(names[0].to_string()),
                    _ => Some("Various artists".into()),
                }
            })
            .unwrap_or_default()
    }
}

#[derive(Deserialize, Clone, Debug)]
pub struct Track {
    pub id: u64,
    pub title: String,
    pub artist: Option<String>,
    pub album: Option<String>,
    pub number: Option<i64>,
    pub album_id: Option<u64>,
    pub duration: Option<f64>,
    pub codec: Option<String>,
    pub open: bool,
    pub stream_url: Option<String>,
    pub cover_url: Option<String>,
    pub truth: Option<Truth>,
}

/// The owner's file-truth measurements (`tracks.analysis`); every field may be missing.
#[derive(Deserialize, Clone, Debug, Default)]
pub struct Truth {
    #[serde(default)]
    pub analyzed: bool,
    pub dr: Option<f64>,
    pub lufs: Option<f64>,
    pub lra: Option<f64>,
    pub true_peak: Option<f64>,
    pub sample_rate: Option<f64>,
    pub bits: Option<f64>,
    pub declared_bits: Option<f64>,
    pub dr_class: Option<String>,
    pub sample_peak: Option<f64>,
    pub clipped_runs: Option<i64>,
    pub cutoff_hz: Option<f64>,
    pub content_hz: Option<f64>,
    #[serde(default)]
    pub flags: Vec<Value>,
}

#[derive(Deserialize, Default, Clone, Debug)]
pub struct SearchResult {
    #[serde(default)]
    pub albums: Vec<Album>,
    #[serde(default)]
    pub tracks: Vec<Track>,
}

#[derive(Deserialize)]
struct Data<T> {
    data: T,
}

#[derive(Deserialize)]
struct Page<T> {
    data: Vec<T>,
    meta: Option<Meta>,
}

#[derive(Deserialize)]
struct Meta {
    current_page: u32,
    last_page: u32,
}

pub type Result<T> = std::result::Result<T, String>;

fn err(e: ureq::Error) -> String {
    match e {
        ureq::Error::Status(401, _) => "not signed in (run `magnetite login`)".into(),
        ureq::Error::Status(413, _) => "server answered 413: the upload is larger than the web server accepts (nginx `client_max_body_size` for /api/v1/uploads, see docs/bare-metal.md)".into(),
        ureq::Error::Status(c, r) => {
            let body = r.into_string().unwrap_or_default();
            // Laravel's JSON errors carry a readable `message`; show that instead of the whole body.
            let said = serde_json::from_str::<Value>(&body).ok().and_then(|v| v["message"].as_str().filter(|m| !m.is_empty()).map(str::to_string)).unwrap_or_else(|| body.chars().take(200).collect());
            format!("server answered {c}: {said}")
        }
        e => e.to_string(),
    }
}

#[derive(Clone)]
pub struct Api {
    pub base: String,
    pub token: String,
}

impl Api {
    pub fn new(server: &str, token: &str) -> Self {
        Api { base: server.trim_end_matches('/').to_string(), token: token.to_string() }
    }

    pub fn auth(&self) -> String {
        format!("Bearer {}", self.token)
    }

    fn get(&self, path: &str) -> ureq::Request {
        ureq::get(&format!("{}/api/v1{}", self.base, path)).set("Authorization", &self.auth()).set("Accept", "application/json")
    }

    /// The account's own meter list (what the web shows); empty when it cannot be read.
    pub fn meters(&self) -> Vec<String> {
        self.get("/me").call().ok().and_then(|r| r.into_json::<Value>().ok()).and_then(|v| v["meters"].as_array().map(|a| a.iter().filter_map(|x| x.as_str().map(str::to_string)).collect())).unwrap_or_default()
    }

    pub fn albums(&self) -> Result<Vec<Album>> {
        let mut all = vec![];
        let mut page = 1;
        loop {
            let p: Page<Album> = self.get(&format!("/albums?page={page}")).call().map_err(err)?.into_json().map_err(|e| e.to_string())?;
            all.extend(p.data);
            match p.meta {
                Some(m) if m.current_page < m.last_page => page += 1,
                _ => return Ok(all),
            }
        }
    }

    /// One page of the library's tracks with their truth (`/tracks?truth=1`, only the analysed ones) and the last page number.
    /// A server without the endpoint answers with an error: the caller falls back to reading albums.
    pub fn analysed_tracks(&self, page: u32) -> Result<(Vec<Track>, u32)> {
        let p: Page<Track> = self.get(&format!("/tracks?truth=1&per_page=300&page={page}")).call().map_err(err)?.into_json().map_err(|e| e.to_string())?;
        Ok((p.data, p.meta.map_or(1, |m| m.last_page)))
    }

    /// The server's search: albums and tracks whose title or artist contains the text (yours and what is shared with you).
    pub fn search(&self, q: &str) -> Result<SearchResult> {
        self.get("/search").query("q", q).call().map_err(err)?.into_json().map_err(|e| e.to_string())
    }

    fn send(&self, method: &str, path: &str) -> ureq::Request {
        ureq::request(method, &format!("{}/api/v1{}", self.base, path)).set("Authorization", &self.auth()).set("Accept", "application/json")
    }

    /// The scan of this account: `state` is idle, queued, running, done or failed.
    pub fn scan(&self) -> Result<Value> {
        self.get("/scan").call().map_err(err)?.into_json().map_err(|e| e.to_string())
    }

    /// The albums that got tracks since a server time (`since` from the upload's answer), all pages.
    pub fn albums_since(&self, since: i64) -> Result<Vec<Album>> {
        let mut all = vec![];
        let mut page = 1;
        loop {
            let p: Page<Album> = self.get(&format!("/albums?since={since}&page={page}")).call().map_err(err)?.into_json().map_err(|e| e.to_string())?;
            all.extend(p.data);
            match p.meta {
                Some(m) if m.current_page < m.last_page => page += 1,
                _ => return Ok(all),
            }
        }
    }

    /// Queue the analysis of one album, one track, or (both None) everything that has none yet; answers with the status.
    pub fn start_analysis(&self, album: Option<u64>, track: Option<u64>) -> Result<Value> {
        self.send("POST", "/analysis").send_json(json!({ "album_id": album, "track_id": track })).map_err(err)?.into_json().map_err(|e| e.to_string())
    }

    /// The analysis run of this account: `state` (idle, queued, running, done), `total`, `done`, `failed`, `current`.
    pub fn analysis(&self) -> Result<Value> {
        self.get("/analysis").call().map_err(err)?.into_json().map_err(|e| e.to_string())
    }

    pub fn track(&self, id: u64) -> Result<Track> {
        let d: Data<Track> = self.get(&format!("/tracks/{id}")).call().map_err(err)?.into_json().map_err(|e| e.to_string())?;
        Ok(d.data)
    }

    /// Tracks without an album that arrived since a server time, in groups by folder (`[{folder, tracks: [{id, title, artist, number}]}]`).
    pub fn loose_tracks(&self, since: i64) -> Result<Vec<Value>> {
        let v: Value = self.get(&format!("/tracks/loose?since={since}")).call().map_err(err)?.into_json().map_err(|e| e.to_string())?;
        Ok(v["groups"].as_array().cloned().unwrap_or_default())
    }

    /// Name an album for loose tracks (`title`, `artist`, `year`, `track_ids`).
    pub fn create_album(&self, fields: Value) -> Result<Album> {
        let d: Data<Album> = self.send("POST", "/albums").send_json(fields).map_err(err)?.into_json().map_err(|e| e.to_string())?;
        Ok(d.data)
    }

    /// Send a multipart body of known length (the upload streams it from disk) and read the JSON answer.
    pub fn upload(&self, content_type: &str, len: u64, body: impl std::io::Read) -> Result<Value> {
        self.send("POST", "/uploads").set("Content-Type", content_type).set("Content-Length", &len.to_string()).send(body).map_err(err)?.into_json().map_err(|e| e.to_string())
    }

    /// MusicBrainz releases that could name the album's tracks (candidates with their per-track proposals).
    pub fn find_titles(&self, id: u64) -> Result<Value> {
        self.send("POST", &format!("/albums/{id}/titles/find")).send_json(json!({})).map_err(err)?.into_json().map_err(|e| e.to_string())
    }

    pub fn apply_titles(&self, id: u64, candidate: usize, parts: &[&str]) -> Result<Value> {
        self.send("POST", &format!("/albums/{id}/titles")).send_json(json!({ "candidate": candidate, "parts": parts })).map_err(err)?.into_json().map_err(|e| e.to_string())
    }

    /// Look for a cover on the web and set it; the answer says where it came from (null: nothing found).
    pub fn find_cover(&self, id: u64) -> Result<Value> {
        self.send("POST", &format!("/albums/{id}/cover/find")).send_json(json!({})).map_err(err)?.into_json().map_err(|e| e.to_string())
    }

    /// Change album fields (the server wants the title every time).
    pub fn edit_album(&self, id: u64, fields: Value) -> Result<Value> {
        self.send("PATCH", &format!("/albums/{id}")).send_json(fields).map_err(err)?.into_json().map_err(|e| e.to_string())
    }

    pub fn album(&self, id: u64) -> Result<Album> {
        let d: Data<Album> = self.get(&format!("/albums/{id}")).call().map_err(err)?.into_json().map_err(|e| e.to_string())?;
        Ok(d.data)
    }
}

/// Sign in with username and password (works on every server that has the token API).
pub fn password_login(server: &str, device_name: &str, username: &str, password: &str) -> Result<(String, String)> {
    let base = server.trim_end_matches('/');
    match ureq::post(&format!("{base}/api/v1/token")).set("Accept", "application/json").send_json(json!({ "username": username, "password": password, "device_name": device_name })) {
        Ok(r) => {
            let v: Value = r.into_json().map_err(|e| e.to_string())?;
            Ok((v["token"].as_str().unwrap_or_default().to_string(), v["user"]["username"].as_str().unwrap_or_default().to_string()))
        }
        Err(ureq::Error::Status(422, r)) => {
            let v: Value = r.into_json().unwrap_or_default();
            Err(v["errors"]["username"][0].as_str().or(v["message"].as_str()).unwrap_or("wrong username or password").to_string())
        }
        Err(e) => Err(err(e)),
    }
}

/// True when the server has no device-code route (older deploy).
pub fn is_missing_route(e: &str) -> bool {
    e.starts_with("server answered 404")
}

/// Sign in like the TV does: show a code, approve it in the browser at /device.
pub fn device_login(server: &str, device_name: &str) -> Result<(String, String)> {
    let base = server.trim_end_matches('/');
    let r: Value = ureq::post(&format!("{base}/api/v1/device-code"))
        .set("Accept", "application/json")
        .send_json(json!({ "device_name": device_name }))
        .map_err(err)?
        .into_json()
        .map_err(|e| e.to_string())?;
    let (code, user_code, url) = (r["device_code"].as_str().unwrap_or_default().to_string(), r["user_code"].as_str().unwrap_or_default(), r["verification_url"].as_str().unwrap_or_default());
    let interval = r["interval"].as_u64().unwrap_or(5).max(1);
    let mut left = r["expires_in"].as_u64().unwrap_or(600);
    println!("Open {url} and enter the code:\n\n    {user_code}\n\nWaiting for approval...");
    while left > 0 {
        sleep(Duration::from_secs(interval));
        left = left.saturating_sub(interval);
        match ureq::post(&format!("{base}/api/v1/device-token")).set("Accept", "application/json").send_json(json!({ "device_code": code })) {
            Ok(r) => {
                let v: Value = r.into_json().map_err(|e| e.to_string())?;
                return Ok((v["token"].as_str().unwrap_or_default().to_string(), v["user"]["username"].as_str().unwrap_or_default().to_string()));
            }
            Err(ureq::Error::Status(400, r)) => {
                let v: Value = r.into_json().unwrap_or_default();
                if v["error"] == "expired_token" {
                    return Err("the code expired".into());
                }
            }
            Err(e) => return Err(err(e)),
        }
    }
    Err("the code expired".into())
}

#[cfg(test)]
mod live_artists {
    use super::*;
    /// `cargo test live_artists -- --ignored --nocapture`: albums whose `artist` is missing, and what their tracks say.
    #[test]
    #[ignore]
    fn live_artists() {
        let cfg = crate::config::load().expect("sign in first");
        let api = Api::new(&cfg.server, &cfg.token);
        let albums = api.albums().unwrap();
        let missing: Vec<_> = albums.iter().filter(|a| a.artist.as_deref().unwrap_or("").is_empty()).collect();
        println!("{} of {} albums have no artist", missing.len(), albums.len());
        for a in missing {
            let full = api.album(a.id).unwrap();
            let mut artists: Vec<String> = full.tracks.iter().filter_map(|t| t.artist.clone()).collect();
            artists.sort();
            artists.dedup();
            println!("{:<45} tracks={} track artists={:?}", a.title, full.tracks.len(), artists);
        }
    }
}

#[cfg(test)]
mod search_tests {
    use super::*;

    #[test]
    fn a_search_answer_parses_with_its_albums_and_tracks() {
        let r: SearchResult = serde_json::from_value(serde_json::json!({
            "albums": [{"id": 1, "title": "Heroes", "artist": "Bowie", "track_count": 10, "cover_url": "http://x/cover/1"}],
            "tracks": [{"id": 5, "title": "Heroes", "artist": "Bowie", "album": "Heroes", "album_id": 1, "duration": 370.2, "open": true, "stream_url": "http://x/stream/5"}]
        }))
        .unwrap();
        assert_eq!((r.albums.len(), r.tracks.len(), r.tracks[0].album_id), (1, 1, Some(1)));
        assert!(serde_json::from_str::<SearchResult>("{}").unwrap().tracks.is_empty());
    }
}

#[cfg(test)]
mod artist_tests {
    use super::*;

    fn album(artist: Option<&str>, display: Option<&str>, tracks: &[&str]) -> Album {
        let t = |a: &str| serde_json::from_value::<Track>(serde_json::json!({"id": 1, "title": "x", "artist": a, "open": true})).unwrap();
        Album { id: 1, title: "A".into(), artist: artist.map(Into::into), display_artist: display.map(Into::into), year: None, track_count: None, dr: None, tracks: tracks.iter().map(|a| t(a)).collect() }
    }

    #[test]
    fn the_shown_artist_prefers_the_servers_then_the_tag_then_the_tracks() {
        assert_eq!(album(None, Some("Srv"), &[]).shown_artist(), "Srv");
        assert_eq!(album(Some("Tag"), None, &["x"]).shown_artist(), "Tag");
        assert_eq!(album(None, None, &["Solo", "Solo"]).shown_artist(), "Solo");
        assert_eq!(album(None, None, &["B", "A", "B"]).shown_artist(), "Various artists");
        assert_eq!(album(None, None, &[]).shown_artist(), "");
    }
}
