//! The album cover in the terminal: fetched in the background through the same `/cover/{album}` the apps use (Bearer token),
//! decoded with the `image` crate and drawn with block characters (`blocks.rs`: quadrants, or half blocks; true colour, or the
//! 256-colour palette with the error spread so Terminal.app does not show flat bands).
use crate::{api::Api, blocks::{self, Cell, Shape}, theme::{truecolor, Rgb}};
use image::{imageops::FilterType, RgbImage};
use ratatui::{layout::Rect, style::Style, text::{Line, Span}, widgets::Paragraph, Frame};
use std::{io::Read, sync::mpsc, thread};

pub struct Cover {
    url: Option<String>,
    img: Option<RgbImage>,
    drawn: Option<((u16, u16, Shape, bool), Vec<Vec<Cell>>)>, // the cells for this size, kept until the area or the cover changes
    rx: Option<mpsc::Receiver<Option<RgbImage>>>,
    palette: Option<(Rgb, Rgb)>,
    artwork: Option<String>, // file:// url of the pixelated copy handed to the system's now-playing display
}

/// `cover_url` is absolute and may name another scheme or host than the one signed in to (a proxy), so only its path and query are used.
fn path_of(url: &str) -> &str {
    url.find("://").and_then(|i| url[i + 3..].find('/').map(|j| &url[i + 3 + j..])).unwrap_or(url)
}

fn fetch(api: &Api, url: &str) -> Option<RgbImage> {
    let resp = ureq::get(&format!("{}{}", api.base, path_of(url))).set("Authorization", &api.auth()).call().ok()?;
    let mut bytes = vec![];
    resp.into_reader().take(8 << 20).read_to_end(&mut bytes).ok()?;
    let img = image::load_from_memory(&bytes).ok()?.to_rgb8();
    // plenty for any terminal; keeps the rescale cheap
    Some(if img.width().max(img.height()) > 256 { image::imageops::resize(&img, 256, 256 * img.height() / img.width().max(1), FilterType::Triangle) } else { img })
}

impl Cover {
    pub fn new() -> Self {
        Cover { url: None, img: None, drawn: None, rx: None, palette: None, artwork: None }
    }

    /// The track changed: fetch its cover unless it is the one already held.
    pub fn show(&mut self, api: &Api, url: Option<&str>) {
        if self.url.as_deref() == url {
            return;
        }
        self.url = url.map(str::to_string);
        self.img = None;
        self.palette = None;
        self.artwork = None;
        self.drawn = None;
        self.rx = None;
        if let Some(u) = url {
            let (api, u, (tx, rx)) = (api.clone(), u.to_string(), mpsc::channel());
            thread::spawn(move || {
                let _ = tx.send(fetch(&api, &u));
            });
            self.rx = Some(rx);
        }
    }

    pub fn poll(&mut self) {
        if let Some(Ok(done)) = self.rx.as_ref().map(|r| r.try_recv()) {
            self.palette = done.as_ref().and_then(|i| palette(i, false));
            self.artwork = done.as_ref().and_then(|i| write_artwork(i, self.url.as_deref().unwrap_or("")));
            self.img = done;
            self.rx = None;
        }
    }

    /// Two vivid colours from the cover (accent, accent2), when it has any.
    pub fn palette(&self) -> Option<(Rgb, Rgb)> {
        self.palette
    }

    /// `file://` url of the pixelated cover for the system's now-playing display, once the cover has loaded.
    pub fn artwork(&self) -> Option<&str> {
        self.artwork.as_deref()
    }

    pub fn has(&self) -> bool {
        self.img.is_some()
    }

    /// How the cover is drawn: quadrants (twice the detail across), or half blocks with `MAGNETITE_COVER=half` for a terminal or
    /// font that cannot show the quadrant characters.
    fn shape() -> Shape {
        match std::env::var("MAGNETITE_COVER").as_deref() {
            Ok("half") => Shape::Half,
            _ => Shape::Quadrant,
        }
    }

    /// The largest square that fits `area` (a cell is twice as tall as wide), centred; nothing when there is no cover.
    pub fn draw(&mut self, f: &mut Frame, area: Rect) {
        let Some(img) = &self.img else { return };
        let side = area.width.min(area.height * 2); // in columns and in pixel rows
        if side < 4 {
            return;
        }
        let (cols, rows) = (side, side / 2);
        let (shape, indexed) = (Self::shape(), !truecolor());
        let key = (cols, rows, shape, indexed);
        if self.drawn.as_ref().map(|d| d.0) != Some(key) {
            // a sharp resize, then a touch of sharpening: the picture has few pixels, so edges count
            let (cw, ch) = shape.pixels();
            let grid = image::imageops::resize(img, cols as u32 * cw as u32, rows as u32 * ch as u32, FilterType::Lanczos3);
            let grid = image::imageops::unsharpen(&grid, 0.7, 2);
            self.drawn = Some((key, blocks::render(&grid, shape, indexed)));
        }
        let cells = &self.drawn.as_ref().unwrap().1;
        let (x0, y0) = (area.x + (area.width - cols) / 2, area.y + (area.height - rows) / 2);
        let lines: Vec<Line> = cells.iter().map(|row| Line::from(row.iter().map(|k| Span::styled(k.ch.to_string(), Style::new().fg(k.fg).bg(k.bg))).collect::<Vec<_>>())).collect();
        f.render_widget(Paragraph::new(lines), Rect::new(x0, y0, cols, rows));
    }
}

/// The cover as the terminal shows it, only bigger: a `grid` x `grid` mosaic (averaged, so it is the same picture as the
/// half-block one) scaled up with hard edges to `size` pixels square.
pub fn pixelated(img: &RgbImage, grid: u32, size: u32) -> RgbImage {
    let small = image::imageops::resize(img, grid, grid, FilterType::Triangle);
    image::imageops::resize(&small, size, size, FilterType::Nearest)
}

/// Write the pixelated cover where the system can read it (the now-playing display of macOS, MPRIS and Windows load a file,
/// and cannot send our token to fetch the real cover). One file per cover, the older ones removed. Returns its `file://` url.
fn write_artwork(img: &RgbImage, key: &str) -> Option<String> {
    use std::hash::{Hash, Hasher};
    let dir = crate::config::dir().join("artwork");
    std::fs::create_dir_all(&dir).ok()?;
    let mut h = std::collections::hash_map::DefaultHasher::new();
    key.hash(&mut h);
    let file = dir.join(format!("{:016x}.png", h.finish()));
    for old in std::fs::read_dir(&dir).ok()?.flatten() {
        if old.path() != file {
            let _ = std::fs::remove_file(old.path());
        }
    }
    pixelated(img, 32, 512).save(&file).ok()?;
    Some(format!("file://{}", file.to_str()?))
}

fn hsv(c: [u8; 3]) -> (f32, f32, f32) {
    let (r, g, b) = (c[0] as f32 / 255.0, c[1] as f32 / 255.0, c[2] as f32 / 255.0);
    let (mx, mn) = (r.max(g).max(b), r.min(g).min(b));
    let d = mx - mn;
    let h = if d == 0.0 {
        0.0
    } else if mx == r {
        60.0 * (((g - b) / d) % 6.0)
    } else if mx == g {
        60.0 * ((b - r) / d + 2.0)
    } else {
        60.0 * ((r - g) / d + 4.0)
    };
    (h.rem_euclid(360.0), if mx == 0.0 { 0.0 } else { d / mx }, mx)
}

fn rgb(h: f32, s: f32, v: f32) -> Rgb {
    let (c, hp) = (v * s, h.rem_euclid(360.0) / 60.0);
    let x = c * (1.0 - (hp % 2.0 - 1.0).abs());
    let (r, g, b) = match hp as u32 {
        0 => (c, x, 0.0),
        1 => (x, c, 0.0),
        2 => (0.0, c, x),
        3 => (0.0, x, c),
        4 => (x, 0.0, c),
        _ => (c, 0.0, x),
    };
    let m = v - c;
    (((r + m) * 255.0).round() as u8, ((g + m) * 255.0).round() as u8, ((b + m) * 255.0).round() as u8)
}

/// The two most prominent vivid hues of a cover, made bright and saturated enough to read on the theme's background
/// (`light` for a light theme). Weight = saturation x value, so grey, black and white areas do not count; a cover without
/// colour gives `None` (the theme's own colours stay). With one hue only, the second colour is a lighter, shifted tone of it.
pub fn palette(img: &RgbImage, light: bool) -> Option<(Rgb, Rgb)> {
    let small = image::imageops::resize(img, 32, 32, FilterType::Triangle);
    const BINS: usize = 36;
    let (mut weight, mut sum) = ([0f32; BINS], [[0f32; 3]; BINS]); // per hue bin: weight, and weighted s, v
    for p in small.pixels() {
        let (h, s, v) = hsv(p.0);
        let w = if s < 0.25 || v < 0.2 { 0.0 } else { s * v };
        let b = (h / 10.0) as usize % BINS;
        weight[b] += w;
        sum[b][0] += w * s;
        sum[b][1] += w * v;
        sum[b][2] += w * h;
    }
    // smooth over neighbouring bins so a hue near a bin edge is not split
    let smooth: Vec<f32> = (0..BINS).map(|i| weight[i] + 0.5 * (weight[(i + 1) % BINS] + weight[(i + BINS - 1) % BINS])).collect();
    let total: f32 = weight.iter().sum();
    if total < 1.0 {
        return None; // (a 32 x 32 cover has 1024 pixels: less than one full-strength pixel of colour is grey)
    }
    let top = (0..BINS).max_by(|a, b| smooth[*a].total_cmp(&smooth[*b]))?;
    let hue_of = |b: usize| -> f32 {
        // weighted circular mean of the bin and its neighbours, from the bins' own hue sums (no wrap-around problem: unwrap relative to the bin centre)
        let centre = b as f32 * 10.0 + 5.0;
        let (mut hw, mut ww) = (0.0, 0.0);
        for k in [(b + BINS - 1) % BINS, b, (b + 1) % BINS] {
            if weight[k] > 0.0 {
                let mean = sum[k][2] / weight[k];
                let d = (mean - centre + 540.0).rem_euclid(360.0) - 180.0;
                hw += weight[k] * (centre + d);
                ww += weight[k];
            }
        }
        if ww > 0.0 { hw / ww } else { centre }
    };
    let sv_of = |b: usize| -> (f32, f32) {
        let w = weight[b].max(1e-6);
        (sum[b][0] / w, sum[b][1] / w)
    };
    let dist = |a: usize, b: usize| {
        let d = (a as i32 - b as i32).unsigned_abs() as usize;
        d.min(BINS - d)
    };
    let second = (0..BINS).filter(|b| dist(*b, top) >= 4 && smooth[*b] >= total * 0.12).max_by(|a, b| smooth[*a].total_cmp(&smooth[*b]));
    let (v_lo, v_hi) = if light { (0.40, 0.70) } else { (0.78, 1.0) };
    let fit = |h: f32, s: f32, v: f32| rgb(h, s.clamp(0.55, 1.0), v.clamp(v_lo, v_hi));
    let (s1, v1) = sv_of(top);
    let a = fit(hue_of(top), s1, v1);
    let b = match second {
        Some(b2) => {
            let (s2, v2) = sv_of(b2);
            fit(hue_of(b2), s2, v2)
        }
        None => fit(hue_of(top) + 30.0, (s1 * 0.8).max(0.55), if light { v_lo } else { v_hi }),
    };
    Some((a, b))
}

#[cfg(test)]
mod palette_tests {
    use super::*;

    fn hue(c: Rgb) -> f32 {
        hsv([c.0, c.1, c.2]).0
    }

    #[test]
    fn the_artwork_is_a_hard_edged_mosaic_of_the_cover() {
        let mut img = RgbImage::from_pixel(64, 64, image::Rgb([200, 30, 30]));
        for y in 0..64 {
            for x in 32..64 {
                img.put_pixel(x, y, image::Rgb([30, 60, 210]));
            }
        }
        let p = pixelated(&img, 32, 512);
        assert_eq!((p.width(), p.height()), (512, 512));
        // every 16 x 16 block is one colour; left half red, right half blue
        assert_eq!(p.get_pixel(0, 0), p.get_pixel(15, 15));
        assert_eq!(p.get_pixel(20, 0).0, [200, 30, 30]);
        assert_eq!(p.get_pixel(500, 500).0, [30, 60, 210]);
    }

    #[test]
    fn two_colour_cover_gives_both_hues_grey_gives_none() {
        let mut img = RgbImage::from_pixel(64, 64, image::Rgb([200, 30, 30]));
        for y in 0..64 {
            for x in 32..64 {
                img.put_pixel(x, y, image::Rgb([30, 60, 210]));
            }
        }
        let (a, b) = palette(&img, false).unwrap();
        let (ha, hb) = (hue(a), hue(b));
        let near = |h: f32, t: f32| ((h - t + 540.0) % 360.0 - 180.0).abs() < 25.0;
        assert!((near(ha, 0.0) && near(hb, 225.0)) || (near(hb, 0.0) && near(ha, 225.0)), "hues {ha} {hb}");
        assert!(hsv([a.0, a.1, a.2]).2 >= 0.77, "bright enough for a dark theme");
        assert!(palette(&RgbImage::from_pixel(64, 64, image::Rgb([120, 120, 120])), false).is_none());
        assert!(palette(&RgbImage::from_pixel(64, 64, image::Rgb([10, 10, 10])), false).is_none());
    }

    #[test]
    fn one_hue_gives_a_second_shifted_tone_and_a_light_theme_gets_darker_colours() {
        let img = RgbImage::from_pixel(64, 64, image::Rgb([40, 160, 60]));
        let (a, b) = palette(&img, false).unwrap();
        assert!(hue(a) > 100.0 && hue(a) < 150.0 && hue(b) > hue(a), "{a:?} {b:?}");
        let (la, _) = palette(&img, true).unwrap();
        assert!(hsv([la.0, la.1, la.2]).2 <= 0.71);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_the_path_of_a_cover_url_is_used() {
        assert_eq!(path_of("http://magnetite.test/cover/12?v=abcdef0123"), "/cover/12?v=abcdef0123");
        assert_eq!(path_of("/cover/3"), "/cover/3");
    }
}

#[cfg(test)]
mod draw_tests {
    use super::*;
    use ratatui::{backend::TestBackend, Terminal};

    #[test]
    fn a_cover_is_drawn_square_and_centred_inside_its_area() {
        let mut cv = Cover::new();
        cv.img = Some(RgbImage::from_pixel(50, 80, image::Rgb([200, 30, 30])));
        let mut t = Terminal::new(TestBackend::new(40, 12)).unwrap();
        t.draw(|f| cv.draw(f, Rect::new(2, 1, 30, 8))).unwrap();
        let b = t.backend().buffer();
        let drawn = |x: u16, y: u16| b[(x, y)].symbol() != " ";
        // 8 rows x 2 = 16 pixels: a 16-column, 8-row square centred in 30 columns
        assert!(drawn(2 + 7, 1) && drawn(2 + 22, 8) && !drawn(2 + 6, 1) && !drawn(2 + 23, 1) && !drawn(0, 0));
        assert!(!Cover::new().has());
    }
}

#[cfg(test)]
mod live {
    use super::*;

    /// `cargo test live_cover -- --ignored`: fetches the first album's cover from the signed-in server.
    #[test]
    #[ignore]
    fn live_cover() {
        let cfg = crate::config::load().expect("sign in first");
        let api = Api::new(&cfg.server, &cfg.token);
        let album = api.albums().unwrap().into_iter().find_map(|a| api.album(a.id).ok().filter(|f| f.tracks.iter().any(|t| t.cover_url.is_some()))).expect("an album with a cover");
        let url = album.tracks.iter().find_map(|t| t.cover_url.clone()).unwrap();
        let img = fetch(&api, &url).expect("cover decodes");
        println!("{}x{} from {}", img.width(), img.height(), path_of(&url));
        assert!(img.width() > 8);
    }
}

#[cfg(test)]
mod live_artwork {
    use super::*;

    /// `cargo test live_artwork -- --ignored --nocapture`: writes the pixelated artwork of the first cover and prints where.
    #[test]
    #[ignore]
    fn live_artwork() {
        let cfg = crate::config::load().expect("sign in first");
        let api = Api::new(&cfg.server, &cfg.token);
        let a = api.albums().unwrap().into_iter().nth(20).unwrap();
        let full = api.album(a.id).unwrap();
        let url = full.tracks.iter().find_map(|t| t.cover_url.clone()).unwrap();
        let img = fetch(&api, &url).unwrap();
        println!("{} -> {:?}", a.title, write_artwork(&img, &url));
    }
}

#[cfg(test)]
mod live_palette {
    use super::*;

    /// `cargo test live_palette -- --ignored --nocapture`: the colours picked for the first 12 covers of the library.
    #[test]
    #[ignore]
    fn live_palette() {
        let cfg = crate::config::load().expect("sign in first");
        let api = Api::new(&cfg.server, &cfg.token);
        for a in api.albums().unwrap().into_iter().step_by(6).take(12) {
            let full = api.album(a.id).unwrap();
            let Some(url) = full.tracks.iter().find_map(|t| t.cover_url.clone()) else { continue };
            let Some(img) = fetch(&api, &url) else { continue };
            let sw = |c: Rgb| format!("\x1b[48;2;{};{};{}m    \x1b[0m", c.0, c.1, c.2);
            match palette(&img, false) {
                Some((x, y)) => println!("{} {}  {}", sw(x), sw(y), a.title),
                None => println!("(no colour)  {}", a.title),
            }
        }
    }
}
