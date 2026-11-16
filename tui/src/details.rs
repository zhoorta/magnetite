//! The details of one file, as the web's measurements panel (`l`): the format, the numbers, the findings with their explanations,
//! and the average spectrum as a line with the cut-off marked. `truth_lines` is also what the no-meters summary shows for the
//! playing track. The graph is drawn with Braille characters (2 x 4 dots a cell) and is pure: tested without a terminal.

use crate::{
    api::Track,
    theme::{c, Theme},
};
use ratatui::{
    layout::{Constraint, Flex, Layout, Rect},
    style::{Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, Clear, Paragraph, Wrap},
    Frame,
};

/// The web's vertical range of the curve.
pub const DB_TOP: f32 = -20.0;
pub const DB_BOTTOM: f32 = -130.0;

/// What the file is and what the analysis found: format, DR, loudness, peaks, bandwidth and (level 2) the findings.
pub fn truth_lines(t: &Track, th: &Theme, level: u8) -> Vec<Line<'static>> {
    let (text, muted) = (c(th.text), c(th.muted));
    let dim = Style::new().fg(muted);
    let n = |v: Option<f64>, d: usize| v.map(|v| format!("{v:.d$}"));
    let mut lines: Vec<Line<'static>> = vec![];
    let tr = t.truth.clone().unwrap_or_default();
    let mut q = vec![t.codec.clone().unwrap_or_default().to_uppercase()];
    match (n(tr.bits, 0), n(tr.sample_rate.map(|s| s / 1000.0), 1)) {
        (Some(b), Some(r)) => q.push(format!("{b}-bit / {} kHz", r.trim_end_matches(".0"))),
        (None, Some(r)) => q.push(format!("{} kHz", r.trim_end_matches(".0"))),
        _ => {}
    }
    if let (Some(d), Some(b)) = (tr.declared_bits, tr.bits) {
        if d > b {
            q.push(format!("stored as {d:.0}-bit"));
        }
    }
    lines.push(Line::from(q.join(" · ")).style(Style::new().fg(text)));
    if !tr.analyzed {
        lines.push(Line::from("not analysed (or not your file)").style(dim));
    } else {
        let mut l = vec![];
        if let Some(dr) = tr.dr {
            l.push(format!("DR{}{}", dr as i32, tr.dr_class.as_deref().map_or(String::new(), |c| format!(" ({c})"))));
        }
        if let Some(v) = n(tr.lufs, 1) {
            l.push(format!("{v} LUFS"));
        }
        if let Some(v) = n(tr.lra, 1) {
            l.push(format!("LRA {v} LU"));
        }
        lines.push(Line::from(l.join(" · ")).style(Style::new().fg(text)));
        let mut l = vec![];
        if let Some(v) = n(tr.true_peak, 1) {
            l.push(format!("true peak {v} dBTP"));
        }
        if let Some(v) = n(tr.sample_peak, 1) {
            l.push(format!("sample peak {v} dB"));
        }
        if tr.clipped_runs.unwrap_or(0) > 0 {
            l.push(format!("{} clipped runs", tr.clipped_runs.unwrap_or(0)));
        }
        if !l.is_empty() {
            lines.push(Line::from(l.join(" · ")).style(dim));
        }
        let khz = |hz: f64| format!("{} kHz", format!("{:.1}", hz / 1000.0).trim_end_matches(".0"));
        let mut l = vec![];
        if let Some(h) = tr.content_hz {
            l.push(format!("content up to {}", khz(h)));
        }
        if let Some(h) = tr.cutoff_hz {
            l.push(format!("cut-off at {}", khz(h)));
        }
        if !l.is_empty() {
            lines.push(Line::from(l.join(" · ")).style(dim));
        }
        if !tr.flags.is_empty() && level >= 2 {
            lines.push(Line::raw(""));
        }
        for fl in tr.flags.iter().filter(|_| level >= 2) {
            let (level, label, detail) = (fl["level"].as_str().unwrap_or(""), fl["label"].as_str().unwrap_or(""), fl["detail"].as_str().unwrap_or(""));
            let col = match level {
                "problem" => c((0xff, 0x4d, 0x4d)),
                "warn" => c(th.accent2),
                _ => muted,
            };
            lines.push(Line::from(vec![Span::styled(if level == "problem" { "✗ " } else { "! " }, Style::new().fg(col)), Span::styled(label.to_string(), Style::new().fg(col).add_modifier(Modifier::BOLD))]));
            lines.push(Line::from(format!("  {detail}")).style(dim));
        }
    }
    lines
}

/// The curve's dots: `w` x `h` cells, each 2 x 4 dots. The line follows the average level (-20 dB at the top, -130 at the
/// bottom, from 0 Hz to the last bin); `cutoff_x` (0..1 of the width) is a dotted vertical line. Returns the Braille bits of each
/// cell for the curve and for the cut-off separately, so the caller can colour them.
pub fn dots(spectrum: &[f32], cutoff: Option<f32>, w: usize, h: usize) -> Vec<Vec<(u8, u8)>> {
    let mut cells = vec![vec![(0u8, 0u8); w]; h];
    if w == 0 || h == 0 {
        return cells;
    }
    // Braille: bit of the dot at column dx (0, 1) and row dy (0 top .. 3)
    const BIT: [[u8; 4]; 2] = [[0x01, 0x02, 0x04, 0x40], [0x08, 0x10, 0x20, 0x80]];
    let (dw, dh) = (w * 2, h * 4);
    let mut set = |x: usize, y: usize, cut: bool| {
        let (cx, dx, cy, dy) = (x / 2, x % 2, y / 4, y % 4);
        if cx < w && cy < h {
            let slot = &mut cells[cy][cx];
            if cut {
                slot.1 |= BIT[dx][dy];
            } else {
                slot.0 |= BIT[dx][dy];
            }
        }
    };

    if let Some(cx) = cutoff.filter(|x| (0.0..=1.0).contains(x)) {
        let x = (cx * (dw - 1) as f32).round() as usize;
        for y in (0..dh).step_by(2) {
            set(x, y, true);
        }
    }

    if spectrum.len() >= 2 {
        let level = |x: usize| -> usize {
            // the highest value among the bins this column covers, so a narrow peak is not skipped on a wide curve
            let from = x * (spectrum.len() - 1) / (dw - 1).max(1);
            let to = ((x + 1) * (spectrum.len() - 1) / (dw - 1).max(1)).max(from + 1).min(spectrum.len() - 1);
            let v = spectrum[from..=to].iter().cloned().fold(f32::NEG_INFINITY, f32::max).clamp(DB_BOTTOM, DB_TOP);
            ((v - DB_BOTTOM) / (DB_TOP - DB_BOTTOM) * (dh - 1) as f32).round() as usize
        };
        let mut prev = level(0);
        for x in 0..dw {
            let lv = level(x);
            // join this column to the last one so a steep slope has no gaps
            let (lo, hi) = (prev.min(lv), prev.max(lv));
            for l in lo..=hi {
                set(x, dh - 1 - l, false);
            }
            prev = lv;
        }
    }
    cells
}

fn braille(mask: u8) -> char {
    char::from_u32(0x2800 + mask as u32).unwrap_or(' ')
}

/// The graph as lines: the curve in `curve`, the cut-off in `cut`.
pub fn graph_lines(spectrum: &[f32], cutoff: Option<f32>, w: usize, h: usize, curve: ratatui::style::Color, cut: ratatui::style::Color) -> Vec<Line<'static>> {
    dots(spectrum, cutoff, w, h)
        .into_iter()
        .map(|row| {
            Line::from(
                row.into_iter()
                    .map(|(a, b)| if a != 0 { Span::styled(braille(a | b).to_string(), Style::new().fg(curve)) } else if b != 0 { Span::styled(braille(b).to_string(), Style::new().fg(cut)) } else { Span::raw(" ") })
                    .collect::<Vec<_>>(),
            )
        })
        .collect()
}

/// The frequency axis under the graph: a label at round kHz values, as many as fit without touching. `max_hz` is where the
/// last bin ends.
pub fn axis(w: usize, max_hz: f32) -> String {
    let mut row = vec![' '; w];
    if w < 8 || max_hz <= 0.0 {
        return row.into_iter().collect();
    }
    let khz = max_hz / 1000.0;
    let step = [1.0f32, 2.0, 5.0, 10.0, 20.0].into_iter().find(|s| (s / khz * w as f32) >= 9.0).unwrap_or(20.0);
    let mut k = 0.0f32;
    let mut last_end = 0usize;
    while k <= khz + 0.001 {
        let label = if k == 0.0 { "0".to_string() } else { format!("{k:.0}k") };
        let col = ((k / khz) * (w - 1) as f32).round() as usize;
        let start = col.saturating_sub(label.len() / 2).min(w.saturating_sub(label.len()));
        if start >= last_end || k == 0.0 {
            for (i, ch) in label.chars().enumerate() {
                if start + i < w {
                    row[start + i] = ch;
                }
            }
            last_end = start + label.len() + 1;
        }
        k += step;
    }
    row.into_iter().collect()
}

/// How many rows `lines` take when wrapped at `width` columns, word by word as `Paragraph` does (a word longer than a row is
/// split), so the box above the graph is as tall as its text and nothing is cut off.
pub fn wrapped_rows(lines: &[Line], width: u16) -> u16 {
    let width = width.max(1) as usize;
    let mut rows = 0usize;
    for line in lines {
        let text: String = line.spans.iter().map(|s| s.content.to_string()).collect();
        let (mut used, mut r) = (0usize, 1usize);
        for word in text.split(' ') {
            let w = word.chars().count();
            let need = if used == 0 { w } else { used + 1 + w };
            if need <= width {
                used = need;
            } else if w > width {
                // a long word starts on a fresh row and fills whole rows
                if used > 0 {
                    r += 1;
                }
                r += w.saturating_sub(1) / width;
                used = w - (w.saturating_sub(1) / width) * width;
            } else {
                r += 1;
                used = w;
            }
        }
        rows += r;
    }
    rows.min(u16::MAX as usize) as u16
}

/// One track's details over the lists.
pub struct Details {
    pub track: Track,
    pub error: Option<String>,
}

pub fn draw(f: &mut Frame, d: &Details, th: &Theme, level: u8) {
    let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
    let width = 110.min(f.area().width);
    let height = 40.min(f.area().height);
    let [row] = Layout::vertical([Constraint::Length(height)]).flex(Flex::Center).areas(f.area());
    let [area]: [Rect; 1] = Layout::horizontal([Constraint::Length(width)]).flex(Flex::Center).areas(row);
    f.render_widget(Clear, area);
    let t = &d.track;
    let title = format!(" {} — {} ", t.artist.clone().unwrap_or_default(), t.title);
    let block = Block::new()
        .borders(Borders::ALL)
        .title(title)
        .title_bottom(Line::from(Span::styled(" j/k other track · d or esc close ", Style::new().fg(muted))))
        .border_style(Style::new().fg(accent))
        .style(Style::new().bg(c(th.bg)).fg(text));
    let inner = block.inner(area);
    f.render_widget(block, area);

    let mut lines = vec![Line::from(Span::styled(t.album.clone().unwrap_or_default(), Style::new().fg(muted))), Line::raw("")];
    lines.extend(truth_lines(t, th, level.max(2))); // the details always say everything: they are what the info level leaves out
    // the box is as tall as the wrapped text; the graph gets what is left, and when that is too little it is left out
    // (cutting the findings off would hide what the file is, the graph can wait for a taller window)
    let text_h = wrapped_rows(&lines, inner.width).min(inner.height);
    let [top, rest] = Layout::vertical([Constraint::Length(text_h), Constraint::Min(0)]).areas(inner);
    f.render_widget(Paragraph::new(lines).wrap(Wrap { trim: false }), top);

    let Some(tr) = t.truth.as_ref().filter(|x| x.analyzed) else { return };
    // the graph: a title line, the curve, the axis
    if rest.height < 6 || rest.width < 20 {
        return;
    }
    let [head, plot, ax] = Layout::vertical([Constraint::Length(1), Constraint::Min(3), Constraint::Length(1)]).areas(rest);
    f.render_widget(Paragraph::new(format!("Average spectrum, {DB_TOP:.0} to {DB_BOTTOM:.0} dB")).style(Style::new().fg(muted).add_modifier(Modifier::BOLD)), head);
    if tr.spectrum.len() < 2 {
        let msg = d.error.clone().unwrap_or_else(|| "no spectrum stored for this file (analyse it again)".into());
        f.render_widget(Paragraph::new(msg).style(Style::new().fg(muted)), plot);
        return;
    }
    let max_hz = tr.step_hz.unwrap_or(500.0) as f32 * tr.spectrum.len() as f32;
    let cutoff = tr.cutoff_hz.map(|h| h as f32 / max_hz);
    let g = graph_lines(&tr.spectrum, cutoff, plot.width as usize, plot.height as usize, c(th.accent2), accent);
    f.render_widget(Paragraph::new(g), plot);
    f.render_widget(Paragraph::new(axis(ax.width as usize, max_hz)).style(Style::new().fg(muted)), ax);
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn track(spectrum: serde_json::Value) -> Track {
        serde_json::from_value(json!({"id": 1, "title": "T", "artist": "A", "album": "Alb", "codec": "flac", "open": true,
            "truth": {"analyzed": true, "dr": 9.0, "dr_class": "fair", "lufs": -10.0, "bits": 16.0, "sample_rate": 44100.0, "cutoff_hz": 16000.0, "step_hz": 500.0, "spectrum": spectrum, "flags": [{"level": "warn", "label": "Cut-off", "detail": "Nothing above 16 kHz"}]}})).unwrap()
    }

    #[test]
    fn a_flat_curve_is_one_row_of_dots_and_a_steep_one_has_no_gaps() {
        let flat = vec![-75.0f32; 40]; // the middle of the range
        let cells = dots(&flat, None, 20, 6);
        let rows_with_dots: Vec<usize> = cells.iter().enumerate().filter(|(_, r)| r.iter().any(|c| c.0 != 0)).map(|(i, _)| i).collect();
        assert_eq!(rows_with_dots.len(), 1, "a level line is on one cell row");
        assert!(cells[rows_with_dots[0]].iter().all(|c| c.0 != 0), "and spans the width");

        let cliff: Vec<f32> = (0..40).map(|i| if i < 20 { -30.0 } else { -125.0 }).collect();
        let cells = dots(&cliff, None, 20, 6);
        // every column has at least one dot (no gap where the curve drops)
        for x in 0..20 {
            assert!((0..6).any(|y| cells[y][x].0 != 0), "column {x} is empty");
        }
    }

    #[test]
    fn the_cutoff_is_a_dotted_vertical_line_where_it_says() {
        let cells = dots(&[-60.0; 30], Some(0.5), 20, 6);
        let col = (0..20).find(|x| cells.iter().any(|r| r[*x].1 != 0)).unwrap();
        assert!((9..=10).contains(&col), "{col}");
        assert!(cells.iter().filter(|r| r[col].1 != 0).count() == 6); // every cell row of that column
        assert!(dots(&[-60.0; 30], Some(2.0), 20, 6).iter().all(|r| r.iter().all(|c| c.1 == 0))); // outside: nothing
    }

    #[test]
    fn empty_and_degenerate_input_does_not_panic() {
        assert!(dots(&[], None, 0, 0).is_empty());
        assert_eq!(dots(&[], Some(0.5), 5, 2).len(), 2);
        assert_eq!(dots(&[-50.0], None, 5, 2).len(), 2);
        let _ = dots(&[f32::NAN, -50.0, f32::INFINITY], None, 7, 3);
    }

    #[test]
    fn wrapped_rows_count_words_that_do_not_fit_and_long_words() {
        let l = |t: &str| Line::from(t.to_string());
        assert_eq!(wrapped_rows(&[l("one two"), l("")], 20), 2);
        assert_eq!(wrapped_rows(&[l("aaaa bbbb cccc")], 9), 2); // "aaaa bbbb" then "cccc"
        assert_eq!(wrapped_rows(&[l("aaaa bbbb cccc")], 4), 3);
        assert_eq!(wrapped_rows(&[l("abcdefghij")], 4), 3); // split in 4 + 4 + 2
        assert_eq!(wrapped_rows(&[l("x")], 0), 1); // never divides by zero
    }

    #[test]
    fn long_finding_text_is_shown_to_the_end_and_does_not_run_into_the_graph() {
        use ratatui::{backend::TestBackend, Terminal};
        let th = crate::theme::builtin().remove(0);
        let long = "Between the samples the reconstructed wave goes over full scale; some players and lossy encoders will clip it.";
        let mut tr = track(json!((0..44).map(|i| -40.0 - i as f32).collect::<Vec<_>>()));
        tr.truth.as_mut().unwrap().flags = vec![json!({"level": "warn", "label": "Peaks", "detail": long})];
        let d = Details { track: tr, error: None };
        let mut t = Terminal::new(TestBackend::new(90, 40)).unwrap();
        t.draw(|f| draw(f, &d, &th, 2)).unwrap();
        let buf = t.backend().buffer().clone();
        let screen: Vec<String> = (0..40u16).map(|y| (0..90u16).map(|x| buf[(x, y)].symbol().to_string()).collect::<String>()).collect();
        let all = screen.join("\n");
        assert!(all.contains("will clip it."), "the end of the text is cut off:\n{all}");
        let heading = screen.iter().position(|r| r.contains("Average spectrum")).expect("the graph's title");
        let last_text = screen.iter().position(|r| r.contains("will clip it.")).unwrap();
        assert!(last_text < heading, "text and graph overlap");
    }

    #[test]
    fn the_axis_has_round_labels_that_do_not_touch() {
        let a = axis(60, 22_000.0);
        assert!(a.starts_with('0') && a.contains("10k") && a.len() == 60, "{a:?}");
        assert!(!a.contains("kk"));
        assert_eq!(axis(5, 22_000.0).trim(), ""); // too narrow to say anything
    }

    #[test]
    fn it_draws_at_awkward_sizes_and_without_a_spectrum() {
        use ratatui::{backend::TestBackend, Terminal};
        let th = crate::theme::builtin().remove(0);
        let curve: Vec<f32> = (0..44).map(|i| -40.0 - i as f32 * 2.0).collect();
        for (w, h) in [(120u16, 40u16), (60, 20), (30, 8), (10, 4)] {
            for spectrum in [json!(curve), json!([])] {
                let d = Details { track: track(spectrum), error: None };
                let mut t = Terminal::new(TestBackend::new(w, h)).unwrap();
                t.draw(|f| draw(f, &d, &th, 2)).unwrap();
            }
        }
    }

    #[test]
    fn the_lines_have_the_format_the_numbers_and_the_findings() {
        let th = crate::theme::builtin().remove(0);
        let text: String = truth_lines(&track(json!([])), &th, 2).iter().map(|l| l.spans.iter().map(|s| s.content.to_string()).collect::<String>()).collect::<Vec<_>>().join("\n");
        assert!(text.contains("FLAC") && text.contains("16-bit / 44.1 kHz") && text.contains("DR9 (fair)") && text.contains("-10.0 LUFS") && text.contains("Cut-off") && text.contains("Nothing above 16 kHz"), "{text}");
        let no_findings: String = truth_lines(&track(json!([])), &th, 1).iter().map(|l| l.spans.iter().map(|s| s.content.to_string()).collect::<String>()).collect::<Vec<_>>().join("\n");
        assert!(!no_findings.contains("Nothing above"));
    }

    /// `cargo test details_preview -- --ignored --nocapture`: the panel as text, to look at the layout.
    #[test]
    #[ignore]
    fn details_preview() {
        use ratatui::{backend::TestBackend, Terminal};
        let th = crate::theme::builtin().remove(0);
        let curve: Vec<f32> = (0..44).map(|i| if i < 32 { -45.0 - i as f32 * 1.2 + ((i * 7) % 5) as f32 } else { -120.0 }).collect();
        let d = Details { track: track(json!(curve)), error: None };
        let mut t = Terminal::new(TestBackend::new(100, 34)).unwrap();
        t.draw(|f| draw(f, &d, &th, 2)).unwrap();
        let buf = t.backend().buffer().clone();
        for y in 0..34u16 {
            println!("{}", (0..100u16).map(|x| buf[(x, y)].symbol().to_string()).collect::<String>());
        }
    }
}
