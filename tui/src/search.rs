//! Search: type a few letters, the server's `/search` answers with the albums and tracks whose title or artist contains them
//! (your own and what is shared with you). Requests wait for a short pause in typing and drop answers to an older text.
use crate::{
    api::{Album, Api, SearchResult, Track},
    mmss,
    theme::{c, Theme},
};
use ratatui::{
    layout::{Constraint, Layout, Rect},
    style::Style,
    text::{Line, Span},
    widgets::{Block, Borders, List, ListItem, ListState, Paragraph},
    Frame,
};
use std::{
    sync::mpsc,
    thread,
    time::{Duration, Instant},
};

const PAUSE: Duration = Duration::from_millis(250);
const MIN_CHARS: usize = 2; // the server refuses fewer

/// One line of the result list.
pub enum Hit {
    Album(Album),
    Track(Track),
}

pub struct Search {
    pub query: String,
    /// Typing (true) or moving in the results (false).
    pub typing: bool,
    pub hits: Vec<Hit>,
    pub sel: ListState,
    pub error: Option<String>,
    changed_at: Instant,
    sent: String, // the text of the last request
    waiting: bool,
    rx: Option<mpsc::Receiver<(String, Result<SearchResult, String>)>>,
}

impl Search {
    pub fn new() -> Self {
        Search { query: String::new(), typing: true, hits: vec![], sel: ListState::default(), error: None, changed_at: Instant::now(), sent: String::new(), waiting: false, rx: None }
    }

    pub fn push(&mut self, ch: char) {
        self.query.push(ch);
        self.changed_at = Instant::now();
    }

    pub fn pop(&mut self) {
        self.query.pop();
        self.changed_at = Instant::now();
    }

    pub fn clear(&mut self) {
        self.query.clear();
        self.hits.clear();
        self.sel.select(None);
        self.sent.clear();
        self.changed_at = Instant::now();
    }

    /// The text to ask for now: changed since the last request, long enough, and typing has paused (or `force`: Enter).
    fn due(&self, force: bool) -> Option<String> {
        let q = self.query.trim();
        (q.chars().count() >= MIN_CHARS && q != self.sent && (force || self.changed_at.elapsed() >= PAUSE)).then(|| q.to_string())
    }

    pub fn tick(&mut self, api: &Api, force: bool) {
        if let Some(q) = self.due(force) {
            let (tx, rx) = mpsc::channel();
            let api = api.clone();
            self.sent = q.clone();
            self.waiting = true;
            thread::spawn(move || {
                let r = api.search(&q);
                let _ = tx.send((q, r));
            });
            self.rx = Some(rx);
        }
        if let Some(Ok((q, r))) = self.rx.as_ref().map(|rx| rx.try_recv()) {
            self.rx = None;
            self.waiting = false;
            if q == self.sent {
                match r {
                    Ok(r) => {
                        self.error = None;
                        self.hits = r.albums.into_iter().map(Hit::Album).chain(r.tracks.into_iter().map(Hit::Track)).collect();
                        self.sel.select(if self.hits.is_empty() { None } else { Some(0) });
                    }
                    Err(e) => self.error = Some(e),
                }
            }
        }
    }

    pub fn mv(&mut self, d: i32) {
        let len = self.hits.len() as i32;
        if len > 0 {
            let i = self.sel.selected().unwrap_or(0) as i32 + d;
            self.sel.select(Some(i.clamp(0, len - 1) as usize));
        }
    }

    pub fn selected(&self) -> Option<&Hit> {
        self.sel.selected().and_then(|i| self.hits.get(i))
    }

    /// The tracks among the hits, in order, and the position of the selected hit in them (for playing from there).
    pub fn tracks_from_selected(&self) -> Option<(Vec<Track>, usize)> {
        let i = self.sel.selected()?;
        let Hit::Track(_) = self.hits.get(i)? else { return None };
        let tracks: Vec<Track> = self.hits.iter().filter_map(|h| if let Hit::Track(t) = h { Some(t.clone()) } else { None }).collect();
        let at = self.hits[..i].iter().filter(|h| matches!(h, Hit::Track(_))).count();
        Some((tracks, at))
    }
}

pub fn draw(f: &mut Frame, area: Rect, s: &mut Search, playing: Option<u64>, icon: &str, th: &Theme) {
    let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
    let [input, list] = Layout::vertical([Constraint::Length(3), Constraint::Min(3)]).areas(area);
    let status = if s.waiting { "  searching…".to_string() } else if let Some(e) = &s.error { format!("  {e}") } else if s.query.trim().chars().count() < MIN_CHARS { format!("  type at least {MIN_CHARS} letters") } else { format!("  {} results", s.hits.len()) };
    f.render_widget(
        Paragraph::new(Line::from(vec![Span::styled("/ ", Style::new().fg(accent)), Span::raw(s.query.clone()), Span::styled(if s.typing { "▏" } else { "" }, Style::new().fg(accent)), Span::styled(status, Style::new().fg(muted))])).block(
            Block::new().borders(Borders::ALL).border_style(Style::new().fg(if s.typing { accent } else { muted })).title(" Search ").style(Style::new().bg(c(th.surface)).fg(text)),
        ),
        input,
    );
    let items: Vec<ListItem> = s
        .hits
        .iter()
        .map(|h| match h {
            Hit::Album(a) => ListItem::new(Line::from(vec![
                Span::styled("album  ", Style::new().fg(c(th.accent2))),
                Span::raw(a.title.clone()),
                Span::styled(format!("  {}{}", a.shown_artist(), a.track_count.map_or(String::new(), |n| format!(" · {n} tracks"))), Style::new().fg(muted)),
            ])),
            Hit::Track(t) => {
                let on = Some(t.id) == playing;
                let mark = if on { format!("{icon}      ") } else { "track  ".to_string() };
                let st = if on { Style::new().fg(c(th.accent2)) } else if t.open { Style::new() } else { Style::new().fg(muted) };
                ListItem::new(Line::from(vec![
                    Span::styled(mark, if on { st } else { Style::new().fg(muted) }),
                    Span::styled(t.title.clone(), st),
                    Span::styled(format!("  {} · {}  {}", t.artist.clone().unwrap_or_default(), t.album.clone().unwrap_or_default(), mmss(t.duration.unwrap_or(0.0))), Style::new().fg(muted)),
                ]))
            }
        })
        .collect();
    f.render_stateful_widget(
        List::new(items).block(Block::new().borders(Borders::ALL).border_style(Style::new().fg(if s.typing { muted } else { accent })).style(Style::new().bg(c(th.surface)).fg(text))).highlight_style(Style::new().fg(c(th.bg)).bg(accent)),
        list,
        &mut s.sel,
    );
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn track(id: u64, title: &str) -> Track {
        serde_json::from_value(json!({"id": id, "title": title, "artist": "A", "open": true})).unwrap()
    }

    fn album(id: u64) -> Album {
        serde_json::from_value(json!({"id": id, "title": "Alb", "artist": "A"})).unwrap()
    }

    #[test]
    fn a_request_waits_for_a_pause_and_for_two_letters_and_is_not_repeated() {
        let mut s = Search::new();
        s.push('a');
        assert_eq!(s.due(true), None); // one letter: the server would refuse
        s.push('b');
        assert_eq!(s.due(false), None); // still typing
        assert_eq!(s.due(true), Some("ab".to_string())); // Enter does not wait
        s.changed_at = Instant::now() - PAUSE;
        assert_eq!(s.due(false), Some("ab".to_string())); // the pause is over
        s.sent = "ab".into();
        assert_eq!(s.due(true), None); // already asked
        s.query = " ab ".into();
        assert_eq!(s.due(true), None); // spaces around do not count as a change
    }

    #[test]
    fn playing_a_hit_queues_the_tracks_among_the_results_from_that_one() {
        let mut s = Search::new();
        s.hits = vec![Hit::Album(album(1)), Hit::Track(track(10, "a")), Hit::Track(track(11, "b")), Hit::Track(track(12, "c"))];
        s.sel.select(Some(2));
        let (tracks, at) = s.tracks_from_selected().unwrap();
        assert_eq!((tracks.iter().map(|t| t.id).collect::<Vec<_>>(), at), (vec![10, 11, 12], 1));
        s.sel.select(Some(0)); // an album is opened, not played
        assert!(s.tracks_from_selected().is_none());
    }

    #[test]
    fn it_draws_at_awkward_sizes() {
        use ratatui::{backend::TestBackend, Terminal};
        let th = crate::theme::builtin().remove(0);
        for (w, h) in [(100u16, 20u16), (30, 6), (10, 4)] {
            let mut s = Search::new();
            s.query = "he".into();
            s.hits = vec![Hit::Album(album(1)), Hit::Track(track(10, "Heroes"))];
            s.sel.select(Some(1));
            let mut t = Terminal::new(TestBackend::new(w, h)).unwrap();
            t.draw(|f| draw(f, Rect::new(0, 0, w, h), &mut s, Some(10), "▶", &th)).unwrap();
        }
    }
}
