//! Loudness-matched playback: the stored integrated loudness (LUFS) of a track, or of its album, decides a gain that brings it to
//! one target, so albums do not jump in volume. Applied to the output only (the meters keep showing the file's own level).
//! Only the owner's analysed tracks have a LUFS; for the others nothing changes.
use crate::api::Track;

/// The level everything is brought to. Most masters are louder than this, so matching mostly turns things down and rarely
/// needs the headroom check below.
pub const TARGET: f32 = -16.0;
/// A boost may not push the track's true peak above this.
const PEAK_CEILING: f64 = -1.0;

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Mode {
    Off,
    /// One gain for the whole album (its mean LUFS), so quiet and loud tracks keep their relation.
    Album,
    /// Every track to the target on its own.
    Track,
}

impl Mode {
    pub fn id(self) -> &'static str {
        match self {
            Mode::Off => "off",
            Mode::Album => "album",
            Mode::Track => "track",
        }
    }
    pub fn from_id(s: &str) -> Mode {
        match s {
            "album" => Mode::Album,
            "track" => Mode::Track,
            _ => Mode::Off,
        }
    }
    pub fn next(self) -> Mode {
        match self {
            Mode::Off => Mode::Album,
            Mode::Album => Mode::Track,
            Mode::Track => Mode::Off,
        }
    }
}

fn lufs(t: &Track) -> Option<f64> {
    t.truth.as_ref().filter(|x| x.analyzed).and_then(|x| x.lufs)
}

/// The gain in dB for `t` (`album` is every track of its album that is known), or `None` when it cannot be worked out
/// (mode off, or no measurement: then the output stays as it is).
pub fn gain_db(mode: Mode, t: &Track, album: &[&Track]) -> Option<f32> {
    let own = lufs(t)?;
    let reference = match mode {
        Mode::Off => return None,
        Mode::Track => own,
        Mode::Album => {
            let known: Vec<f64> = album.iter().filter_map(|a| lufs(a)).collect();
            if known.is_empty() { own } else { known.iter().sum::<f64>() / known.len() as f64 }
        }
    };
    let mut g = TARGET as f64 - reference;
    if g > 0.0 {
        // a boost only as far as the track's own peak allows
        let headroom = t.truth.as_ref().and_then(|x| x.true_peak).map_or(0.0, |tp| (PEAK_CEILING - tp).max(0.0));
        g = g.min(headroom);
    }
    Some(g.clamp(-24.0, 12.0) as f32)
}

pub fn linear(db: f32) -> f32 {
    10f32.powf(db / 20.0)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::api::Truth;

    fn track(lufs: Option<f64>, tp: Option<f64>) -> Track {
        serde_json::from_value::<Track>(serde_json::json!({"id": 1, "title": "t", "open": true,
            "truth": {"analyzed": lufs.is_some(), "lufs": lufs, "true_peak": tp}}))
        .unwrap()
    }

    #[test]
    fn a_loud_track_is_turned_down_to_the_target_and_a_quiet_one_up_as_far_as_its_peak_allows() {
        let loud = track(Some(-9.0), Some(0.2));
        assert_eq!(gain_db(Mode::Track, &loud, &[]), Some(-7.0));
        let quiet = track(Some(-24.0), Some(-10.0));
        assert_eq!(gain_db(Mode::Track, &quiet, &[]), Some(8.0));
        let quiet_but_peaky = track(Some(-24.0), Some(-3.0)); // 8 dB wanted, 2 dB of room below -1 dBTP
        assert_eq!(gain_db(Mode::Track, &quiet_but_peaky, &[]), Some(2.0));
        let over = track(Some(-30.0), Some(0.5)); // no room at all
        assert_eq!(gain_db(Mode::Track, &over, &[]), Some(0.0));
    }

    #[test]
    fn album_mode_gives_every_track_of_an_album_the_same_gain() {
        let (a, b) = (track(Some(-10.0), Some(-1.0)), track(Some(-14.0), Some(-1.0)));
        let album = [&a, &b];
        // mean -12 LUFS: both tracks -4 dB, so the 4 dB between them stays
        assert_eq!(gain_db(Mode::Album, &a, &album), Some(-4.0));
        assert_eq!(gain_db(Mode::Album, &b, &album), Some(-4.0));
        assert_eq!(gain_db(Mode::Track, &b, &album), Some(-2.0));
    }

    #[test]
    fn nothing_changes_without_a_measurement_or_when_off() {
        assert_eq!(gain_db(Mode::Track, &track(None, None), &[]), None);
        assert_eq!(gain_db(Mode::Off, &track(Some(-9.0), None), &[]), None);
        let _ = Truth::default();
        assert!((linear(-6.0) - 0.501).abs() < 0.001 && (linear(0.0) - 1.0).abs() < 1e-6);
    }

    #[test]
    fn modes_cycle_and_round_trip() {
        assert_eq!(Mode::Off.next().next().next(), Mode::Off);
        for m in [Mode::Off, Mode::Album, Mode::Track] {
            assert_eq!(Mode::from_id(m.id()), m);
        }
    }
}
