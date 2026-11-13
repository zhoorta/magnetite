//! Themes: the same JSON bundles as the web player (palette only; radius, font and visualizer looks do not apply in a terminal).
//! Built-in ones come from `resources/js/magnetite/themes.json`; your own go in `~/.config/magnetite/themes/*.json`
//! (one theme object or a list of them).
use ratatui::style::Color;
use serde_json::Value;
use std::sync::OnceLock;

pub type Rgb = (u8, u8, u8);

#[derive(Clone, Debug)]
pub struct Theme {
    pub id: String,
    pub name: String,
    pub bg: Rgb,
    pub surface: Rgb,
    pub text: Rgb,
    pub muted: Rgb,
    pub accent: Rgb,
    pub accent2: Rgb,
}

pub fn truecolor() -> bool {
    static T: OnceLock<bool> = OnceLock::new();
    *T.get_or_init(|| std::env::var("COLORTERM").map(|v| v == "truecolor" || v == "24bit").unwrap_or(false))
}

/// A colour for this terminal: exact RGB where truecolor is announced, else the nearest of the 256-colour palette
/// (Terminal.app has no truecolor).
pub fn c(rgb: Rgb) -> Color {
    if truecolor() {
        Color::Rgb(rgb.0, rgb.1, rgb.2)
    } else {
        Color::Indexed(xterm256(rgb))
    }
}

pub fn xterm256((r, g, b): Rgb) -> u8 {
    let cube = |v: u8| (v as f32 / 255.0 * 5.0).round() as u8;
    let level = |i: u8| if i == 0 { 0 } else { 55 + 40 * i as i32 };
    let (cr, cg, cb) = (cube(r), cube(g), cube(b));
    let cube_rgb = (level(cr), level(cg), level(cb));
    let avg = (r as i32 + g as i32 + b as i32) / 3;
    let gi = ((avg - 8).max(0) as f32 / 10.0).round().min(23.0) as u8;
    let gray = 8 + 10 * gi as i32;
    let dist = |p: (i32, i32, i32)| (p.0 - r as i32).pow(2) + (p.1 - g as i32).pow(2) + (p.2 - b as i32).pow(2);
    if dist((gray, gray, gray)) < dist(cube_rgb) {
        232 + gi
    } else {
        16 + 36 * cr + 6 * cg + cb
    }
}

pub fn lerp(a: Rgb, b: Rgb, t: f32) -> Rgb {
    let m = |x: u8, y: u8| (x as f32 + (y as f32 - x as f32) * t.clamp(0.0, 1.0)).round() as u8;
    (m(a.0, b.0), m(a.1, b.1), m(a.2, b.2))
}

fn hex(s: &str) -> Option<Rgb> {
    let h = s.trim().strip_prefix('#')?;
    let p = |i: usize, n: usize| u8::from_str_radix(&h[i..i + n], 16).ok();
    match h.len() {
        6 => Some((p(0, 2)?, p(2, 2)?, p(4, 2)?)),
        3 => Some((p(0, 1)? * 17, p(1, 1)? * 17, p(2, 1)? * 17)),
        _ => None,
    }
}

pub fn parse(v: &Value) -> Option<Theme> {
    let col = &v["colors"];
    let get = |k: &str| col[k].as_str().and_then(hex);
    let (bg, text, accent) = (get("bg")?, get("text")?, get("accent")?);
    let name = v["name"].as_str().or(v["id"].as_str())?.to_string();
    Some(Theme {
        id: v["id"].as_str().map(str::to_string).unwrap_or_else(|| name.to_lowercase().replace(' ', "-")),
        name,
        bg,
        text,
        accent,
        surface: get("surface").unwrap_or(bg),
        muted: get("muted").unwrap_or_else(|| lerp(text, bg, 0.5)),
        accent2: get("accent2").unwrap_or(accent),
    })
}

fn parse_many(v: &Value) -> Vec<Theme> {
    match v {
        Value::Array(a) => a.iter().filter_map(parse).collect(),
        other => parse(other).into_iter().collect(),
    }
}

pub fn builtin() -> Vec<Theme> {
    parse_many(&serde_json::from_str(include_str!("../../resources/js/magnetite/themes.json")).unwrap_or(Value::Null))
}

fn user_dir() -> std::path::PathBuf {
    crate::config::dir().join("themes")
}

/// Built-ins first, then the user's own; a user theme with a built-in's id replaces it.
pub fn all() -> Vec<Theme> {
    let mut list = builtin();
    let mut files: Vec<_> = std::fs::read_dir(user_dir()).into_iter().flatten().flatten().map(|e| e.path()).filter(|p| p.extension().is_some_and(|x| x == "json")).collect();
    files.sort();
    for f in files {
        if let Some(v) = std::fs::read(&f).ok().and_then(|b| serde_json::from_slice::<Value>(&b).ok()) {
            for t in parse_many(&v) {
                match list.iter().position(|x| x.id == t.id) {
                    Some(i) => list[i] = t,
                    None => list.push(t),
                }
            }
        }
    }
    list
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_web_themes_load() {
        let t = builtin();
        assert!(t.len() >= 7);
        let term = t.iter().find(|t| t.id == "terminal").unwrap();
        assert_eq!(term.accent, (0x39, 0xff, 0x6a));
    }

    #[test]
    fn a_small_bundle_gets_defaults_and_a_broken_one_is_skipped() {
        let v: Value = serde_json::from_str(r##"[{"name":"Mine","colors":{"bg":"#000","text":"#fff","accent":"#f00"}},{"id":"bad","colors":{"bg":"nope"}}]"##).unwrap();
        let t = parse_many(&v);
        assert_eq!(t.len(), 1);
        assert_eq!((t[0].id.as_str(), t[0].bg, t[0].accent2), ("mine", (0, 0, 0), (255, 0, 0)));
    }

    #[test]
    fn the_256_fallback_picks_sensible_cells() {
        assert_eq!(xterm256((0, 0, 0)), 16);
        assert_eq!(xterm256((255, 255, 255)), 231);
        assert!((232..=255).contains(&xterm256((128, 128, 128))));
        assert_eq!(xterm256((255, 0, 0)), 196);
    }
}
