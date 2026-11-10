//! `magnetite analyze`: start the server's file analysis (DR, loudness, true peak, clipping, lossy-source findings) for an
//! album, a track or everything not yet measured, follow it, and print what it found. The same report ends `upload --analyze`.

use crate::api::{Album, Api, Track};
use crate::truth::severity;
use std::io::{self, BufRead, Write};
use std::time::{Duration, Instant};

#[derive(Debug, PartialEq)]
pub enum Target {
    All,
    Album(u64),
    Track(u64),
    Text(String),
    Status,
}

#[derive(Debug, PartialEq)]
pub struct Options {
    pub target: Target,
    pub wait: bool,
}

/// `analyze --all | --album ID | --track ID | --status | TEXT…`, with `--no-wait` to queue and return.
pub fn parse(args: &[String]) -> Result<Options, String> {
    let mut target = None;
    let mut text = vec![];
    let mut wait = true;
    let mut it = args.iter();
    let id = |v: Option<&String>, name: &str| v.and_then(|v| v.parse::<u64>().ok()).ok_or(format!("{name} needs an id"));
    while let Some(a) = it.next() {
        match a.as_str() {
            "--all" => target = Some(Target::All),
            "--status" => target = Some(Target::Status),
            "--album" => target = Some(Target::Album(id(it.next(), "--album")?)),
            "--track" => target = Some(Target::Track(id(it.next(), "--track")?)),
            "--no-wait" => wait = false,
            f if f.starts_with("--") => return Err(format!("unknown option {f}")),
            t => text.push(t.to_string()),
        }
    }
    let target = match (target, text.is_empty()) {
        (Some(t), true) => t,
        (None, false) => Target::Text(text.join(" ")),
        (Some(_), false) => return Err("give one thing to analyse: --all, --album, --track or a search text".into()),
        (None, true) => return Err("usage: magnetite analyze --all | --album ID | --track ID | TEXT… | --status  [--no-wait]".into()),
    };
    Ok(Options { target, wait })
}

pub fn run(api: &Api, args: &[String]) -> i32 {
    let o = match parse(args) {
        Ok(o) => o,
        Err(e) => return fail(&e),
    };
    match go(api, o) {
        Ok(()) => 0,
        Err(e) => fail(&e),
    }
}

fn fail(msg: &str) -> i32 {
    eprintln!("{msg}");
    1
}

fn go(api: &Api, o: Options) -> Result<(), String> {
    let (album, track) = match o.target {
        Target::Status => return Ok(println!("{}", describe(&api.analysis()?))),
        Target::All => (None, None),
        Target::Album(id) => (Some(id), None),
        Target::Track(id) => (None, Some(id)),
        Target::Text(t) => match resolve(api, &t)? {
            Found::Album(a) => (Some(a.id), None),
            Found::Track(t) => (None, Some(t.id)),
        },
    };
    let status = api.start_analysis(album, track)?;
    if !o.wait {
        return Ok(println!("queued: {}", describe(&status)));
    }
    wait(api, &album.into_iter().collect::<Vec<_>>())?;
    match (album, track) {
        (Some(id), _) => report_album(api, id),
        (_, Some(id)) => report_track(api, id),
        _ => Ok(println!("{}", describe(&api.analysis()?))),
    }
}

enum Found {
    Album(Album),
    Track(Track),
}

/// A search text that must lead to one album (or, failing that, one track); several are listed with their ids, or chosen at a terminal.
fn resolve(api: &Api, text: &str) -> Result<Found, String> {
    let r = api.search(text, 1)?;
    match (r.albums.len(), r.tracks.len()) {
        (0, 0) => Err(format!("nothing found for \"{text}\"")),
        (1, _) => Ok(Found::Album(r.albums.into_iter().next().unwrap())),
        (0, 1) => Ok(Found::Track(r.tracks.into_iter().next().unwrap())),
        (0, _) => Err(list_tracks(&r.tracks)),
        _ => {
            for (i, a) in r.albums.iter().enumerate() {
                println!("  {:>2}  #{:<5} {} · {}", i + 1, a.id, a.shown_artist(), a.title);
            }
            if !io::IsTerminal::is_terminal(&io::stdin()) {
                return Err("several albums match: pass one with --album ID".into());
            }
            print!("which one? [1-{}]: ", r.albums.len());
            let _ = io::stdout().flush();
            let mut line = String::new();
            let _ = io::stdin().lock().read_line(&mut line);
            match line.trim().parse::<usize>() {
                Ok(n) if (1..=r.albums.len()).contains(&n) => Ok(Found::Album(r.albums.into_iter().nth(n - 1).unwrap())),
                _ => Err("no album chosen".into()),
            }
        }
    }
}

fn list_tracks(tracks: &[Track]) -> String {
    let mut s = String::from("several tracks match: pass one with --track ID\n");
    for t in tracks.iter().take(15) {
        s += &format!("  #{:<6} {} — {}\n", t.id, t.artist.clone().unwrap_or_default(), t.title);
    }
    s
}

fn describe(s: &serde_json::Value) -> String {
    let n = |k: &str| s[k].as_u64().unwrap_or(0);
    match s["state"].as_str().unwrap_or("idle") {
        "running" => format!("running: {} of {} tracks{}", n("done"), n("total"), s["current"].as_str().map(|c| format!(" · {c}")).unwrap_or_default()),
        "queued" => "waiting for the queue worker".into(),
        "done" if n("total") == 0 => "nothing to measure: everything has its analysis".into(),
        "done" => format!("done: {} tracks measured, {} failed", n("done"), n("failed")),
        _ => "nothing is running".into(),
    }
}

/// True when the run's own list says every one of these albums is finished (done or failed). The overall state can say
/// "queued" a while longer than that: the server counts waiting jobs, and a miscount only wears off after an hour.
fn albums_finished(s: &serde_json::Value, albums: &[u64]) -> bool {
    !albums.is_empty() && albums.iter().all(|a| matches!(s["albums"][a.to_string()]["state"].as_str(), Some("done" | "failed")))
}

/// Follows the run until it is over (a day at most: a big library takes hours; `--no-wait` and `--status` are for those).
/// With `albums` it also stops when those albums are finished, whatever the overall state says.
pub fn wait(api: &Api, albums: &[u64]) -> Result<(), String> {
    let start = Instant::now();
    let mut seen_busy = false;
    loop {
        let s = api.analysis()?;
        let state = s["state"].as_str().unwrap_or("idle");
        match state {
            "queued" | "running" if albums_finished(&s, albums) => {
                println!("\r  analysis finished{}", " ".repeat(40));
                return Ok(());
            }
            "queued" | "running" => {
                seen_busy = true;
                print!("\r  {}{}", describe(&s), " ".repeat(10));
                let _ = io::stdout().flush();
            }
            // "idle" right away means there was nothing to measure (everything already has its analysis)
            _ => {
                if seen_busy {
                    println!("\r  {}{}", describe(&s), " ".repeat(30));
                }
                return Ok(());
            }
        }
        if start.elapsed() > Duration::from_secs(86_400) {
            return Err("still running after a day; it goes on at the server (`magnetite analyze --status`)".into());
        }
        std::thread::sleep(Duration::from_secs(2));
    }
}

fn mark(t: &Track) -> &'static str {
    match severity(t) {
        3 => "✗",
        2 => "!",
        1 => "·",
        _ => " ",
    }
}

fn row(t: &Track) -> String {
    let Some(tr) = &t.truth else { return format!("  {} {:>2} {}  (not analysed)", " ", t.number.unwrap_or(0), t.title) };
    if !tr.analyzed {
        return format!("  {} {:>2} {}  (not analysed)", " ", t.number.unwrap_or(0), t.title);
    }
    let fmt = format!("{} {}", t.codec.clone().unwrap_or_default().to_uppercase(), match (tr.bits, tr.sample_rate) {
        (Some(b), Some(sr)) => format!("{}/{}", b as u32, (sr / 1000.0 * 10.0).round() / 10.0),
        _ => String::new(),
    });
    let n = |v: Option<f64>, d: usize| v.map_or("–".to_string(), |v| format!("{v:.d$}"));
    let flags = tr.flags.iter().filter_map(|f| f["label"].as_str()).collect::<Vec<_>>().join(", ");
    format!("  {} {:>2} {:<32} {:<14} DR {:>2}  {:>6} LUFS  TP {:>5}  {flags}", mark(t), t.number.unwrap_or(0), t.title.chars().take(32).collect::<String>(), fmt.trim(), n(tr.dr, 0), n(tr.lufs, 1), n(tr.true_peak, 1))
}

fn summary(tracks: &[Track]) -> String {
    let count = |s: u8| tracks.iter().filter(|t| severity(t) == s).count();
    let analysed = tracks.iter().filter(|t| t.truth.as_ref().is_some_and(|x| x.analyzed)).count();
    let drs: Vec<f64> = tracks.iter().filter_map(|t| t.truth.as_ref().and_then(|x| x.dr)).collect();
    let dr = if drs.is_empty() { String::new() } else { format!(", mean DR {:.1}", drs.iter().sum::<f64>() / drs.len() as f64) };
    format!("  {analysed} of {} analysed{dr}; {} problem, {} warning, {} note", tracks.len(), count(3), count(2), count(1))
}

/// The findings of one album, track by track.
pub fn report_album(api: &Api, id: u64) -> Result<(), String> {
    let a = api.album(id)?;
    println!("\n{} · {}", a.shown_artist(), a.title);
    for t in &a.tracks {
        println!("{}", row(t));
    }
    println!("{}", summary(&a.tracks));
    Ok(())
}

fn report_track(api: &Api, id: u64) -> Result<(), String> {
    let t = api.track(id)?;
    println!("\n{} — {}", t.artist.clone().unwrap_or_default(), t.title);
    println!("{}", row(&t));
    for f in t.truth.iter().flat_map(|x| &x.flags) {
        println!("      {}: {}", f["label"].as_str().unwrap_or(""), f["detail"].as_str().unwrap_or(""));
    }
    Ok(())
}

/// What a background analysis tells the player.
pub enum Msg {
    Progress(String),
    Done(String),
    Failed(String),
}

/// An analysis started from the player: queued and followed on a thread, reporting through a channel.
pub struct Job {
    pub rx: std::sync::mpsc::Receiver<Msg>,
    pub album: Option<u64>, // the album that gets new numbers (to read it again when done)
}

impl Job {
    pub fn start(api: &Api, album: Option<u64>, track: Option<u64>, owner_album: Option<u64>) -> Job {
        let watched: Vec<u64> = album.into_iter().collect();
        let (tx, rx) = std::sync::mpsc::channel();
        let api = api.clone();
        std::thread::spawn(move || {
            if let Err(e) = api.start_analysis(album, track) {
                let _ = tx.send(Msg::Failed(e));
                return;
            }
            let mut busy = false;
            loop {
                match api.analysis() {
                    Ok(s) => match s["state"].as_str().unwrap_or("idle") {
                        "queued" | "running" if albums_finished(&s, &watched) => {
                            let _ = tx.send(Msg::Done(if busy { "analysis finished".into() } else { "nothing to measure: it is analysed already".into() }));
                            return;
                        }
                        "queued" | "running" => {
                            busy = true;
                            if tx.send(Msg::Progress(describe(&s))).is_err() {
                                return;
                            }
                        }
                        _ => {
                            let _ = tx.send(Msg::Done(if busy { describe(&s) } else { "nothing to measure: it is analysed already".into() }));
                            return;
                        }
                    },
                    Err(e) => {
                        let _ = tx.send(Msg::Failed(e));
                        return;
                    }
                }
                std::thread::sleep(Duration::from_secs(2));
            }
        });
        Job { rx, album: owner_album }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn args(a: &[&str]) -> Vec<String> {
        a.iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn reads_the_target() {
        assert_eq!(parse(&args(&["--all"])).unwrap(), Options { target: Target::All, wait: true });
        assert_eq!(parse(&args(&["--album", "7", "--no-wait"])).unwrap(), Options { target: Target::Album(7), wait: false });
        assert_eq!(parse(&args(&["--track", "9"])).unwrap().target, Target::Track(9));
        assert_eq!(parse(&args(&["recados", "jafumega"])).unwrap().target, Target::Text("recados jafumega".into()));
        assert_eq!(parse(&args(&["--status"])).unwrap().target, Target::Status);
        for bad in [&[][..], &["--album"], &["--album", "x"], &["--all", "text"], &["--bogus"]] {
            assert!(parse(&args(bad)).is_err(), "{bad:?}");
        }
    }

    #[test]
    fn says_what_the_run_is_doing() {
        assert_eq!(describe(&json!({"state": "running", "done": 3, "total": 8, "current": "Song"})), "running: 3 of 8 tracks · Song");
        assert_eq!(describe(&json!({"state": "done", "total": 9, "done": 8, "failed": 1})), "done: 8 tracks measured, 1 failed");
        assert_eq!(describe(&json!({"state": "done", "total": 0, "done": 0})), "nothing to measure: everything has its analysis");
        assert_eq!(describe(&json!({"state": "idle"})), "nothing is running");
    }

    fn track(flags: serde_json::Value) -> Track {
        serde_json::from_value(json!({"id": 1, "title": "Song", "number": 3, "codec": "flac", "open": true, "truth": {"analyzed": true, "dr": 8.0, "lufs": -9.5, "true_peak": 0.4, "bits": 16.0, "sample_rate": 44100.0, "flags": flags}})).unwrap()
    }

    #[test]
    fn a_row_shows_numbers_and_findings() {
        let r = row(&track(json!([{"level": "problem", "label": "Clipped"}])));
        assert!(r.contains('✗') && r.contains("FLAC 16/44.1") && r.contains("DR  8") && r.contains("-9.5") && r.contains("Clipped"), "{r}");
        assert!(row(&serde_json::from_value(json!({"id": 2, "title": "X", "open": true})).unwrap()).contains("not analysed"));
    }

    #[test]
    fn an_album_that_is_finished_ends_the_wait_even_when_the_run_says_queued() {
        let s = json!({"state": "queued", "albums": {"1": {"state": "done"}, "2": {"state": "running"}, "3": {"state": "failed"}}});
        assert!(albums_finished(&s, &[1]) && albums_finished(&s, &[1, 3]));
        assert!(!albums_finished(&s, &[1, 2]) && !albums_finished(&s, &[]) && !albums_finished(&s, &[9]));
    }

    #[test]
    fn counts_findings_by_level() {
        let s = summary(&[track(json!([{"level": "problem"}])), track(json!([{"level": "warn"}, {"level": "note"}])), track(json!([]))]);
        assert!(s.contains("3 of 3 analysed") && s.contains("mean DR 8.0") && s.contains("1 problem, 1 warning, 0 note"), "{s}");
    }

    /// Against a running server: `MAGNETITE_TEST_URL=… MAGNETITE_TEST_TOKEN=… MAGNETITE_TEST_ALBUM=1 cargo test background_job -- --ignored`.
    #[test]
    #[ignore]
    fn background_job_follows_a_run_to_the_end() {
        let (url, token, album) = (std::env::var("MAGNETITE_TEST_URL").unwrap(), std::env::var("MAGNETITE_TEST_TOKEN").unwrap(), std::env::var("MAGNETITE_TEST_ALBUM").unwrap().parse().unwrap());
        let job = Job::start(&Api::new(&url, &token), Some(album), None, Some(album));
        let mut last = String::new();
        loop {
            match job.rx.recv_timeout(Duration::from_secs(60)).expect("no answer") {
                Msg::Progress(s) => last = s,
                Msg::Done(s) => return println!("done: {s} (last progress: {last})"),
                Msg::Failed(e) => panic!("{e}"),
            }
        }
    }
}
