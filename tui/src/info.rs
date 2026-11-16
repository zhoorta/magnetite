//! How much the lists and the player say about a file: the web's info level (`dense/info.js`). 0 nothing, 1 the format and the
//! numbers (DR, loudness), 2 also the findings. It is the account's setting (`ui.info` from `/me`, `PUT /settings/ui`), so a
//! change here shows in the web library and the other way round. Pure; the keys and the drawing are in `main.rs`.

use crate::{api::Track, truth::severity};

pub const DEFAULT: u8 = 2;

/// `I` goes 2, 1, 0, 2, as in the web.
pub fn cycle(level: u8) -> u8 {
    (level + 2) % 3
}

pub fn label(level: u8) -> &'static str {
    ["off", "DR only", "all"][level.min(2) as usize]
}

/// A saved level that is out of range (a newer server) counts as the default.
pub fn clamp(level: u64) -> u8 {
    if level <= 2 { level as u8 } else { DEFAULT }
}

/// The line under the player's title: the format only, `OPUS · 32-bit / 48 kHz` (the numbers and the findings are in the details,
/// `d` on a track row). The stored depth when it is known (what the file says it is), else what was measured. Empty at level 0.
pub fn meta_line(t: &Track, level: u8) -> String {
    if level == 0 {
        return String::new();
    }
    let truth = t.truth.clone().unwrap_or_default();
    let mut meta = vec![t.codec.clone().unwrap_or_default().to_uppercase()];
    let bits = truth.declared_bits.or(truth.bits).filter(|b| *b > 0.0);
    let khz = truth.sample_rate.filter(|r| *r > 0.0).map(|r| format!("{} kHz", format!("{:.1}", r / 1000.0).trim_end_matches(".0")));
    match (bits, khz) {
        (Some(b), Some(k)) => meta.push(format!("{}-bit / {k}", b as u32)),
        (None, Some(k)) => meta.push(k),
        (Some(b), None) => meta.push(format!("{}-bit", b as u32)),
        _ => {}
    }
    meta.retain(|m| !m.is_empty());
    meta.join(" · ")
}

/// What a track row adds after its duration, and whether it is a finding worth the accent colour: `DR9` at level 1, the marker
/// too at level 2. Nothing at 0 or before the track is analysed.
pub fn row_extra(t: &Track, level: u8, marker: &str) -> Option<(String, bool)> {
    let tr = t.truth.as_ref().filter(|x| x.analyzed)?;
    if level == 0 {
        return None;
    }
    let dr = tr.dr.map_or("–".to_string(), |d| format!("{d:>2.0}"));
    if level == 1 {
        return Some((format!("  DR{dr}"), false));
    }

    Some((format!("  {marker} DR{dr}"), severity(t) >= 2))
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn track(flags: serde_json::Value) -> Track {
        serde_json::from_value(json!({"id": 1, "title": "T", "codec": "flac", "open": true, "truth": {"analyzed": true, "dr": 9.4, "lufs": -10.2, "bits": 16.0, "sample_rate": 44100.0, "flags": flags}})).unwrap()
    }

    #[test]
    fn i_goes_all_dr_off_and_round() {
        assert_eq!([cycle(2), cycle(1), cycle(0)], [1, 0, 2]);
        assert_eq!([label(0), label(1), label(2)], ["off", "DR only", "all"]);
        assert_eq!((clamp(1), clamp(7)), (1, 2));
    }

    #[test]
    fn the_player_line_is_the_format_only_and_nothing_at_zero() {
        let t = track(json!([{"level": "problem", "label": "Clipped"}]));
        assert_eq!(meta_line(&t, 2), "FLAC · 16-bit / 44.1 kHz");
        assert_eq!(meta_line(&t, 1), "FLAC · 16-bit / 44.1 kHz");
        assert_eq!(meta_line(&t, 0), "");
        let mut opus = track(json!([]));
        opus.codec = Some("opus".into());
        opus.truth.as_mut().unwrap().bits = Some(32.0);
        opus.truth.as_mut().unwrap().sample_rate = Some(48000.0);
        assert_eq!(meta_line(&opus, 2), "OPUS · 32-bit / 48 kHz");
        let mut stored = track(json!([]));
        stored.truth.as_mut().unwrap().declared_bits = Some(24.0); // the file says 24, the audio uses 16
        assert_eq!(meta_line(&stored, 2), "FLAC · 24-bit / 44.1 kHz");
    }

    #[test]
    fn a_track_row_shows_dr_then_the_marker_and_only_when_analysed() {
        let t = track(json!([{"level": "warn", "label": "Loud"}]));
        assert_eq!(row_extra(&t, 0, "!"), None);
        assert_eq!(row_extra(&t, 1, "!"), Some(("  DR 9".into(), false)));
        assert_eq!(row_extra(&t, 2, "!"), Some(("  ! DR 9".into(), true)));
        let mut plain = track(json!([]));
        plain.truth.as_mut().unwrap().analyzed = false;
        assert_eq!(row_extra(&plain, 2, " "), None);
        assert_eq!(row_extra(&track(json!([{"level": "note"}])), 2, "·").map(|x| x.1), Some(false)); // a note is not a finding to flag
    }
}
