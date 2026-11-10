//! The operating system's media controls (souvlaki): the keyboard's media keys, the Now Playing widget and Control Center on
//! macOS, MPRIS on Linux (`playerctl`, desktop widgets, status bars), the system transport controls on Windows. Best effort: if
//! the platform refuses, the client simply runs without them.
use souvlaki::{MediaControlEvent, MediaControls, MediaMetadata, MediaPlayback, MediaPosition, PlatformConfig, SeekDirection};
use std::{
    sync::mpsc::{channel, Receiver},
    time::Duration,
};

#[derive(Debug, PartialEq, Clone, Copy)]
pub enum Cmd {
    Play,
    Pause,
    Toggle,
    Next,
    Previous,
    Stop,
    /// Seconds, relative.
    SeekBy(f64),
    /// Seconds, absolute.
    SetPosition(f64),
}

fn map(e: MediaControlEvent) -> Option<Cmd> {
    let dir = |d: SeekDirection, s: f64| if d == SeekDirection::Forward { s } else { -s };
    Some(match e {
        MediaControlEvent::Play => Cmd::Play,
        MediaControlEvent::Pause => Cmd::Pause,
        MediaControlEvent::Toggle => Cmd::Toggle,
        MediaControlEvent::Next => Cmd::Next,
        MediaControlEvent::Previous => Cmd::Previous,
        MediaControlEvent::Stop => Cmd::Stop,
        MediaControlEvent::Seek(d) => Cmd::SeekBy(dir(d, 10.0)),
        MediaControlEvent::SeekBy(d, by) => Cmd::SeekBy(dir(d, by.as_secs_f64())),
        MediaControlEvent::SetPosition(MediaPosition(p)) => Cmd::SetPosition(p.as_secs_f64()),
        _ => return None, // volume (the client has none), shuffle, open uri, raise, quit
    })
}

/// What the system should show.
#[derive(Clone, PartialEq, Default)]
pub struct Now {
    pub track: Option<u64>,
    pub title: String,
    pub artist: String,
    pub album: String,
    pub duration: f64,
    pub playing: bool,
}

pub struct Media {
    controls: Option<MediaControls>,
    rx: Receiver<Cmd>,
    shown: Option<Now>,
    since: f64,
}

impl Media {
    pub fn new() -> Self {
        let (tx, rx) = channel();
        let controls = MediaControls::new(PlatformConfig { dbus_name: "magnetite_tui", display_name: "Magnetite", hwnd: None }).ok().and_then(|mut c| {
            c.attach(move |e| {
                if let Some(cmd) = map(e) {
                    let _ = tx.send(cmd);
                }
            })
            .ok()?;
            Some(c)
        });
        Media { controls, rx, shown: None, since: 0.0 }
    }

    pub fn active(&self) -> bool {
        self.controls.is_some()
    }

    pub fn commands(&self) -> Vec<Cmd> {
        self.rx.try_iter().collect()
    }

    /// Tell the system what plays. Only sends when the track or the state changed, or every 5 s to keep the position right.
    pub fn update(&mut self, now: Option<&Now>, position: f64) {
        let Some(c) = self.controls.as_mut() else { return };
        let stale = position - self.since > 5.0 || position < self.since;
        if self.shown.as_ref() == now && !stale {
            return;
        }
        let progress = Some(MediaPosition(Duration::from_secs_f64(position.max(0.0))));
        match now {
            Some(n) => {
                if self.shown.as_ref().map(|s| (s.track, &s.title)) != Some((n.track, &n.title)) {
                    let _ = c.set_metadata(MediaMetadata {
                        title: Some(&n.title),
                        artist: Some(&n.artist),
                        album: Some(&n.album),
                        duration: (n.duration > 0.0).then(|| Duration::from_secs_f64(n.duration)),
                        cover_url: None, // our covers need the token, which the system cannot send
                    });
                }
                let _ = c.set_playback(if n.playing { MediaPlayback::Playing { progress } } else { MediaPlayback::Paused { progress } });
            }
            None => {
                let _ = c.set_playback(MediaPlayback::Stopped);
            }
        }
        self.shown = now.cloned();
        self.since = position;
    }
}

/// macOS delivers the media keys through the main run loop, which a terminal program does not run by itself: give it a turn.
#[cfg(target_os = "macos")]
pub fn pump() {
    use std::ffi::c_void;
    #[link(name = "CoreFoundation", kind = "framework")]
    unsafe extern "C" {
        static kCFRunLoopDefaultMode: *const c_void;
        fn CFRunLoopRunInMode(mode: *const c_void, seconds: f64, return_after_source_handled: u8) -> i32;
    }
    // SAFETY: both are CoreFoundation's own symbols; called from the main thread with a zero timeout.
    unsafe {
        CFRunLoopRunInMode(kCFRunLoopDefaultMode, 0.0, 0);
    }
}

#[cfg(not(target_os = "macos"))]
pub fn pump() {}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn system_events_become_commands_and_the_ones_we_cannot_serve_are_dropped() {
        assert_eq!(map(MediaControlEvent::Toggle), Some(Cmd::Toggle));
        assert_eq!(map(MediaControlEvent::Seek(SeekDirection::Backward)), Some(Cmd::SeekBy(-10.0)));
        assert_eq!(map(MediaControlEvent::SeekBy(SeekDirection::Forward, Duration::from_secs(30))), Some(Cmd::SeekBy(30.0)));
        assert_eq!(map(MediaControlEvent::SetPosition(MediaPosition(Duration::from_secs(61)))), Some(Cmd::SetPosition(61.0)));
        assert_eq!(map(MediaControlEvent::SetVolume(0.5)), None);
    }
}

#[cfg(test)]
mod live {
    use super::*;

    /// `cargo test live_media -- --ignored --nocapture`: registers with the system for two seconds (look at Control Center /
    /// `playerctl metadata`, press a media key) and prints the commands received.
    #[test]
    #[ignore]
    fn live_media() {
        let mut m = Media::new();
        println!("controls active: {}", m.active());
        let now = Now { track: Some(1), title: "Test tone".into(), artist: "Magnetite".into(), album: "Checks".into(), duration: 180.0, playing: true };
        m.update(Some(&now), 12.0);
        for _ in 0..60 {
            pump();
            std::thread::sleep(Duration::from_millis(50));
            for c in m.commands() {
                println!("command: {c:?}");
            }
        }
    }
}
