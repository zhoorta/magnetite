//! Meter state computed in the UI thread: from the audio tap (levels, spectra, shapes) and from every sample played (loudness).
use ebur128::{EbuR128, Mode};
use rustfft::{num_complex::Complex, FftPlanner};
use std::collections::VecDeque;

pub const BANDS: usize = 24; // log-spaced spectrum bars
pub const THIRD: usize = 31; // ISO third-octave bands, 20 Hz .. 20 kHz
const N: usize = 2048;
const NB: usize = 4096;
const SPECTROGRAM_COLUMNS: usize = 400;

#[derive(Default, Clone, Copy)]
pub struct Loudness {
    pub momentary: Option<f64>,
    pub short_term: Option<f64>,
    pub integrated: Option<f64>,
    pub range: Option<f64>,
    pub true_peak: Option<f64>, // dBTP
}

pub struct Meters {
    pub bands: [f32; BANDS],  // 0..1, smoothed
    pub third: [f32; THIRD],  // 0..1, smoothed
    pub rms: (f32, f32),      // dBFS
    pub peak: (f32, f32),     // dBFS, held
    pub corr: f32,            // -1..1
    pub needle: (f32, f32),   // 0..1 on the -30..+3 dBFS dial, VU ballistics (about 0.3 s)
    pub eye: (f32, f32),      // 0..1 on -36..0 dBFS, magic-eye ballistics
    pub history: VecDeque<[f32; THIRD]>, // spectrogram columns, newest last
    pub lufs: Loudness,
    pub lufs_history: VecDeque<f32>, // momentary, every half second
    pub poster: crate::posters::State, // what the poster meters remember between frames
    peak_age: (f32, f32),
    column_clock: f32,
    lufs_clock: f32,
    ebu: Option<(EbuR128, u32)>,
    planner: FftPlanner<f32>,
}

fn smooth(cur: &mut f32, v: f32, dt: f32) {
    *cur = if v > *cur { v } else { (*cur - dt * 1.5).max(v) };
}

/// ISO centre of third-octave band `i` (0..31): 1 kHz is band 17.
pub fn third_centre(i: usize) -> f32 {
    1000.0 * 2f32.powf((i as f32 - 17.0) / 3.0)
}

impl Meters {
    pub fn new() -> Self {
        Meters {
            bands: [0.0; BANDS],
            third: [0.0; THIRD],
            rms: (-90.0, -90.0),
            peak: (-90.0, -90.0),
            corr: 0.0,
            needle: (0.0, 0.0),
            eye: (0.0, 0.0),
            history: VecDeque::new(),
            lufs: Loudness::default(),
            lufs_history: VecDeque::new(),
            poster: crate::posters::State::new(),
            peak_age: (0.0, 0.0),
            column_clock: 0.0,
            lufs_clock: 0.0,
            ebu: None,
            planner: FftPlanner::new(),
        }
    }

    /// A new track starts: loudness and the histories begin again.
    pub fn reset_track(&mut self) {
        self.ebu = None;
        self.lufs = Loudness::default();
        self.lufs_history.clear();
        self.history.clear();
    }

    pub fn idle(&mut self, dt: f32) {
        for b in self.bands.iter_mut().chain(self.third.iter_mut()) {
            *b = (*b - dt * 2.0).max(0.0);
        }
        self.rms = (-90.0, -90.0);
        self.peak = (self.peak.0 - dt * 20.0, self.peak.1 - dt * 20.0);
        self.ballistics(dt);
        self.posters(dt, false);
    }

    fn posters(&mut self, dt: f32, playing: bool) {
        let loud = self.lufs.short_term.or(self.lufs.momentary);
        self.poster.update(&self.third, self.rms, self.corr, loud, self.peak, playing, dt);
    }

    /// Needle and eye follow the RMS like the web's `Meter`: `tau = response / 4.6`, so 99% of the way in `response` seconds.
    fn ballistics(&mut self, dt: f32) {
        let follow = |cur: &mut f32, db: f32, (min, max, attack, release): (f32, f32, f32, f32)| {
            let target = ((db - min) / (max - min)).clamp(0.0, 1.0);
            let tau = (if target > *cur { attack } else { release }) / 4.6;
            *cur += (target - *cur) * (1.0 - (-dt / tau).exp());
        };
        let n = (-30.0, 3.0, 0.3, 0.3);
        let e = (-36.0, 0.0, 0.12, 0.35);
        follow(&mut self.needle.0, self.rms.0, n);
        follow(&mut self.needle.1, self.rms.1, n);
        follow(&mut self.eye.0, self.rms.0, e);
        follow(&mut self.eye.1, self.rms.1, e);
    }

    fn mags(&mut self, t: &[f32], n: usize) -> Vec<f32> {
        let frames = t.len() / 2;
        let t = &t[(frames - n) * 2..];
        let mut buf: Vec<Complex<f32>> = t
            .chunks(2)
            .enumerate()
            .map(|(i, f)| {
                let w = 0.5 - 0.5 * (2.0 * std::f32::consts::PI * i as f32 / (n - 1) as f32).cos();
                Complex::new((f[0] + f[1]) * 0.5 * w, 0.0)
            })
            .collect();
        self.planner.plan_fft_forward(n).process(&mut buf);
        buf[..n / 2].iter().map(|c| c.norm() / (n as f32 / 4.0)).collect()
    }

    /// `tap` is the latest interleaved stereo frames, `played` every sample played since the last call.
    pub fn update(&mut self, tap: &[f32], played: &[f32], rate: u32, dt: f32) {
        self.loudness(played, rate, dt);
        let frames = tap.len() / 2;
        if frames < NB {
            return self.idle(dt);
        }
        let t = &tap[(frames - N) * 2..];
        let (mut sl, mut sr, mut pl, mut pr, mut lr) = (0.0f64, 0.0f64, 0.0f32, 0.0f32, 0.0f64);
        for f in t.chunks(2) {
            sl += (f[0] * f[0]) as f64;
            sr += (f[1] * f[1]) as f64;
            lr += (f[0] * f[1]) as f64;
            pl = pl.max(f[0].abs());
            pr = pr.max(f[1].abs());
        }
        let db = |x: f64| (10.0 * x.max(1e-9).log10()) as f32;
        self.rms = (db(sl / N as f64), db(sr / N as f64));
        self.ballistics(dt);
        self.corr = if sl > 1e-9 && sr > 1e-9 { (lr / (sl * sr).sqrt()) as f32 } else { 0.0 };
        let pk = |p: f32| 20.0 * p.max(1e-5).log10();
        for (cur, new, age) in [(&mut self.peak.0, pk(pl), &mut self.peak_age.0), (&mut self.peak.1, pk(pr), &mut self.peak_age.1)] {
            if new >= *cur {
                *cur = new;
                *age = 0.0;
            } else {
                *age += dt;
                if *age > 1.0 {
                    *cur = (*cur - dt * 20.0).max(new);
                }
            }
        }

        // 24 log bars, 40 Hz .. 16 kHz, from 2048 frames
        let m = self.mags(tap, N);
        let hz = |i: usize| i as f32 * rate as f32 / N as f32;
        let (lo, hi) = (40.0f32, 16000.0f32.min(rate as f32 / 2.0));
        for b in 0..BANDS {
            let f0 = lo * (hi / lo).powf(b as f32 / BANDS as f32);
            let f1 = lo * (hi / lo).powf((b + 1) as f32 / BANDS as f32);
            let mut v = 0.0f32;
            for i in 1..N / 2 {
                if hz(i) >= f0 && hz(i) < f1.max(f0 + hz(1)) {
                    v = v.max(m[i]);
                }
            }
            smooth(&mut self.bands[b], ((20.0 * v.max(1e-6).log10() + 70.0) / 70.0).clamp(0.0, 1.0), dt);
        }

        // 31 third-octave bands from 4096 frames; 3 dB/octave tilt so music looks level
        let m = self.mags(tap, NB);
        let bin = rate as f32 / NB as f32;
        for b in 0..THIRD {
            let fc = third_centre(b);
            let (f0, f1) = (fc / 2f32.powf(1.0 / 6.0), fc * 2f32.powf(1.0 / 6.0));
            let (i0, i1) = ((f0 / bin).ceil() as usize, ((f1 / bin).ceil() as usize).min(NB / 2));
            let v = if i1 > i0 { (i0.max(1)..i1).map(|i| m[i]).fold(0.0, f32::max) } else { m[((fc / bin).round() as usize).clamp(1, NB / 2 - 1)] };
            let tilt = 3.0 * (fc / 1000.0).log2();
            smooth(&mut self.third[b], ((20.0 * v.max(1e-6).log10() + tilt + 80.0) / 80.0).clamp(0.0, 1.0), dt);
        }
        self.posters(dt, true);
        self.column_clock += dt;
        if self.column_clock >= 0.1 {
            self.column_clock = 0.0;
            self.history.push_back(self.third);
            while self.history.len() > SPECTROGRAM_COLUMNS {
                self.history.pop_front();
            }
        }
    }

    fn loudness(&mut self, played: &[f32], rate: u32, dt: f32) {
        if self.ebu.as_ref().map_or(true, |(_, r)| *r != rate) {
            self.ebu = EbuR128::new(2, rate, Mode::M | Mode::S | Mode::I | Mode::LRA | Mode::TRUE_PEAK).ok().map(|e| (e, rate));
        }
        let Some((e, _)) = self.ebu.as_mut() else { return };
        if !played.is_empty() {
            let _ = e.add_frames_f32(played);
        }
        let finite = |v: f64| v.is_finite().then_some(v);
        self.lufs = Loudness {
            momentary: e.loudness_momentary().ok().and_then(finite),
            short_term: e.loudness_shortterm().ok().and_then(finite),
            integrated: e.loudness_global().ok().and_then(finite),
            range: e.loudness_range().ok().and_then(finite),
            true_peak: (0..2).filter_map(|c| e.true_peak(c).ok()).map(|p| 20.0 * p.max(1e-9).log10()).fold(None, |a: Option<f64>, v| Some(a.map_or(v, |a| a.max(v)))),
        };
        self.lufs_clock += dt;
        if self.lufs_clock >= 0.5 {
            self.lufs_clock = 0.0;
            self.lufs_history.push_back(self.lufs.momentary.unwrap_or(-70.0) as f32);
            while self.lufs_history.len() > 240 {
                self.lufs_history.pop_front();
            }
        }
    }
}

#[cfg(test)]
pub mod tests {
    use super::*;

    /// Interleaved stereo sine; the right channel is the left one scaled by `right`.
    pub fn sine(freq: f32, db: f32, secs: f32, rate: u32, right: f32) -> Vec<f32> {
        let a = 10f32.powf(db / 20.0);
        (0..(secs * rate as f32) as usize)
            .flat_map(|i| {
                let v = a * (2.0 * std::f32::consts::PI * freq * i as f32 / rate as f32).sin();
                [v, v * right]
            })
            .collect()
    }

    #[test]
    fn a_1khz_tone_shows_in_the_right_third_octave_band_and_in_loudness() {
        let rate = 48000;
        let audio = sine(1000.0, -20.0, 5.0, rate, 1.0);
        let mut m = Meters::new();
        let mut pos = 0;
        while pos + 1600 * 2 <= audio.len() {
            let end = pos + 1600 * 2; // 1/30 s per tick
            let from = end.saturating_sub(4096 * 2);
            m.update(&audio[from..end], &audio[pos..end], rate, 1.0 / 30.0);
            pos = end;
        }
        let top = m.third.iter().enumerate().max_by(|a, b| a.1.partial_cmp(b.1).unwrap()).unwrap().0;
        assert_eq!(top, 17, "1 kHz is third-octave band 17, got {top} ({:?})", m.third);
        let lufs = m.lufs.integrated.expect("integrated loudness");
        assert!((-22.0..-18.0).contains(&lufs), "{lufs}");
        assert!((m.rms.0 - -23.0).abs() < 1.0, "rms {}", m.rms.0); // -20 dBFS peak sine = -23 dBFS rms
        assert!(m.corr > 0.99, "identical channels correlate: {}", m.corr);
        let tp = m.lufs.true_peak.unwrap();
        assert!((tp - -20.0).abs() < 1.0, "true peak {tp}");
    }

    #[test]
    fn a_new_track_starts_loudness_again() {
        let mut m = Meters::new();
        m.update(&[], &sine(1000.0, -20.0, 1.0, 48000, 1.0), 48000, 0.03);
        assert!(m.lufs.momentary.is_some());
        m.reset_track();
        assert!(m.lufs.momentary.is_none() && m.history.is_empty());
    }
}
