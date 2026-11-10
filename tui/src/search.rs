//! Search: type a few letters, the server's `/search` answers with the albums and tracks whose title or artist contains them
//! (your own and what is shared with you). Requests wait for a short pause in typing and drop answers to an older text.
use crate::{
    api::{Album, Api, SearchResult, Track},
    mmss,
    theme::{c, Theme},
};
use ratatui::{
    layout::{Constraint, Layout, Rect},
    style::{Modifier, Style},
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

/// A letter without its accent or case, one character for one character, so positions in the folded text are positions in
/// the shown one (the server folds the same way: `jafumega` finds Jáfumega).
fn fold_char(c: char) -> char {
    let lower = c.to_lowercase().next().unwrap_or(c);
    match lower {
        'á' | 'à' | 'â' | 'ã' | 'ä' | 'å' | 'ā' => 'a',
        'é' | 'è' | 'ê' | 'ë' | 'ē' => 'e',
        'í' | 'ì' | 'î' | 'ï' | 'ī' => 'i',
        'ó' | 'ò' | 'ô' | 'õ' | 'ö' | 'ø' | 'ō' => 'o',
        'ú' | 'ù' | 'û' | 'ü' | 'ū' => 'u',
        'ç' | 'ć' | 'č' => 'c',
        'ñ' | 'ń' => 'n',
        'ý' | 'ÿ' => 'y',
        'š' | 'ś' => 's',
        'ž' | 'ź' | 'ż' => 'z',
        other => other,
    }
}

fn fold(text: &str) -> Vec<char> {
    text.chars().map(fold_char).collect()
}

/// The words of a search text, folded.
fn terms(query: &str) -> Vec<Vec<char>> {
    query.split_whitespace().map(fold).filter(|t| !t.is_empty()).collect()
}

/// Which characters of `text` are part of a match of one of the words.
fn marks(text: &str, words: &[Vec<char>]) -> Vec<bool> {
    let folded = fold(text);
    let mut on = vec![false; folded.len()];
    for w in words {
        if w.len() > folded.len() {
            continue;
        }
        for start in 0..=folded.len() - w.len() {
            if folded[start..start + w.len()] == w[..] {
                on[start..start + w.len()].iter_mut().for_each(|m| *m = true);
            }
        }
    }
    on
}

/// `text` as spans, the parts that match the search words bold and underlined (a style that shows on any background, selected or not).
fn highlighted(text: &str, words: &[Vec<char>], base: Style) -> Vec<Span<'static>> {
    let on = marks(text, words);
    let hit = base.add_modifier(Modifier::BOLD | Modifier::UNDERLINED);
    let chars: Vec<char> = text.chars().collect();
    let mut spans = vec![];
    let mut i = 0;
    while i < chars.len() {
        let j = (i..chars.len()).find(|&k| on[k] != on[i]).unwrap_or(chars.len());
        spans.push(Span::styled(chars[i..j].iter().collect::<String>(), if on[i] { hit } else { base }));
        i = j;
    }
    spans
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
    let words = terms(&s.query);
    let items: Vec<ListItem> = s
        .hits
        .iter()
        .map(|h| match h {
            Hit::Album(a) => {
                let mut spans = vec![Span::styled("album  ", Style::new().fg(c(th.accent2)))];
                spans.extend(highlighted(&a.title, &words, Style::new()));
                spans.push(Span::styled("  ", Style::new().fg(muted)));
                spans.extend(highlighted(&a.shown_artist(), &words, Style::new().fg(muted)));
                spans.push(Span::styled(format!("{}{}", a.year.map_or(String::new(), |y| format!(" · {y}")), a.track_count.map_or(String::new(), |n| format!(" · {n} tracks"))), Style::new().fg(muted)));
                ListItem::new(Line::from(spans))
            }
            Hit::Track(t) => {
                let on = Some(t.id) == playing;
                let mark = if on { format!("{icon}      ") } else { "track  ".to_string() };
                let st = if on { Style::new().fg(c(th.accent2)) } else if t.open { Style::new() } else { Style::new().fg(muted) };
                let mut spans = vec![Span::styled(mark, if on { st } else { Style::new().fg(muted) })];
                spans.extend(highlighted(&t.title, &words, st));
                spans.push(Span::styled("  ", Style::new().fg(muted)));
                spans.extend(highlighted(&t.artist.clone().unwrap_or_default(), &words, Style::new().fg(muted)));
                spans.push(Span::styled(" · ", Style::new().fg(muted)));
                spans.extend(highlighted(&t.album.clone().unwrap_or_default(), &words, Style::new().fg(muted)));
                spans.push(Span::styled(format!("  {}", mmss(t.duration.unwrap_or(0.0))), Style::new().fg(muted)));
                ListItem::new(Line::from(spans))
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
    fn the_matching_parts_are_found_without_accents_or_case() {
        let on = |text: &str, q: &str| marks(text, &terms(q)).iter().map(|&m| if m { '#' } else { '.' }).collect::<String>();
        assert_eq!(on("Jáfumega - Recados", "jafumega"), "########..........");
        assert_eq!(on("Heroes", "he ROE"), "#####."); // two words, both found, the final s is not part of either
        assert_eq!(on("David Bowie", "bowie heroes"), "......#####");
        assert_eq!(on("Coração", "CORACAO"), "#######");
        assert_eq!(on("abc", ""), "...");
        assert_eq!(on("ab", "abcdef"), "..");
    }

    #[test]
    fn highlighted_text_keeps_every_character_and_marks_only_the_matches() {
        let spans = highlighted("Bowie - Heroes", &terms("heroes"), Style::new());
        assert_eq!(spans.iter().map(|s| s.content.to_string()).collect::<String>(), "Bowie - Heroes");
        assert_eq!(spans.len(), 2);
        assert!(spans[1].style.add_modifier.contains(Modifier::BOLD) && !spans[0].style.add_modifier.contains(Modifier::BOLD));
        assert!(highlighted("", &terms("x"), Style::new()).is_empty());
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
