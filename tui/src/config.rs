use serde::{Deserialize, Serialize};
use std::path::PathBuf;

#[derive(Serialize, Deserialize, Clone)]
pub struct Config {
    pub server: String,
    pub token: String,
    pub username: String,
    #[serde(default)]
    pub theme: Option<String>,
    /// The meters shown (ids, see `panels::Kind`); absent means the account's own list from `/me`.
    #[serde(default)]
    pub meters: Option<Vec<String>>,
    /// What was playing when the client last ran: it is opened again (paused) at the next start.
    #[serde(default)]
    pub last: Option<Last>,
    /// Meter colours taken from the album cover; absent means on.
    #[serde(default)]
    pub tint: Option<bool>,
    /// How many times taller than wide one cell of the terminal is (about 2; fonts differ), so that the cover is a true square.
    /// Absent: what the terminal reports, else 2. `MAGNETITE_CELL_ASPECT` overrides it.
    #[serde(default)]
    pub cell_aspect: Option<f32>,
    /// Loudness matching: "off", "album" or "track" (absent: off).
    #[serde(default)]
    pub loudness: Option<String>,
    /// The folders that were open in the album list (ids), so the list looks the same at the next start.
    #[serde(default)]
    pub open_folders: Vec<u64>,
}

#[derive(Serialize, Deserialize, Clone, Default)]
pub struct Last {
    pub album: u64,
    pub track: u64,
    pub position: f64,
}

/// `~/.config/magnetite` (or `$XDG_CONFIG_HOME/magnetite`).
pub fn dir() -> PathBuf {
    let base = std::env::var_os("XDG_CONFIG_HOME")
        .map(PathBuf::from)
        .or_else(|| std::env::var_os("HOME").map(|h| PathBuf::from(h).join(".config")))
        .unwrap_or_else(|| PathBuf::from("."));
    base.join("magnetite")
}

fn path() -> PathBuf {
    dir().join("tui.json")
}

pub fn load() -> Option<Config> {
    serde_json::from_slice(&std::fs::read(path()).ok()?).ok()
}

pub fn save(c: &Config) -> std::io::Result<()> {
    if cfg!(test) {
        return Ok(()); // tests must never overwrite the real token file
    }
    let p = path();
    std::fs::create_dir_all(p.parent().unwrap())?;
    std::fs::write(&p, serde_json::to_vec_pretty(c).unwrap())?;
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt;
        std::fs::set_permissions(&p, std::fs::Permissions::from_mode(0o600))?; // the token is a password
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn an_old_config_without_last_still_loads_and_last_round_trips() {
        let old = r#"{"server":"https://x","token":"t","username":"u"}"#;
        let mut c: Config = serde_json::from_str(old).unwrap();
        assert!(c.last.is_none());
        c.last = Some(Last { album: 3, track: 9, position: 61.5 });
        let back: Config = serde_json::from_slice(&serde_json::to_vec(&c).unwrap()).unwrap();
        assert_eq!((back.last.as_ref().unwrap().track, back.last.unwrap().position), (9, 61.5));
    }
}
