//! `e`: edit an album's details as a table, like the web's edit table: the album's title, artist, release date and record
//! label, then one row per track (number, title, artist). `ctrl-s` saves everything in one request
//! (`PATCH /albums/{id}/details`, all or nothing); the files and their tags are never touched. The state and the checks are
//! pure and tested here; the request and the keys are in `main.rs`.

use crate::api::Album;
use crate::theme::{c, Theme};
use ratatui::{
    layout::{Constraint, Flex, Layout, Rect},
    style::{Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, Clear, Paragraph},
    Frame,
};
use serde_json::{json, Value};

pub const HEADER: [&str; 4] = ["Title", "Artist", "Release date", "Label"];

pub struct Edit {
    pub album: u64,
    /// Title, artist, release date, label.
    pub head: [String; 4],
    pub head_was: [String; 4],
    /// Per track: id, number, title, artist (empty: the album's), and what they were.
    pub rows: Vec<Row>,
    /// The cell with the cursor: `row` is 0..4 for the album's fields, then 4.. for the tracks; `col` 0 number, 1 title, 2 artist (tracks only).
    pub row: usize,
    pub col: usize,
    pub error: String,
}

pub struct Row {
    pub id: u64,
    pub cells: [String; 3],
    pub was: [String; 3],
}

impl Edit {
    pub fn new(a: &Album) -> Edit {
        let head = [a.title.clone(), a.artist.clone().unwrap_or_default(), a.release_date.clone().or_else(|| a.year.map(|y| y.to_string())).unwrap_or_default(), a.label.clone().unwrap_or_default()];
        let rows = a
            .tracks
            .iter()
            .map(|t| {
                let e = t.edit.clone().unwrap_or_default();
                let cells = [e.track_number.or(t.number).map(|n| n.to_string()).unwrap_or_default(), e.title.unwrap_or_else(|| t.title.clone()), e.artist.unwrap_or_default()];
                Row { id: t.id, was: cells.clone(), cells }
            })
            .collect();
        Edit { album: a.id, head_was: head.clone(), head, rows, row: 0, col: 1, error: String::new() }
    }

    pub fn len(&self) -> usize {
        HEADER.len() + self.rows.len()
    }

    fn on_track(&self) -> bool {
        self.row >= HEADER.len()
    }

    pub fn cell(&mut self) -> &mut String {
        if self.on_track() {
            &mut self.rows[self.row - HEADER.len()].cells[self.col]
        } else {
            &mut self.head[self.row]
        }
    }

    pub fn up(&mut self) {
        self.row = self.row.saturating_sub(1);
        self.fit();
    }

    pub fn down(&mut self) {
        self.row = (self.row + 1).min(self.len() - 1);
        self.fit();
    }

    /// Left and right: the columns of a track row.
    pub fn side(&mut self, by: i32) {
        if self.on_track() {
            self.col = (self.col as i32 + by).clamp(0, 2) as usize;
        }
    }

    /// Tab: the next cell, row by row.
    pub fn next(&mut self) {
        if self.on_track() && self.col < 2 {
            self.col += 1;
        } else if self.row + 1 < self.len() {
            self.row += 1;
            self.col = 0;
        }
    }

    fn fit(&mut self) {
        if !self.on_track() {
            self.col = 0;
        } else if self.col == 0 && self.row == HEADER.len() {
            self.col = 1;
        }
    }

    pub fn changed(&self) -> bool {
        self.head != self.head_was || self.rows.iter().any(|r| r.cells != r.was)
    }

    /// Check the values the way the server will, so a typo is caught before the request.
    pub fn check(&self) -> Result<(), String> {
        if self.head[0].trim().is_empty() {
            return Err("the album needs a title".into());
        }
        if !self.head[2].trim().is_empty() && !valid_date(self.head[2].trim()) {
            return Err("the release date is a year, 2001-03 or 2001-03-12".into());
        }
        for (i, r) in self.rows.iter().enumerate() {
            if r.cells[1].trim().is_empty() {
                return Err(format!("track {} needs a title", i + 1));
            }
            let n = r.cells[0].trim();
            if !n.is_empty() && !n.parse::<u32>().is_ok_and(|v| (1..=999).contains(&v)) {
                return Err(format!("track {}: the number is 1 to 999 or empty", i + 1));
            }
        }
        Ok(())
    }

    /// The body of `PATCH /albums/{id}/details`: every field and every track (the server saves all or nothing).
    pub fn body(&self) -> Value {
        let s = |v: &str| if v.trim().is_empty() { Value::Null } else { json!(v.trim()) };
        json!({
            "title": self.head[0].trim(), "artist": s(&self.head[1]), "release_date": s(&self.head[2]), "label": s(&self.head[3]),
            "tracks": self.rows.iter().map(|r| json!({
                "id": r.id, "title": r.cells[1].trim(), "artist": s(&r.cells[2]),
                "track_number": r.cells[0].trim().parse::<u32>().ok(),
            })).collect::<Vec<_>>(),
        })
    }
}

/// `2001`, `2001-03` or `2001-03-12` (the server's `Album::DATE_PATTERN`).
pub fn valid_date(s: &str) -> bool {
    let p: Vec<&str> = s.split('-').collect();
    let num = |x: &str, n: usize| x.len() == n && x.bytes().all(|b| b.is_ascii_digit());
    match p.as_slice() {
        [y] => num(y, 4),
        [y, m] => num(y, 4) && num(m, 2) && (1..=12).contains(&m.parse::<u32>().unwrap_or(0)),
        [y, m, d] => num(y, 4) && num(m, 2) && num(d, 2) && (1..=12).contains(&m.parse::<u32>().unwrap_or(0)) && (1..=31).contains(&d.parse::<u32>().unwrap_or(0)),
        _ => false,
    }
}

pub fn draw(f: &mut Frame, e: &Edit, th: &Theme) {
    let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
    let width = 120.min(f.area().width);
    let height = (e.len() as u16 + 6).min(f.area().height).max(10.min(f.area().height));
    let [row] = Layout::vertical([Constraint::Length(height)]).flex(Flex::Center).areas(f.area());
    let [area]: [Rect; 1] = Layout::horizontal([Constraint::Length(width)]).flex(Flex::Center).areas(row);
    f.render_widget(Clear, area);
    let foot = if e.error.is_empty() { " type to edit · tab next cell · arrows move · ctrl-s save · esc cancel ".to_string() } else { format!(" {} ", e.error) };
    let block = Block::new()
        .borders(Borders::ALL)
        .title(format!(" Edit details{} ", if e.changed() { " *" } else { "" }))
        .title_bottom(Line::from(Span::styled(foot, Style::new().fg(if e.error.is_empty() { muted } else { accent }))))
        .border_style(Style::new().fg(accent))
        .style(Style::new().bg(c(th.bg)).fg(text));
    let inner = block.inner(area);
    f.render_widget(block, area);

    let cell = |v: &str, w: usize, on: bool, changed: bool| {
        let mut shown: String = v.chars().take(w.saturating_sub(1)).collect();
        if on {
            shown.push('▏');
        }
        let style = if on { Style::new().fg(c(th.bg)).bg(accent) } else if changed { Style::new().fg(accent) } else { Style::new().fg(text) };
        Span::styled(format!("{shown:<w$}"), style)
    };
    let mut lines: Vec<Line> = vec![];
    for (i, name) in HEADER.iter().enumerate() {
        let on = e.row == i;
        lines.push(Line::from(vec![Span::styled(format!("{name:<14}"), Style::new().fg(muted)), cell(&e.head[i], (inner.width as usize).saturating_sub(15), on, e.head[i] != e.head_was[i])]));
    }
    lines.push(Line::from(Span::styled(format!("{:<5}{:<50}{}", "No.", "Title", "Artist (empty: the album's)"), Style::new().fg(muted).add_modifier(Modifier::BOLD))));
    // scroll so the cursor row stays in view
    let room = (inner.height as usize).saturating_sub(lines.len());
    let cur = e.row.saturating_sub(HEADER.len());
    let first = if e.on_track() { cur.saturating_sub(room.saturating_sub(1)) } else { 0 };
    for (i, r) in e.rows.iter().enumerate().skip(first).take(room) {
        let on = |col| e.on_track() && cur == i && e.col == col;
        lines.push(Line::from(vec![
            cell(&r.cells[0], 5, on(0), r.cells[0] != r.was[0]),
            cell(&r.cells[1], 50, on(1), r.cells[1] != r.was[1]),
            cell(&r.cells[2], (inner.width as usize).saturating_sub(55), on(2), r.cells[2] != r.was[2]),
        ]));
    }
    f.render_widget(Paragraph::new(lines), inner);
}

#[cfg(test)]
mod tests {
    use super::*;

    fn album() -> Album {
        serde_json::from_value(json!({
            "id": 7, "title": "Just For Love", "artist": "QMS", "year": 1970, "release_date": "1970-08", "label": "Capitol",
            "tracks": [
                {"id": 1, "title": "Wolfgang's Vault", "open": true, "number": 1, "edit": {"title": "Wolfgang's Vault", "artist": null, "track_number": 1}},
                {"id": 2, "title": "Fresh Air", "open": true, "number": 2, "artist": "QMS", "edit": {"title": "Fresh Air", "artist": "QMS", "track_number": 2}},
            ]
        }))
        .unwrap()
    }

    #[test]
    fn starts_unchanged_with_the_stored_values_not_the_shown_artist() {
        let e = Edit::new(&album());
        assert!(!e.changed());
        assert_eq!((e.head[2].as_str(), e.rows[0].cells[2].as_str(), e.rows[1].cells[2].as_str()), ("1970-08", "", "QMS"));
        assert!(e.check().is_ok());
    }

    #[test]
    fn typing_marks_it_changed_and_the_body_carries_every_track() {
        let mut e = Edit::new(&album());
        e.cell().push('!');
        assert!(e.changed());
        e.down();
        e.cell().clear(); // artist emptied: null
        let b = e.body();
        assert_eq!(b["title"], "Just For Love!");
        assert_eq!(b["artist"], Value::Null);
        assert_eq!(b["tracks"].as_array().unwrap().len(), 2);
        assert_eq!(b["tracks"][1], json!({"id": 2, "title": "Fresh Air", "artist": "QMS", "track_number": 2}));
    }

    #[test]
    fn checks_match_the_servers_rules() {
        let mut e = Edit::new(&album());
        e.head[2] = "1970-13".into();
        assert!(e.check().unwrap_err().contains("release date"));
        e.head[2] = "".into();
        assert!(e.check().is_ok());
        e.rows[1].cells[0] = "0".into();
        assert!(e.check().unwrap_err().contains("track 2"));
        e.rows[1].cells[0] = "".into();
        e.rows[0].cells[1] = "  ".into();
        assert!(e.check().unwrap_err().contains("track 1 needs a title"));
        e.head[0].clear();
        assert!(e.check().unwrap_err().contains("album needs a title"));
        assert!(valid_date("2001") && valid_date("2001-03") && valid_date("2001-03-12") && !valid_date("01-03") && !valid_date("2001-3"));
    }

    #[test]
    fn the_cursor_moves_by_cell() {
        let mut e = Edit::new(&album());
        e.row = 3;
        e.next();
        assert_eq!((e.row, e.col), (4, 0));
        e.next();
        e.next();
        assert_eq!((e.row, e.col), (4, 2));
        e.next();
        assert_eq!((e.row, e.col), (5, 0));
        e.up();
        e.up();
        e.up();
        e.up();
        e.up();
        assert_eq!((e.row, e.col), (0, 0));
        e.side(1);
        assert_eq!(e.col, 0); // the album's fields have one column
    }
}
