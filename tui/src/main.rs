mod api;
mod audio;
mod config;
mod cover;
mod media;
mod meters;
mod panels;
mod stream;
mod theme;
mod truth;

use api::{Album, Api, Track};
use audio::Player;
use crossterm::event::{self, Event, KeyCode, KeyEventKind, KeyModifiers};
use meters::Meters;
use panels::{Ctx, Kind};
use theme::{c, Theme};
use ratatui::{
    layout::{Constraint, Layout, Rect},
    style::{Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, List, ListItem, ListState, Paragraph},
    DefaultTerminal, Frame,
};
use std::time::{Duration, Instant};

fn main() {
    let args: Vec<String> = std::env::args().skip(1).collect();
    match args.first().map(String::as_str) {
        Some("login") => login(args.get(1)),
        Some("logout") => {
            let _ = std::fs::remove_file(config_path_hint());
            println!("Signed out locally (revoke the token on the server under your account if you want it gone).");
        }
        Some("theme") => theme_cmd(args.get(1)),
        Some("-h") | Some("--help") => println!("magnetite            open the player\nmagnetite login URL  sign in (shows a code to approve in the browser)\nmagnetite logout     forget the token\nmagnetite theme [ID] list themes or choose one (t cycles them in the player)"),
        _ => run(),
    }
}

fn config_path_hint() -> std::path::PathBuf {
    let base = std::env::var_os("XDG_CONFIG_HOME").map(Into::into).or_else(|| std::env::var_os("HOME").map(|h| std::path::PathBuf::from(h).join(".config"))).unwrap_or_default();
    base.join("magnetite").join("tui.json")
}

fn login(server: Option<&String>) {
    let Some(server) = server else { return eprintln!("usage: magnetite login https://your.server [--password]") };
    let force_password = std::env::args().any(|a| a == "--password");
    let result = if force_password {
        ask_password(server)
    } else {
        match api::device_login(server, "Magnetite terminal") {
            Err(e) if api::is_missing_route(&e) => {
                println!("This server has no device-code sign-in yet; using username and password.");
                ask_password(server)
            }
            other => other,
        }
    };
    match result {
        Ok((token, username)) => {
            config::save(&config::Config { server: server.trim_end_matches('/').into(), token, username: username.clone(), theme: config::load().and_then(|c| c.theme), meters: None, last: config::load().and_then(|c| c.last), tint: config::load().and_then(|c| c.tint) }).expect("could not save the config");
            println!("Signed in as {username}.");
        }
        Err(e) => eprintln!("Sign-in failed: {e}"),
    }
}

fn ask_password(server: &str) -> api::Result<(String, String)> {
    use std::io::Write;
    print!("Username: ");
    std::io::stdout().flush().ok();
    let mut user = String::new();
    std::io::stdin().read_line(&mut user).map_err(|e| e.to_string())?;
    print!("Password: ");
    std::io::stdout().flush().ok();
    let pass = read_hidden().map_err(|e| e.to_string())?;
    println!();
    api::password_login(server, "Magnetite terminal", user.trim(), &pass)
}

/// Read a line without echo.
fn read_hidden() -> std::io::Result<String> {
    crossterm::terminal::enable_raw_mode()?;
    let mut s = String::new();
    let res = loop {
        match event::read() {
            Ok(Event::Key(k)) if k.kind == KeyEventKind::Press => match k.code {
                KeyCode::Enter => break Ok(()),
                KeyCode::Backspace => {
                    s.pop();
                }
                KeyCode::Char('c') if k.modifiers.contains(KeyModifiers::CONTROL) => break Err(std::io::Error::other("cancelled")),
                KeyCode::Char(c) => s.push(c),
                _ => {}
            },
            Ok(_) => {}
            Err(e) => break Err(e),
        }
    };
    crossterm::terminal::disable_raw_mode()?;
    res.map(|_| s)
}

fn theme_cmd(id: Option<&String>) {
    let Some(mut cfg) = config::load() else { return eprintln!("Sign in first: magnetite login https://your.server") };
    let all = theme::all();
    match id {
        None => {
            for t in &all {
                println!("{} {:<16} {}", if cfg.theme.as_deref() == Some(&t.id) { "*" } else { " " }, t.id, t.name);
            }
            println!("\nOwn themes: JSON files in {}", config::dir().join("themes").display());
        }
        Some(id) if all.iter().any(|t| &t.id == id) => {
            cfg.theme = Some(id.clone());
            config::save(&cfg).expect("could not save the config");
            println!("Theme: {id}");
        }
        Some(id) => eprintln!("No theme called {id}; run `magnetite theme` to list them."),
    }
}

fn run() {
    let Some(cfg) = config::load() else { return eprintln!("Not signed in. Run: magnetite login https://your.server") };
    let api = Api::new(&cfg.server, &cfg.token);
    let albums = match api.albums() {
        Ok(a) => a,
        Err(e) => return eprintln!("{e}"),
    };
    let player = match Player::new() {
        Ok(p) => p,
        Err(e) => return eprintln!("audio: {e}"),
    };
    let mut app = App::new(api, albums, player, cfg);
    app.restore();
    let mut term = ratatui::init();
    let res = app.run(&mut term);
    ratatui::restore();
    if let Err(e) = res {
        eprintln!("{e}");
    }
}

#[derive(PartialEq, Clone, Copy)]
enum View {
    Library,
    Truth, // the file-truth table instead of the two lists
}

#[derive(PartialEq)]
enum Focus {
    Albums,
    Tracks,
}

struct App {
    api: Api,
    cfg: config::Config,
    themes: Vec<Theme>,
    theme: usize,
    user: String,
    albums: Vec<Album>,
    album_sel: ListState,
    open: Option<Album>, // the album whose tracks are shown
    track_sel: ListState,
    focus: Focus,
    queue: Vec<Track>,
    now: Option<usize>,
    player: Player,
    meters: Meters,
    tap: Vec<f32>,
    panels: Vec<Kind>,
    active: usize, // the panel that m / M change
    cover: cover::Cover,
    media: media::Media,
    tint: bool,         // meters take their colours from the cover
    tint_cur: [f32; 6], // the colours shown now (accent, accent2), easing toward the target
    queue_albums: Vec<u64>, // the album of each queued track (a queue from the truth table mixes albums)
    view: View,
    truth: truth::Browser,
    resume: Option<f64>, // a track opened from the last session, not started: space plays it from here
    saved_at: Instant,
    full: bool,    // meters fill the terminal, like the web's full screen
    status: String,
    quit: bool,
}

impl App {
    fn new(api: Api, albums: Vec<Album>, player: Player, cfg: config::Config) -> Self {
        let themes = theme::all();
        let theme = cfg.theme.as_ref().and_then(|id| themes.iter().position(|t| &t.id == id)).unwrap_or_else(|| themes.iter().position(|t| t.id == "terminal").unwrap_or(0));
        // the meters: this client's own choice if there is one, else the account's list, else spectrum and wide meters
        let from_ids = |ids: &[String]| ids.iter().filter_map(|i| Kind::from_id(i)).take(4).collect::<Vec<_>>();
        let mut panels = cfg.meters.as_deref().map(from_ids).unwrap_or_default();
        if panels.is_empty() && cfg.meters.is_none() {
            panels = from_ids(&api.meters());
        }
        if panels.is_empty() && cfg.meters.is_none() {
            panels = vec![Kind::Spectrum, Kind::VuWide];
        }
        let tint = cfg.tint.unwrap_or(true);
        let tint_cur = { let t = &themes[theme]; [t.accent.0 as f32, t.accent.1 as f32, t.accent.2 as f32, t.accent2.0 as f32, t.accent2.1 as f32, t.accent2.2 as f32] };
        let mut album_sel = ListState::default();
        if !albums.is_empty() {
            album_sel.select(Some(0));
        }
        App { api, user: cfg.username.clone(), cfg, themes, theme, albums, album_sel, open: None, track_sel: ListState::default(), focus: Focus::Albums, queue: vec![], now: None, player, meters: Meters::new(), tap: vec![], panels, active: 0, cover: cover::Cover::new(), media: media::Media::new(), tint, tint_cur, queue_albums: vec![], view: View::Library, truth: truth::Browser::new(), resume: None, saved_at: Instant::now(), full: false, status: String::new(), quit: false }
    }

    fn run(&mut self, term: &mut DefaultTerminal) -> std::io::Result<()> {
        let mut last = Instant::now();
        while !self.quit {
            let dt = last.elapsed().as_secs_f32();
            last = Instant::now();
            self.tick(dt);
            term.draw(|f| self.draw(f))?;
            media::pump();
            if event::poll(Duration::from_millis(33))? {
                if let Event::Key(k) = event::read()? {
                    if k.kind == KeyEventKind::Press {
                        self.key(k.code, k.modifiers);
                    }
                }
            }
        }
        self.save_last();
        self.player.stop();
        Ok(())
    }

    fn tick(&mut self, dt: f32) {
        // ease the meter colours toward the cover's (or the theme's own when the cover has none or the tint is off)
        let th = &self.themes[self.theme];
        let (a, b) = match (self.tint, self.cover.palette()) {
            (true, Some(p)) => p,
            _ => (th.accent, th.accent2),
        };
        let target = [a.0, a.1, a.2, b.0, b.1, b.2].map(|v| v as f32);
        let k = 1.0 - (-dt / 0.3).exp();
        for (cur, t) in self.tint_cur.iter_mut().zip(target) {
            *cur += (t - *cur) * k;
        }
        self.cover.poll();
        self.truth.poll();
        self.media_commands();
        // a restored track waits for space: the idle player would otherwise report an "ended" track and move on to the next
        if self.resume.is_none() && self.player.ended() {
            self.step(1, true);
        }
        if let Some(e) = self.player.error() {
            self.status = e;
        }
        if self.now.is_some() && self.resume.is_none() && !self.player.paused() {
            if self.saved_at.elapsed() > Duration::from_secs(5) {
                self.save_last();
            }
            self.tap = self.player.tap();
            let played = self.player.take_played();
            self.meters.update(&self.tap, &played, self.player.rate(), dt);
        } else {
            self.meters.idle(dt);
        }
    }

    fn key(&mut self, code: KeyCode, mods: KeyModifiers) {
        if self.view == View::Truth && !self.full && mods != KeyModifiers::CONTROL && self.truth_key(code) {
            return;
        }
        match (code, mods) {
            (KeyCode::Char('i'), _) if !self.full => {
                self.view = View::Truth;
                self.truth.start(&self.api, &self.albums);
            }
            (KeyCode::Char('f') | KeyCode::Char('F'), _) => self.full = !self.full,
            (KeyCode::Esc, _) if self.full => self.full = false,
            (KeyCode::Down | KeyCode::Up | KeyCode::Left | KeyCode::Right | KeyCode::Enter | KeyCode::Char('j' | 'k' | 'h' | 'l'), _) if self.full => {}
            (KeyCode::Char('q'), _) | (KeyCode::Char('c'), KeyModifiers::CONTROL) => self.quit = true,
            (KeyCode::Char(' '), _) => self.toggle_play(),
            (KeyCode::Char('n'), _) => self.step(1, false),
            (KeyCode::Char('p'), _) => self.step(-1, false),
            (KeyCode::Tab, _) => self.active = (self.active + 1) % self.panels.len().max(1),
            (KeyCode::Char('m' | 'M'), _) if self.panels.is_empty() => self.add_meter(),
            (KeyCode::Char('m'), _) => self.cycle_meter(1),
            (KeyCode::Char('M'), _) => self.cycle_meter(-1),
            (KeyCode::Char('+'), _) => self.add_meter(),
            (KeyCode::Char('-'), _) => self.remove_meter(),
            (KeyCode::Char('c'), _) => {
                self.tint = !self.tint;
                self.cfg.tint = Some(self.tint);
                let _ = config::save(&self.cfg);
                self.status = format!("meter colours from the cover: {}", if self.tint { "on" } else { "off" });
            }
            (KeyCode::Char('t'), _) => self.cycle_theme(1),
            (KeyCode::Char('T'), _) => self.cycle_theme(-1),
            (KeyCode::Char('.'), _) => match self.resume {
                Some(p) => self.resume = Some(p + 10.0),
                None => self.player.seek_by(10.0),
            },
            (KeyCode::Char(','), _) => match self.resume {
                Some(p) => self.resume = Some((p - 10.0).max(0.0)),
                None => self.player.seek_by(-10.0),
            },
            (KeyCode::Down | KeyCode::Char('j'), _) => self.mv(1),
            (KeyCode::Up | KeyCode::Char('k'), _) => self.mv(-1),
            (KeyCode::Left | KeyCode::Char('h') | KeyCode::Esc, _) => self.focus = Focus::Albums,
            (KeyCode::Right | KeyCode::Char('l') | KeyCode::Enter, _) => self.enter(),
            _ => {}
        }
    }

    /// Remember the track and position (not while a restored track waits for space: that one is already what is saved).
    fn save_last(&mut self) {
        self.saved_at = Instant::now();
        if self.resume.is_some() {
            return;
        }
        let (Some(t), Some(album)) = (self.now.and_then(|i| self.queue.get(i)), self.playing_album()) else { return };
        self.cfg.last = Some(config::Last { album, track: t.id, position: self.player.position() });
        let _ = config::save(&self.cfg);
    }

    /// Open the album that was playing last time with its track selected and ready (paused): space starts it where it stopped.
    fn restore(&mut self) {
        let Some(last) = self.cfg.last.clone() else { return };
        let Some(ai) = self.albums.iter().position(|a| a.id == last.album) else { return };
        let Ok(full) = self.api.album(last.album) else { return };
        let Some(ti) = full.tracks.iter().position(|t| t.id == last.track && t.stream_url.is_some()) else { return };
        self.album_sel.select(Some(ai));
        self.track_sel.select(Some(ti));
        self.queue = full.tracks.clone();
        self.queue_albums = vec![full.id; self.queue.len()];
        self.open = Some(full);
        self.focus = Focus::Tracks;
        self.now = Some(ti);
        self.resume = Some(last.position);
        self.cover.show(&self.api, self.queue[ti].cover_url.as_deref());
        self.status = "space plays where you stopped".into();
    }

    /// No meters, in the dock: the cover on the left, and beside it the track, the album and what the file truth says about it.
    fn draw_summary(&mut self, f: &mut Frame, area: Rect, th: &Theme) {
        let side = (area.height * 2).min(area.width / 3);
        let (cover_area, info) = if self.cover.has() && area.width >= 40 && side >= 8 {
            (Rect::new(area.x + 1, area.y, side, area.height), Rect::new(area.x + side + 3, area.y, area.width - side - 4, area.height))
        } else {
            (Rect::default(), Rect::new(area.x + 1, area.y, area.width.saturating_sub(2), area.height))
        };
        self.cover.draw(f, cover_area);
        f.render_widget(Paragraph::new(self.summary_lines(th)).wrap(ratatui::widgets::Wrap { trim: false }), info);
    }

    /// No meters, full screen: the cover big in the middle, the same information centred below it.
    fn draw_summary_full(&mut self, f: &mut Frame, area: Rect, th: &Theme) {
        let lines = self.summary_lines(th);
        let info_h = (lines.len() as u16).min(area.height.saturating_sub(8)).max(2);
        let [cover_area, info] = Layout::vertical([Constraint::Min(2), Constraint::Length(info_h)]).areas(area);
        self.cover.draw(f, Rect::new(cover_area.x, cover_area.y, cover_area.width, cover_area.height.saturating_sub(1)));
        f.render_widget(Paragraph::new(lines).centered().wrap(ratatui::widgets::Wrap { trim: false }), Rect::new(info.x + 2, info.y, info.width.saturating_sub(4), info.height));
    }

    fn summary_lines(&self, th: &Theme) -> Vec<Line<'static>> {
        let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
        let Some(t) = self.now.and_then(|i| self.queue.get(i)) else { return vec![] };
        let album = self.playing_album().and_then(|id| self.albums.iter().find(|a| a.id == id));
        let dim = Style::new().fg(muted);
        let n = |v: Option<f64>, d: usize| v.map(|v| format!("{v:.d$}"));
        let state = if self.player.loading() { "  loading…" } else if self.player.paused() || self.resume.is_some() { "  paused" } else { "" };
        let mut lines = vec![
            Line::from(vec![Span::styled(t.title.clone(), Style::new().fg(text).add_modifier(Modifier::BOLD)), Span::styled(state, dim)]),
            Line::from(t.artist.clone().unwrap_or_default()).style(Style::new().fg(accent)),
        ];
        let mut al = vec![t.album.clone().or(album.map(|a| a.title.clone())).unwrap_or_default()];
        if let Some(y) = album.and_then(|a| a.year).filter(|y| *y > 0) {
            al.push(y.to_string());
        }
        if let Some(k) = album.and_then(|a| a.track_count) {
            al.push(match t.number {
                Some(i) => format!("track {i} of {k}"),
                None => format!("{k} tracks"),
            });
        }
        if let Some(dr) = album.and_then(|a| a.dr) {
            al.push(format!("album DR{}", dr.round() as i32));
        }
        lines.push(Line::from(al.join(" · ")).style(dim));
        lines.push(Line::raw(""));
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
            if !tr.flags.is_empty() {
                lines.push(Line::raw(""));
            }
            for fl in &tr.flags {
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

    fn draw_progress(&self, f: &mut Frame, area: Rect, th: &Theme) {
        let (muted, accent) = (c(th.muted), c(th.accent));
        let (pos, dur) = (self.position(), self.now.and_then(|i| self.queue.get(i)).and_then(|t| t.duration).unwrap_or(0.0));
        let w = area.width.saturating_sub(16) as usize;
        let at = if dur > 0.0 { ((pos / dur).clamp(0.0, 1.0) * w as f64) as usize } else { 0 };
        f.render_widget(
            Paragraph::new(Line::from(vec![
                Span::raw(format!(" {} ", mmss(pos))),
                Span::styled("━".repeat(at), Style::new().fg(accent)),
                Span::styled("●", Style::new().fg(c(th.accent2))),
                Span::styled("─".repeat(w.saturating_sub(at)), Style::new().fg(muted)),
                Span::raw(format!(" {}", mmss(dur))),
            ])),
            area,
        );
    }

    /// The theme with its two accents replaced by the eased cover colours; only the meters are drawn with it.
    fn meter_theme(&self, th: &Theme) -> Theme {
        let q = |i: usize| self.tint_cur[i].round().clamp(0.0, 255.0) as u8;
        Theme { accent: (q(0), q(1), q(2)), accent2: (q(3), q(4), q(5)), ..th.clone() }
    }

    /// Space and the system's play / pause: a restored track starts where it stopped, otherwise pause toggles.
    fn toggle_play(&mut self) {
        match (self.resume.take(), self.now) {
            (Some(pos), Some(i)) => {
                self.start(i);
                self.player.seek_to(pos);
            }
            _ => self.player.toggle_pause(),
        }
    }

    /// ▶ while it plays, a pause mark while it is paused (also a restored track waiting for space).
    fn play_icon(&self) -> &'static str {
        if self.player.paused() || self.resume.is_some() { "‖" } else { "▶" }
    }

    fn is_playing(&self) -> bool {
        self.now.is_some() && self.resume.is_none() && !self.player.paused()
    }

    /// Commands from the media keys, Control Center, `playerctl`...
    fn media_commands(&mut self) {
        for cmd in self.media.commands() {
            match cmd {
                media::Cmd::Toggle => self.toggle_play(),
                media::Cmd::Play if !self.is_playing() => self.toggle_play(),
                media::Cmd::Pause | media::Cmd::Stop if self.is_playing() => self.toggle_play(),
                media::Cmd::Next => self.step(1, false),
                media::Cmd::Previous => self.step(-1, false),
                media::Cmd::SeekBy(d) => match self.resume {
                    Some(p) => self.resume = Some((p + d).max(0.0)),
                    None => self.player.seek_by(d),
                },
                media::Cmd::SetPosition(t) => match self.resume {
                    Some(_) => self.resume = Some(t),
                    None => self.player.seek_to(t),
                },
                _ => {}
            }
        }
        let now = self.now.and_then(|i| self.queue.get(i)).map(|t| media::Now {
            track: Some(t.id),
            title: t.title.clone(),
            artist: t.artist.clone().unwrap_or_default(),
            album: t.album.clone().unwrap_or_default(),
            duration: t.duration.unwrap_or(0.0),
            playing: self.is_playing(),
        });
        let pos = self.position();
        self.media.update(now.as_ref(), pos);
    }

    fn playing_album(&self) -> Option<u64> {
        self.now.and_then(|i| self.queue_albums.get(i)).copied()
    }

    /// Keys of the truth table; true when it used the key.
    fn truth_key(&mut self, code: KeyCode) -> bool {
        match code {
            KeyCode::Down | KeyCode::Char('j') => self.truth.mv(1),
            KeyCode::Up | KeyCode::Char('k') => self.truth.mv(-1),
            KeyCode::PageDown => self.truth.mv(10),
            KeyCode::PageUp => self.truth.mv(-10),
            KeyCode::Home | KeyCode::Char('g') => self.truth.mv(i32::MIN / 2),
            KeyCode::End | KeyCode::Char('G') => self.truth.mv(i32::MAX / 2),
            KeyCode::Char('s') => self.truth.cycle_sort(),
            KeyCode::Char('o') => self.truth.toggle_filter(),
            KeyCode::Esc | KeyCode::Left | KeyCode::Char('h') | KeyCode::Char('i') => self.view = View::Library,
            KeyCode::Enter | KeyCode::Right | KeyCode::Char('l') => {
                // what is shown becomes the queue, so next and previous walk the list (all the problem files, say)
                let (rows, sel) = (self.truth.view(), self.truth.table.selected());
                let Some(sel) = sel.filter(|i| *i < rows.len()) else { return true };
                let (queue, albums) = (rows.iter().map(|r| r.track.clone()).collect::<Vec<_>>(), rows.iter().map(|r| r.album_id).collect::<Vec<_>>());
                self.queue = queue;
                self.queue_albums = albums;
                self.start(sel);
            }
            _ => return false,
        }
        true
    }

    fn position(&self) -> f64 {
        self.resume.unwrap_or_else(|| self.player.position())
    }

    fn cycle_theme(&mut self, d: i32) {
        let n = self.themes.len() as i32;
        self.theme = (self.theme as i32 + d).rem_euclid(n) as usize;
        let t = &self.themes[self.theme];
        self.status = format!("theme: {}", t.name);
        self.cfg.theme = Some(t.id.clone());
        let _ = config::save(&self.cfg);
    }

    fn save_meters(&mut self) {
        self.status = if self.panels.is_empty() { "meters: none (m or + brings them back)".into() } else { format!("meters: {}", self.panels.iter().map(|k| k.label()).collect::<Vec<_>>().join(" + ")) };
        self.cfg.meters = Some(self.panels.iter().map(|k| k.id().to_string()).collect());
        let _ = config::save(&self.cfg);
    }

    /// The active panel gets the next (or previous) kind that no other panel shows.
    fn cycle_meter(&mut self, d: i32) {
        let n = Kind::ALL.len() as i32;
        let start = Kind::ALL.iter().position(|k| *k == self.panels[self.active]).unwrap_or(0) as i32;
        for step in 1..=n {
            let k = Kind::ALL[(start + d * step).rem_euclid(n) as usize];
            if !self.panels.iter().enumerate().any(|(i, p)| i != self.active && *p == k) {
                self.panels[self.active] = k;
                break;
            }
        }
        self.save_meters();
    }

    /// Up to four meters at once, like the web.
    fn add_meter(&mut self) {
        if self.panels.len() < 4 {
            if let Some(k) = Kind::ALL.iter().find(|k| !self.panels.contains(k)) {
                self.panels.push(*k);
            }
        }
        self.save_meters();
    }

    fn remove_meter(&mut self) {
        // the last one can go too: no meters is a layout of its own (an empty list is saved, so it stays that way)
        self.panels.pop();
        self.active = self.active.min(self.panels.len().saturating_sub(1));
        self.save_meters();
    }

    fn mv(&mut self, d: i32) {
        let (state, len) = match self.focus {
            Focus::Albums => (&mut self.album_sel, self.albums.len()),
            Focus::Tracks => (&mut self.track_sel, self.open.as_ref().map_or(0, |a| a.tracks.len())),
        };
        if len > 0 {
            let i = state.selected().unwrap_or(0) as i32 + d;
            state.select(Some(i.clamp(0, len as i32 - 1) as usize));
        }
    }

    fn enter(&mut self) {
        match self.focus {
            Focus::Albums => {
                let Some(a) = self.album_sel.selected().and_then(|i| self.albums.get(i)) else { return };
                match self.api.album(a.id) {
                    Ok(full) => {
                        self.track_sel.select(if full.tracks.is_empty() { None } else { Some(0) });
                        if let Some(i) = self.album_sel.selected() {
                            if self.albums[i].display_artist.is_none() && self.albums[i].artist.is_none() {
                                self.albums[i].display_artist = Some(full.shown_artist()).filter(|a| !a.is_empty());
                            }
                        }
                        self.open = Some(full);
                        self.focus = Focus::Tracks;
                        self.status.clear();
                    }
                    Err(e) => self.status = e,
                }
            }
            Focus::Tracks => {
                let (Some(album), Some(i)) = (&self.open, self.track_sel.selected()) else { return };
                self.queue = album.tracks.clone();
                self.queue_albums = vec![album.id; album.tracks.len()];
                self.start(i);
            }
        }
    }

    /// Next or previous playable track; at the end of the queue playback just stops.
    fn step(&mut self, d: i32, auto: bool) {
        let Some(now) = self.now else { return };
        let mut i = now as i32 + d;
        while i >= 0 && (i as usize) < self.queue.len() && self.queue[i as usize].stream_url.is_none() {
            i += d;
        }
        if i >= 0 && (i as usize) < self.queue.len() {
            self.start(i as usize);
        } else if auto {
            self.player.stop();
            self.now = None;
        }
    }

    fn start(&mut self, i: usize) {
        self.resume = None;
        match self.queue[i].stream_url.clone() {
            Some(url) => {
                self.now = Some(i);
                self.cover.show(&self.api, self.queue[i].cover_url.as_deref());
                self.meters.reset_track();
                self.status.clear();
                self.player.play(&self.api, url);
                self.save_last();
                // warm the next playable track
                if let Some(next) = self.queue.iter().skip(i + 1).find_map(|t| t.stream_url.clone()) {
                    self.player.prefetch(&self.api, next);
                }
            }
            None => self.status = "this track is not shared with you".into(),
        }
    }

    /// 2 info lines, the meter panels (7 rows for one or two, 13 for a 2 x 2 grid), the progress line and the border.
    fn now_height(&self, total: u16) -> u16 {
        let panels = match self.panels.len() {
            0 => 12,
            1 | 2 => 7,
            _ => 13,
        };
        (2 + panels + 1 + 2).min(total.saturating_sub(8)).max(6)
    }

    /// Room for the cover on the left when there is one and the area is wide enough: a square as tall as the area, at most a third of its width.
    fn split_cover(&self, area: Rect) -> (Rect, Rect) {
        let side = (area.height * 2).min(area.width / 3);
        if !self.cover.has() || area.width < 70 || side < 8 {
            return (Rect::default(), area);
        }
        (Rect::new(area.x + 1, area.y, side, area.height), Rect::new(area.x + side + 2, area.y, area.width - side - 2, area.height))
    }

    /// The web's full screen: the page background, the meters filling everything, no borders or titles on the cells,
    /// then the track (title, artist in the accent, album muted), the progress line and the hint.
    fn draw_full(&mut self, f: &mut Frame, th: &Theme) {
        let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
        if self.panels.is_empty() && self.now.is_some() {
            let [body, prog, foot] = Layout::vertical([Constraint::Min(3), Constraint::Length(1), Constraint::Length(1)]).areas(f.area());
            self.draw_summary_full(f, Rect::new(body.x, body.y + 1, body.width, body.height.saturating_sub(1)), th);
            self.draw_progress(f, prog, th);
            let hint = if self.status.is_empty() { " f/esc close · space pause · n/p · ,/. seek · m or + brings the meters back · t theme".to_string() } else { self.status.clone() };
            f.render_widget(Paragraph::new(hint).style(Style::new().fg(muted)), foot);
            return;
        }
        let [meters, title, prog, foot] = Layout::vertical([Constraint::Min(3), Constraint::Length(2), Constraint::Length(1), Constraint::Length(1)]).areas(f.area());
        let mt = self.meter_theme(th);
        let ctx = Ctx { m: &self.meters, tap: &self.tap, rate: self.player.rate(), th: &mt };
        // no meters: like the web, the cover big in the middle above the title
        let (cover_area, meters) = if self.panels.is_empty() { (Rect::new(meters.x, meters.y + 1, meters.width, meters.height.saturating_sub(2)), meters) } else { self.split_cover(meters) };
        self.cover.draw(f, cover_area);
        let pad = Rect::new(meters.x + 1, meters.y + 1, meters.width.saturating_sub(2), meters.height.saturating_sub(1));
        for (kind, cell) in self.panels.iter().zip(cells(self.panels.len(), pad)) {
            // a one-cell gutter between meters, like the web's cells
            panels::draw(f, Rect::new(cell.x, cell.y, cell.width.saturating_sub(2).max(1), cell.height.saturating_sub(1).max(1)), *kind, &ctx);
        }
        let t = self.now.and_then(|i| self.queue.get(i));
        let state = if self.player.loading() { "  loading…" } else if self.player.paused() || self.resume.is_some() { "  paused" } else { "" };
        let head = match t {
            Some(t) => vec![
                Line::from(vec![Span::styled(t.title.clone(), Style::new().add_modifier(Modifier::BOLD)), Span::styled(format!("  {}", t.artist.clone().unwrap_or_default()), Style::new().fg(accent)), Span::styled(state, Style::new().fg(muted))]),
                Line::from(t.album.clone().unwrap_or_default()).style(Style::new().fg(muted)),
            ],
            None => vec![Line::from("Nothing playing").style(Style::new().fg(muted))],
        };
        f.render_widget(Paragraph::new(head).centered().style(Style::new().fg(text)), title);
        self.draw_progress(f, prog, th);
        let hint = if self.status.is_empty() { format!(" {}  ·  f/esc close · space pause · n/p · ,/. seek · tab/m meter · +/- panels · t theme", self.panels.get(self.active).map_or("", |k| k.label())) } else { self.status.clone() };
        f.render_widget(Paragraph::new(hint).style(Style::new().fg(muted)), foot);
    }

    fn draw(&mut self, f: &mut Frame) {
        let th = self.themes[self.theme].clone();
        let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
        f.render_widget(Block::new().style(Style::new().bg(c(th.bg)).fg(text)), f.area());
        if self.full {
            return self.draw_full(f, &th);
        }
        let [head, body, now, foot] = Layout::vertical([Constraint::Length(1), Constraint::Min(6), Constraint::Length(self.now_height(f.area().height)), Constraint::Length(1)]).areas(f.area());
        f.render_widget(
            Line::from(vec![Span::styled(" magnetite ", Style::new().fg(c(th.bg)).bg(accent)), Span::raw(format!("  {}  ·  {} albums  ·  {}", self.user, self.albums.len(), th.name))]),
            head,
        );

        if self.view == View::Truth {
            let playing = self.now.and_then(|i| self.queue.get(i)).map(|t| t.id);
            let icon = self.play_icon();
            truth::draw(f, body, &mut self.truth, playing, icon, &th);
            self.draw_now(f, now, &th);
            let hint = if self.status.is_empty() { "enter play from here (the list becomes the queue) · s sort · o only findings · j/k PgUp/PgDn move · space pause · n/p · i or esc back".to_string() } else { self.status.clone() };
            f.render_widget(Paragraph::new(format!(" {hint}")).style(Style::new().fg(muted)), foot);
            return;
        }
        let [left, right] = Layout::horizontal([Constraint::Percentage(42), Constraint::Percentage(58)]).areas(body);
        let pane = |on: bool, title: String| Block::new().borders(Borders::ALL).border_style(Style::new().fg(if on { accent } else { muted })).title(title).style(Style::new().bg(c(th.surface)).fg(text));
        let sel = Style::new().fg(c(th.bg)).bg(accent);
        let items: Vec<ListItem> = self
            .albums
            .iter()
            .map(|a| {
                let on = self.playing_album() == Some(a.id);
                let mark = if on { Span::styled(format!("{} ", self.play_icon()), Style::new().fg(c(th.accent2))) } else { Span::raw("  ") };
                let title = if on { Span::styled(a.title.clone(), Style::new().fg(c(th.accent2))) } else { Span::raw(a.title.clone()) };
                ListItem::new(Line::from(vec![mark, title, Span::styled(format!("  {}", a.shown_artist()), Style::new().fg(muted))]))
            })
            .collect();
        f.render_stateful_widget(List::new(items).block(pane(self.focus == Focus::Albums, " Albums ".into())).highlight_style(sel), left, &mut self.album_sel);

        let playing = self.now.and_then(|i| self.queue.get(i)).map(|t| t.id);
        let mt = self.meter_theme(&th);
        let title = self.open.as_ref().map_or(" Tracks ".to_string(), |a| format!(" {} ", a.title));
        let items: Vec<ListItem> = self
            .open
            .as_ref()
            .map(|a| a.tracks.as_slice())
            .unwrap_or(&[])
            .iter()
            .map(|t| {
                let mark = if Some(t.id) == playing { self.play_icon() } else { " " };
                let n = t.number.map_or(String::new(), |n| n.to_string());
                let style = if Some(t.id) == playing { Style::new().fg(c(th.accent2)) } else if t.open { Style::new() } else { Style::new().fg(muted) };
                let mut spans = vec![Span::raw(format!("{mark}{n:>2} {:<30} {:>6}", t.title, mmss(t.duration.unwrap_or(0.0))))];
                if Some(t.id) == playing {
                    // a live spectrum peek beside the playing track; on the selected row (accent background) it is drawn in the page colour
                    let on_sel = self.track_sel.selected().is_some_and(|i| self.open.as_ref().and_then(|a| a.tracks.get(i)).is_some_and(|x| x.id == t.id));
                    let (lo, hi) = if on_sel { (c(th.bg), c(th.bg)) } else { (c(mt.accent), c(mt.accent2)) };
                    spans.push(Span::raw("  "));
                    spans.extend(spark(&self.meters.bands, 8, lo, hi, self.player.paused() || self.resume.is_some()));
                }
                ListItem::new(Line::from(spans)).style(style)
            })
            .collect();
        f.render_stateful_widget(List::new(items).block(pane(self.focus == Focus::Tracks, title)).highlight_style(sel), right, &mut self.track_sel);

        self.draw_now(f, now, &th);
        let hint = if self.status.is_empty() { "enter open/play · i file truth · space pause · n/p next/prev · ,/. seek 10s · tab/m meter · c cover colours · +/- add/remove (the last one too) · f full screen · t theme · h back · q quit".to_string() } else { self.status.clone() };
        f.render_widget(Paragraph::new(format!(" {hint}")).style(Style::new().fg(muted)), foot);
    }

    fn draw_now(&mut self, f: &mut Frame, area: Rect, th: &Theme) {
        let (muted, accent) = (c(th.muted), c(th.accent));
        let block = Block::new().borders(Borders::ALL).border_style(Style::new().fg(muted)).title(" Now playing ").style(Style::new().bg(c(th.surface)).fg(c(th.text)));
        let inner = block.inner(area);
        f.render_widget(block, area);
        let t = self.now.and_then(|i| self.queue.get(i));
        if self.panels.is_empty() && t.is_some() {
            let [body, prog] = Layout::vertical([Constraint::Min(3), Constraint::Length(1)]).areas(inner);
            self.draw_summary(f, body, th);
            self.draw_progress(f, prog, th);
            return;
        }
        let [info, mid, prog] = Layout::vertical([Constraint::Length(2), if self.panels.is_empty() { Constraint::Length(0) } else { Constraint::Min(3) }, Constraint::Length(1)]).areas(inner);

        let Some(t) = t else {
            f.render_widget(Paragraph::new("Nothing playing. Open an album and press enter.").style(Style::new().fg(muted)), info);
            return;
        };
        let truth = t.truth.clone().unwrap_or_default();
        let mut meta = vec![t.codec.clone().unwrap_or_default().to_uppercase()];
        if let (Some(b), Some(sr)) = (truth.bits, truth.sample_rate) {
            meta.push(format!("{}/{}", b as u32, sr / 1000.0));
        }
        if let Some(dr) = truth.dr {
            meta.push(format!("DR{}", dr as i32));
        }
        if let Some(l) = truth.lufs {
            meta.push(format!("{l:.1} LUFS"));
        }
        if truth.analyzed {
            if !truth.flags.is_empty() {
                meta.push(format!("{} finding(s) !", truth.flags.len()));
            }
        }
        let state = if self.player.loading() { "  loading…" } else if self.player.paused() || self.resume.is_some() { "  paused" } else { "" };
        f.render_widget(
            Paragraph::new(vec![
                Line::from(vec![Span::styled(t.artist.clone().unwrap_or_default(), Style::new().add_modifier(Modifier::BOLD)), Span::raw(format!(" — {}{state}", t.title))]),
                Line::from(meta.join(" · ")).style(Style::new().fg(muted)),
            ]),
            info,
        );

        let mt = self.meter_theme(th);
        let ctx = Ctx { m: &self.meters, tap: &self.tap, rate: self.player.rate(), th: &mt };
        let (cover_area, mid) = self.split_cover(mid);
        self.cover.draw(f, cover_area);
        for (i, (kind, cell)) in self.panels.iter().zip(cells(self.panels.len(), mid)).enumerate() {
            let on = self.panels.len() > 1 && i == self.active;
            let block = Block::new()
                .borders(Borders::TOP)
                .border_style(Style::new().fg(if on { accent } else { muted }))
                .title(Span::styled(format!(" {}{} ", if on { "▸ " } else { "" }, kind.label()), Style::new().fg(if on { accent } else { c(th.text) })));
            let inner = block.inner(cell);
            f.render_widget(block, cell);
            panels::draw(f, inner, *kind, &ctx);
        }

        self.draw_progress(f, prog, th);
    }
}

/// Cells for `n` panels, the web's `layout()`: the column count that makes the cells closest to 1.6 : 1 (a terminal cell
/// is about twice as tall as wide, so a cell of w x h is w / (2h) on screen); a wide area puts meters side by side, a tall one
/// stacks them, an uneven last row stretches its cells (2 x 2 beats 3 + 1).
fn cells(n: usize, area: Rect) -> Vec<Rect> {
    if n == 0 {
        return vec![];
    }
    let (w, h) = (area.width as f32, area.height as f32 * 2.0);
    let (mut best, mut score) = (1usize, f32::INFINITY);
    for cols in 1..=n {
        let rows = n.div_ceil(cols);
        let ragged = if n % cols == 0 { 0.0 } else { 0.4 };
        let sc = (w / cols as f32 / (h / rows as f32) / 1.6).ln().abs() + ragged;
        if sc < score {
            (best, score) = (cols, sc);
        }
    }
    let rows = n.div_ceil(best);
    let split = |total: u16, k: usize, i: usize| -> (u16, u16) {
        let a = (total as usize * i / k) as u16;
        (a, (total as usize * (i + 1) / k) as u16 - a)
    };
    let mut out = Vec::with_capacity(n);
    for r in 0..rows {
        let in_row = best.min(n - r * best);
        let (y, rh) = split(area.height, rows, r);
        for c in 0..in_row {
            let (x, cw) = split(area.width, in_row, c);
            out.push(Rect::new(area.x + x, area.y + y, cw, rh));
        }
    }
    out
}

/// `n` bars of eighth blocks from spectrum bands (0..1): each bar the loudest of its share of the bands; bars from `lo` up to `hi`
/// by height. Paused, a flat low line instead of whatever the decay left.
fn spark(bands: &[f32], n: usize, lo: ratatui::style::Color, hi: ratatui::style::Color, paused: bool) -> Vec<Span<'static>> {
    const B: [char; 9] = [' ', '▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
    (0..n)
        .map(|i| {
            let (a, b) = (i * bands.len() / n, ((i + 1) * bands.len() / n).max(i * bands.len() / n + 1));
            let v = if paused { 0.0 } else { bands[a..b.min(bands.len())].iter().cloned().fold(0.0, f32::max) };
            let level = ((v * 8.0).round() as usize).clamp(1, 8); // never blank: an idle bar is a short stub
            Span::styled(B[level].to_string(), Style::new().fg(if level >= 6 { hi } else { lo }))
        })
        .collect()
}

fn mmss(s: f64) -> String {
    let s = s.max(0.0) as u64;
    format!("{}:{:02}", s / 60, s % 60)
}

#[cfg(test)]
mod spark_tests {
    use super::*;

    #[test]
    fn the_peek_has_n_bars_that_follow_the_loudest_band_of_their_share() {
        let mut bands = [0.0f32; 24];
        bands[0] = 1.0; // first share of three bands
        bands[23] = 0.5;
        let s = spark(&bands, 8, ratatui::style::Color::Red, ratatui::style::Color::Blue, false);
        assert_eq!(s.len(), 8);
        let ch = |i: usize| s[i].content.chars().next().unwrap();
        assert_eq!((ch(0), ch(7), ch(3)), ('█', '▄', '▁'));
        assert!(spark(&bands, 8, ratatui::style::Color::Red, ratatui::style::Color::Blue, true).iter().all(|x| x.content == "▁"));
    }
}

#[cfg(test)]
mod layout_tests {
    use super::*;

    #[test]
    fn wide_areas_put_meters_side_by_side_tall_ones_stack_them_and_cells_tile_exactly() {
        let wide = cells(2, Rect::new(0, 0, 120, 14));
        assert_eq!((wide[0].y, wide[1].y), (0, 0));
        let tall = cells(2, Rect::new(0, 0, 40, 40));
        assert_eq!((tall[0].x, tall[1].x), (0, 0));
        for n in 1..=4 {
            let area = Rect::new(3, 2, 101, 37);
            let cs = cells(n, area);
            assert_eq!(cs.len(), n);
            let covered: u32 = cs.iter().map(|r| r.width as u32 * r.height as u32).sum();
            assert_eq!(covered, 101 * 37, "{n} cells leave gaps or overlap");
        }
    }
}

#[cfg(test)]
mod live_tests {
    use super::*;
    use ratatui::{backend::TestBackend, Terminal};

    /// `cargo test live_summary -- --ignored --nocapture`: the first album with a playable track, no meters, as the dock draws it.
    #[test]
    #[ignore]
    fn live_summary() {
        let cfg = config::load().expect("sign in first");
        let api = Api::new(&cfg.server, &cfg.token);
        let albums = api.albums().unwrap();
        let mut app = App::new(api.clone(), albums.clone(), Player::new().unwrap(), cfg);
        let full = albums.iter().find_map(|a| api.album(a.id).ok().filter(|f| f.tracks.iter().any(|t| t.stream_url.is_some() && t.truth.as_ref().is_some_and(|x| x.analyzed)))).expect("an analysed album");
        let ti = full.tracks.iter().position(|t| t.stream_url.is_some()).unwrap();
        app.queue = full.tracks.clone();
        app.queue_albums = vec![full.id; app.queue.len()];
        app.now = Some(ti);
        app.resume = None;
        app.open = Some(full.clone());
        app.focus = Focus::Tracks;
        for (i, b) in app.meters.bands.iter_mut().enumerate() {
            *b = ((i as f32 * 0.5).sin().abs() * 0.8 + 0.1).min(1.0);
        }
        app.panels.clear();
        app.cover.show(&api, app.queue[ti].cover_url.as_deref());
        for _ in 0..50 {
            std::thread::sleep(Duration::from_millis(100));
            app.cover.poll();
            if app.cover.has() {
                break;
            }
        }
        app.full = false;
        let mut t = Terminal::new(TestBackend::new(110, 40)).unwrap();
        t.draw(|f| app.draw(f)).unwrap();
        let b = t.backend().buffer();
        for y in 0..40 {
            println!("{}", (0..110).map(|x| b[(x, y)].symbol().to_string()).collect::<String>());
        }
    }
}

#[cfg(test)]
mod live_truth {
    use super::*;
    use ratatui::{backend::TestBackend, Terminal};

    /// `cargo test live_truth -- --ignored --nocapture`: the truth table of the signed-in library.
    #[test]
    #[ignore]
    fn live_truth() {
        let cfg = config::load().expect("sign in first");
        let api = Api::new(&cfg.server, &cfg.token);
        let albums = api.albums().unwrap();
        let mut app = App::new(api.clone(), albums.clone(), Player::new().unwrap(), cfg);
        app.view = View::Truth;
        let t0 = Instant::now();
        app.truth.start(&api, &albums);
        while !app.truth.done() && t0.elapsed() < Duration::from_secs(120) {
            std::thread::sleep(Duration::from_millis(100));
            app.truth.poll();
        }
        println!("loaded {} albums in {:.1}s, {} analysed tracks", app.truth.loaded, t0.elapsed().as_secs_f32(), app.truth.rows.len());
        let mut t = Terminal::new(TestBackend::new(150, 40)).unwrap();
        t.draw(|f| app.draw(f)).unwrap();
        let b = t.backend().buffer();
        for y in 0..40 {
            println!("{}", (0..150).map(|x| b[(x, y)].symbol().to_string()).collect::<String>());
        }
    }
}
