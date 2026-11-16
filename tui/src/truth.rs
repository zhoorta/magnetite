//! The file-truth browser: every analysed track of the library in one table, worst first, so problems (clipped, likely lossy,
//! padded bits, duplicates, very low DR) can be found and played without opening albums one by one. Data comes from the same
//! `/albums/{id}` the album view uses (the API has no list of tracks with their truth), fetched by four background threads.
use crate::{
    api::{Album, Api, Track},
    theme::{c, Theme},
};
use ratatui::{
    layout::{Constraint, Layout, Rect},
    style::{Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, Cell, Paragraph, Row as TRow, Table, TableState, Wrap},
    Frame,
};
use std::{cmp::Ordering, sync::mpsc, thread};

#[derive(Clone)]
pub struct Row {
    pub album_id: u64,
    pub album_order: usize,
    pub track: Track,
}

#[derive(Clone, Copy, PartialEq, Debug)]
pub enum Sort {
    Severity,
    Dr,
    Loudness,
    TruePeak,
    Album,
    /// Copies of one recording next to each other, tracks with copies first.
    Copies,
}

#[derive(Clone, Copy, PartialEq, Debug)]
pub enum Filter {
    All,
    Findings,
    Copies,
}

impl Sort {
    const ALL: [Sort; 6] = [Sort::Severity, Sort::Dr, Sort::Loudness, Sort::TruePeak, Sort::Album, Sort::Copies];
    pub fn label(self) -> &'static str {
        match self {
            Sort::Severity => "worst findings first",
            Sort::Dr => "lowest DR first",
            Sort::Loudness => "loudest first",
            Sort::TruePeak => "highest true peak first",
            Sort::Album => "library order",
            Sort::Copies => "copies together",
        }
    }
}

/// 3 problem, 2 warn, 1 note, 0 nothing.
pub fn severity(t: &Track) -> u8 {
    t.truth.as_ref().map_or(0, |tr| {
        tr.flags
            .iter()
            .map(|f| match f["level"].as_str() {
                Some("problem") => 3,
                Some("warn") => 2,
                _ => 1,
            })
            .max()
            .unwrap_or(0)
    })
}

fn num(t: &Track, f: impl Fn(&crate::api::Truth) -> Option<f64>) -> Option<f64> {
    t.truth.as_ref().and_then(f)
}

/// `None` always last, whatever the direction.
fn cmp_opt(a: Option<f64>, b: Option<f64>, desc: bool) -> Ordering {
    match (a, b) {
        (Some(a), Some(b)) => {
            let o = a.partial_cmp(&b).unwrap_or(Ordering::Equal);
            if desc { o.reverse() } else { o }
        }
        (Some(_), None) => Ordering::Less,
        (None, Some(_)) => Ordering::Greater,
        (None, None) => Ordering::Equal,
    }
}

/// What the loading thread reports: how many steps there will be (pages or albums), then the rows of each step.
enum Msg {
    Total(usize),
    Rows(Vec<Row>),
}

fn analysed(t: &Track) -> bool {
    t.truth.as_ref().is_some_and(|x| x.analyzed)
}

/// The library's analysed tracks: a few paged requests when the server has `/tracks`, else every album (four threads).
fn load(api: Api, albums: Vec<Album>, tx: mpsc::Sender<Msg>) {
    let order: std::collections::HashMap<u64, usize> = albums.iter().enumerate().map(|(i, a)| (a.id, i)).collect();
    if let Ok((first, last)) = api.analysed_tracks(1) {
        let _ = tx.send(Msg::Total(last as usize));
        let rows = |tracks: Vec<Track>| -> Vec<Row> {
            tracks.into_iter().filter(analysed).map(|track| Row { album_id: track.album_id.unwrap_or(0), album_order: track.album_id.and_then(|id| order.get(&id).copied()).unwrap_or(usize::MAX), track }).collect()
        };
        let _ = tx.send(Msg::Rows(rows(first)));
        for page in 2..=last {
            match api.analysed_tracks(page) {
                Ok((t, _)) => {
                    let _ = tx.send(Msg::Rows(rows(t)));
                }
                Err(_) => {
                    let _ = tx.send(Msg::Rows(vec![]));
                }
            }
        }
        return;
    }
    let _ = tx.send(Msg::Total(albums.len()));
    let ids: Vec<u64> = albums.iter().map(|a| a.id).collect();
    let workers: Vec<_> = (0..4usize)
        .map(|k| {
            let (api, tx, ids) = (api.clone(), tx.clone(), ids.clone());
            thread::spawn(move || {
                for (i, id) in ids.iter().enumerate().skip(k).step_by(4) {
                    let rows = api.album(*id).map(|a| a.tracks.into_iter().filter(analysed).map(|track| Row { album_id: *id, album_order: i, track }).collect()).unwrap_or_default();
                    if tx.send(Msg::Rows(rows)).is_err() {
                        return;
                    }
                }
            })
        })
        .collect();
    for w in workers {
        let _ = w.join();
    }
}

pub struct Browser {
    pub rows: Vec<Row>,
    pub total: usize,
    pub loaded: usize,
    pub sort: Sort,
    pub filter: Filter,
    /// The other tracks that are the same recording (same artist and title, length within 2 s), by track id.
    peers: std::collections::HashMap<u64, Vec<u64>>,
    keys: std::collections::HashMap<u64, String>,
    pub table: TableState,
    rx: Option<mpsc::Receiver<Msg>>,
}

impl Browser {
    pub fn new() -> Self {
        Browser { rows: vec![], total: 0, loaded: 0, sort: Sort::Severity, filter: Filter::All, peers: Default::default(), keys: Default::default(), table: TableState::default(), rx: None }
    }

    /// Forget what was loaded, so the next `start` reads the library again (after new analyses).
    pub fn reset(&mut self) {
        *self = Browser::new();
    }

    /// Load the library's analysed tracks, once, in the background.
    pub fn start(&mut self, api: &Api, albums: &[Album]) {
        if self.rx.is_some() {
            return;
        }
        let (tx, rx) = mpsc::channel();
        self.total = albums.len().max(1);
        let (api, albums) = (api.clone(), albums.to_vec());
        thread::spawn(move || load(api, albums, tx));
        self.rx = Some(rx);
    }

    pub fn poll(&mut self) {
        let Some(rx) = &self.rx else { return };
        let keep = self.selected_id();
        let mut changed = false;
        while let Ok(msg) = rx.try_recv() {
            match msg {
                Msg::Total(n) => self.total = n.max(1),
                Msg::Rows(rows) => {
                    self.loaded += 1;
                    changed |= !rows.is_empty();
                    self.rows.extend(rows);
                }
            }
        }
        if changed {
            self.regroup();
            self.reselect(keep);
        }
    }

    /// Find the copies: group by normalised artist and title, then link the tracks of a group whose lengths are within 2 s.
    fn regroup(&mut self) {
        let norm = |s: &Option<String>| s.as_deref().unwrap_or("").trim().to_lowercase();
        self.keys.clear();
        self.peers.clear();
        let mut groups: std::collections::HashMap<String, Vec<(u64, f64)>> = Default::default();
        for r in &self.rows {
            let key = format!("{}|{}", norm(&r.track.artist), r.track.title.trim().to_lowercase());
            if !norm(&r.track.artist).is_empty() {
                groups.entry(key.clone()).or_default().push((r.track.id, r.track.duration.unwrap_or(0.0)));
            }
            self.keys.insert(r.track.id, key);
        }
        for g in groups.values().filter(|g| g.len() > 1) {
            for (id, d) in g {
                let others: Vec<u64> = g.iter().filter(|(o, od)| o != id && (od - d).abs() <= 2.0).map(|(o, _)| *o).collect();
                if !others.is_empty() {
                    self.peers.insert(*id, others);
                }
            }
        }
    }

    /// How many copies of this recording there are (itself included) and whether one is bit-identical; 1 when it is alone.
    pub fn copies_of(&self, t: &Track) -> (usize, bool) {
        let flagged = |code: &str| t.truth.as_ref().map_or(0, |x| x.flags.iter().filter(|f| f["code"].as_str() == Some(code)).count());
        let exact = flagged("duplicate") > 0;
        let n = self.peers.get(&t.id).map_or(0, Vec::len).max(flagged("duplicate") + flagged("same_recording"));
        (n + 1, exact)
    }

    pub fn done(&self) -> bool {
        self.rx.is_some() && self.loaded >= self.total
    }

    /// The rows shown: filtered, then sorted.
    pub fn view(&self) -> Vec<&Row> {
        let mut v: Vec<&Row> = self.rows.iter().filter(|r| match self.filter {
            Filter::All => true,
            Filter::Findings => severity(&r.track) > 0,
            Filter::Copies => self.copies_of(&r.track).0 > 1,
        }).collect();
        let key = |r: &&Row| self.keys.get(&r.track.id).cloned().unwrap_or_default();
        let by_album = |a: &&Row, b: &&Row| (a.album_order, a.track.number).cmp(&(b.album_order, b.track.number));
        match self.sort {
            Sort::Severity => v.sort_by(|a, b| severity(&b.track).cmp(&severity(&a.track)).then(cmp_opt(num(&a.track, |t| t.dr), num(&b.track, |t| t.dr), false)).then_with(|| by_album(a, b))),
            Sort::Dr => v.sort_by(|a, b| cmp_opt(num(&a.track, |t| t.dr), num(&b.track, |t| t.dr), false).then_with(|| by_album(a, b))),
            Sort::Loudness => v.sort_by(|a, b| cmp_opt(num(&a.track, |t| t.lufs), num(&b.track, |t| t.lufs), true).then_with(|| by_album(a, b))),
            Sort::TruePeak => v.sort_by(|a, b| cmp_opt(num(&a.track, |t| t.true_peak), num(&b.track, |t| t.true_peak), true).then_with(|| by_album(a, b))),
            Sort::Album => v.sort_by(by_album),
            Sort::Copies => v.sort_by(|a, b| (self.copies_of(&a.track).0 > 1).cmp(&(self.copies_of(&b.track).0 > 1)).reverse().then_with(|| key(a).cmp(&key(b))).then_with(|| by_album(a, b))),
        }
        v
    }

    pub fn selected_id(&self) -> Option<u64> {
        self.table.selected().and_then(|i| self.view().get(i).map(|r| r.track.id))
    }

    /// Keep the same track selected after the order or the filter changed (else the top).
    pub fn reselect(&mut self, id: Option<u64>) {
        let at = id.and_then(|id| self.view().iter().position(|r| r.track.id == id));
        let len = self.view().len();
        self.table.select(if len == 0 { None } else { Some(at.unwrap_or(0)) });
    }

    pub fn cycle_sort(&mut self) {
        let keep = self.selected_id();
        let i = Sort::ALL.iter().position(|s| *s == self.sort).unwrap_or(0);
        self.sort = Sort::ALL[(i + 1) % Sort::ALL.len()];
        self.reselect(keep);
    }

    /// `o`: only tracks with findings, or all again.
    pub fn toggle_filter(&mut self) {
        self.set_filter(if self.filter == Filter::Findings { Filter::All } else { Filter::Findings });
    }

    /// `d`: only tracks that have copies, copies side by side (and back to the worst-first order when it is left).
    pub fn toggle_copies(&mut self) {
        if self.filter == Filter::Copies {
            self.sort = Sort::Severity;
            self.set_filter(Filter::All);
        } else {
            self.sort = Sort::Copies;
            self.set_filter(Filter::Copies);
        }
    }

    fn set_filter(&mut self, f: Filter) {
        let keep = self.selected_id();
        self.filter = f;
        self.reselect(keep);
    }

    pub fn mv(&mut self, d: i32) {
        let len = self.view().len() as i32;
        if len > 0 {
            let i = self.table.selected().unwrap_or(0) as i32 + d;
            self.table.select(Some(i.clamp(0, len - 1) as usize));
        }
    }
}

fn level_style(level: &str, th: &Theme) -> Style {
    Style::new().fg(match level {
        "problem" => c((0xff, 0x4d, 0x4d)),
        "warn" => c(th.accent2),
        _ => c(th.muted),
    })
}

fn num_s(v: Option<f64>, d: usize) -> String {
    v.map_or("—".into(), |v| format!("{v:.d$}"))
}

pub fn draw(f: &mut Frame, area: Rect, b: &mut Browser, playing: Option<u64>, icon: &str, th: &Theme) {
    let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
    let detail_h = if area.height >= 16 { 8 } else { 0 };
    let [table_area, detail_area] = Layout::vertical([Constraint::Min(4), Constraint::Length(detail_h)]).areas(area);

    let view = b.view();
    let mut state = b.table.clone();
    let with = b.rows.iter().filter(|r| severity(&r.track) > 0).count();
    let loading = if b.done() { String::new() } else { format!(" · loading {}/{}", b.loaded, b.total) };
    let groups = b.rows.iter().filter(|r| b.copies_of(&r.track).0 > 1).count();
    let filter = match b.filter {
        Filter::All => "",
        Filter::Findings => " · only findings",
        Filter::Copies => " · only copies",
    };
    let title = format!(" File truth · {} tracks · {} with findings · {} with copies · {}{}{} ", b.rows.len(), with, groups, b.sort.label(), filter, loading);

    let rows: Vec<TRow> = view
        .iter()
        .map(|r| {
            let t = &r.track;
            let tr = t.truth.clone().unwrap_or_default();
            let sev = severity(t);
            let (mark, st) = match sev {
                3 => ("✗", level_style("problem", th)),
                2 => ("!", level_style("warn", th)),
                1 => ("·", level_style("note", th)),
                _ => (" ", Style::new()),
            };
            let play = if Some(t.id) == playing { icon } else { " " };
            let fmt = [t.codec.clone().unwrap_or_default().to_uppercase(), match (tr.bits, tr.sample_rate) {
                (Some(bits), Some(sr)) => format!("{}/{}", bits as u32, (sr / 1000.0 * 10.0).round() / 10.0),
                _ => String::new(),
            }]
            .join(" ");
            let (n, exact) = b.copies_of(t);
            let copies = if n > 1 { format!("×{n}{}", if exact { "=" } else { "" }) } else { String::new() };
            let labels = tr.flags.iter().filter_map(|x| x["label"].as_str()).collect::<Vec<_>>().join(", ");
            let name = if Some(t.id) == playing { Style::new().fg(c(th.accent2)) } else { Style::new() };
            TRow::new(vec![
                Cell::from(format!("{play}{mark}")).style(st),
                Cell::from(format!("{} — {}", t.artist.clone().unwrap_or_default(), t.title)).style(name),
                Cell::from(t.album.clone().unwrap_or_default()).style(Style::new().fg(muted)),
                Cell::from(fmt),
                Cell::from(num_s(tr.dr, 0)),
                Cell::from(num_s(tr.lufs, 1)),
                Cell::from(num_s(tr.true_peak, 1)),
                Cell::from(copies).style(Style::new().fg(c(th.accent2))),
                Cell::from(labels).style(st),
            ])
        })
        .collect();
    let header = TRow::new(["", "Track", "Album", "Format", "DR", "LUFS", "TP", "Copies", "Findings"]).style(Style::new().fg(muted).add_modifier(Modifier::BOLD));
    let widths = [Constraint::Length(3), Constraint::Fill(3), Constraint::Fill(2), Constraint::Length(13), Constraint::Length(3), Constraint::Length(6), Constraint::Length(6), Constraint::Length(6), Constraint::Fill(3)];
    let table = Table::new(rows, widths)
        .header(header)
        .column_spacing(1)
        .row_highlight_style(Style::new().fg(c(th.bg)).bg(accent))
        .block(Block::new().borders(Borders::ALL).border_style(Style::new().fg(accent)).title(title).style(Style::new().bg(c(th.surface)).fg(text)));
    f.render_stateful_widget(table, table_area, &mut state);

    if detail_h > 0 {
        let sel = state.selected().and_then(|i| view.get(i));
        let lines = sel.map_or(vec![Line::from("Nothing to show (yet).").style(Style::new().fg(muted))], |r| {
            let mut l = detail(&r.track, th);
            for id in b.peers.get(&r.track.id).into_iter().flatten() {
                if let Some(o) = b.rows.iter().find(|x| x.track.id == *id) {
                    let tr = o.track.truth.clone().unwrap_or_default();
                    let fmt = match (tr.bits, tr.sample_rate) {
                        (Some(bits), Some(sr)) => format!(" {}/{}", bits as u32, (sr / 1000.0 * 10.0).round() / 10.0),
                        _ => String::new(),
                    };
                    l.push(Line::from(vec![
                        Span::styled("copy  ", Style::new().fg(c(th.accent2)).add_modifier(Modifier::BOLD)),
                        Span::styled(format!("{} · {}{} · {} LUFS · DR{}", o.track.album.clone().unwrap_or_default(), o.track.codec.clone().unwrap_or_default().to_uppercase(), fmt, num_s(tr.lufs, 1), num_s(tr.dr, 0)), Style::new().fg(muted)),
                    ]));
                }
            }
            l
        });
        f.render_widget(
            Paragraph::new(lines).wrap(Wrap { trim: false }).block(Block::new().borders(Borders::ALL).border_style(Style::new().fg(muted)).style(Style::new().bg(c(th.surface)).fg(text))),
            detail_area,
        );
    }
    b.table = state; // keeps the scroll offset
}

fn detail(t: &Track, th: &Theme) -> Vec<Line<'static>> {
    let (muted, accent) = (c(th.muted), c(th.accent));
    let tr = t.truth.clone().unwrap_or_default();
    let mut nums = vec![];
    if let Some(v) = tr.dr {
        nums.push(format!("DR{}{}", v as i32, tr.dr_class.as_deref().map_or(String::new(), |c| format!(" ({c})"))));
    }
    if let Some(v) = tr.lufs {
        nums.push(format!("{v:.1} LUFS"));
    }
    if let Some(v) = tr.lra {
        nums.push(format!("LRA {v:.1}"));
    }
    if let Some(v) = tr.true_peak {
        nums.push(format!("true peak {v:.1} dBTP"));
    }
    if tr.clipped_runs.unwrap_or(0) > 0 {
        nums.push(format!("{} clipped runs", tr.clipped_runs.unwrap_or(0)));
    }
    if let Some(v) = tr.content_hz {
        nums.push(format!("content to {:.1} kHz", v / 1000.0));
    }
    if let Some(v) = tr.cutoff_hz {
        nums.push(format!("cut-off {:.1} kHz", v / 1000.0));
    }
    let mut lines = vec![
        Line::from(vec![Span::styled(t.title.clone(), Style::new().add_modifier(Modifier::BOLD)), Span::styled(format!("  {}", t.artist.clone().unwrap_or_default()), Style::new().fg(accent))]),
        Line::from(nums.join(" · ")).style(Style::new().fg(muted)),
    ];
    for fl in &tr.flags {
        let level = fl["level"].as_str().unwrap_or("");
        lines.push(Line::from(vec![Span::styled(fl["label"].as_str().unwrap_or("").to_string(), level_style(level, th).add_modifier(Modifier::BOLD)), Span::styled(format!("  {}", fl["detail"].as_str().unwrap_or("")), Style::new().fg(muted))]));
    }
    lines
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::api::Truth;
    use serde_json::json;

    fn row(id: u64, order: usize, dr: Option<f64>, lufs: Option<f64>, levels: &[&str]) -> Row {
        let truth = Truth { analyzed: true, dr, lufs, flags: levels.iter().map(|l| json!({"level": l, "label": "x", "detail": "y"})).collect(), ..Default::default() };
        Row {
            album_id: 1,
            album_order: order,
            track: Track { id, title: format!("t{id}"), artist: None, album: None, number: Some(id as i64), album_id: None, duration: None, codec: None, shared: false, shared_by: None, open: true, stream_url: None, cover_url: None, truth: Some(truth) },
        }
    }

    fn browser() -> Browser {
        let mut b = Browser::new();
        b.rows = vec![
            row(1, 0, Some(12.0), Some(-16.0), &[]),
            row(2, 1, Some(5.0), Some(-8.0), &["warn"]),
            row(3, 2, None, Some(-11.0), &["problem", "note"]),
            row(4, 3, Some(7.0), None, &["note"]),
        ];
        b.reselect(None);
        b
    }

    fn ids(b: &Browser) -> Vec<u64> {
        b.view().iter().map(|r| r.track.id).collect()
    }

    #[test]
    fn worst_findings_come_first_then_the_lowest_dr_and_missing_numbers_last() {
        let mut b = browser();
        assert_eq!(ids(&b), vec![3, 2, 4, 1]);
        b.cycle_sort(); // dr: lowest first, unknown last
        assert_eq!(ids(&b), vec![2, 4, 1, 3]);
        b.cycle_sort(); // loudness: loudest first, unknown last
        assert_eq!(ids(&b), vec![2, 3, 1, 4]);
    }

    #[test]
    fn the_filter_keeps_only_tracks_with_findings_and_the_selection_follows_the_track() {
        let mut b = browser();
        b.table.select(Some(3)); // track 1, no findings
        b.toggle_filter();
        assert_eq!(ids(&b), vec![3, 2, 4]);
        assert_eq!(b.selected_id(), Some(3)); // track 1 is gone: back to the top
        b.table.select(Some(1));
        b.cycle_sort();
        assert_eq!(b.selected_id(), Some(2)); // same track after a re-sort
    }

    #[test]
    fn copies_are_found_by_artist_title_and_length_and_the_filter_keeps_them_together() {
        let mut b = Browser::new();
        let mk = |id: u64, artist: &str, title: &str, dur: f64, order: usize| {
            let mut r = row(id, order, Some(8.0), Some(-12.0), &[]);
            r.track.artist = Some(artist.into());
            r.track.title = title.into();
            r.track.duration = Some(dur);
            r
        };
        b.rows = vec![mk(1, "Bowie", "Heroes", 370.0, 0), mk(2, "Other", "Solo", 200.0, 1), mk(3, "bowie", "HEROES ", 371.0, 2), mk(4, "Bowie", "Heroes", 500.0, 3), mk(5, "Zed", "Song", 100.0, 4), mk(6, "Zed", "Song", 101.0, 5)];
        b.regroup();
        assert_eq!(b.copies_of(&b.rows[0].track), (2, false));
        assert_eq!(b.copies_of(&b.rows[1].track), (1, false));
        assert_eq!(b.copies_of(&b.rows[3].track), (1, false)); // a live version, 130 s longer
        b.reselect(None);
        b.toggle_copies();
        assert_eq!(ids(&b), vec![1, 3, 5, 6]); // only copies, a recording's copies side by side
        b.toggle_copies();
        assert_eq!(b.filter, Filter::All);
        assert_eq!(b.sort, Sort::Severity);
    }

    #[test]
    fn the_servers_exact_duplicate_flag_counts_even_when_the_titles_differ() {
        let mut b = Browser::new();
        let mut r = row(1, 0, Some(8.0), Some(-12.0), &[]);
        r.track.truth.as_mut().unwrap().flags = vec![json!({"code": "duplicate", "level": "warn", "label": "Exact duplicate", "detail": "x"})];
        b.rows = vec![r];
        b.regroup();
        assert_eq!(b.copies_of(&b.rows[0].track), (2, true));
    }

    #[test]
    fn severity_is_the_worst_level() {
        assert_eq!(severity(&row(1, 0, None, None, &["note", "problem"]).track), 3);
        assert_eq!(severity(&row(1, 0, None, None, &[]).track), 0);
    }

    /// A stand-in server: `/tracks` answers two pages (only when `bulk`), `/albums/N` one album each, anything else 404.
    fn serve(bulk: bool) -> String {
        use std::io::{Read, Write};
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        let base = format!("http://{}", listener.local_addr().unwrap());
        std::thread::spawn(move || {
            for stream in listener.incoming().flatten() {
                std::thread::spawn(move || {
                    let mut s = stream;
                    let mut buf = [0u8; 2048];
                    let n = s.read(&mut buf).unwrap_or(0);
                    let line = String::from_utf8_lossy(&buf[..n]).lines().next().unwrap_or("").to_string();
                    let track = |id: u64, album: u64| json!({"id": id, "title": format!("t{id}"), "album_id": album, "open": true, "truth": {"analyzed": true, "dr": 8.0, "flags": []}});
                    let body = if bulk && line.contains("/tracks?") {
                        let page = if line.contains("page=2") { 2 } else { 1 };
                        Some(json!({"data": [track(page * 10, 2), track(page * 10 + 1, 1)], "meta": {"current_page": page, "last_page": 2}}))
                    } else if let Some(id) = line.split("/albums/").nth(1).and_then(|r| r.split(' ').next()).and_then(|r| r.parse::<u64>().ok()) {
                        Some(json!({"data": {"id": id, "title": "A", "tracks": [track(id * 100, id)]}}))
                    } else {
                        None
                    };
                    let reply = match body {
                        Some(b) => format!("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{}", b.to_string().len(), b),
                        None => "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n".to_string(),
                    };
                    let _ = s.write_all(reply.as_bytes());
                });
            }
        });
        base
    }

    fn album(id: u64) -> Album {
        serde_json::from_value(json!({"id": id, "title": "A"})).unwrap()
    }

    fn load_all(base: &str) -> Browser {
        let api = Api::new(base, "tok");
        let mut b = Browser::new();
        b.start(&api, &[album(1), album(2)]);
        for _ in 0..100 {
            std::thread::sleep(std::time::Duration::from_millis(50));
            b.poll();
            if b.done() {
                break;
            }
        }
        b
    }

    #[test]
    fn it_loads_with_a_few_paged_requests_when_the_server_has_tracks_and_falls_back_to_albums_when_not() {
        let bulk = load_all(&serve(true));
        assert!(bulk.done());
        let mut ids: Vec<_> = bulk.rows.iter().map(|r| (r.track.id, r.album_order)).collect();
        ids.sort();
        assert_eq!(ids, vec![(10, 1), (11, 0), (20, 1), (21, 0)]); // album_order is the position in the album list

        let old = load_all(&serve(false)); // 404 on /tracks: one request per album
        assert!(old.done());
        let mut ids: Vec<_> = old.rows.iter().map(|r| (r.track.id, r.album_id)).collect();
        ids.sort();
        assert_eq!(ids, vec![(100, 1), (200, 2)]);
    }

    #[test]
    fn it_draws_at_awkward_sizes() {
        use ratatui::{backend::TestBackend, Terminal};
        let th = crate::theme::builtin().remove(0);
        for (w, h) in [(120u16, 30u16), (40, 6), (10, 3)] {
            let mut b = browser();
            let mut t = Terminal::new(TestBackend::new(w, h)).unwrap();
            t.draw(|f| draw(f, Rect::new(0, 0, w, h), &mut b, Some(2), "▶", &th)).unwrap();
        }
    }
}
