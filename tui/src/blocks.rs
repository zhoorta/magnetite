//! Pictures made of block characters: the cover in the terminal. A cell shows two colours; which two, and which part of
//! the cell each takes, is chosen per cell to fit its pixels best. Two shapes: half blocks (1 x 2 pixels a cell, every
//! terminal) and quadrants (2 x 2, `▘▝▖▗▚▌▐▀▄▛▜▙▟`: twice the detail across; the block-element characters need a font that
//! has them, which nearly every monospace font does). Where the terminal has no true colour (macOS Terminal.app) the colours
//! come from the 256-colour palette and the error of every choice is spread over the cells still to come (Floyd-Steinberg),
//! so a smooth sky stays smooth instead of stepping through a few flat bands.

use image::RgbImage;
use ratatui::style::Color;

#[derive(Clone, Copy, PartialEq, Debug)]
pub enum Shape {
    Half,
    Quadrant,
}

impl Shape {
    /// Pixels across and down in one cell.
    pub fn pixels(self) -> (usize, usize) {
        match self {
            Shape::Half => (1, 2),
            Shape::Quadrant => (2, 2),
        }
    }

    /// The character that is the foreground where the bit is set; bit = row * width + column.
    fn glyph(self, mask: usize) -> char {
        match self {
            Shape::Half => [' ', '▀', '▄', '█'][mask],
            Shape::Quadrant => [' ', '▘', '▝', '▀', '▖', '▌', '▞', '▛', '▗', '▚', '▐', '▜', '▄', '▙', '▟', '█'][mask],
        }
    }
}

#[derive(Clone, Copy, PartialEq, Debug)]
pub struct Cell {
    pub ch: char,
    pub fg: Color,
    pub bg: Color,
}

/// How much of a pixel's colour error reaches its neighbours (1 is full Floyd-Steinberg), and the most that one pixel passes on.
const SPREAD: f32 = 0.6;
const MAX_ERROR: f32 = 28.0;
/// Of the colour part of the error (what is left after the brightness) a grey pixel passes on `MIN_CHROMA`, a saturated one
/// `MAX_CHROMA`: all of it would dot a flat red with magenta, none of it would give up the dithering of colours.
const MIN_CHROMA: f32 = 0.15;
const MAX_CHROMA: f32 = 0.5;

const LEVELS: [i32; 6] = [0, 95, 135, 175, 215, 255];

/// The nearest entry of the 256-colour palette's colour cube and grey ramp (indexes 16 to 255; the first 16 are whatever
/// the terminal's theme says), with its RGB.
pub fn nearest(rgb: [f32; 3]) -> (u8, [f32; 3]) {
    let v = rgb.map(|x| x.round().clamp(0.0, 255.0) as i32);
    let level = |x: i32| (0..6).min_by_key(|&i| (LEVELS[i] - x).abs()).unwrap();
    let (r, g, b) = (level(v[0]), level(v[1]), level(v[2]));
    let cube = [LEVELS[r], LEVELS[g], LEVELS[b]];
    let avg = (v[0] + v[1] + v[2]) / 3;
    let gi = (((avg - 8) as f32 / 10.0).round().clamp(0.0, 23.0)) as i32;
    let gray = 8 + 10 * gi;
    let dist = |p: [i32; 3]| (0..3).map(|i| (p[i] - v[i]).pow(2)).sum::<i32>();
    // a colour that is nearly grey should be a grey: the palette's cube has tints a few steps off grey that would show as speckle
    let spread = v.iter().max().unwrap() - v.iter().min().unwrap();
    let favour = if spread < 40 { 2 } else { 1 };
    if dist([gray; 3]) < dist(cube) * favour {
        (232 + gi as u8, [gray as f32; 3])
    } else {
        (16 + 36 * r as u8 + 6 * g as u8 + b as u8, cube.map(|x| x as f32))
    }
}

fn mean(px: &[[f32; 3]], mask: usize, want: bool) -> ([f32; 3], usize) {
    let (mut sum, mut n) = ([0.0; 3], 0);
    for (i, p) in px.iter().enumerate() {
        if ((mask >> i) & 1 == 1) == want {
            (0..3).for_each(|k| sum[k] += p[k]);
            n += 1;
        }
    }
    (sum.map(|s| s / n.max(1) as f32), n)
}

/// The two colours and the glyph that come closest to a cell's pixels.
fn best(px: &[[f32; 3]], split_gain: f32, split_min: f32) -> (usize, [f32; 3], [f32; 3]) {
    let last = 1 << (px.len() - 1);
    let (all, _) = mean(px, (1 << px.len()) - 1, true);
    let one: f32 = px.iter().map(|p| (0..3).map(|k| (p[k] - all[k]).powi(2)).sum::<f32>()).sum();
    // the one-colour cell: the whole cell takes the foreground (the full block)
    let mut top = ((one * split_gain).min(one - split_min), (1 << px.len()) - 1, all, all);
    // the last pixel always belongs to the background, so every split is tried once
    for mask in 1..last {
        let ((a, _), (b, _)) = (mean(px, mask, true), mean(px, mask, false));
        let err: f32 = px.iter().enumerate().map(|(i, p)| { let m = if (mask >> i) & 1 == 1 { a } else { b }; (0..3).map(|k| (p[k] - m[k]).powi(2)).sum::<f32>() }).sum();
        if err < top.0 {
            top = (err, mask, a, b);
        }
    }
    (top.1, top.2, top.3)
}

/// How the cells are chosen and, in the 256-colour mode, how the colour error is passed on; `Tuning::for_mode` is what the player uses.
#[derive(Clone, Copy, Debug)]
pub struct Tuning {
    /// Share of a pixel's colour error passed to its neighbours (0: none, the nearest palette colour only).
    pub spread: f32,
    /// A cell is split in two colours only when that cuts its error to less than this share of the one-colour error (1: always
    /// split when it helps at all). Keeps flat areas flat: a cell whose pixels differ only by grain stays one colour.
    pub split_gain: f32,
    /// ...and the split must also remove at least this much squared colour error (summed over the cell's pixels and channels),
    /// so grain a few levels deep is not mistaken for an edge.
    pub split_min: f32,
    /// Choose the split from the picture's own pixels, not from the ones the spread has moved, so the glyphs follow the real
    /// edges and not the dither.
    pub split_from_source: bool,
}

impl Tuning {
    /// What the player uses. True colour needs no help: every cell takes its best two colours. The 256-colour palette is coarse,
    /// and spreading the error freely turns a flat red with paper grain into a field of dots and blurs the lettering on it, so
    /// there a cell stays one colour unless splitting it clearly helps (`split_gain`), the glyphs follow the picture's own
    /// edges, and only a little error is passed on (enough to break up the steps of a gradient).
    pub fn for_mode(indexed: bool) -> Self {
        if indexed {
            Tuning { spread: 0.3, split_gain: 0.6, split_min: 500.0, split_from_source: true }
        } else {
            Tuning { spread: 0.0, split_gain: 1.0, split_min: 0.0, split_from_source: false }
        }
    }

    /// Free error spreading with every split allowed: the strongest dithering, for the tests of the algorithm.
    #[cfg(test)]
    fn strong() -> Self {
        Tuning { spread: SPREAD, split_gain: 1.0, split_min: 0.0, split_from_source: false }
    }
}

/// Draws `img` (its width and height must be multiples of the cell's) as rows of cells.
pub fn render(img: &RgbImage, shape: Shape, indexed: bool) -> Vec<Vec<Cell>> {
    render_with(img, shape, indexed, Tuning::for_mode(indexed))
}

pub fn render_with(img: &RgbImage, shape: Shape, indexed: bool, tune: Tuning) -> Vec<Vec<Cell>> {
    let (cw, ch) = shape.pixels();
    let (w, h) = (img.width() as usize, img.height() as usize);
    let (cols, rows) = (w / cw, h / ch);
    let mut err = vec![[0.0f32; 3]; w * h];
    let mut out = vec![];

    for cy in 0..rows {
        let mut line = Vec::with_capacity(cols);
        for cx in 0..cols {
            let at = |i: usize| ((cx * cw + i % cw), (cy * ch + i / cw));
            let px: Vec<[f32; 3]> = (0..cw * ch)
                .map(|i| {
                    let (x, y) = at(i);
                    let p = img.get_pixel(x as u32, y as u32).0;
                    [0, 1, 2].map(|k| (p[k] as f32 + err[y * w + x][k]).clamp(0.0, 255.0))
                })
                .collect();
            let (mask, fg, bg) = if tune.split_from_source {
                let src: Vec<[f32; 3]> = (0..cw * ch).map(|i| { let (x, y) = at(i); img.get_pixel(x as u32, y as u32).0.map(f32::from) }).collect();
                let (m, _, _) = best(&src, tune.split_gain, tune.split_min);
                let full = (1 << px.len()) - 1;
                let (a, _) = mean(&px, m, true);
                (m, a, if m == full { a } else { mean(&px, m, false).0 })
            } else {
                best(&px, tune.split_gain, tune.split_min)
            };
            let (fgc, bgc, shown) = if indexed {
                let ((fi, fr), (bi, br)) = (nearest(fg), nearest(bg));
                (Color::Indexed(fi), Color::Indexed(bi), Some((fr, br)))
            } else {
                let rgb = |c: [f32; 3]| Color::Rgb(c[0].round() as u8, c[1].round() as u8, c[2].round() as u8);
                (rgb(fg), rgb(bg), None)
            };
            if let Some((fr, br)) = shown {
                // what is left over goes to the pixels that are not drawn yet: right, and the row below
                for (i, p) in px.iter().enumerate() {
                    let (x, y) = at(i);
                    let used = if (mask >> i) & 1 == 1 { fr } else { br };
                    // only part of the error is passed on, and never a large one: a flat colour between two palette entries
                    // should settle on the nearer ones, not wander through greys
                    let raw = [0, 1, 2].map(|k| p[k] - used[k]);
                    let lum = (raw[0] + raw[1] + raw[2]) / 3.0;
                    // brightness error travels on; colour error only in part, or a grey picture fills with tints
                    let sat = p.iter().cloned().fold(f32::MIN, f32::max) - p.iter().cloned().fold(f32::MAX, f32::min);
                    let chroma = ((sat - 20.0) / 60.0).clamp(MIN_CHROMA, MAX_CHROMA);
                    let e = raw.map(|x| ((lum + (x - lum) * chroma) * tune.spread).clamp(-MAX_ERROR, MAX_ERROR));
                    for (dx, dy, wt) in [(1i32, 0i32, 7.0f32), (-1, 1, 3.0), (0, 1, 5.0), (1, 1, 1.0)] {
                        let (nx, ny) = (x as i32 + dx, y as i32 + dy);
                        if nx < 0 || ny < 0 || nx as usize >= w || ny as usize >= h {
                            continue;
                        }
                        let (ncx, ncy) = (nx as usize / cw, ny as usize / ch);
                        if (ncy, ncx) > (cy, cx) {
                            (0..3).for_each(|k| err[ny as usize * w + nx as usize][k] += e[k] * wt / 16.0);
                        }
                    }
                }
            }
            line.push(Cell { ch: shape.glyph(mask), fg: fgc, bg: bgc });
        }
        out.push(line);
    }
    out
}

/// The RGB of a colour that `render` produced.
pub fn rgb_of(c: Color) -> (u8, u8, u8) {
    match c {
        Color::Rgb(r, g, b) => (r, g, b),
        Color::Indexed(i) if i >= 232 => { let v = 8 + 10 * (i - 232); (v, v, v) }
        Color::Indexed(i) if i >= 16 => { let n = i - 16; (LEVELS[(n / 36) as usize] as u8, LEVELS[((n / 6) % 6) as usize] as u8, LEVELS[(n % 6) as usize] as u8) }
        _ => (0, 0, 0),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use image::Rgb;

    fn solid(w: u32, h: u32, c: [u8; 3]) -> RgbImage {
        RgbImage::from_pixel(w, h, Rgb(c))
    }

    #[test]
    fn a_split_picture_is_drawn_with_the_matching_block() {
        let mut img = solid(2, 2, [255, 0, 0]);
        img.put_pixel(1, 0, Rgb([0, 0, 255]));
        img.put_pixel(1, 1, Rgb([0, 0, 255]));
        let cell = render(&img, Shape::Quadrant, false)[0][0];
        // left half red, right half blue: a left-half block with red in front and blue behind, or the reverse
        assert!(cell.ch == '▌' || cell.ch == '▐', "{}", cell.ch);
        let (a, b) = (rgb_of(cell.fg), rgb_of(cell.bg));
        assert!(matches!((a, b), ((255, 0, 0), (0, 0, 255)) | ((0, 0, 255), (255, 0, 0))), "{a:?} {b:?}");
    }

    #[test]
    fn a_diagonal_edge_gets_a_quadrant_pair_that_half_blocks_cannot_draw() {
        let mut img = solid(2, 2, [0, 0, 0]);
        img.put_pixel(0, 0, Rgb([255, 255, 255]));
        img.put_pixel(1, 1, Rgb([255, 255, 255]));
        let cell = render(&img, Shape::Quadrant, false)[0][0];
        assert!(cell.ch == '▚' || cell.ch == '▞', "{}", cell.ch);
    }

    #[test]
    fn half_blocks_put_the_top_pixel_in_front() {
        let mut img = solid(1, 2, [10, 200, 10]);
        img.put_pixel(0, 1, Rgb([200, 10, 10]));
        let cell = render(&img, Shape::Half, false)[0][0];
        assert_eq!((cell.ch, rgb_of(cell.fg), rgb_of(cell.bg)), ('▀', (10, 200, 10), (200, 10, 10)));
    }

    #[test]
    fn one_colour_gives_one_colour() {
        for shape in [Shape::Half, Shape::Quadrant] {
            let (w, h) = (shape.pixels().0 as u32 * 3, shape.pixels().1 as u32 * 2);
            let rows = render(&solid(w, h, [40, 90, 160]), shape, false);
            assert_eq!((rows.len(), rows[0].len()), (2, 3));
            assert!(rows.iter().flatten().all(|c| c.fg == c.bg && rgb_of(c.fg) == (40, 90, 160)));
        }
    }

    #[test]
    fn the_nearest_palette_colour_is_the_nearest_one() {
        assert_eq!(nearest([0.0, 0.0, 0.0]).0, 16);
        assert_eq!(nearest([255.0, 255.0, 255.0]).0, 231);
        assert_eq!(nearest([255.0, 0.0, 0.0]).0, 196);
        // 100 is 5 from the cube's 95 and 35 from 135: the old rounding picked 135
        assert_eq!(nearest([100.0, 0.0, 0.0]).1, [95.0, 0.0, 0.0]);
        assert!((232..=255).contains(&nearest([128.0, 128.0, 128.0]).0));
    }

    /// A colour that is not in the 256-colour palette: the average of what is drawn must be at least as near as the single
    /// nearest colour. The spreading is kept mild (a flat colour stays calm), so on a flat colour it may do no more than that;
    /// on gradients it is what keeps the bands apart.
    /// The case that looked bad in Terminal.app: a flat red with paper grain and white lettering. The grain must not turn into
    /// dots (few different cells in the flat part), and the lettering must stay a clean white-on-red edge.
    #[test]
    fn grain_on_a_flat_colour_stays_calm_in_256_colours_and_the_lettering_stays_sharp() {
        let mut img = RgbImage::new(80, 40);
        let mut seed = 12345u32;
        for (x, y, p) in img.enumerate_pixels_mut() {
            seed = seed.wrapping_mul(1664525).wrapping_add(1013904223);
            let grain = ((seed >> 24) % 13) as i32 - 6; // +-6 levels of paper grain
            let lettering = (20..60).contains(&x) && (14..26).contains(&y);
            *p = if lettering { Rgb([250, 250, 250]) } else { Rgb([(190 + grain) as u8, (57 + grain) as u8, (42 + grain) as u8]) };
        }
        let rows = render(&img, Shape::Quadrant, true);
        let flat: Vec<&Cell> = rows[..6].iter().flatten().collect(); // the rows above the lettering
        let distinct: std::collections::HashSet<_> = flat.iter().map(|c| (c.ch, c.fg, c.bg)).collect();
        assert!(distinct.len() <= 3, "{} different cells in a flat area", distinct.len());
        let white = rows[10..13].iter().flatten().filter(|c| rgb_of(c.fg).0 > 200 && rgb_of(c.fg).1 > 200 && rgb_of(c.bg).1 > 200).count();
        assert_eq!(white, 3 * 20, "the lettering lost its edges"); // three rows of twenty cells, all white
    }

    #[test]
    fn a_flat_dark_red_stays_red_in_256_colours() {
        // the old renderer turned this maroon into a flat grey; every cell must keep a red or magenta-red hue, not grey
        let rows = render_with(&solid(40, 40, [110, 20, 35]), Shape::Quadrant, true, Tuning::strong());
        let red = rows.iter().flatten().filter(|c| { let (r, g, b) = rgb_of(c.fg); r > g + 40 && r + 20 > b }).count();
        assert!(red * 10 >= rows.iter().flatten().count() * 9, "{red} of {} cells have a red foreground", rows.iter().flatten().count());
    }

    #[test]
    fn a_smooth_gradient_in_256_colours_follows_the_original_closer_than_flat_bands() {
        // dark red to orange across 120 pixels: the palette has only a handful of steps in between
        let mut img = solid(120, 8, [0, 0, 0]);
        for x in 0..120u32 {
            for y in 0..8 {
                img.put_pixel(x, y, Rgb([(90 + x) as u8, (x / 3) as u8, 0]));
            }
        }
        let rows = render_with(&img, Shape::Quadrant, true, Tuning::strong());
        let width_of_runs = |cells: &[Cell]| { let mut runs = 1; for w in cells.windows(2) { if w[0].fg != w[1].fg || w[0].bg != w[1].bg { runs += 1; } } runs };
        // flat bands would change colour about once per palette step (a few times across the picture); a dithered one keeps changing
        assert!(width_of_runs(&rows[0]) > 8, "{} colour changes", width_of_runs(&rows[0]));
    }

    #[test]
    fn the_256_colour_mode_keeps_the_average_colour() {
        let target = [110.0f32, 62.0, 140.0];
        let img = solid(40, 40, [110, 62, 140]);
        let rows = render_with(&img, Shape::Quadrant, true, Tuning::strong());
        let mut sum = [0.0f32; 3];
        let mut n = 0.0;
        for c in rows.iter().flatten() {
            assert!(matches!(c.fg, Color::Indexed(i) if i >= 16) && matches!(c.bg, Color::Indexed(i) if i >= 16));
            let (f, b) = (rgb_of(c.fg), rgb_of(c.bg));
            // the glyph says how much of the cell each colour covers
            let covered = match c.ch { ' ' => 0.0, '█' => 1.0, '▀' | '▄' | '▌' | '▐' | '▞' | '▚' => 0.5, _ => 0.25 };
            let covered = if matches!(c.ch, '▛' | '▜' | '▙' | '▟') { 0.75 } else { covered };
            for k in 0..3 {
                sum[k] += [f.0, f.1, f.2][k] as f32 * covered + [b.0, b.1, b.2][k] as f32 * (1.0 - covered);
            }
            n += 1.0;
        }
        let avg = sum.map(|s| s / n);
        let off: f32 = (0..3).map(|k| (avg[k] - target[k]).abs()).sum::<f32>() / 3.0;
        let plain = nearest(target).1;
        let plain_off: f32 = (0..3).map(|k| (plain[k] - target[k]).abs()).sum::<f32>() / 3.0;
        assert!(off <= plain_off + 0.5, "dithered {off:.1} off, nearest single colour {plain_off:.1} off ({avg:?})");
    }

    /// Cells back to pixels, to look at them.
    fn unrender(cells: &[Vec<Cell>], shape: Shape, scale: u32) -> RgbImage {
        let (cw, ch) = shape.pixels();
        let (cols, rows) = (cells[0].len(), cells.len());
        let mut out = RgbImage::new((cols * cw) as u32 * scale, (rows * ch) as u32 * scale);
        for (cy, row) in cells.iter().enumerate() {
            for (cx, cell) in row.iter().enumerate() {
                let mask = (0..1 << (cw * ch)).find(|&m| shape.glyph(m) == cell.ch).unwrap();
                for i in 0..cw * ch {
                    let (r, g, b) = rgb_of(if (mask >> i) & 1 == 1 { cell.fg } else { cell.bg });
                    let (x, y) = ((cx * cw + i % cw) as u32, (cy * ch + i / cw) as u32);
                    for dy in 0..scale { for dx in 0..scale { out.put_pixel(x * scale + dx, y * scale + dy, Rgb([r, g, b])); } }
                }
            }
        }
        out
    }

    /// `MAGNETITE_PREVIEW=cover.jpg MAGNETITE_PREVIEW_OUT=dir cargo test preview -- --ignored`: writes what each mode draws.
    #[test]
    #[ignore]
    fn preview() {
        let (src, out) = (std::env::var("MAGNETITE_PREVIEW").unwrap(), std::env::var("MAGNETITE_PREVIEW_OUT").unwrap());
        let img = image::open(src).unwrap().to_rgb8();
        {
            // what the player drew before: Triangle resize, every pixel its nearest palette colour, half blocks
            let old = image::imageops::resize(&img, 50, 50, image::imageops::FilterType::Triangle);
            let mut shown = old.clone();
            for p in shown.pixels_mut() {
                let (r, g, b) = rgb_of(Color::Indexed(crate::theme::xterm256((p.0[0], p.0[1], p.0[2]))));
                *p = Rgb([r, g, b]);
            }
            image::imageops::resize(&shown, 800, 800, image::imageops::FilterType::Nearest).save(format!("{out}/old-256.png")).unwrap();
        }
        let variants: [(&str, Shape, bool, Tuning); 5] = [
            ("a-strong", Shape::Quadrant, true, Tuning::strong()),
            ("b-nearest", Shape::Quadrant, true, Tuning { spread: 0.0, split_gain: 1.0, split_min: 0.0, split_from_source: false }),
            ("c-calm", Shape::Quadrant, true, Tuning { spread: 0.3, split_gain: 0.6, split_min: 500.0, split_from_source: true }),
            ("d-nearest-calm-split", Shape::Quadrant, true, Tuning { spread: 0.0, split_gain: 0.6, split_min: 500.0, split_from_source: true }),
            ("e-true", Shape::Quadrant, false, Tuning::for_mode(false)),
        ];
        for (name, shape, indexed, tune) in variants {
            let (cols, rows) = (100u32, 50u32);
            let (cw, ch) = shape.pixels();
            let grid = image::imageops::resize(&img, cols * cw as u32, rows * ch as u32, image::imageops::FilterType::Lanczos3);
            let grid = image::imageops::unsharpen(&grid, 0.7, 2);
            let cells = render_with(&grid, shape, indexed, tune);
            // the same on screen size: a pixel of the half-block picture is twice as wide as a quadrant's
            let mean = |im: &RgbImage| { let n = (im.width() * im.height()) as f64; (0..3).map(|k| (im.pixels().map(|p| p.0[k] as f64).sum::<f64>() / n).round()).collect::<Vec<_>>() };
            println!("{name}: source mean {:?}, drawn mean {:?}", mean(&grid), mean(&unrender(&cells, shape, 1)));
            let picture = unrender(&cells, shape, 4);
            let picture = image::imageops::resize(&picture, 800, 800, image::imageops::FilterType::Nearest);
            picture.save(format!("{out}/{name}.png")).unwrap();
        }
    }
}
