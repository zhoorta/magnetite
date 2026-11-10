//! The album cover in the terminal: fetched in the background through the same `/cover/{album}` the apps use (Bearer token),
//! decoded with the `image` crate and drawn with half blocks (two pixels per cell, true colour or the 256-colour fallback).
use crate::{api::Api, theme::c};
use image::{imageops::FilterType, RgbImage};
use ratatui::{layout::Rect, style::Style, text::{Line, Span}, widgets::Paragraph, Frame};
use std::{io::Read, sync::mpsc, thread};

pub struct Cover {
    url: Option<String>,
    img: Option<RgbImage>,
    scaled: Option<((u32, u32), RgbImage)>,
    rx: Option<mpsc::Receiver<Option<RgbImage>>>,
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
        Cover { url: None, img: None, scaled: None, rx: None }
    }

    /// The track changed: fetch its cover unless it is the one already held.
    pub fn show(&mut self, api: &Api, url: Option<&str>) {
        if self.url.as_deref() == url {
            return;
        }
        self.url = url.map(str::to_string);
        self.img = None;
        self.scaled = None;
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
            self.img = done;
            self.rx = None;
        }
    }

    pub fn has(&self) -> bool {
        self.img.is_some()
    }

    /// The largest square that fits `area` (a cell is twice as tall as wide), centred; nothing when there is no cover.
    pub fn draw(&mut self, f: &mut Frame, area: Rect) {
        let Some(img) = &self.img else { return };
        let side = area.width.min(area.height * 2); // in columns and in pixel rows
        if side < 4 {
            return;
        }
        let (pw, ph) = (side as u32, side as u32);
        if self.scaled.as_ref().map(|s| s.0) != Some((pw, ph)) {
            self.scaled = Some(((pw, ph), image::imageops::resize(img, pw, ph, FilterType::Triangle)));
        }
        let px = &self.scaled.as_ref().unwrap().1;
        let (cols, rows) = (side, side / 2);
        let (x0, y0) = (area.x + (area.width - cols) / 2, area.y + (area.height - rows) / 2);
        let lines: Vec<Line> = (0..rows as u32)
            .map(|r| {
                Line::from(
                    (0..cols as u32)
                        .map(|x| {
                            let (t, b) = (px.get_pixel(x, r * 2).0, px.get_pixel(x, r * 2 + 1).0);
                            Span::styled("▀", Style::new().fg(c((t[0], t[1], t[2]))).bg(c((b[0], b[1], b[2]))))
                        })
                        .collect::<Vec<_>>(),
                )
            })
            .collect();
        f.render_widget(Paragraph::new(lines), Rect::new(x0, y0, cols, rows));
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
        let drawn = |x: u16, y: u16| b[(x, y)].symbol() == "▀";
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
