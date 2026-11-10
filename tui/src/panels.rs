//! The meters as text: one function per kind, drawn into the cell a panel is given (the contract of the web visualizers:
//! use only the width and height you get).
use crate::{
    meters::{Meters, BANDS, THIRD},
    theme::{c, lerp, Rgb, Theme},
};
use ratatui::{
    layout::Rect,
    style::Style,
    text::{Line, Span},
    widgets::Paragraph,
    Frame,
};

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Kind {
    VuWide,
    VuLeds,
    Spectrum,
    ThirdOctave,
    Spectrogram,
    Scope,
    Goniometer,
    Loudness,
    VuNeedle,
    MagicEye,
}

impl Kind {
    pub const ALL: [Kind; 10] = [Kind::VuWide, Kind::VuLeds, Kind::VuNeedle, Kind::MagicEye, Kind::Spectrum, Kind::ThirdOctave, Kind::Spectrogram, Kind::Scope, Kind::Goniometer, Kind::Loudness];

    /// The ids are the web's where a meter exists there (`spectrum` is terminal-only).
    pub fn id(self) -> &'static str {
        match self {
            Kind::VuWide => "vu-wide",
            Kind::VuLeds => "vu-leds",
            Kind::Spectrum => "spectrum",
            Kind::ThirdOctave => "third-octave",
            Kind::Spectrogram => "spectrogram",
            Kind::Scope => "scope",
            Kind::Goniometer => "goniometer",
            Kind::Loudness => "loudness",
            Kind::VuNeedle => "vu-needle",
            Kind::MagicEye => "magic-eye",
        }
    }

    pub fn label(self) -> &'static str {
        match self {
            Kind::VuWide => "Wide meters",
            Kind::VuLeds => "LED meters",
            Kind::Spectrum => "Spectrum",
            Kind::ThirdOctave => "Third-octave",
            Kind::Spectrogram => "Spectrogram",
            Kind::Scope => "Scope",
            Kind::Goniometer => "Goniometer",
            Kind::Loudness => "Loudness (LUFS)",
            Kind::VuNeedle => "VU needles",
            Kind::MagicEye => "Magic eyes",
        }
    }

    pub fn from_id(id: &str) -> Option<Kind> {
        Kind::ALL.into_iter().find(|k| k.id() == id)
    }
}

pub struct Ctx<'a> {
    pub m: &'a Meters,
    pub tap: &'a [f32], // interleaved stereo, newest last
    pub rate: u32,
    pub th: &'a Theme,
}

pub fn draw(f: &mut Frame, area: Rect, kind: Kind, cx: &Ctx) {
    if area.width < 4 || area.height < 1 {
        return;
    }
    let lines = match kind {
        Kind::VuWide => vu_wide(cx, area),
        Kind::VuLeds => vu(cx, area, true),
        Kind::Spectrum => block_bars(&cx.m.bands, area, cx.th),
        Kind::ThirdOctave => block_bars(&cx.m.third, area, cx.th),
        Kind::Spectrogram => spectrogram(cx, area),
        Kind::Scope => scope(cx, area),
        Kind::Goniometer => goniometer(cx, area),
        Kind::Loudness => loudness(cx, area),
        Kind::VuNeedle => needles(cx, area),
        Kind::MagicEye => eyes(cx, area),
    };
    f.render_widget(Paragraph::new(lines), area);
}

const BLOCKS: [char; 9] = [' ', '▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

fn pad_to(w: usize, used: usize) -> Span<'static> {
    Span::raw(" ".repeat(w.saturating_sub(used) / 2))
}

/// Vertical bars with eighth-block resolution; accent at the bottom, accent2 at the top.
fn block_bars(vals: &[f32], area: Rect, th: &Theme) -> Vec<Line<'static>> {
    let (w, h) = (area.width as usize, area.height as usize);
    let bw = (w / vals.len()).max(1);
    (0..h)
        .map(|r| {
            let row = h - 1 - r;
            let s: String = vals
                .iter()
                .map(|v| {
                    let level = ((v * h as f32 * 8.0) as i32 - row as i32 * 8).clamp(0, 8) as usize;
                    BLOCKS[level].to_string().repeat(bw)
                })
                .collect();
            let color = c(lerp(th.accent, th.accent2, row as f32 / (h.max(2) - 1) as f32));
            Line::from(vec![pad_to(w, vals.len() * bw), Span::styled(s, Style::new().fg(color))])
        })
        .collect()
}

/// Two level bars (L and R) with peak hold: accent, accent2 from -12 dB, red above -3 dB. `segmented` draws LED segments.
fn vu(cx: &Ctx, area: Rect, segmented: bool) -> Vec<Line<'static>> {
    let (th, m) = (cx.th, cx.m);
    let (muted, hot) = (c(th.muted), c((0xff, 0x4d, 0x4d)));
    let w = (area.width as usize).saturating_sub(16).max(4);
    let bar = |label: &str, rms: f32, peak: f32| {
        let cell = |db: f32| (((db + 60.0) / 60.0).clamp(0.0, 1.0) * w as f32) as usize;
        let (n, p) = (cell(rms), cell(peak).min(w - 1));
        let mut spans = vec![Span::raw(format!("{label} "))];
        for i in 0..w {
            let db = i as f32 / w as f32 * 60.0 - 60.0;
            let color = if db > -3.0 { hot } else if db > -12.0 { c(th.accent2) } else { c(th.accent) };
            spans.push(if segmented {
                if i % 2 == 1 {
                    Span::raw(" ")
                } else if i < n || (i == p && p >= n) {
                    Span::styled("▮", Style::new().fg(color))
                } else {
                    Span::styled("▯", Style::new().fg(muted))
                }
            } else if i == p && p >= n {
                Span::styled("▎", Style::new().fg(color))
            } else if i < n {
                Span::styled("█", Style::new().fg(color))
            } else {
                Span::styled("░", Style::new().fg(muted))
            });
        }
        spans.push(Span::raw(format!(" {rms:>6.1} dB")));
        Line::from(spans)
    };
    let mut lines = vec![Line::raw(""); (area.height as usize).saturating_sub(2) / 2];
    lines.push(bar("L", m.rms.0, m.peak.0));
    lines.push(bar("R", m.rms.1, m.peak.1));
    lines
}

fn ramp(th: &Theme, v: f32) -> Rgb {
    if v < 0.5 { lerp(th.surface, th.accent, v * 2.0) } else { lerp(th.accent, th.accent2, (v - 0.5) * 2.0) }
}

/// Frequency up, time to the right; two pixel rows per cell with half blocks.
fn spectrogram(cx: &Ctx, area: Rect) -> Vec<Line<'static>> {
    let (w, h) = (area.width as usize, area.height as usize);
    let hist = &cx.m.history;
    (0..h)
        .map(|r| {
            let (top, bottom) = ((h - r) * 2 - 1, (h - r) * 2 - 2); // pixel rows from the bottom
            let band = |p: usize| (p * THIRD / (h * 2)).min(THIRD - 1);
            let spans: Vec<Span> = (0..w)
                .map(|x| {
                    let col = (hist.len() + x).checked_sub(w).and_then(|i| hist.get(i));
                    match col {
                        Some(v) => Span::styled("▀", Style::new().fg(c(ramp(cx.th, v[band(top)]))).bg(c(ramp(cx.th, v[band(bottom)])))),
                        None => Span::raw(" "),
                    }
                })
                .collect();
            Line::from(spans)
        })
        .collect()
}

/// A braille canvas: 2 x 4 dots per cell.
struct Canvas {
    w: usize,
    h: usize,
    bits: Vec<u8>,
    col: Vec<Option<ratatui::style::Color>>,
    glyph: Vec<Option<char>>,
    bg: Vec<Option<ratatui::style::Color>>,
}

impl Canvas {
    fn new(area: Rect) -> Self {
        let (w, h) = (area.width as usize, area.height as usize);
        Canvas { w, h, bits: vec![0; w * h], col: vec![None; w * h], glyph: vec![None; w * h], bg: vec![None; w * h] }
    }
    fn size(&self) -> (i32, i32) {
        (self.w as i32 * 2, self.h as i32 * 4)
    }
    fn set(&mut self, x: i32, y: i32) {
        let (pw, ph) = self.size();
        if x < 0 || y < 0 || x >= pw || y >= ph {
            return;
        }
        const MAP: [[u8; 4]; 2] = [[0x01, 0x02, 0x04, 0x40], [0x08, 0x10, 0x20, 0x80]];
        self.bits[(y as usize / 4) * self.w + x as usize / 2] |= MAP[x as usize % 2][y as usize % 4];
    }
    /// Like `set`, and the cell takes this colour (the last one drawn into a cell wins).
    fn set_c(&mut self, x: i32, y: i32, color: ratatui::style::Color) {
        let (pw, ph) = self.size();
        if x >= 0 && y >= 0 && x < pw && y < ph {
            self.col[(y as usize / 4) * self.w + x as usize / 2] = Some(color);
        }
        self.set(x, y);
    }
    /// Background colour for the cells whose centre lies inside the box (dot coordinates).
    fn fill(&mut self, x: i32, y: i32, w: i32, h: i32, color: ratatui::style::Color) {
        for cy in 0..self.h {
            for cx in 0..self.w {
                let (mx, my) = (cx as i32 * 2 + 1, cy as i32 * 4 + 2);
                if mx >= x && mx < x + w && my >= y && my < y + h {
                    self.bg[cy * self.w + cx] = Some(color);
                }
            }
        }
    }
    /// A letter in a cell, in place of its dots.
    fn put(&mut self, cx: usize, cy: usize, ch: char) {
        if cx < self.w && cy < self.h {
            self.glyph[cy * self.w + cx] = Some(ch);
        }
    }
    fn lines(&self, color: ratatui::style::Color) -> Vec<Line<'static>> {
        (0..self.h)
            .map(|y| {
                Line::from(
                    (0..self.w)
                        .map(|x| {
                            let i = y * self.w + x;
                            let mut st = Style::new().fg(self.col[i].unwrap_or(color));
                            if let Some(b) = self.bg[i] {
                                st = st.bg(b);
                            }
                            match self.glyph[i] {
                                Some(g) => Span::styled(g.to_string(), st),
                                None => Span::styled(char::from_u32(0x2800 + self.bits[i] as u32).unwrap_or(' ').to_string(), st),
                            }
                        })
                        .collect::<Vec<_>>(),
                )
            })
            .collect()
    }
}

fn scope(cx: &Ctx, area: Rect) -> Vec<Line<'static>> {
    let mut cv = Canvas::new(area);
    let frames = cx.tap.len() / 2;
    let win = (cx.rate as usize / 25).min(frames / 2).max(64); // about 40 ms
    if frames >= win * 2 {
        let mono: Vec<f32> = cx.tap[(frames - win * 2) * 2..].chunks(2).map(|f| (f[0] + f[1]) * 0.5).collect();
        // steady picture: start at a rising zero crossing in the first half
        let start = (1..win).find(|&i| mono[i - 1] < 0.0 && mono[i] >= 0.0).unwrap_or(0);
        let span = &mono[start..start + win];
        let peak = span.iter().fold(0.1f32, |a, v| a.max(v.abs()));
        let (pw, ph) = cv.size();
        let mut last: Option<i32> = None;
        for x in 0..pw {
            let v = span[(x as usize * (win - 1)) / (pw as usize - 1).max(1)] / peak;
            let y = ((1.0 - v * 0.9) * 0.5 * (ph - 1) as f32).round() as i32;
            let from = last.unwrap_or(y);
            for yy in from.min(y)..=from.max(y) {
                cv.set(x, yy);
            }
            last = Some(y);
        }
    }
    cv.lines(c(cx.th.accent))
}

fn goniometer(cx: &Ctx, area: Rect) -> Vec<Line<'static>> {
    let mut cv = Canvas::new(area);
    let (pw, ph) = cv.size();
    let (cxp, cyp, r) = (pw / 2, ph / 2, (pw.min(ph) / 2 - 1) as f32);
    for t in 0..=4 {
        // frame ticks: the mid/side axes
        let d = (r * t as f32 / 4.0) as i32;
        cv.set(cxp, cyp - d);
        cv.set(cxp, cyp + d);
        cv.set(cxp - d, cyp);
        cv.set(cxp + d, cyp);
    }
    let frames = cx.tap.len() / 2;
    for f in cx.tap[(frames.saturating_sub(1536)) * 2..].chunks(2) {
        let (l, rr) = (f[0], f[1]);
        let (x, y) = ((rr - l) * std::f32::consts::FRAC_1_SQRT_2, (l + rr) * std::f32::consts::FRAC_1_SQRT_2);
        cv.set(cxp + (x * r * 0.95) as i32, cyp - (y * r * 0.95) as i32);
    }
    cv.lines(c(cx.th.accent))
}

/// The web's `vu-wide`: two long shallow windows (4.2 : 1) with a 28 degree scale arc whose pivot is far below the window, so the
/// needle only sweeps sideways; red zone from -6, ticks every 2 dB, labels at -30 -20 -10 -6 -3 0, a clip lamp, L / R. Stacked or
/// side by side, whichever gives bigger windows, centred in the cell.
fn vu_wide(cx: &Ctx, area: Rect) -> Vec<Line<'static>> {
    const ASPECT: f32 = 4.2;
    const MAJOR: [i32; 6] = [-30, -20, -10, -6, -3, 0];
    let mut cv = Canvas::new(area);
    let (pw, ph) = cv.size();
    let (hot, ink, face, rim) = (c((0xff, 0x4d, 0x4d)), c(cx.th.text), c(cx.th.surface), c(cx.th.muted));
    let theta = 14f32.to_radians();
    let gap = (pw.min(ph) as f32 * 0.06).max(2.0);
    let (pwf, phf) = (pw as f32, ph as f32);
    let side = ((pwf - gap * 3.0) / 2.0).min((phf - gap * 2.0) * ASPECT);
    let stacked = (pwf - gap * 2.0).min((phf - gap * 3.0) / 2.0 * ASPECT);
    let use_stack = stacked > side;
    let bw = if use_stack { stacked } else { side }.max(8.0);
    let bh = bw / ASPECT;
    let origin = |i: usize| -> (f32, f32) {
        if use_stack {
            ((pwf - bw) / 2.0, (phf - (bh * 2.0 + gap)) / 2.0 + i as f32 * (bh + gap))
        } else {
            ((pwf - (bw * 2.0 + gap)) / 2.0 + i as f32 * (bw + gap), (phf - bh) / 2.0)
        }
    };
    for (i, (v, peak)) in [(cx.m.needle.0, cx.m.peak.0), (cx.m.needle.1, cx.m.peak.1)].into_iter().enumerate() {
        let (x, y) = origin(i);
        let (xi, yi, wi, hi) = (x.round() as i32, y.round() as i32, bw.round() as i32, bh.round() as i32);
        cv.fill(xi, yi, wi, hi, face);
        let r = bw * 0.43 / theta.sin();
        let (mx, py) = (x + bw / 2.0, y + bh * 0.42 + r);
        let angle = |db: f32| -theta + 2.0 * theta * ((db + 30.0) / 33.0);
        let pt = |db: f32, len: f32| (mx + angle(db).sin() * len, py - angle(db).cos() * len);
        let inside = |p: (f32, f32)| p.0 >= x && p.0 < x + bw && p.1 >= y && p.1 < y + bh;
        let mut dot = |cv: &mut Canvas, p: (f32, f32), col| {
            if inside(p) {
                cv.set_c(p.0.round() as i32, p.1.round() as i32, col);
            }
        };
        // red zone along the arc, two dots thick
        let mut db = -6.0;
        while db <= 3.0 {
            for t in [0.0, 1.0] {
                dot(&mut cv, pt(db, r - t), hot);
            }
            db += 0.1;
        }
        for d in (-30..=2).step_by(2) {
            let major = MAJOR.contains(&d);
            let (a, b) = (r - bh * 0.05, r - bh * if major { 0.16 } else { 0.11 });
            for k in 0..=6 {
                dot(&mut cv, pt(d as f32, a + (b - a) * k as f32 / 6.0), ink);
            }
            if major {
                let (lx, ly) = pt(d as f32, r - bh * 0.27);
                let text = d.to_string();
                let c0 = (lx / 2.0) as i32 - text.len() as i32 / 2;
                for (k, ch) in text.chars().enumerate() {
                    if lx >= x && lx < x + bw && ly >= y && ly < y + bh && c0 + k as i32 >= 0 {
                        cv.put((c0 + k as i32) as usize, (ly / 4.0) as usize, ch);
                        if ((c0 + k as i32) as usize) < cv.w {
                            cv.col[(ly / 4.0) as usize * cv.w + (c0 + k as i32) as usize] = Some(ink);
                        }
                    }
                }
            }
        }
        cv.put((x / 2.0).ceil() as usize + 1, (y / 4.0) as usize, if i == 0 { 'L' } else { 'R' });
        let lamp = if peak > -0.5 { hot } else { rim };
        dot(&mut cv, (x + bw * 0.965, y + bh * 0.2), lamp);
        // the needle from far below; only the part in the window shows
        let a = -theta + 2.0 * theta * v;
        let (len0, len1) = (r - bh * 1.3, r + bh * 0.06);
        let n = ((len1 - len0) * 2.0) as i32;
        for k in 0..=n {
            let len = len0 + (len1 - len0) * k as f32 / n.max(1) as f32;
            dot(&mut cv, (mx + a.sin() * len, py - a.cos() * len), ink);
        }
    }
    cv.lines(ink)
}

/// The web's `vu-needle` (`dial.js`): two round-cornered faces (1.55 : 1, the theme's surface) side by side and centred, a 96 degree
/// scale of -30..+3 dBFS with the pivot near the bottom, red zone from -6, ticks every 2 dB, labels at the major ones, "dB",
/// L / R, a clip lamp, the needle with a short tail and a hub.
fn needles(cx: &Ctx, area: Rect) -> Vec<Line<'static>> {
    const ASPECT: f32 = 1.55;
    const MAJOR: [i32; 6] = [-30, -20, -10, -6, -3, 0];
    let mut cv = Canvas::new(area);
    let (pw, ph) = cv.size();
    let (hot, ink, face) = (c((0xff, 0x4d, 0x4d)), c(cx.th.text), c(cx.th.surface));
    let sweep = 96f32.to_radians();
    let (pwf, phf) = (pw as f32, ph as f32);
    let gap = (pwf.min(phf) * 0.04).max(1.0);
    let bw = ((pwf - gap * 3.0) / 2.0).min((phf - gap * 2.0) * ASPECT).max(8.0);
    let bh = bw / ASPECT;
    let x0 = (pwf - (bw * 2.0 + gap)) / 2.0;
    let y = (phf - bh) / 2.0;
    for (i, (v, peak)) in [(cx.m.needle.0, cx.m.peak.0), (cx.m.needle.1, cx.m.peak.1)].into_iter().enumerate() {
        let x = x0 + i as f32 * (bw + gap);
        cv.fill(x.round() as i32, y.round() as i32, bw.round() as i32, bh.round() as i32, face);
        let r = (bw * 0.6).min(bh * 0.85);
        let (mx, my) = (x + bw / 2.0, y + bh * 0.93);
        let angle = |db: f32| -sweep / 2.0 + sweep * ((db + 30.0) / 33.0);
        let pt = |db: f32, len: f32| (mx + angle(db).sin() * len, my - angle(db).cos() * len);
        let inside = |p: (f32, f32)| p.0 >= x && p.0 < x + bw && p.1 >= y && p.1 < y + bh;
        let dot = |cv: &mut Canvas, p: (f32, f32), col| {
            if inside(p) {
                cv.set_c(p.0.round() as i32, p.1.round() as i32, col);
            }
        };
        // red zone: an arc, two dots thick
        let mut db = -6.0;
        while db <= 3.0 {
            for t in [0.93, 0.89] {
                dot(&mut cv, pt(db, r * t), hot);
            }
            db += 0.08;
        }
        for d in (-30..=2).step_by(2) {
            let major = MAJOR.contains(&d);
            let (a, b) = (r * if major { 0.8 } else { 0.85 }, r * 0.9);
            for k in 0..=4 {
                dot(&mut cv, pt(d as f32, a + (b - a) * k as f32 / 4.0), ink);
            }
            if major && bw > 40.0 {
                let (lx, ly) = pt(d as f32, r * 0.7);
                let text = d.to_string();
                let c0 = (lx / 2.0) as i32 - text.len() as i32 / 2;
                for (k, ch) in text.chars().enumerate() {
                    let (cxx, cyy) = ((c0 + k as i32) as usize, (ly / 4.0) as usize);
                    if lx >= x && lx < x + bw && ly >= y && ly < y + bh && c0 + k as i32 >= 0 && cxx < cv.w && cyy < cv.h {
                        cv.put(cxx, cyy, ch);
                        cv.col[cyy * cv.w + cxx] = Some(ink);
                    }
                }
            }
        }
        if bh > 12.0 {
            cv.put((x / 2.0).ceil() as usize + 1, (y / 4.0) as usize, if i == 0 { 'L' } else { 'R' });
            let (dx, dy) = (mx / 2.0, (my - r * 0.36) / 4.0);
            if bw > 40.0 && dy >= 0.0 {
                for (k, ch) in "dB".chars().enumerate() {
                    cv.put(dx as usize + k, dy as usize, ch);
                }
            }
        }
        dot(&mut cv, (x + bw * 0.9, y + bh * 0.15), if peak > -0.5 { hot } else { c(cx.th.muted) });
        // needle: a short tail behind the pivot, to 92% of the radius; then the hub
        let a = -sweep / 2.0 + sweep * v;
        let n = (r * 2.0) as i32;
        for k in 0..=n {
            let len = -r * 0.08 + r * 1.0 * k as f32 / n.max(1) as f32;
            if len <= r * 0.92 {
                dot(&mut cv, (mx + a.sin() * len, my - a.cos() * len), ink);
            }
        }
        for (dx, dy) in [(0.0, 0.0), (1.0, 0.0), (0.0, -1.0), (1.0, -1.0)] {
            dot(&mut cv, (mx + dx, my + dy), ink);
        }
    }
    cv.lines(ink)
}

/// Two tubes: a glowing target that dims and a dark wedge opening downward from the centre; the wedge closes as the level rises.
fn eyes(cx: &Ctx, area: Rect) -> Vec<Line<'static>> {
    let mut cv = Canvas::new(area);
    let (pw, ph) = cv.size();
    let d = (ph - 1).min((pw - 6) / 2);
    if d < 8 {
        return cv.lines(c(cx.th.accent2));
    }
    let r = d as f32 / 2.0;
    let x0 = (pw - (2 * d + 4)) / 2;
    for (i, v) in [cx.m.eye.0, cx.m.eye.1].into_iter().enumerate() {
        let (ex, ey) = ((x0 + i as i32 * (d + 4)) as f32 + r, (ph - 1) as f32 / 2.0);
        let wedge = 78f32.to_radians() * (1.0 - v);
        let glow = c(lerp(cx.th.surface, cx.th.accent2, 0.45 + 0.55 * v));
        for y in 0..ph {
            for x in 0..pw {
                let (dx, dy) = (x as f32 - ex, y as f32 - ey);
                let dist = (dx * dx + dy * dy).sqrt();
                if dist > r + 0.3 {
                    continue;
                }
                if dist >= r - 0.6 {
                    cv.set_c(x, y, c(cx.th.muted));
                    continue;
                }
                let inside = dist <= r * 0.84 && dist >= r * 0.12;
                let angle = dx.atan2(dy).abs(); // 0 straight down
                if inside && angle >= wedge {
                    cv.set_c(x, y, glow);
                }
            }
        }
        cv.put(((ex / 2.0) as usize).min(pw as usize / 2 - 1), (ph as usize - 1) / 4, if i == 0 { 'L' } else { 'R' });
    }
    cv.lines(c(cx.th.accent2))
}

fn loudness(cx: &Ctx, area: Rect) -> Vec<Line<'static>> {
    let (th, l) = (cx.th, cx.m.lufs);
    let n = |v: Option<f64>, unit: &str| v.map_or("—".to_string(), |v| format!("{v:.1}{unit}"));
    let mut lines = vec![
        Line::from(vec![
            Span::styled(format!("M {}", n(l.momentary, "")), Style::new().fg(c(th.accent2))),
            Span::raw(format!("   S {}   I {} LUFS", n(l.short_term, ""), n(l.integrated, ""))),
        ]),
        Line::from(format!("LRA {} LU   TP {}", n(l.range, ""), n(l.true_peak, " dBTP"))).style(Style::new().fg(c(th.muted))),
    ];
    let rows = (area.height as usize).saturating_sub(2);
    if rows > 0 {
        // momentary loudness over the last minute, -40 .. -5 LUFS
        let w = area.width as usize;
        let hist = &cx.m.lufs_history;
        let vals: Vec<f32> = (0..w).map(|x| (hist.len() + x).checked_sub(w).and_then(|i| hist.get(i)).map_or(0.0, |v| ((v + 40.0) / 35.0).clamp(0.0, 1.0))).collect();
        lines.extend(block_bars(&vals, Rect::new(0, 0, area.width, rows as u16), th));
    }
    lines
}

#[allow(dead_code)]
const _: usize = BANDS; // kept public for the spectrum bars

#[cfg(test)]
mod tests {
    use super::*;
    use crate::meters::tests::sine;
    use ratatui::{backend::TestBackend, Terminal};

    fn text(term: &Terminal<TestBackend>) -> String {
        let b = term.backend().buffer();
        (0..b.area.height).map(|y| (0..b.area.width).map(|x| b[(x, y)].symbol().to_string()).collect::<String>()).collect::<Vec<_>>().join("\n")
    }

    #[test]
    fn every_meter_draws_inside_its_cell_at_awkward_sizes() {
        let rate = 48000;
        let audio = sine(440.0, -12.0, 3.0, rate, 0.5);
        let mut m = Meters::new();
        for i in 0..40 {
            let end: usize = (i + 1) * 1600 * 2;
            let tap = &audio[end.saturating_sub(4096 * 2)..end];
            m.update(tap, &audio[i * 3200..end], rate, 0.1);
        }
        let tap = audio[audio.len() - 4096 * 2..].to_vec();
        let th = crate::theme::builtin().remove(0);
        for kind in Kind::ALL {
            for (w, h) in [(60u16, 5u16), (30, 3), (12, 1), (5, 2), (100, 12)] {
                let mut term = Terminal::new(TestBackend::new(w + 4, h + 2)).unwrap();
                term
                    .draw(|f| draw(f, Rect::new(2, 1, w, h), kind, &Ctx { m: &m, tap: &tap, rate, th: &th }))
                    .unwrap_or_else(|e| panic!("{kind:?} {w}x{h}: {e}"));
                // nothing outside the cell
                let t = text(&term);
                let rows: Vec<&str> = t.lines().collect();
                assert!(rows[0].trim().is_empty() && rows[h as usize + 1].trim().is_empty(), "{kind:?} {w}x{h} drew outside its cell");
                assert!(rows.iter().all(|r| r.chars().take(2).all(|c| c == ' ') && r.chars().skip(w as usize + 2).all(|c| c == ' ')), "{kind:?} {w}x{h} drew beside its cell");
            }
        }
    }

    #[test]
    fn the_goniometer_shows_a_vertical_line_for_mono_and_the_loudness_meter_its_numbers() {
        let rate = 48000;
        let tap = sine(440.0, -6.0, 0.2, rate, 1.0); // identical channels: all energy on the mid axis
        let th = crate::theme::builtin().remove(0);
        let m = Meters::new();
        let mut term = Terminal::new(TestBackend::new(20, 8)).unwrap();
        term.draw(|f| draw(f, Rect::new(0, 0, 20, 8), Kind::Goniometer, &Ctx { m: &m, tap: &tap, rate, th: &th })).unwrap();
        let t = text(&term);
        // column 10 holds the mid axis; outside the row of axis ticks (row 4) nothing lands beside it
        for (y, l) in t.lines().enumerate().filter(|(y, _)| *y != 4) {
            assert!(l.chars().enumerate().all(|(x, c)| x == 9 || x == 10 || c == '\u{2800}'), "mono must stay centred (row {y}):\n{t}");
        }
        assert!(t.lines().nth(4).unwrap().chars().nth(10).unwrap() != '\u{2800}');
        let mut term = Terminal::new(TestBackend::new(40, 4)).unwrap();
        term.draw(|f| draw(f, Rect::new(0, 0, 40, 4), Kind::Loudness, &Ctx { m: &m, tap: &[], rate, th: &th })).unwrap();
        assert!(text(&term).contains("LUFS"));
    }

    #[test]
    fn ids_round_trip_and_unknown_ones_are_ignored() {
        for k in Kind::ALL {
            assert_eq!(Kind::from_id(k.id()), Some(k));
        }
        assert_eq!(Kind::from_id("nonsense"), None);
    }
}

#[cfg(test)]
mod preview {
    use super::*;
    use crate::meters::tests::sine;
    use ratatui::{backend::TestBackend, Terminal};
    #[test]
    #[ignore]
    fn show() {
        let rate = 48000;
        let audio = sine(440.0, -9.0, 3.0, rate, 0.5);
        let mut m = Meters::new();
        for i in 0..40 {
            let end: usize = (i + 1) * 1600 * 2;
            m.update(&audio[end.saturating_sub(4096 * 2)..end], &audio[i * 3200..end], rate, 0.1);
        }
        let th = crate::theme::builtin().remove(0);
        for kind in [Kind::VuNeedle] {
            for (w, h) in [(50u16, 16u16), (28, 6)] {
                let mut t = Terminal::new(TestBackend::new(w, h)).unwrap();
                t.draw(|f| draw(f, Rect::new(0, 0, w, h), kind, &Ctx { m: &m, tap: &[], rate, th: &th })).unwrap();
                let b = t.backend().buffer();
                for y in 0..h { println!("{}", (0..w).map(|x| b[(x, y)].symbol().to_string()).collect::<String>()); }
                println!("----");
            }
        }
    }
}
