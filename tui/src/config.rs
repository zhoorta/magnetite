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
