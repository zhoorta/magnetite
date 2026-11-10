//! `magnetite upload <zip|folder|audio files…>`: send music to the server, wait for the scan, then look at what arrived
//! (MusicBrainz titles, a cover, a few fields). The server does the work through the same endpoints as the web library.

use crate::api::{Album, Api};
use serde_json::{json, Value};
use std::io::{self, BufRead, Read, Write};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

#[derive(Default, Debug, PartialEq)]
pub struct Options {
    pub paths: Vec<PathBuf>,
    pub identify: bool,
    pub cover: bool,
    pub review: bool, // ask about every album (off with --no-review)
    pub artist: Option<String>,
    pub album: Option<String>,
    pub year: Option<i64>,
}

/// The words after `upload`. Flags: `--identify`, `--cover`, `--no-review`, `--artist X`, `--album X`, `--year N`.
pub fn parse(args: &[String]) -> Result<Options, String> {
    let mut o = Options { review: true, ..Default::default() };
    let mut it = args.iter();
    while let Some(a) = it.next() {
        let mut value = |name: &str| it.next().cloned().ok_or(format!("{name} needs a value"));
        match a.as_str() {
            "--identify" => o.identify = true,
            "--cover" => o.cover = true,
            "--no-review" => o.review = false,
            "--artist" => o.artist = Some(value("--artist")?),
            "--album" => o.album = Some(value("--album")?),
            "--year" => o.year = Some(value("--year")?.parse().map_err(|_| "--year needs a number".to_string())?),
            f if f.starts_with("--") => return Err(format!("unknown option {f}")),
            p => o.paths.push(PathBuf::from(p)),
        }
    }
    if o.paths.is_empty() {
        return Err("usage: magnetite upload <zip|folder|audio files…> [--identify] [--cover] [--no-review] [--artist X] [--album X] [--year N]".into());
    }
    Ok(o)
}

/// The server's list (`TrackLibrary::TYPES`); anything else it would skip.
const AUDIO: [&str; 6] = ["mp3", "m4a", "flac", "ogg", "opus", "wav"];

fn is_audio(p: &Path) -> bool {
    p.extension().and_then(|e| e.to_str()).is_some_and(|e| AUDIO.contains(&e.to_lowercase().as_str()))
}

fn is_zip(p: &Path) -> bool {
    p.extension().and_then(|e| e.to_str()).is_some_and(|e| e.eq_ignore_ascii_case("zip"))
}

/// What goes into one request: a zip (made from a folder, so it is deleted after) or a single audio file.
struct Item {
    path: PathBuf,
    field: &'static str, // "archives" or "files"
    name: String,
    temp: bool,
}

impl Drop for Item {
    fn drop(&mut self) {
        if self.temp {
            let _ = std::fs::remove_file(&self.path);
        }
    }
}

/// A folder as a zip in the temp folder, stored without compression (audio does not shrink). Only audio and cover images go in:
/// the server would skip the rest, and this keeps the upload small.
fn zip_folder(dir: &Path) -> Result<Item, String> {
    let name = dir.canonicalize().ok().and_then(|p| p.file_name().map(|n| n.to_string_lossy().into_owned())).unwrap_or_else(|| "upload".into());
    let tmp = std::env::temp_dir().join(format!("magnetite-{}-{}.zip", std::process::id(), name.replace(['/', '\\'], "_")));
    let file = std::fs::File::create(&tmp).map_err(|e| e.to_string())?;
    let mut zip = zip::ZipWriter::new(file);
    let opts = zip::write::SimpleFileOptions::default().compression_method(zip::CompressionMethod::Stored).large_file(true);
    let mut count = 0;
    let mut stack = vec![dir.to_path_buf()];
    while let Some(d) = stack.pop() {
        let mut entries: Vec<_> = std::fs::read_dir(&d).map_err(|e| format!("{}: {e}", d.display()))?.flatten().map(|e| e.path()).collect();
        entries.sort();
        for p in entries {
            let hidden = p.file_name().is_some_and(|n| n.to_string_lossy().starts_with('.'));
            if hidden {
                continue;
            }
            if p.is_dir() {
                stack.push(p);
            } else if is_audio(&p) || p.extension().and_then(|e| e.to_str()).is_some_and(|e| ["jpg", "jpeg", "png"].contains(&e.to_lowercase().as_str())) {
                let rel = p.strip_prefix(dir).unwrap_or(&p).to_string_lossy().replace('\\', "/");
                zip.start_file(rel, opts).map_err(|e| e.to_string())?;
                io::copy(&mut std::fs::File::open(&p).map_err(|e| e.to_string())?, &mut zip).map_err(|e| e.to_string())?;
                if is_audio(&p) {
                    count += 1;
                }
            }
        }
    }
    zip.finish().map_err(|e| e.to_string())?;
    if count == 0 {
        let _ = std::fs::remove_file(&tmp);
        return Err(format!("{}: no audio files in it", dir.display()));
    }
    Ok(Item { path: tmp, field: "archives", name: format!("{name}.zip"), temp: true })
}

fn items(paths: &[PathBuf]) -> Result<Vec<Item>, String> {
    let mut out = vec![];
    for p in paths {
        if p.is_dir() {
            out.push(zip_folder(p)?);
        } else if !p.is_file() {
            return Err(format!("{}: not found", p.display()));
        } else if is_zip(p) {
            out.push(Item { path: p.clone(), field: "archives", name: p.file_name().unwrap().to_string_lossy().into_owned(), temp: false });
        } else if is_audio(p) {
            out.push(Item { path: p.clone(), field: "files", name: p.file_name().unwrap().to_string_lossy().into_owned(), temp: false });
        } else {
            return Err(format!("{}: not a zip, a folder or an audio file", p.display()));
        }
    }
    Ok(out)
}

/// Counts what passes through and prints the percentage.
struct Progress<R> {
    inner: R,
    done: u64,
    total: u64,
    label: String,
    shown: u64,
}

impl<R: Read> Read for Progress<R> {
    fn read(&mut self, buf: &mut [u8]) -> io::Result<usize> {
        let n = self.inner.read(buf)?;
        self.done += n as u64;
        let pct = if self.total == 0 { 100 } else { self.done * 100 / self.total };
        if pct != self.shown {
            self.shown = pct;
            print!("\r  {} {:>3}%  ({:.1} of {:.1} MB)", self.label, pct, self.done as f64 / 1e6, self.total as f64 / 1e6);
            let _ = io::stdout().flush();
        }
        Ok(n)
    }
}

/// The multipart body of one file, as header + file + footer, and its length (nothing is read into memory).
fn body(item: &Item) -> Result<(String, u64, impl Read), String> {
    let boundary = format!("----magnetite{:x}", std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map_or(0, |d| d.as_nanos()));
    let name = item.name.replace(['"', '\r', '\n'], "_");
    let ctype = if item.field == "archives" { "application/zip" } else { "application/octet-stream" };
    let head = format!("--{boundary}\r\nContent-Disposition: form-data; name=\"{}[]\"; filename=\"{name}\"\r\nContent-Type: {ctype}\r\n\r\n", item.field);
    let tail = format!("\r\n--{boundary}--\r\n");
    let file = std::fs::File::open(&item.path).map_err(|e| format!("{}: {e}", item.path.display()))?;
    let len = head.len() as u64 + file.metadata().map_err(|e| e.to_string())?.len() + tail.len() as u64;
    Ok((format!("multipart/form-data; boundary={boundary}"), len, io::Cursor::new(head.into_bytes()).chain(file).chain(io::Cursor::new(tail.into_bytes()))))
}

/// Uploads one item; prints what the server said. Returns the server time from before it wrote, and whether anything was added.
fn send(api: &Api, item: &Item) -> Result<(i64, bool), String> {
    let (ctype, len, reader) = body(item)?;
    let progress = Progress { inner: reader, done: 0, total: len, label: item.name.clone(), shown: 101 };
    let answer = api.upload(&ctype, len, progress)?;
    println!();
    let since = answer["since"].as_i64().unwrap_or(0);
    let mut added = false;
    for r in answer["archives"].as_array().into_iter().flatten() {
        if r["ok"].as_bool() == Some(true) {
            added |= r["added"].as_u64().unwrap_or(0) > 0;
            println!("  {}: {} added, {} already there{}", r["name"].as_str().unwrap_or(""), r["added"], r["existing"], match r["skipped"].as_u64() { Some(n) if n > 0 => format!(", {n} skipped"), _ => String::new() });
        } else {
            eprintln!("  {}: {}", r["name"].as_str().unwrap_or(""), r["error"].as_str().unwrap_or("refused"));
        }
    }
    for r in answer["files"].as_array().into_iter().flatten() {
        if r["ok"].as_bool() == Some(true) {
            added |= r["status"] == "added";
            println!("  {}: {}", r["name"].as_str().unwrap_or(&item.name), r["status"].as_str().unwrap_or("ok"));
        } else {
            eprintln!("  {}: {}", r["name"].as_str().unwrap_or(&item.name), r["error"].as_str().or(r["message"].as_str()).unwrap_or("refused"));
        }
    }
    Ok((since, added))
}

/// Polls the scan until it has finished (or failed, or 30 minutes passed).
fn wait_for_scan(api: &Api) -> Result<(), String> {
    let start = Instant::now();
    loop {
        let s = api.scan()?;
        match s["state"].as_str().unwrap_or("idle") {
            "done" | "idle" => return Ok(println!("\r  scan finished{}", " ".repeat(20))),
            "failed" => return Err(format!("the scan failed: {}", s["error"].as_str().unwrap_or("unknown error"))),
            st => {
                print!("\r  scan {st}: {} files read", s["processed"].as_u64().unwrap_or(0));
                let _ = io::stdout().flush();
            }
        }
        if start.elapsed() > Duration::from_secs(1800) {
            return Err("the scan is taking more than 30 minutes; it keeps going on the server".into());
        }
        std::thread::sleep(Duration::from_secs(2));
    }
}

fn ask(prompt: &str) -> String {
    print!("{prompt}");
    let _ = io::stdout().flush();
    let mut line = String::new();
    let _ = io::stdin().lock().read_line(&mut line);
    line.trim().to_string()
}

pub fn run(api: &Api, args: &[String]) -> i32 {
    let opts = match parse(args) {
        Ok(o) => o,
        Err(e) => return fail(&e),
    };
    let items = match items(&opts.paths) {
        Ok(i) => i,
        Err(e) => return fail(&e),
    };
    let (mut since, mut any) = (i64::MAX, false);
    for item in &items {
        match send(api, item) {
            Ok((s, added)) => {
                since = since.min(s.max(1));
                any |= added;
            }
            Err(e) => return fail(&format!("{}: {e}", item.name)),
        }
    }
    drop(items);
    if !any {
        println!("Nothing new was added (the files were already there, or were refused).");
        return 0;
    }
    if let Err(e) = wait_for_scan(api) {
        return fail(&e);
    }
    let albums = match api.albums_since(since) {
        Ok(a) => a,
        Err(e) => return fail(&e),
    };
    let interactive = opts.review && io::IsTerminal::is_terminal(&io::stdin());
    let made = name_loose(api, since, &opts, interactive);
    if albums.is_empty() && made.is_empty() {
        println!("The scan found no new albums.");
        return 0;
    }
    println!("\n{} album{} from this upload:", albums.len() + made.len(), if albums.len() + made.len() == 1 { "" } else { "s" });
    for a in albums.iter().chain(&made) {
        println!("  #{:<5} {} · {} ({} tracks)", a.id, a.shown_artist(), a.title, a.track_count.unwrap_or(0));
    }
    // the named ones already carry what the flags said
    let todo: Vec<(Album, bool)> = albums.into_iter().map(|a| (a, true)).chain(made.into_iter().map(|a| (a, false))).collect();
    for (a, edit) in todo {
        let a = if edit { edit_fields(api, a, &opts) } else { a };
        println!("\n{} · {}", a.shown_artist(), a.title);
        if opts.identify && !identify(api, &a, true) && interactive {
            println!("  no confident match");
        }
        if opts.cover {
            cover(api, &a);
        }
        if interactive && !review(api, &a) {
            break;
        }
    }
    0
}

/// Files without an album tag stay loose after the scan. For each folder of them the person names an album (the flags
/// answer for every folder; at a terminal it asks, with the folder's name as the suggestion); without either, they are
/// only reported. Returns the albums made.
fn name_loose(api: &Api, since: i64, o: &Options, interactive: bool) -> Vec<Album> {
    let groups = match api.loose_tracks(since) {
        Ok(g) => g,
        Err(e) => {
            eprintln!("  loose tracks: {e}");
            return vec![];
        }
    };
    let mut made = vec![];
    for g in groups {
        let ids: Vec<u64> = g["tracks"].as_array().into_iter().flatten().filter_map(|t| t["id"].as_u64()).collect();
        let folder = g["folder"].as_str().unwrap_or("").to_string();
        let label = if folder.is_empty() { "the top folder".to_string() } else { format!("\"{folder}\"") };
        println!("\n{} track{} in {label} have no album tag.", ids.len(), if ids.len() == 1 { "" } else { "s" });
        let (mut title, mut artist, mut year) = (o.album.clone(), o.artist.clone(), o.year);
        if title.is_none() && interactive {
            let t = ask(&format!("  album name [{}] (- to leave them loose): ", if folder.is_empty() { "none" } else { &folder }));
            title = match t.as_str() {
                "-" => None,
                "" if !folder.is_empty() => Some(folder.clone()),
                "" => None,
                _ => Some(t),
            };
            if title.is_some() && artist.is_none() {
                artist = Some(ask("  artist (empty for none): ")).filter(|s| !s.is_empty());
            }
            if title.is_some() && year.is_none() {
                year = ask("  year (empty for none): ").parse().ok();
            }
        }
        let Some(title) = title else {
            println!("  left loose (name them with --album, or in the web library)");
            continue;
        };
        match api.create_album(json!({ "title": title, "artist": artist, "year": year, "track_ids": ids })) {
            Ok(a) => {
                println!("  album \"{}\" made from {} tracks", a.title, a.track_count.unwrap_or(0));
                made.push(a);
            }
            Err(e) => eprintln!("  {e}"),
        }
    }
    made
}

fn fail(msg: &str) -> i32 {
    eprintln!("{msg}");
    1
}

/// `--artist`, `--album` and `--year` apply to every new album.
fn edit_fields(api: &Api, mut a: Album, o: &Options) -> Album {
    if o.artist.is_none() && o.album.is_none() && o.year.is_none() {
        return a;
    }
    let title = o.album.clone().unwrap_or_else(|| a.title.clone());
    let mut f = json!({ "title": title });
    if let Some(x) = &o.artist {
        f["artist"] = json!(x);
    }
    if let Some(y) = o.year {
        f["year"] = json!(y);
    }
    match api.edit_album(a.id, f) {
        Ok(_) => {
            a.title = title;
            if o.artist.is_some() {
                a.artist = o.artist.clone();
                a.display_artist = o.artist.clone();
            }
            if o.year.is_some() {
                a.year = o.year;
            }
            println!("  #{} updated", a.id);
        }
        Err(e) => eprintln!("  #{}: {e}", a.id),
    }
    a
}

/// A candidate may be applied unattended only when it is a good fit and gives every track a title.
fn confident(c: &Value) -> bool {
    c["score"].as_f64().is_some_and(|s| s >= 0.8) && c["tracks"].as_array().is_some_and(|t| !t.is_empty() && t.iter().all(|x| x["matched"].as_bool() == Some(true)))
}

fn show_candidate(c: &Value) {
    println!("  {} · {}  {}{}  fit {:.0}%, {} of {} tracks", c["artist"].as_str().unwrap_or("?"), c["title"].as_str().unwrap_or("?"), c["date"].as_str().unwrap_or(""), c["label"].as_str().map(|l| format!("  [{l}]")).unwrap_or_default(), c["score"].as_f64().unwrap_or(0.0) * 100.0, c["matched"], c["tracks"].as_array().map_or(0, Vec::len));
    for t in c["tracks"].as_array().into_iter().flatten() {
        let (cur, new) = (t["current"].as_str().unwrap_or(""), t["title"].as_str().unwrap_or(""));
        let mark = if t["matched"].as_bool() == Some(true) { " " } else { "?" };
        let n = t["number"].as_i64().map_or(String::new(), |n| format!("{n:>2}"));
        if cur == new {
            println!("   {mark} {n} {new}");
        } else {
            println!("   {mark} {n} {cur}  →  {new}");
        }
    }
}

/// Looks up titles. Unattended (`auto`) it applies the best candidate only when `confident`; otherwise it shows each
/// candidate and asks. Returns whether something was applied.
fn identify(api: &Api, a: &Album, auto: bool) -> bool {
    let found = match api.find_titles(a.id) {
        Ok(f) => f,
        Err(e) => {
            eprintln!("  MusicBrainz: {e}");
            return false;
        }
    };
    let candidates = found["candidates"].as_array().cloned().unwrap_or_default();
    if candidates.is_empty() {
        println!("  MusicBrainz found nothing for this album");
        return false;
    }
    if auto {
        if !confident(&candidates[0]) {
            return false;
        }
        show_candidate(&candidates[0]);
        return apply(api, a, 0, &candidates[0]);
    }
    for (i, c) in candidates.iter().enumerate() {
        println!("  candidate {} of {}", i + 1, candidates.len());
        show_candidate(c);
        match ask("  apply it? [y]es / [n]ext / [s]kip: ").to_lowercase().as_str() {
            "y" | "yes" => return apply(api, a, i, c),
            "s" | "skip" | "q" => return false,
            _ => {}
        }
    }
    false
}

fn apply(api: &Api, a: &Album, index: usize, c: &Value) -> bool {
    let mut parts = vec!["titles"];
    if c["date"].as_str().is_some() {
        parts.push("date");
    }
    if c["label"].as_str().is_some() {
        parts.push("label");
    }
    match api.apply_titles(a.id, index, &parts) {
        Ok(r) => {
            println!("  applied: {} titles{}{}", r["done"]["titles"], if r["done"]["date"] == true { ", date" } else { "" }, if r["done"]["label"] == true { ", label" } else { "" });
            true
        }
        Err(e) => {
            eprintln!("  {e}");
            false
        }
    }
}

fn cover(api: &Api, a: &Album) {
    match api.find_cover(a.id) {
        Ok(r) => match r["source"].as_str() {
            Some(s) => println!("  cover set (from {s})"),
            None => println!("  no cover found"),
        },
        Err(e) => eprintln!("  cover: {e}"),
    }
}

/// The per-album menu. Returns false when the person quits the review.
fn review(api: &Api, a: &Album) -> bool {
    let mut a = a.clone();
    loop {
        match ask("  [i]dentify  [c]over  [e]dit  [enter] next  [q]uit: ").to_lowercase().as_str() {
            "i" => {
                identify(api, &a, false);
            }
            "c" => cover(api, &a),
            "e" => {
                let o = Options {
                    artist: Some(ask(&format!("  artist [{}]: ", a.shown_artist()))).filter(|s| !s.is_empty()),
                    album: Some(ask(&format!("  album [{}]: ", a.title))).filter(|s| !s.is_empty()),
                    year: ask(&format!("  year [{}]: ", a.year.map_or(String::new(), |y| y.to_string()))).parse().ok(),
                    ..Default::default()
                };
                a = edit_fields(api, a, &o);
            }
            "q" | "quit" => return false,
            "" | "n" | "next" => return true,
            _ => {}
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(a: &[&str]) -> Vec<String> {
        a.iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn reads_paths_and_flags() {
        let o = parse(&args(&["a.zip", "--identify", "--artist", "X Y", "dir", "--year", "1999", "--no-review"])).unwrap();
        assert_eq!(o.paths, vec![PathBuf::from("a.zip"), PathBuf::from("dir")]);
        assert!(o.identify && !o.cover && !o.review);
        assert_eq!((o.artist.as_deref(), o.year), (Some("X Y"), Some(1999)));
        assert!(parse(&args(&[])).is_err() && parse(&args(&["a", "--bogus"])).is_err() && parse(&args(&["a", "--year"])).is_err() && parse(&args(&["a", "--year", "x"])).is_err());
    }

    #[test]
    fn only_a_good_full_match_is_applied_alone() {
        let t = |m: bool| json!({"matched": m});
        assert!(confident(&json!({"score": 0.9, "tracks": [t(true), t(true)]})));
        assert!(!confident(&json!({"score": 0.9, "tracks": [t(true), t(false)]})));
        assert!(!confident(&json!({"score": 0.5, "tracks": [t(true)]})));
        assert!(!confident(&json!({"score": null, "tracks": [t(true)]})));
        assert!(!confident(&json!({"score": 0.9, "tracks": []})));
    }

    #[test]
    fn a_folder_becomes_a_zip_of_audio_and_covers_only() {
        let dir = std::env::temp_dir().join(format!("mag-up-test-{}", std::process::id())).join("Band - Record");
        std::fs::create_dir_all(dir.join("CD2")).unwrap();
        for f in ["01.flac", "CD2/02.mp3", "cover.jpg", "notes.txt", ".hidden.mp3"] {
            std::fs::write(dir.join(f), b"x").unwrap();
        }
        let item = zip_folder(&dir).unwrap();
        assert_eq!((item.field, item.name.as_str()), ("archives", "Band - Record.zip"));
        let mut names: Vec<String> = zip::ZipArchive::new(std::fs::File::open(&item.path).unwrap()).unwrap().file_names().map(String::from).collect();
        names.sort();
        assert_eq!(names, ["01.flac", "CD2/02.mp3", "cover.jpg"]);
        let path = item.path.clone();
        drop(item);
        assert!(!path.exists());
        std::fs::remove_dir_all(dir.parent().unwrap()).unwrap();
    }

    #[test]
    fn the_body_has_the_declared_length() {
        let f = std::env::temp_dir().join(format!("mag-body-{}.mp3", std::process::id()));
        std::fs::write(&f, b"abcdef").unwrap();
        let item = Item { path: f.clone(), field: "files", name: "we\"ird.mp3".into(), temp: false };
        let (ctype, len, mut r) = body(&item).unwrap();
        let mut out = vec![];
        r.read_to_end(&mut out).unwrap();
        assert_eq!(out.len() as u64, len);
        let text = String::from_utf8(out).unwrap();
        assert!(ctype.starts_with("multipart/form-data; boundary=") && text.contains("name=\"files[]\"; filename=\"we_ird.mp3\"") && text.contains("abcdef"));
        std::fs::remove_file(f).unwrap();
    }
}
