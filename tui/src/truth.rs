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
}

impl Sort {
    const ALL: [Sort; 5] = [Sort::Severity, Sort::Dr, Sort::Loudness, Sort::TruePeak, Sort::Album];
    pub fn label(self) -> &'static str {
        match self {
            Sort::Severity => "worst findings first",
            Sort::Dr => "lowest DR first",
            Sort::Loudness => "loudest first",
            Sort::TruePeak => "highest true peak first",
            Sort::Album => "library order",
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

pub struct Browser {
    pub rows: Vec<Row>,
    pub total: usize,
    pub loaded: usize,
    pub sort: Sort,
    pub only_findings: bool,
    pub table: TableState,
    rx: Option<mpsc::Receiver<(usize, Option<Album>)>>,
}

impl Browser {
    pub fn new() -> Self {
        Browser { rows: vec![], total: 0, loaded: 0, sort: Sort::Severity, only_findings: false, table: TableState::default(), rx: None }
    }

    /// Fetch every album's tracks, once, with four threads.
    pub fn start(&mut self, api: &Api, albums: &[Album]) {
        if self.rx.is_some() {
            return;
        }
        let (tx, rx) = mpsc::channel();
        self.total = albums.len();
        let ids: Vec<u64> = albums.iter().map(|a| a.id).collect();
        for k in 0..4usize {
            let (api, tx, ids) = (api.clone(), tx.clone(), ids.clone());
            thread::spawn(move || {
                for (i, id) in ids.iter().enumerate().skip(k).step_by(4) {
                    if tx.send((i, api.album(*id).ok())).is_err() {
                        return;
                    }
                }
            });
        }
        self.rx = Some(rx);
    }

    pub fn poll(&mut self) {
        let Some(rx) = &self.rx else { return };
        let keep = self.selected_id();
        let mut changed = false;
        while let Ok((order, album)) = rx.try_recv() {
            self.loaded += 1;
            if let Some(a) = album {
                changed = true;
                self.rows.extend(a.tracks.into_iter().filter(|t| t.truth.as_ref().is_some_and(|x| x.analyzed)).map(|track| Row { album_id: a.id, album_order: order, track }));
            }
        }
        if changed {
            self.reselect(keep);
        }
    }

    pub fn done(&self) -> bool {
        self.rx.is_some() && self.loaded >= self.total
    }

    /// The rows shown: filtered, then sorted.
    pub fn view(&self) -> Vec<&Row> {
        let mut v: Vec<&Row> = self.rows.iter().filter(|r| !self.only_findings || severity(&r.track) > 0).collect();
        let by_album = |a: &&Row, b: &&Row| (a.album_order, a.track.number).cmp(&(b.album_order, b.track.number));
        match self.sort {
            Sort::Severity => v.sort_by(|a, b| severity(&b.track).cmp(&severity(&a.track)).then(cmp_opt(num(&a.track, |t| t.dr), num(&b.track, |t| t.dr), false)).then_with(|| by_album(a, b))),
            Sort::Dr => v.sort_by(|a, b| cmp_opt(num(&a.track, |t| t.dr), num(&b.track, |t| t.dr), false).then_with(|| by_album(a, b))),
            Sort::Loudness => v.sort_by(|a, b| cmp_opt(num(&a.track, |t| t.lufs), num(&b.track, |t| t.lufs), true).then_with(|| by_album(a, b))),
            Sort::TruePeak => v.sort_by(|a, b| cmp_opt(num(&a.track, |t| t.true_peak), num(&b.track, |t| t.true_peak), true).then_with(|| by_album(a, b))),
            Sort::Album => v.sort_by(by_album),
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

    pub fn toggle_filter(&mut self) {
        let keep = self.selected_id();
        self.only_findings = !self.only_findings;
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

pub fn draw(f: &mut Frame, area: Rect, b: &mut Browser, playing: Option<u64>, th: &Theme) {
    let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
    let detail_h = if area.height >= 16 { 8 } else { 0 };
    let [table_area, detail_area] = Layout::vertical([Constraint::Min(4), Constraint::Length(detail_h)]).areas(area);

    let view = b.view();
    let mut state = b.table.clone();
    let with = b.rows.iter().filter(|r| severity(&r.track) > 0).count();
    let loading = if b.done() { String::new() } else { format!(" · loading {}/{}", b.loaded, b.total) };
    let title = format!(" File truth · {} tracks · {} with findings · {}{}{} ", b.rows.len(), with, b.sort.label(), if b.only_findings { " · only findings" } else { "" }, loading);

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
            let play = if Some(t.id) == playing { "▶" } else { " " };
            let fmt = [t.codec.clone().unwrap_or_default().to_uppercase(), match (tr.bits, tr.sample_rate) {
                (Some(bits), Some(sr)) => format!("{}/{}", bits as u32, (sr / 1000.0 * 10.0).round() / 10.0),
                _ => String::new(),
            }]
            .join(" ");
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
                Cell::from(labels).style(st),
            ])
        })
        .collect();
    let header = TRow::new(["", "Track", "Album", "Format", "DR", "LUFS", "TP", "Findings"]).style(Style::new().fg(muted).add_modifier(Modifier::BOLD));
    let widths = [Constraint::Length(3), Constraint::Fill(3), Constraint::Fill(2), Constraint::Length(13), Constraint::Length(3), Constraint::Length(6), Constraint::Length(6), Constraint::Fill(3)];
    let table = Table::new(rows, widths)
        .header(header)
        .column_spacing(1)
        .row_highlight_style(Style::new().fg(c(th.bg)).bg(accent))
        .block(Block::new().borders(Borders::ALL).border_style(Style::new().fg(accent)).title(title).style(Style::new().bg(c(th.surface)).fg(text)));
    f.render_stateful_widget(table, table_area, &mut state);

    if detail_h > 0 {
        let sel = state.selected().and_then(|i| view.get(i));
        let lines = sel.map_or(vec![Line::from("Nothing to show (yet).").style(Style::new().fg(muted))], |r| detail(&r.track, th));
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
            track: Track { id, title: format!("t{id}"), artist: None, album: None, number: Some(id as i64), duration: None, codec: None, open: true, stream_url: None, cover_url: None, truth: Some(truth) },
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
    fn severity_is_the_worst_level() {
        assert_eq!(severity(&row(1, 0, None, None, &["note", "problem"]).track), 3);
        assert_eq!(severity(&row(1, 0, None, None, &[]).track), 0);
    }

    #[test]
    fn it_draws_at_awkward_sizes() {
        use ratatui::{backend::TestBackend, Terminal};
        let th = crate::theme::builtin().remove(0);
        for (w, h) in [(120u16, 30u16), (40, 6), (10, 3)] {
            let mut b = browser();
            let mut t = Terminal::new(TestBackend::new(w, h)).unwrap();
            t.draw(|f| draw(f, Rect::new(0, 0, w, h), &mut b, Some(2), &th)).unwrap();
        }
    }
}
