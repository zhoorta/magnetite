//! A/B compare: two copies of one recording (two masters, a rip and its lossy twin, a duplicate) play in lock step on two
//! output streams; one is heard, the other is muted but keeps decoding, so a switch is instant and in the same place. The
//! meters of both sides are drawn side by side from each side's own samples (a muted side still shows its file's level), and
//! "same loudness" brings both to the quieter one's LUFS (attenuation only), because a louder copy always sounds better.
use crate::{
    api::Track,
    meters::Meters,
    mmss,
    panels::{self, Ctx, Kind},
    theme::{c, Theme},
    truth::Row,
};
use ratatui::{
    layout::{Constraint, Layout, Rect},
    style::{Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, Paragraph},
    Frame,
};
use std::time::Instant;

pub struct Ab {
    pub a: Track,
    /// Every other copy of the recording that can be played; `b_idx` is the one being compared.
    pub candidates: Vec<Track>,
    pub b_idx: usize,
    /// 0 = A is heard, 1 = B.
    pub audible: usize,
    pub matched: bool,
    /// Both sides are loading; nothing is heard until each has buffered, so they start together.
    pub syncing: bool,
    pub started: Instant,
}

impl Ab {
    pub fn new(a: Track, candidates: Vec<Track>) -> Self {
        Ab { a, candidates, b_idx: 0, audible: 0, matched: true, syncing: true, started: Instant::now() }
    }

    pub fn b(&self) -> &Track {
        &self.candidates[self.b_idx]
    }

    /// Gains in dB for A and B: with matching, both come down to the quieter one's loudness; without it, none.
    pub fn gains_db(&self) -> (f32, f32) {
        let l = |t: &Track| t.truth.as_ref().filter(|x| x.analyzed).and_then(|x| x.lufs);
        match (self.matched, l(&self.a), l(self.b())) {
            (true, Some(a), Some(b)) => {
                let target = a.min(b);
                ((target - a) as f32, (target - b) as f32)
            }
            _ => (0.0, 0.0),
        }
    }

    /// The linear gain of each side: the heard one at its matching gain, the other silent.
    pub fn output_gains(&self) -> (f32, f32) {
        let (ga, gb) = self.gains_db();
        let (a, b) = (crate::loudness::linear(ga), crate::loudness::linear(gb));
        if self.audible == 0 { (a, 0.0) } else { (0.0, b) }
    }
}

fn norm(s: &Option<String>) -> String {
    s.as_deref().unwrap_or("").trim().to_lowercase()
}

/// The other playable tracks that are the same recording as `t`: same artist and title (case and spacing aside), a length
/// within 2 s (the server's rule for "another copy"). Copies on other albums come first, they are the usual masters to compare.
pub fn copies(rows: &[Row], t: &Track) -> Vec<Track> {
    let (artist, title) = (norm(&t.artist), norm(&Some(t.title.clone())));
    if artist.is_empty() || title.is_empty() {
        return vec![];
    }
    let mut v: Vec<&Row> = rows
        .iter()
        .filter(|r| r.track.id != t.id && r.track.stream_url.is_some() && norm(&r.track.artist) == artist && norm(&Some(r.track.title.clone())) == title && (r.track.duration.unwrap_or(0.0) - t.duration.unwrap_or(0.0)).abs() <= 2.0)
        .collect();
    v.sort_by_key(|r| (r.track.album == t.album, r.track.id));
    v.into_iter().map(|r| r.track.clone()).collect()
}

fn describe(t: &Track) -> String {
    let tr = t.truth.clone().unwrap_or_default();
    let mut p = vec![t.codec.clone().unwrap_or_default().to_uppercase()];
    if let (Some(b), Some(sr)) = (tr.bits, tr.sample_rate) {
        p.push(format!("{}/{}", b as u32, (sr / 1000.0 * 10.0).round() / 10.0));
    }
    if let Some(d) = tr.dr {
        p.push(format!("DR{}", d as i32));
    }
    if let Some(l) = tr.lufs {
        p.push(format!("{l:.1} LUFS"));
    }
    if let Some(tp) = tr.true_peak {
        p.push(format!("TP {tp:.1}"));
    }
    p.join(" · ")
}

pub struct Side<'a> {
    pub meters: &'a Meters,
    pub tap: &'a [f32],
}

/// The whole screen of the compare: two columns (A, B) each with its title, format line and meters, then the difference line,
/// the progress and the keys.
#[allow(clippy::too_many_arguments)]
pub fn draw(f: &mut Frame, area: Rect, ab: &Ab, a: Side, b: Side, rate: u32, th: &Theme, panels: &[Kind], pos: f64, status: &str) {
    let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
    let [head, cols, diff, prog, foot] = Layout::vertical([Constraint::Length(1), Constraint::Min(6), Constraint::Length(2), Constraint::Length(1), Constraint::Length(1)]).areas(area);
    let (ga, gb) = ab.gains_db();
    f.render_widget(
        Paragraph::new(Line::from(vec![
            Span::styled(" A/B compare ", Style::new().fg(c(th.bg)).bg(accent)),
            Span::raw(format!("  same loudness: {}", if ab.matched { "on" } else { "off" })),
            Span::styled(if ab.syncing { "  starting both…" } else { "" }, Style::new().fg(muted)),
        ])),
        head,
    );
    let [left, right] = Layout::horizontal([Constraint::Percentage(50), Constraint::Percentage(50)]).areas(cols);
    let b_track = ab.b();
    for (i, (area, track, side, gain, label)) in [(left, &ab.a, a, ga, "A"), (right, b_track, b, gb, "B")].into_iter().enumerate() {
        let on = ab.audible == i;
        let block = Block::new().borders(Borders::ALL).border_style(Style::new().fg(if on { accent } else { muted })).title(Span::styled(format!(" {label}{} ", if on { "  ▶ heard" } else { "" }), Style::new().fg(if on { accent } else { muted }).add_modifier(Modifier::BOLD)));
        let inner = block.inner(area);
        f.render_widget(block, area);
        let [info, body] = Layout::vertical([Constraint::Length(4), Constraint::Min(2)]).areas(inner);
        f.render_widget(
            Paragraph::new(vec![
                Line::from(vec![Span::styled(track.title.clone(), Style::new().fg(text).add_modifier(Modifier::BOLD)), Span::styled(format!("  {}", track.artist.clone().unwrap_or_default()), Style::new().fg(accent))]),
                Line::from(track.album.clone().unwrap_or_default()).style(Style::new().fg(muted)),
                Line::from(describe(track)).style(Style::new().fg(text)),
                Line::from(if ab.matched && (ga != 0.0 || gb != 0.0) { format!("gain {gain:+.1} dB") } else { String::new() }).style(Style::new().fg(muted)),
            ]),
            info,
        );
        let ctx = Ctx { m: side.meters, tap: side.tap, rate, th };
        let shown: Vec<Kind> = panels.iter().take(2).copied().collect();
        for (kind, cell) in shown.iter().zip(crate::cells(shown.len(), body)) {
            panels::draw(f, Rect::new(cell.x, cell.y, cell.width.saturating_sub(1).max(1), cell.height), *kind, &ctx);
        }
    }
    // what differs, B against A
    let d = |x: Option<f64>, y: Option<f64>, unit: &str, dec: usize| match (x, y) {
        (Some(x), Some(y)) => Some(format!("{:+.dec$}{unit}", y - x)),
        _ => None,
    };
    let tr = |t: &Track| t.truth.clone().unwrap_or_default();
    let (ta, tb) = (tr(&ab.a), tr(b_track));
    let diffs: Vec<String> = [d(ta.lufs, tb.lufs, " LUFS", 1).map(|s| format!("loudness {s}")), d(ta.dr, tb.dr, "", 0).map(|s| format!("DR {s}")), d(ta.true_peak, tb.true_peak, " dB", 1).map(|s| format!("true peak {s}")), d(ta.content_hz.map(|h| h / 1000.0), tb.content_hz.map(|h| h / 1000.0), " kHz", 1).map(|s| format!("content {s}"))]
        .into_iter()
        .flatten()
        .collect();
    f.render_widget(
        Paragraph::new(vec![Line::from(format!(" B against A: {}", if diffs.is_empty() { "no measurements to compare".to_string() } else { diffs.join(" · ") })).style(Style::new().fg(text)), Line::from(format!(" {} candidate(s) for B, showing {} of {}", ab.candidates.len(), ab.b_idx + 1, ab.candidates.len())).style(Style::new().fg(muted))]),
        diff,
    );
    let dur = ab.a.duration.unwrap_or(0.0);
    let w = prog.width.saturating_sub(16) as usize;
    let at = if dur > 0.0 { ((pos / dur).clamp(0.0, 1.0) * w as f64) as usize } else { 0 };
    f.render_widget(
        Paragraph::new(Line::from(vec![
            Span::raw(format!(" {} ", mmss(pos))),
            Span::styled("━".repeat(at), Style::new().fg(accent)),
            Span::styled("●", Style::new().fg(c(th.accent2))),
            Span::styled("─".repeat(w.saturating_sub(at)), Style::new().fg(muted)),
            Span::raw(format!(" {}", mmss(dur))),
        ])),
        prog,
    );
    let hint = if status.is_empty() { " 1/2 or x: hear A/B · g same loudness · [ ] other copy · space · ,/. seek · m + - meters · esc leave".to_string() } else { status.to_string() };
    f.render_widget(Paragraph::new(hint).style(Style::new().fg(muted)), foot);
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn track(id: u64, artist: &str, title: &str, album: &str, dur: f64, lufs: Option<f64>) -> Track {
        serde_json::from_value(json!({"id": id, "title": title, "artist": artist, "album": album, "duration": dur, "open": true, "stream_url": "http://x/s",
            "truth": {"analyzed": true, "lufs": lufs, "dr": 8.0, "flags": []}}))
        .unwrap()
    }

    fn row(t: Track) -> Row {
        Row { album_id: 1, album_order: 0, track: t }
    }

    #[test]
    fn copies_are_the_same_recording_other_albums_first() {
        let a = track(1, "Bowie", "Heroes", "Heroes", 370.0, Some(-12.0));
        let rows = vec![
            row(a.clone()),
            row(track(2, "bowie ", "HEROES", "Heroes (Remaster)", 371.5, Some(-9.0))), // spacing and case do not matter, 1.5 s apart
            row(track(3, "Bowie", "Heroes", "Heroes", 370.5, Some(-12.0))),            // same album: a duplicate
            row(track(4, "Bowie", "Heroes", "Live", 410.0, None)),                      // different length
            row(track(5, "Other", "Heroes", "X", 370.0, None)),                         // different artist
        ];
        let ids: Vec<u64> = copies(&rows, &a).iter().map(|t| t.id).collect();
        assert_eq!(ids, vec![2, 3]);
        assert!(copies(&rows, &track(9, "", "Heroes", "", 370.0, None)).is_empty());
    }

    #[test]
    fn matching_brings_both_down_to_the_quieter_copy_and_only_the_heard_side_has_sound() {
        let a = track(1, "B", "H", "x", 100.0, Some(-12.0));
        let b = track(2, "B", "H", "y", 100.0, Some(-9.0));
        let mut ab = Ab::new(a, vec![b]);
        let (ga, gb) = ab.gains_db();
        assert_eq!((ga, gb), (0.0, -3.0));
        let (la, lb) = ab.output_gains();
        assert!((la - 1.0).abs() < 1e-6 && lb == 0.0);
        ab.audible = 1;
        let (la, lb) = ab.output_gains();
        assert!(la == 0.0 && (lb - 0.7079).abs() < 0.001);
        ab.matched = false;
        assert_eq!(ab.gains_db(), (0.0, 0.0));
    }

    #[test]
    fn it_draws_at_awkward_sizes() {
        use ratatui::{backend::TestBackend, Terminal};
        let th = crate::theme::builtin().remove(0);
        let ab = Ab::new(track(1, "B", "H", "x", 100.0, Some(-12.0)), vec![track(2, "B", "H", "y", 100.0, Some(-9.0))]);
        let m = Meters::new();
        for (w, h) in [(120u16, 30u16), (50, 12), (20, 6)] {
            let mut t = Terminal::new(TestBackend::new(w, h)).unwrap();
            t.draw(|f| draw(f, Rect::new(0, 0, w, h), &ab, Side { meters: &m, tap: &[] }, Side { meters: &m, tap: &[] }, 48000, &th, &[Kind::VuWide, Kind::Spectrum, Kind::Scope], 12.0, "")).unwrap();
        }
    }
}
