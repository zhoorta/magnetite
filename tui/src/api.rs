use serde::Deserialize;
use serde_json::{json, Value};
use std::{thread::sleep, time::Duration};

#[derive(Deserialize, Clone, Debug)]
pub struct Album {
    pub id: u64,
    pub title: String,
    pub artist: Option<String>,
    pub year: Option<i64>,
    pub track_count: Option<u64>,
    pub dr: Option<f64>,
    #[serde(default)]
    pub tracks: Vec<Track>,
}

#[derive(Deserialize, Clone, Debug)]
pub struct Track {
    pub id: u64,
    pub title: String,
    pub artist: Option<String>,
    pub album: Option<String>,
    pub number: Option<i64>,
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
        ureq::Error::Status(c, r) => format!("server answered {c}: {}", r.into_string().unwrap_or_default().chars().take(200).collect::<String>()),
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
