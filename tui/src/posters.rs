//! Poster meters (docs/posters.md) in the terminal: flat shapes painted into a small picture, two by two pixels a cell, and shown
//! with quadrant blocks (`blocks.rs`, the cover's renderer); the track's text is real text on the rows above or below.
//! A web poster is an upright 600 x 800 sheet; here a poster fills the cell it is given, wide or tall, because a terminal cell
//! has too few pixels to leave margins. The ballistics (`Bars`, `Reference`, `follow`) are the web's `poster.js`.
use crate::{
    api::Track,
    blocks::{self, Shape, Tuning},
    cover,
    meters::THIRD,
    panels::Ctx,
    theme::{c, lerp, truecolor, Rgb},
};
use image::RgbImage;
use std::sync::OnceLock;
use ratatui::{
    layout::Rect,
    style::{Modifier, Style},
    text::{Line, Span},
    widgets::Paragraph,
    Frame,
};

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Poster {
    Pillars,
    Arches,
    Lens,
    Fan,
    Slides,
    Dials,
    Rings,
    Tunnel,
    Lenses,
    Overcome,
    Facets,
    Heads,
}

/// Below this a level counts as silence (the meters' floors are -90 dB for a channel and -120 dB for a band).
const SILENT: f32 = -85.0;

/// One step towards `target`: about 99% of the way after `seconds`.
pub fn follow(value: f32, target: f32, dt: f32, seconds: f32) -> f32 {
    value + (target - value) * (1.0 - (-dt / (seconds.max(0.001) / 4.6)).exp())
}

/// What "full" means for a track: a level that follows the track's own peaks (quickly up, 2 dB a second down), so a quiet
/// recording and a loud master move alike.
pub struct Reference {
    pub db: f32,
}

impl Reference {
    pub fn update(&mut self, peak: f32, dt: f32) {
        let floor = self.db.max(peak - 30.0);
        self.db = (if peak > floor { peak.min(floor + 40.0 * dt) } else { peak.max(floor - 2.0 * dt) }).max(-90.0);
    }

    /// 0..1 over `range` dB below the reference, with a curve that keeps ordinary levels in the middle.
    pub fn norm(&self, db: f32, range: f32) -> f32 {
        if db <= SILENT {
            return 0.0;
        }
        ((db - (self.db - range)) / range).clamp(0.0, 1.0).powf(1.5)
    }
}

/// `n` groups of the third-octave bands, each the loudest band it covers, rising and falling like a meter.
pub struct Bars {
    pub v: Vec<f32>,
    reference: Reference,
    attack: f32,
    release: f32,
}

impl Bars {
    fn new(n: usize, attack: f32, release: f32) -> Self {
        Bars { v: vec![0.0; n], reference: Reference { db: -90.0 }, attack, release }
    }

    fn update(&mut self, db: &[f32; THIRD], dt: f32) {
        let n = self.v.len();
        self.reference.update(db.iter().cloned().fold(f32::MIN, f32::max), dt);
        for i in 0..n {
            let from = i * THIRD / n;
            let to = ((i + 1) * THIRD / n).max(from + 1).min(THIRD);
            let raw = self.reference.norm(db[from..to].iter().cloned().fold(f32::MIN, f32::max), 40.0);
            self.v[i] = follow(self.v[i], raw, dt, if raw > self.v[i] { self.attack } else { self.release });
        }
    }
}

/// What the posters remember between frames; it lives in `Meters`, so every set of meters (the A/B sides too) has its own.
pub struct State {
    pillars: Bars,
    arches: Bars,
    fan: Bars,
    slides: Bars,
    rings: Bars,
    tunnel: Bars,
    spectrum: Bars,
    spin: f32,
    lenses: Bars,
    over: Bars,
    over_ref: Reference,
    over_l: f32,
    over_r: f32,
    facets: Bars,
    history: Vec<Vec<f32>>,
    acc: f32,
    heads: Bars,
    hot: usize,
    t: f32,
    level: Reference,
    l: f32,
    r: f32,
    corr: f32,
    loud: f32,
    peak: f32,
    dial_corr: f32,
}

impl State {
    pub fn new() -> Self {
        State {
            pillars: Bars::new(7, 0.04, 0.35),
            arches: Bars::new(6, 0.05, 0.4),
            fan: Bars::new(12, 0.05, 0.4),
            slides: Bars::new(4, 0.08, 0.6),
            rings: Bars::new(9, 0.06, 0.5),
            tunnel: Bars::new(7, 0.08, 0.5),
            spectrum: Bars::new(22, 0.08, 0.5),
            spin: 0.0,
            lenses: Bars::new(9, 0.06, 0.45),
            over: Bars::new(31, 0.05, 0.4),
            over_ref: Reference { db: -90.0 },
            over_l: 0.0,
            over_r: 0.0,
            facets: Bars::new(13, 0.04, 0.2),
            history: vec![vec![0.0; 13]; 16],
            acc: 0.0,
            heads: Bars::new(16, 0.08, 0.5),
            hot: 0,
            t: 0.0,
            level: Reference { db: -90.0 },
            l: 0.0,
            r: 0.0,
            corr: 0.0,
            loud: 0.0,
            peak: 0.0,
            dial_corr: 0.5,
        }
    }

    /// `third` is the meters' 0..1 bands (80 dB), `rms` and `peak_db` in dBFS, `loud` the short-term (or momentary) LUFS.
    pub fn update(&mut self, third: &[f32; THIRD], rms: (f32, f32), corr: f32, loud: Option<f64>, peaks: (f32, f32), playing: bool, dt: f32) {
        let peak_db = peaks.0.max(peaks.1);
        let mut db = [0.0f32; THIRD];
        for (d, v) in db.iter_mut().zip(third) {
            *d = if *v <= 0.0 { -120.0 } else { v * 80.0 - 80.0 };
        }
        for bars in [&mut self.pillars, &mut self.arches, &mut self.fan, &mut self.slides, &mut self.rings, &mut self.tunnel, &mut self.spectrum, &mut self.lenses, &mut self.over, &mut self.facets, &mut self.heads] {
            bars.update(&db, dt);
        }
        self.t += dt;
        self.spin += dt * (0.25 + 2.2 * self.tunnel.v.iter().sum::<f32>() / 7.0);
        // the overcome circles ride the channels' held peaks, scaled to their own reference, and fall slowly
        self.over_ref.update(peak_db, dt);
        self.over_l = self.over_ref.norm(peaks.0, 40.0).max(self.over_l - 0.35 * dt);
        self.over_r = self.over_ref.norm(peaks.1, 40.0).max(self.over_r - 0.35 * dt);
        // the loudest head only changes when another is clearly louder
        let v = &self.heads.v;
        let loudest = (0..v.len()).fold(0, |b, i| if v[i] > v[b] { i } else { b });
        if v[loudest] > v[self.hot] + 0.08 {
            self.hot = loudest;
        }
        // the facets scroll a column at a time, only while playing
        if playing {
            self.acc += dt;
            if self.acc >= 0.14 {
                self.acc = 0.0;
                self.history.rotate_left(1);
                *self.history.last_mut().unwrap() = self.facets.v.clone();
            }
        }
        self.level.update(rms.0.max(rms.1), dt);
        self.l = follow(self.l, self.level.norm(rms.0, 30.0), dt, 0.25);
        self.r = follow(self.r, self.level.norm(rms.1, 30.0), dt, 0.25);
        self.corr = follow(self.corr, corr.clamp(-1.0, 1.0), dt, 0.25);
        self.loud = follow(self.loud, loud.map_or(0.0, |l| ((l as f32 + 40.0) / 35.0).clamp(0.0, 1.0)), dt, 0.4);
        self.peak = ((peak_db + 40.0) / 40.0).clamp(0.0, 1.0).max(self.peak - 0.25 * dt);
        self.dial_corr = follow(self.dial_corr, (corr.clamp(-1.0, 1.0) + 1.0) / 2.0, dt, 0.4);
    }
}

#[derive(Clone, Copy, PartialEq)]
enum Mix {
    Paint,
    /// Like ink on light paper: overlaps get darker.
    Multiply,
    /// On dark paper: overlaps get lighter.
    Screen,
}

/// The colour as this terminal will show it: itself with true colour, else the palette entry the rest of the screen uses for
/// it (`theme::c`), so a poster's paper is the page's background and a label's ground is the shape it sits on.
fn shown(rgb: Rgb) -> [f32; 3] {
    let (r, g, b) = if truecolor() { rgb } else { blocks::rgb_of(c(rgb)) };
    [r as f32, g as f32, b as f32]
}

fn lum(c: Rgb) -> f32 {
    0.2126 * c.0 as f32 + 0.7152 * c.1 as f32 + 0.0722 * c.2 as f32
}

/// WCAG contrast ratio of two colours.
fn contrast(a: Rgb, b: Rgb) -> f32 {
    let rel = |c: Rgb| {
        let f = |v: u8| { let v = v as f32 / 255.0; if v <= 0.03928 { v / 12.92 } else { ((v + 0.055) / 1.055).powf(2.4) } };
        0.2126 * f(c.0) + 0.7152 * f(c.1) + 0.0722 * f(c.2)
    };
    let (x, y) = (rel(a), rel(b));
    (x.max(y) + 0.05) / (x.min(y) + 0.05)
}

fn is_dark(c: Rgb) -> bool {
    lum(c) < 128.0
}

fn overlap(paper: Rgb) -> Mix {
    if is_dark(paper) { Mix::Screen } else { Mix::Multiply }
}

/// The picture a poster paints. Coordinates are in units of one pixel's height; a pixel is `1 / aspect` of that wide (a cell is
/// `aspect` times taller than wide and holds 2 x 2 pixels), so a circle drawn in units is round on the screen.
struct Canvas {
    pw: usize,
    ph: usize,
    aspect: f32,
    px: Vec<[f32; 3]>,
    clip: [f32; 4],
}

impl Canvas {
    fn new(cols: u16, rows: u16, aspect: f32, paper: Rgb) -> Self {
        let (pw, ph) = (cols as usize * 2, rows as usize * 2);
        Canvas { pw, ph, aspect, px: vec![shown(paper); pw * ph], clip: [f32::MIN, f32::MIN, f32::MAX, f32::MAX] }
    }

    fn size(&self) -> (f32, f32) {
        (self.pw as f32 / self.aspect, self.ph as f32)
    }

    /// The pixels inside `bbox` (x0, y0, x1, y1) and the clip whose centre `inside` accepts take the colour.
    fn fill(&mut self, bbox: [f32; 4], color: Rgb, mix: Mix, inside: impl Fn(f32, f32) -> bool) {
        let (x0, y0, x1, y1) = (bbox[0].max(self.clip[0]), bbox[1].max(self.clip[1]), bbox[2].min(self.clip[2]), bbox[3].min(self.clip[3]));
        if x1 <= x0 || y1 <= y0 {
            return;
        }
        let col = shown(color);
        let (i0, i1) = ((x0 * self.aspect).floor().max(0.0) as usize, ((x1 * self.aspect).ceil().max(0.0) as usize).min(self.pw));
        let (j0, j1) = (y0.floor().max(0.0) as usize, (y1.ceil().max(0.0) as usize).min(self.ph));
        for j in j0..j1 {
            let y = j as f32 + 0.5;
            if y < y0 || y >= y1 {
                continue;
            }
            for i in i0..i1 {
                let x = (i as f32 + 0.5) / self.aspect;
                if x < x0 || x >= x1 || !inside(x, y) {
                    continue;
                }
                let p = &mut self.px[j * self.pw + i];
                *p = match mix {
                    Mix::Paint => col,
                    Mix::Multiply => [0, 1, 2].map(|k| p[k] * col[k] / 255.0),
                    Mix::Screen => [0, 1, 2].map(|k| 255.0 - (255.0 - p[k]) * (255.0 - col[k]) / 255.0),
                };
            }
        }
    }

    /// A thin outline of an ellipse (`half` is half the line's width, in units).
    fn ring_line(&mut self, cx: f32, cy: f32, rx: f32, ry: f32, half: f32, color: Rgb) {
        let (rx, ry) = (rx.max(0.3), ry.max(0.3));
        let near = half / rx.min(ry);
        self.fill([cx - rx - half, cy - ry - half, cx + rx + half, cy + ry + half], color, Mix::Paint, |x, y| {
            let f = (((x - cx) / rx).powi(2) + ((y - cy) / ry).powi(2)).sqrt();
            (f - 1.0).abs() <= near
        });
    }

    /// A line one pixel wide (or `half * 2` units), vertical or horizontal.
    fn vline(&mut self, x: f32, y0: f32, y1: f32, half: f32, color: Rgb) {
        self.fill([x - half, y0, x + half, y1], color, Mix::Paint, |_, _| true);
    }

    fn hline(&mut self, x0: f32, x1: f32, y: f32, half: f32, color: Rgb) {
        self.fill([x0, y - half, x1, y + half], color, Mix::Paint, |_, _| true);
    }

    fn rect(&mut self, x: f32, y: f32, w: f32, h: f32, color: Rgb) {
        self.fill([x, y, x + w, y + h], color, Mix::Paint, |_, _| true);
    }

    fn disc(&mut self, cx: f32, cy: f32, r: f32, color: Rgb, mix: Mix) {
        self.fill([cx - r, cy - r, cx + r, cy + r], color, mix, |x, y| (x - cx).powi(2) + (y - cy).powi(2) <= r * r);
    }

    /// A column with a round top, from `top` down to `bottom`.
    fn arch(&mut self, x: f32, top: f32, w: f32, bottom: f32, color: Rgb) {
        let r = (w / 2.0).min(bottom - top).max(0.0);
        self.fill([x, top, x + w, bottom], color, Mix::Paint, |px, py| {
            py >= top + r || (px - px.max(x + r).min((x + w - r).max(x + r))).powi(2) + (py - (top + r)).powi(2) <= r * r
        });
    }

    fn image(&self) -> RgbImage {
        RgbImage::from_fn(self.pw as u32, self.ph as u32, |x, y| image::Rgb(self.px[y as usize * self.pw + x as usize].map(|v| v.round().clamp(0.0, 255.0) as u8)))
    }
}

/// A few words on top of the picture, in cells of the picture's area.
struct Label {
    col: u16,
    row: u16,
    text: String,
    fg: Rgb,
    bg: Rgb,
}

/// The colours a poster takes from the theme (the web's theme level 1): its accents stay its own.
struct Look {
    paper: Rgb,
    ink: Rgb,
    dark: Rgb,
}

const ORANGE: Rgb = (0xf2, 0x6a, 0x14);
const ORANGE_LIGHT: Rgb = (0xf5, 0x8a, 0x2a);
const ARCH: Rgb = (0xf4, 0x60, 0x1a);
const LENS_L: Rgb = (0xfb, 0x6a, 0x1c);
const LENS_R: Rgb = (0xee, 0x2e, 0x2e);
const GREEN: Rgb = (0x12, 0xa8, 0x4a);
const SLIDE_A: Rgb = (0xe8, 0x58, 0x1f);
const SLIDE_B: Rgb = (0xe3, 0x26, 0x2e);
const SLIDE_C: Rgb = (0xf5, 0xa8, 0x00);
const DIAL: Rgb = (0xe8, 0x37, 0x2c);
const PLANET: Rgb = (0xf0, 0x8a, 0x12);
const CREAM: Rgb = (0xf3, 0xe8, 0xc0);
const TUNNEL: Rgb = (0xff, 0x4a, 0x35);
const SUN: Rgb = (0xe2, 0x41, 0x2a);
const BLUE: Rgb = (0x1f, 0x63, 0xb0);
const RED: Rgb = (0xee, 0x2a, 0x24);
const FACET_GROUND: Rgb = (0xf2, 0x50, 0x0f);
const YELLOW: Rgb = (0xff, 0xd2, 0x1a);
const TEAL: Rgb = (0x1d, 0x6f, 0x8a);
const HEAD_RED: Rgb = (0xf0, 0x4a, 0x24);

impl Poster {
    /// The colour of the title line.
    fn accent(self) -> Rgb {
        match self {
            Poster::Pillars => ORANGE,
            Poster::Arches => ARCH,
            Poster::Lens => LENS_L,
            Poster::Fan => GREEN,
            Poster::Slides => SLIDE_A,
            Poster::Dials => DIAL,
            Poster::Rings => PLANET,
            Poster::Tunnel => TUNNEL,
            Poster::Lenses => SUN,
            Poster::Overcome => BLUE,
            Poster::Facets => (0x1a, 0x1a, 0x1a),
            Poster::Heads => HEAD_RED,
        }
    }

    /// The colour under the picture and the text.
    fn ground(self, k: &Look) -> Rgb {
        match self {
            Poster::Rings => k.dark,
            Poster::Facets => FACET_GROUND,
            _ => k.paper,
        }
    }

    /// Text above the picture (a book cover) or below it (a record sleeve).
    fn text_on_top(self) -> bool {
        matches!(self, Poster::Arches | Poster::Lens | Poster::Dials | Poster::Rings | Poster::Tunnel | Poster::Heads)
    }

    fn caps(self) -> bool {
        matches!(self, Poster::Pillars | Poster::Slides | Poster::Tunnel)
    }
}

fn pillars(cv: &mut Canvas, e: &[f32]) {
    let (u, v) = cv.size();
    let gap = (u * 0.01).max(1.0 / cv.aspect);
    let w = (u - gap * 6.0) / 7.0;
    for (i, e) in e.iter().enumerate() {
        let h = v * (0.35 + 0.65 * e);
        cv.arch(i as f32 * (w + gap), v - h, w, v, if i % 2 == 1 { ORANGE_LIGHT } else { ORANGE });
    }
}

/// The circle in the corner of Pillars swells with the lowest range; left out where the cell is too low for it.
fn pillars_corner(cv: &mut Canvas, e: &[f32], paper: Rgb) {
    let (u, v) = cv.size();
    if v < 12.0 {
        return;
    }
    let r = u.min(v) * 0.12 * (1.0 + 0.3 * e[0]);
    let (cx, cy) = (u - r * 1.3, r * 1.3);
    cv.disc(cx, cy, r + (r * 0.15).max(1.0), paper, Mix::Paint);
    cv.disc(cx, cy, r, ORANGE, Mix::Paint);
}

fn arches(cv: &mut Canvas, e: &[f32], k: &Look) {
    let (u, v) = cv.size();
    let aw = u / e.len() as f32;
    let gap = (aw * 0.03).max(1.0 / cv.aspect);
    for (i, e) in e.iter().enumerate() {
        let h = v * (0.22 + 0.78 * e);
        let (x, w) = (i as f32 * aw + gap / 2.0, aw - gap);
        let step = (w * 0.085).max(1.0);
        for (n, col) in [k.ink, ARCH, k.ink, ARCH, k.ink, k.paper].into_iter().enumerate() {
            let wk = w - 2.0 * n as f32 * step;
            if wk <= 0.0 {
                break;
            }
            cv.arch(x + n as f32 * step, v - h + n as f32 * step, wk, v, col);
        }
    }
}

fn lens(cv: &mut Canvas, st: &State, k: &Look, labels: &mut Vec<Label>, cols: u16, rows: u16) {
    let (u, v) = cv.size();
    let base = (u * 0.26).min(v * 0.36);
    let dx = base * (0.2 + (1.0 - st.corr) / 2.0 * 1.5);
    let mix = overlap(k.paper);
    cv.disc(u / 2.0 - dx, v / 2.0, base * (0.55 + st.l * 0.7), LENS_L, mix);
    cv.disc(u / 2.0 + dx, v / 2.0, base * (0.55 + st.r * 0.7), LENS_R, mix);
    if rows >= 6 && cols >= 24 {
        let corr = format!("{:+.2}", st.corr);
        let row = rows - 1;
        labels.push(Label { col: 1, row, text: "L".into(), fg: k.ink, bg: k.paper });
        labels.push(Label { col: cols - 2, row, text: "R".into(), fg: k.ink, bg: k.paper });
        labels.push(Label { col: (cols - corr.len() as u16) / 2, row, text: corr, fg: k.ink, bg: k.paper });
    }
}

/// Twelve rays from the top right corner. The web's sheet is stretched over the cell: rays stay rays.
fn fan(cv: &mut Canvas, e: &[f32], k: &Look) {
    let (u, v) = cv.size();
    let (ox, oy, hub) = (516.0f32, 60.0f32, 26.0f32);
    let (from, to) = (185.0f32, 72.0f32);
    let turn = (from - to) / e.len() as f32;
    let polar = move |x: f32, y: f32| {
        let (dx, dy) = (x / u * 600.0 - ox, y / v * 800.0 - oy);
        let a = dy.atan2(dx).to_degrees();
        (dx.hypot(dy), if a < 0.0 { a + 360.0 } else { a })
    };
    for (i, e) in e.iter().enumerate() {
        let (a0, length) = (from - i as f32 * turn, 800.0 * (0.3 + 0.6 * e));
        cv.fill([0.0, 0.0, u, v], if i % 2 == 0 { k.ink } else { GREEN }, Mix::Paint, |x, y| {
            let (r, a) = polar(x, y);
            r >= hub && r <= length && a <= a0 && a > a0 - turn
        });
    }
}

/// Four rows of half-discs, low at the top: a dome and a bowl slide towards each other with the energy and overlap.
fn slides(cv: &mut Canvas, e: &[f32], k: &Look) {
    let (u, v) = cv.size();
    let rows = e.len() as f32;
    let r = (v / rows).min(u / 4.0);
    let y0 = (v - rows * r) / 2.0;
    let span = u / 2.0 - r;
    for (n, e) in e.iter().enumerate() {
        let y = y0 + n as f32 * r;
        let ground = if n % 2 == 1 { k.paper } else { SLIDE_C };
        cv.rect(0.0, y, u, r, ground);
        let slide = e * span;
        let (dome, bowl) = if n % 2 == 0 { (r + slide, u - r - slide) } else { (u - r - slide, r + slide) };
        cv.clip = [0.0, y, u, y + r];
        cv.disc(dome, y + r, r, SLIDE_A, overlap(ground));
        cv.disc(bowl, y, r, SLIDE_B, overlap(ground));
        cv.clip = [f32::MIN, f32::MIN, f32::MAX, f32::MAX];
    }
}

/// Four gauges, each a circle filled along a diagonal: loudness, peak, correlation and the track's measured DR.
fn dials(cv: &mut Canvas, cx: &Ctx, k: &Look, labels: &mut Vec<Label>, cols: u16, rows: u16) {
    let (u, v) = cv.size();
    let st = &cx.m.poster;
    let across = if u >= 2.2 * v { 4 } else { 2 };
    let down = 4 / across;
    let (cw, ch) = (u / across as f32, v / down as f32);
    let r = cw.min(ch) * 0.43;
    let loud = cx.m.lufs.short_term.or(cx.m.lufs.momentary);
    let peak = cx.m.peak.0.max(cx.m.peak.1);
    let dr = cx.track.and_then(|t| t.truth.as_ref()).and_then(|t| t.dr);
    let items = [
        ("loudness", st.loud, loud.map_or("–".into(), |l| format!("{l:.1} LUFS"))),
        ("peak", st.peak, if peak > -90.0 { format!("{peak:.1} dB") } else { "–".into() }),
        ("correlation", st.dial_corr, format!("{:+.2}", cx.m.corr)),
        ("dynamic range", dr.map_or(0.0, |d| ((d as f32 - 4.0) / 16.0).clamp(0.0, 1.0)), dr.map_or("–".into(), |d| format!("DR{d}"))),
    ];
    for (i, (label, value, text)) in items.into_iter().enumerate() {
        let (ix, iy) = (i % across, i / across);
        let (x0, y0) = (ix as f32 * cw, iy as f32 * ch);
        let dark = (ix + iy) % 2 == 0;
        let (ground, face) = if dark { (k.ink, DIAL) } else { (DIAL, k.ink) };
        cv.rect(x0, y0, cw, ch, ground);
        let (mx, my) = (x0 + cw / 2.0, y0 + ch / 2.0);
        cv.disc(mx, my, r, face, Mix::Paint);
        let edge = (1.0 - 2.0 * value.clamp(0.0, 1.0)) * r;
        cv.fill([mx - r, my - r, mx + r, my + r], k.paper, Mix::Paint, |x, y| {
            let (dx, dy) = (x - mx, y - my);
            dx * dx + dy * dy <= r * r && (dx + dy) / std::f32::consts::SQRT_2 >= edge
        });
        // the cells of this tile: the label in its top left corner, the reading in its bottom left
        let (c0, c1) = ((ix as u16 * cols) / across as u16, ((ix as u16 + 1) * cols) / across as u16);
        let (r0, r1) = ((iy as u16 * rows) / down as u16, ((iy as u16 + 1) * rows) / down as u16);
        let room = (c1 - c0).saturating_sub(2) as usize;
        if r1 - r0 >= 8 && room >= 8 {
            let cut = |s: &str| s.chars().take(room).collect::<String>();
            labels.push(Label { col: c0 + 1, row: r0, text: cut(label), fg: k.paper, bg: ground });
            labels.push(Label { col: c0 + 1, row: r1 - 1, text: cut(&text), fg: k.paper, bg: ground });
        }
    }
}

/// A planet on a dark sky; nine curved stripes pass behind and in front of it, each thicker with the energy of its range.
fn rings(cv: &mut Canvas, e: &[f32], k: &Look) {
    let (u, v) = cv.size();
    cv.rect(0.0, 0.0, u, v, k.dark);
    let stripe = if (lum(CREAM) - lum(k.dark)).abs() >= (lum(k.ink) - lum(k.dark)).abs() { CREAM } else { k.ink };
    let level = e.iter().sum::<f32>() / e.len() as f32;
    let (cx, cy) = (if u > v { u * 0.3 } else { u * 0.1 }, v * 0.7);
    let radius = (u * 0.46).min(v * 0.36) * (0.96 + 0.08 * level);
    const TILT: f32 = 0.42;
    // nine stripes where they stay apart on the near side (two pixels each), else five, each the louder of its two ranges
    let n = if radius * 0.16 * TILT >= 2.0 { e.len() } else { e.len().div_ceil(2) };
    let step = e.len().div_ceil(n);
    let e: Vec<f32> = e.chunks(step).map(|c| c.iter().cloned().fold(0.0, f32::max)).collect();
    let spacing = radius * 1.44 / n as f32;
    let (sin, cos) = 0.4f32.sin_cos();
    // the stripes are circles on one tilted disc, seen at an angle: a point's own circle is the one through it, so the stripes
    // narrow together towards the near and far side and never run into each other
    let ring = |cv: &mut Canvas, i: usize, front: bool| {
        let rx = radius * 1.3 + i as f32 * spacing;
        let half = spacing * (0.15 + 0.5 * e[i]) / 2.0;
        cv.fill([cx - rx - half, cy - rx, cx + rx + half, cy + rx], stripe, Mix::Paint, |x, y| {
            let (dx, dy) = (x - cx, y - cy);
            let (lx, ly) = (dx * cos - dy * sin, dx * sin + dy * cos);
            (ly >= 0.0) == front && (lx.hypot(ly / TILT) - rx).abs() <= half
        });
    };
    for i in 0..e.len() {
        ring(cv, i, false);
    }
    cv.disc(cx, cy, radius, PLANET, Mix::Paint);
    for i in 0..e.len() {
        ring(cv, i, true);
    }
}


/// Seven circles hang from one top edge; the gap between two is a frequency range and widens with its energy. Beside them a
/// small spectrum and a globe that turns faster with the level. One colour of line on a flat ground.
fn tunnel(cv: &mut Canvas, st: &State, ground_line: Rgb) {
    let (u, v) = cv.size();
    let e = &st.tunnel.v;
    let max_d = (v * 0.94).min(u * 0.7);
    let (cx, top) = (u * 0.4, v * 0.03);
    let weight: Vec<f32> = e.iter().enumerate().map(|(j, v)| (0.55 + 1.1 * (v * 1.5).min(1.0)) * (1.0 + j as f32 * 0.28)).collect();
    let total: f32 = weight.iter().sum();
    let mut acc = 0.0;
    for (j, w) in weight.iter().enumerate() {
        acc += w;
        let d = max_d * (0.2 + 0.8 * acc / total);
        cv.ring_line(cx, top + d / 2.0, d / 2.0, d / 2.0, 0.5 + 0.5 * (e[j] * 1.4).min(1.0), ground_line);
    }
    // the spectrum: bars standing on a middle line, taller to the right
    let (x0, x1, mid) = (u * 0.74, u * 0.97, v * 0.18);
    let n = st.spectrum.v.len();
    for (i, s) in st.spectrum.v.iter().enumerate() {
        let h = 2.0 + s * v * 0.3 * (0.35 + 0.65 * i as f32 / n as f32);
        cv.vline(x0 + (x1 - x0) * i as f32 / (n - 1) as f32, mid - h / 2.0, mid + h / 2.0, 0.5 / cv.aspect, ground_line);
    }
    // the globe: a rim, four meridians that turn, three latitudes
    let (gx, gy, gr) = (u * 0.855, v * 0.6, (u * 0.1).min(v * 0.14));
    let half = 0.5;
    cv.ring_line(gx, gy, gr, gr, half, ground_line);
    for i in 0..4 {
        cv.ring_line(gx, gy, (st.spin + i as f32 * std::f32::consts::FRAC_PI_4).cos().abs() * gr, gr, half, ground_line);
    }
    for f in [-0.5f32, 0.0, 0.5] {
        let w = (1.0 - f * f).sqrt() * gr;
        cv.hline(gx - w, gx + w, gy + f * gr, half, ground_line);
    }
}

/// A dark panel with a red sun and a fine line per band group; each line swells into a cream lens where its band has energy,
/// and the lenses drift slowly sideways.
fn lenses(cv: &mut Canvas, st: &State, k: &Look) {
    let (u, v) = cv.size();
    cv.rect(0.0, 0.0, u, v, k.dark);
    let r = u * 0.78;
    cv.clip = [0.0, 0.0, u, v];
    cv.disc(u / 2.0, v * 0.42 + r, r, SUN, Mix::Paint);
    let e = &st.lenses.v;
    let (first, last) = (v * 0.6, v - 1.0);
    let gap = (last - first) / e.len() as f32;
    let line = if lum(CREAM) > lum(k.dark) { CREAM } else { k.ink };
    for (j, e) in e.iter().enumerate() {
        let y = first + (j as f32 + 0.5) * gap;
        cv.hline(0.0, u, y, 0.5, line);
        let lx = u * (0.5 + 0.36 * (st.t * 0.13 * (1.0 + j as f32 * 0.17) + j as f32 * 1.9).sin());
        let half = u * (0.07 + 0.17 * e);
        let lift = gap * (0.1 + e * 0.8);
        cv.fill([lx - half, y - lift, lx + half, y + lift], line, Mix::Paint, |x, py| {
            let t = (x - lx) / half;
            (py - y).abs() <= lift * (1.0 - t * t).powi(2)
        });
    }
}

/// One hairline per third-octave band, as tall as its energy; two circles ride the channels' peaks and clear a halo in the lines.
fn overcome(cv: &mut Canvas, st: &State, k: &Look) {
    let (u, v) = cv.size();
    let (x0, x1, y0, y1) = (u * 0.1, u * 0.89, v * 0.087, v * 0.915);
    let n = st.over.v.len();
    let step = (x1 - x0) / (n - 1) as f32;
    let half = if step * cv.aspect >= 3.0 { 1.0 } else { 0.5 } / cv.aspect;
    for (i, e) in st.over.v.iter().enumerate() {
        let top = y0 + (1.0 - (0.7 + 0.3 * e)) * (y1 - y0);
        cv.vline(x0 + i as f32 * step, top, y1, half, k.ink);
    }
    let r = (u * 0.075).min(v * 0.12);
    for (col, at, level) in [(BLUE, 0.2 + 0.06 * (st.t * 0.2).sin(), st.over_l), (RED, 0.78 + 0.06 * (st.t * 0.17 + 2.0).sin(), st.over_r)] {
        let x = x0 + (x1 - x0) * at;
        let y = y1 - r - 1.0 - level * (y1 - y0 - 2.0 * r - 2.0);
        cv.disc(x, y, r + (r * 0.15).max(1.0), k.paper, Mix::Paint);
        cv.disc(x, y, r, col, Mix::Paint);
    }
}

/// A grid of triangles that scrolls like a coarse spectrogram: time runs to the left, low frequencies at the bottom; a cell is
/// paper, yellow or a full yellow square.
fn facets(cv: &mut Canvas, st: &State, k: &Look) {
    let (u, v) = cv.size();
    let cols = st.history.len();
    let cell = u / cols as f32;
    let rows = ((v / cell).floor() as usize).max(1);
    let shift = (v - rows as f32 * cell) / 2.0;
    for (c, col) in st.history.iter().enumerate() {
        for r in 0..rows {
            let value = col[(r * col.len() / rows).min(col.len() - 1)];
            let (x, y) = (c as f32 * cell, shift + (rows - 1 - r) as f32 * cell);
            if value > 0.82 {
                cv.rect(x, y, cell, cell, YELLOW);
                continue;
            }
            let upper = if value < 0.4 { k.paper } else { YELLOW };
            cv.fill([x, y, x + cell, y + cell], upper, Mix::Paint, |px, py| (px - x) + (py - y) <= cell);
            if value > 0.6 {
                cv.fill([x, y, x + cell, y + cell], YELLOW, Mix::Paint, |px, py| (px - x) + (py - y) > cell);
            }
        }
    }
}

/// One head in profile, facing right, in a 100 x 110 box (the web's outline, its curves cut into short lines).
fn head_outline() -> &'static [(f32, f32)] {
    static H: OnceLock<Vec<(f32, f32)>> = OnceLock::new();
    H.get_or_init(|| {
        let bez = |p: [(f32, f32); 4], out: &mut Vec<(f32, f32)>| {
            for i in 1..=12 {
                let t = i as f32 / 12.0;
                let m = 1.0 - t;
                let w = [m * m * m, 3.0 * m * m * t, 3.0 * m * t * t, t * t * t];
                out.push((0..4).fold((0.0, 0.0), |a, k| (a.0 + w[k] * p[k].0, a.1 + w[k] * p[k].1)));
            }
        };
        let mut o = vec![(10.0, 110.0), (12.0, 78.0)];
        bez([(12.0, 78.0), (-4.0, 62.0), (0.0, 18.0), (40.0, 6.0)], &mut o);
        bez([(40.0, 6.0), (72.0, -4.0), (98.0, 26.0), (92.0, 52.0)], &mut o);
        o.extend([(100.0, 66.0), (92.0, 72.0), (95.0, 82.0), (86.0, 90.0), (88.0, 100.0), (66.0, 104.0), (66.0, 110.0)]);
        o
    })
}

fn inside(poly: &[(f32, f32)], x: f32, y: f32) -> bool {
    let mut on = false;
    for i in 0..poly.len() {
        let ((ax, ay), (bx, by)) = (poly[i], poly[(i + 1) % poly.len()]);
        if (ay > y) != (by > y) && x < ax + (y - ay) / (by - ay) * (bx - ax) {
            on = !on;
        }
    }
    on
}

/// Sixteen heads, one per range, each swelling with its energy; the one in red is the loudest range right now.
fn heads(cv: &mut Canvas, st: &State) {
    let (u, v) = cv.size();
    let (cw, ch) = (u / 4.0, v / 4.0);
    let scale = (cw * 0.78 / 100.0).min(ch * 0.95 / 110.0);
    let outline = head_outline();
    for (i, e) in st.heads.v.iter().enumerate() {
        let grow = scale * (0.88 + 0.12 * (e * 1.6).min(1.0));
        let (ox, oy) = ((i % 4) as f32 * cw + cw / 2.0 - 50.0 * grow, (i / 4) as f32 * ch + ch / 2.0 - 55.0 * grow);
        cv.fill([ox, oy, ox + 100.0 * grow, oy + 110.0 * grow], if i == st.hot { HEAD_RED } else { TEAL }, Mix::Paint, |x, y| inside(outline, (x - ox) / grow, (y - oy) / grow));
    }
}

fn clock(seconds: f64) -> String {
    format!("{}:{:02}", (seconds / 60.0) as u64, (seconds % 60.0) as u64)
}

/// The lines a poster prints for the track: the name (the artist, or the title when there is none), the title, the small print.
fn words(track: Option<&Track>) -> (String, String, String) {
    let Some(t) = track else { return ("magnetite".into(), String::new(), String::new()) };
    let artist = t.artist.clone().filter(|a| !a.is_empty());
    let title = if artist.is_some() { t.title.clone() } else { String::new() };
    let truth = t.truth.as_ref();
    let khz = truth.and_then(|x| x.sample_rate).map(|r| format!("{} kHz", (r / 100.0).round() / 10.0));
    let bits = truth.and_then(|x| x.declared_bits.or(x.bits)).map(|b| format!("{b}-bit"));
    let depth = match (bits, khz) {
        (Some(b), Some(k)) => Some(format!("{b} / {k}")),
        (b, k) => k.or(b),
    };
    let small = [t.album.clone(), t.codec.as_ref().map(|c| c.to_uppercase()), depth, t.duration.filter(|d| *d > 0.0).map(clock)];
    (artist.unwrap_or_else(|| t.title.clone()), title, small.into_iter().flatten().filter(|s| !s.is_empty()).collect::<Vec<_>>().join(" · "))
}

/// Flat colours need no help: every cell takes its best two, nothing is spread to the neighbours.
const FLAT: Tuning = Tuning { spread: 0.0, split_gain: 1.0, split_min: 0.0, split_from_source: false };

pub fn draw(f: &mut Frame, area: Rect, poster: Poster, cx: &Ctx) {
    if area.width < 4 || area.height < 1 {
        return;
    }
    let k = Look { paper: cx.th.bg, ink: cx.th.text, dark: cx.th.surface };
    // text only where there is room to read it and still a picture: three lines in a tall cell, two in a lower one, none in
    // the low cells of the dock (the track is named right above them)
    let lines = if area.width < 24 { 0 } else if area.height >= 14 { 3 } else if area.height >= 10 { 2 } else { 0 };
    let (art, text) = if poster.text_on_top() {
        (Rect::new(area.x, area.y + lines, area.width, area.height - lines), Rect::new(area.x, area.y, area.width, lines))
    } else {
        (Rect::new(area.x, area.y, area.width, area.height - lines), Rect::new(area.x, area.y + area.height - lines, area.width, lines))
    };

    let ground = poster.ground(&k);
    // the tunnel is one colour of line: its red on a dark theme, the theme's text on a light one
    let ground_line = if contrast(TUNNEL, ground) >= 2.5 { TUNNEL } else { k.ink };
    let mut cv = Canvas::new(art.width, art.height, cover::cell_aspect(), ground);
    let mut labels = vec![];
    let st = &cx.m.poster;
    match poster {
        Poster::Pillars => {
            pillars(&mut cv, &st.pillars.v);
            pillars_corner(&mut cv, &st.pillars.v, k.paper);
        }
        Poster::Arches => arches(&mut cv, &st.arches.v, &k),
        Poster::Lens => lens(&mut cv, st, &k, &mut labels, art.width, art.height),
        Poster::Fan => fan(&mut cv, &st.fan.v, &k),
        Poster::Slides => slides(&mut cv, &st.slides.v, &k),
        Poster::Dials => dials(&mut cv, cx, &k, &mut labels, art.width, art.height),
        Poster::Rings => rings(&mut cv, &st.rings.v, &k),
        Poster::Tunnel => tunnel(&mut cv, st, ground_line),
        Poster::Lenses => lenses(&mut cv, st, &k),
        Poster::Overcome => overcome(&mut cv, st, &k),
        Poster::Facets => facets(&mut cv, st, &k),
        Poster::Heads => heads(&mut cv, st),
    }

    let cells = blocks::render_with(&cv.image(), Shape::Quadrant, !truecolor(), FLAT);
    let rows: Vec<Line> = cells.iter().map(|row| Line::from(row.iter().map(|k| Span::styled(k.ch.to_string(), Style::new().fg(k.fg).bg(k.bg))).collect::<Vec<_>>())).collect();
    f.render_widget(Paragraph::new(rows), art);
    for l in labels {
        let w = (l.text.chars().count() as u16).min(art.width.saturating_sub(l.col));
        if w > 0 && l.row < art.height {
            f.render_widget(Paragraph::new(l.text).style(Style::new().fg(c(l.fg)).bg(c(l.bg))), Rect::new(art.x + l.col, art.y + l.row, w, 1));
        }
    }

    if lines > 0 {
        // the theme's text colour where it reads on this ground, else the best of a few
        let ink = [k.ink, CREAM, (0x1a, 0x1a, 0x1a), k.paper].into_iter().find(|c| contrast(*c, ground) >= 4.5).unwrap_or_else(|| if lum(ground) < 128.0 { CREAM } else { (0x1a, 0x1a, 0x1a) });
        let accent = if contrast(poster.accent(), ground) >= 2.5 { poster.accent() } else { ink };
        let (name, title, small) = words(cx.track);
        let name = if poster.caps() { name.to_uppercase() } else { name };
        let quiet = lerp(ink, ground, 0.35);
        let mut out = vec![Line::from(Span::styled(format!(" {name}"), Style::new().fg(c(ink)).add_modifier(Modifier::BOLD)))];
        if lines >= 3 {
            out.push(Line::from(Span::styled(format!(" {title}"), Style::new().fg(c(accent)))));
            out.push(Line::from(Span::styled(format!(" {small}"), Style::new().fg(c(quiet)))));
        } else {
            let sep = if title.is_empty() || small.is_empty() { "" } else { " · " };
            out.push(Line::from(vec![Span::styled(format!(" {title}"), Style::new().fg(c(accent))), Span::styled(format!("{sep}{small}"), Style::new().fg(c(quiet)))]));
        }
        f.render_widget(Paragraph::new(out).style(Style::new().bg(c(ground))), text);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn bands(loud: std::ops::Range<usize>, level: f32) -> [f32; THIRD] {
        let mut b = [0.0; THIRD];
        b[loud].iter_mut().for_each(|v| *v = level);
        b
    }

    #[test]
    fn a_group_rises_with_its_own_range_and_falls_back_in_silence() {
        let mut bars = Bars::new(7, 0.04, 0.35);
        let low = bands(0..4, 0.8); // only the lowest bands sound
        let db = low.map(|v: f32| if v <= 0.0 { -120.0 } else { v * 80.0 - 80.0 });
        for _ in 0..30 {
            bars.update(&db, 1.0 / 30.0);
        }
        assert!(bars.v[0] > 0.9, "the lowest group is full: {:?}", bars.v);
        assert!(bars.v[3..].iter().all(|v| *v < 0.01), "the others stay down: {:?}", bars.v);
        for _ in 0..60 {
            bars.update(&[-120.0; THIRD], 1.0 / 30.0);
        }
        assert!(bars.v[0] < 0.01);
    }

    #[test]
    fn a_quiet_and_a_loud_recording_move_alike() {
        let run = |level: f32| {
            let mut st = State::new();
            for _ in 0..90 {
                st.update(&bands(8..12, level), (-90.0, -90.0), 0.0, None, (-90.0, -90.0), true, 1.0 / 30.0);
            }
            st.pillars.v.iter().cloned().fold(0.0, f32::max)
        };
        assert!((run(0.9) - run(0.5)).abs() < 0.05, "loud {} quiet {}", run(0.9), run(0.5));
    }

    #[test]
    fn silence_draws_nothing_on_the_level_shapes() {
        let mut st = State::new();
        for _ in 0..300 {
            st.update(&[0.0; THIRD], (-90.0, -90.0), 0.0, None, (-90.0, -90.0), true, 0.1);
        }
        assert!(st.l < 0.01 && st.r < 0.01 && st.peak < 0.01 && st.pillars.v.iter().all(|v| *v < 0.01));
    }

    #[test]
    fn a_disc_is_round_on_the_screen_whatever_the_cell_shape() {
        for aspect in [2.0f32, 2.14] {
            let mut cv = Canvas::new(40, 20, aspect, (0, 0, 0));
            let (u, v) = cv.size();
            cv.disc(u / 2.0, v / 2.0, 10.0, (255, 255, 255), Mix::Paint);
            let lit = |x: usize, y: usize| cv.px[y * cv.pw + x][0] > 0.0;
            let wide = (0..cv.pw).filter(|x| lit(*x, cv.ph / 2)).count() as f32 / aspect;
            let tall = (0..cv.ph).filter(|y| lit(cv.pw / 2, *y)).count() as f32;
            assert!((wide - tall).abs() <= 1.5, "aspect {aspect}: {wide} wide, {tall} tall");
        }
    }

    #[test]
    fn overlaps_darken_on_light_paper_and_lighten_on_dark() {
        let mut cv = Canvas::new(2, 2, 2.0, (200, 200, 200));
        cv.fill([0.0, 0.0, 9.0, 9.0], (128, 128, 128), overlap((200, 200, 200)), |_, _| true);
        assert!(cv.px[0][0] < 128.0);
        let mut cv = Canvas::new(2, 2, 2.0, (40, 40, 40));
        cv.fill([0.0, 0.0, 9.0, 9.0], (128, 128, 128), overlap((40, 40, 40)), |_, _| true);
        assert!(cv.px[0][0] > 128.0);
    }

    #[test]
    fn the_words_are_the_artist_the_title_and_the_small_print() {
        let t: Track = serde_json::from_value(serde_json::json!({
            "id": 1, "title": "So What", "artist": "Miles Davis", "album": "Kind of Blue", "duration": 562.0, "codec": "flac", "open": true,
            "truth": { "sample_rate": 44100.0, "bits": 16.0 }
        }))
        .unwrap();
        assert_eq!(words(Some(&t)), ("Miles Davis".into(), "So What".into(), "Kind of Blue · FLAC · 16-bit / 44.1 kHz · 9:22".into()));
        let mut alone = t.clone();
        alone.artist = None;
        assert_eq!(words(Some(&alone)).0, "So What");
        assert_eq!(words(Some(&alone)).1, "");
        assert_eq!(words(None).0, "magnetite");
    }
}

/// `MAGNETITE_PREVIEW_OUT=dir cargo test posters::preview -- --ignored`: every poster as the terminal shows it, as PNG files.
#[cfg(test)]
mod preview {
    use super::*;
    use crate::{meters::Meters, panels::Kind};
    use ratatui::{backend::TestBackend, style::Color, Terminal};

    fn rgb(c: Color, th: &crate::theme::Theme) -> [u8; 3] {
        match c {
            Color::Rgb(r, g, b) => [r, g, b],
            Color::Indexed(_) => { let (r, g, b) = blocks::rgb_of(c); [r, g, b] }
            _ => [th.bg.0, th.bg.1, th.bg.2],
        }
    }

    #[test]
    #[ignore]
    fn show() {
        let out = std::env::var("MAGNETITE_PREVIEW_OUT").unwrap();
        let t: Track = serde_json::from_value(serde_json::json!({
            "id": 1, "title": "So What", "artist": "Miles Davis", "album": "Kind of Blue", "duration": 562.0, "codec": "flac", "open": true,
            "truth": { "sample_rate": 44100.0, "bits": 16.0, "dr": 12.0 }
        }))
        .unwrap();
        let mut m = Meters::new();
        // a canned spectrum: strong lows, a dip, some highs
        for (i, b) in m.third.iter_mut().enumerate() {
            *b = (0.75 - 0.012 * i as f32 + 0.18 * (i as f32 * 0.9).sin()).clamp(0.05, 1.0);
        }
        m.rms = (-16.0, -19.0);
        m.peak = (-4.0, -6.0);
        m.corr = 0.55;
        for _ in 0..60 {
            let (third, rms, corr, peak) = (m.third, m.rms, m.corr, m.peak);
            m.poster.update(&third, rms, corr, Some(-14.0), peak, true, 1.0 / 30.0);
        }
        let glyphs = [' ', '▘', '▝', '▀', '▖', '▌', '▞', '▛', '▗', '▚', '▐', '▜', '▄', '▙', '▟', '█'];
        for th in crate::theme::builtin().into_iter().take(3) {
            for kind in Kind::ALL.into_iter().filter(|k| k.poster().is_some()) {
                for (w, h) in [(70u16, 40u16), (120, 34), (56, 9)] {
                    let mut term = Terminal::new(TestBackend::new(w, h)).unwrap();
                    term.draw(|f| crate::panels::draw(f, Rect::new(0, 0, w, h), kind, &Ctx { m: &m, tap: &[], rate: 48000, th: &th, track: Some(&t) })).unwrap();
                    let b = term.backend().buffer();
                    // a cell is drawn 8 x 16; letters are a small mark in the cell, enough to see where text sits
                    let mut img = RgbImage::new(w as u32 * 8, h as u32 * 16);
                    for y in 0..h {
                        for x in 0..w {
                            let cell = &b[(x, y)];
                            let (fg, bg) = (rgb(cell.fg, &th), rgb(cell.bg, &th));
                            let ch = cell.symbol().chars().next().unwrap_or(' ');
                            let mask = glyphs.iter().position(|g| *g == ch);
                            for py in 0..16u32 {
                                for px in 0..8u32 {
                                    let on = match mask {
                                        Some(m) => (m >> ((py / 8) * 2 + px / 4)) & 1 == 1,
                                        None => (2..6).contains(&px) && (5..12).contains(&py),
                                    };
                                    img.put_pixel(x as u32 * 8 + px, y as u32 * 16 + py, image::Rgb(if on { fg } else { bg }));
                                }
                            }
                        }
                    }
                    img.save(format!("{out}/{}-{}-{w}x{h}.png", th.id, kind.id())).unwrap();
                }
            }
        }
    }
}
