//! The `?` overlay: the keys of the view you are in, from one table.

use crate::theme::{c, Theme};
use ratatui::{
    layout::{Constraint, Flex, Layout, Rect},
    style::{Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, Clear, Paragraph},
    Frame,
};

#[derive(Clone, Copy, PartialEq)]
pub enum Where {
    Library,
    Truth,
    Search,
    Full,
    Ab,
}

type Section = (&'static str, &'static [(&'static str, &'static str)]);

const PLAYBACK: Section = ("Playback", &[
    ("space", "pause / play"),
    ("n / p", "next / previous (p restarts after 3 s)"),
    (", / .", "seek -10 s / +10 s"),
    ("L", "loudness matching: off, album, track"),
]);

const METERS: Section = ("Meters", &[
    ("tab", "choose the panel m changes"),
    ("m / M", "next / previous meter"),
    ("w / W", "next / previous poster"),
    ("+ / -", "add / remove a panel (0 to 4)"),
    ("c", "meter colours from the cover"),
    ("t / T", "next / previous theme"),
]);

const LIBRARY: Section = ("Library", &[
    ("j / k", "move"),
    ("enter", "open an album / play from a track"),
    ("l / enter", "open or close a folder, open an album"),
    ("h", "close a folder or go to the one above; from the tracks, back to the albums"),
    ("x", "mark the album for a move (and go down)"),
    ("v", "move the marked albums, or the selected one, to a folder"),
    ("N", "new folder (inside the selected folder, beside the selected album)"),
    ("/", "search: albums, tracks and folders (year: dr: codec: artist: album: filters)"),
    ("A", "analyse the selected album / track (server side)"),
    ("i", "file-truth table"),
    ("f", "full screen"),
    ("q", "quit"),
]);

const TRUTH: Section = ("File-truth table", &[
    ("j / k  PgUp / PgDn  g / G", "move"),
    ("enter", "play from here (the list becomes the queue)"),
    ("s", "sort: findings, DR, loudness, peak, library, copies"),
    ("o", "only tracks with findings"),
    ("d", "only tracks with copies, copies side by side"),
    ("a", "A/B compare with another copy"),
    ("/", "search"),
    ("i / esc", "back"),
]);

const SEARCH: Section = ("Search", &[
    ("type", "two letters or more; every key is text; all words must match"),
    ("year: dr: codec:", "year:1983  year:1960..1969  dr:<8  codec:flac"),
    ("artist: album:", "artist:bowie  artist:\"david bowie\"  album:heroes"),
    ("enter / down", "move into the results"),
    ("delete", "clear the text"),
    ("j / k  PgUp / PgDn", "move in the results"),
    ("enter", "play a track (the hits become the queue) / open an album"),
    ("/ or esc", "back to typing; esc again closes"),
]);

const FULL: Section = ("Full screen", &[
    ("f / esc", "back"),
    ("", "playback and meter keys work, browsing keys are off"),
]);

const AB: Section = ("A/B compare", &[
    ("1 / 2 / x", "hear A / hear B / switch"),
    ("g", "same loudness on / off"),
    ("[ / ]", "another copy as B"),
    ("space", "pause both"),
    (", / .", "seek both"),
    ("esc / a", "leave; what you hear keeps playing"),
]);

fn sections(w: Where) -> Vec<Section> {
    match w {
        Where::Library => vec![LIBRARY, PLAYBACK, METERS],
        Where::Truth => vec![TRUTH, PLAYBACK, METERS],
        Where::Search => vec![SEARCH, PLAYBACK],
        Where::Full => vec![FULL, PLAYBACK, METERS],
        Where::Ab => vec![AB, METERS],
    }
}

pub fn draw(f: &mut Frame, w: Where, th: &Theme) {
    let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
    let mut lines: Vec<Line> = vec![];
    for (name, keys) in sections(w) {
        if !lines.is_empty() {
            lines.push(Line::raw(""));
        }
        lines.push(Line::from(Span::styled(name, Style::new().fg(accent).add_modifier(Modifier::BOLD))));
        for (k, d) in keys {
            lines.push(Line::from(vec![Span::styled(format!("  {k:<26}"), Style::new().fg(text)), Span::styled(*d, Style::new().fg(muted))]));
        }
    }
    lines.push(Line::raw(""));
    lines.push(Line::from(Span::styled("  ? or esc closes", Style::new().fg(muted))));

    let width = 86.min(f.area().width);
    let height = (lines.len() as u16 + 2).min(f.area().height);
    let [row] = Layout::vertical([Constraint::Length(height)]).flex(Flex::Center).areas(f.area());
    let [area]: [Rect; 1] = Layout::horizontal([Constraint::Length(width)]).flex(Flex::Center).areas(row);
    f.render_widget(Clear, area);
    let block = Block::new().borders(Borders::ALL).title(" Keys ").border_style(Style::new().fg(accent)).style(Style::new().bg(c(th.bg)).fg(text));
    f.render_widget(Paragraph::new(lines).block(block), area);
}

#[cfg(test)]
mod tests {
    use super::*;
    use ratatui::{backend::TestBackend, Terminal};

    #[test]
    fn every_view_draws_in_a_small_and_a_big_terminal() {
        let th = crate::theme::all().remove(0);
        for (w, h) in [(60, 12), (120, 50)] {
            for view in [Where::Library, Where::Truth, Where::Search, Where::Full, Where::Ab] {
                let mut t = Terminal::new(TestBackend::new(w, h)).unwrap();
                t.draw(|f| draw(f, view, &th)).unwrap();
            }
        }
    }
}
