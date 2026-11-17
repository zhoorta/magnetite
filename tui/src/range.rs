//! Two small meter states kept apart from the drawing so they can be tested: the live loudness range (a fixed-size histogram of the
//! short-term loudness, as the web's `MeterDsp.liveRange()`) and the crest factor (peak over RMS in a short window, with a line of the
//! last half minute).
use std::collections::VecDeque;

const ABS_GATE: f64 = -70.0;
const BINS: usize = 800; // 0.1 LU bins from -70 LUFS up
pub const SHOWN: usize = 60; // 1 LU bins from -60 to 0 LUFS, for the histogram on screen
const SHOWN_MIN: i32 = -60;

pub struct Lra {
    hist: Vec<u32>,
    energy: f64, // the sum of the short-term energies above -70 LUFS
    n: u32,
    /// The 10th and 95th percentile in LUFS, once there are two values.
    pub bounds: Option<(f64, f64)>,
}

const OFFSET: f64 = -0.691;
fn energy(l: f64) -> f64 {
    10f64.powf((l - OFFSET) / 10.0)
}
fn lufs(e: f64) -> f64 {
    if e > 0.0 { OFFSET + 10.0 * e.log10() } else { f64::NEG_INFINITY }
}

impl Lra {
    pub fn new() -> Self {
        Lra { hist: vec![0; BINS], energy: 0.0, n: 0, bounds: None }
    }

    pub fn clear(&mut self) {
        *self = Lra::new();
    }

    /// One short-term loudness value (every 100 ms once 3 s have played).
    pub fn add(&mut self, l: f64) {
        if l.is_nan() || l <= ABS_GATE {
            return;
        }
        self.hist[(((l - ABS_GATE) * 10.0) as usize).min(BINS - 1)] += 1;
        self.energy += energy(l);
        self.n += 1;
        self.bounds = self.live();
    }

    /// The range in LU (0 until there are two values).
    pub fn range(&self) -> f64 {
        self.bounds.map_or(0.0, |(lo, hi)| hi - lo)
    }

    fn live(&self) -> Option<(f64, f64)> {
        if self.n < 2 {
            return None;
        }
        // values more than 20 LU under the mean of the rest are dropped, then the 10th and 95th percentile
        let threshold = lufs(self.energy / self.n as f64) - 20.0;
        let mut from = ((threshold - ABS_GATE) * 10.0).ceil().max(0.0) as usize;
        if from as f64 / 10.0 + ABS_GATE <= threshold {
            from += 1;
        }
        let count: u32 = self.hist.iter().skip(from).sum();
        if count < 2 {
            return None;
        }
        let nth = |k: u32| {
            let mut seen = 0;
            for i in from..BINS {
                seen += self.hist[i];
                if seen > k {
                    return ABS_GATE + (i as f64 + 0.5) / 10.0;
                }
            }
            ABS_GATE + BINS as f64 / 10.0
        };
        let at = |p: f64| ((count - 1) as f64 * p).round() as u32;
        Some((nth(at(0.1)), nth(at(0.95))))
    }

    /// The short-term values by loudness, 1 LU bins from -60 to 0 LUFS.
    pub fn shape(&self) -> [u32; SHOWN] {
        let mut out = [0; SHOWN];
        for (i, n) in self.hist.iter().enumerate() {
            let k = (i / 10) as i32 + ABS_GATE as i32 - SHOWN_MIN; // the bin's LUFS (-70 + i/10) minus the first shown (-60)
            if (0..SHOWN as i32).contains(&k) {
                out[k as usize] += n;
            }
        }
        out
    }
}

const WINDOW: f32 = 3.0; // seconds the reading looks at
const POINT: f32 = 0.25; // seconds between the points of the line
pub const LINE: usize = 120; // 30 s

pub struct Crest {
    frames: VecDeque<(f32, f32, f32)>, // dt, peak, energy
    since: f32,
    pub value: Option<f32>,
    pub line: VecDeque<f32>,
}

impl Crest {
    pub fn new() -> Self {
        Crest { frames: VecDeque::new(), since: 0.0, value: None, line: VecDeque::new() }
    }

    pub fn clear(&mut self) {
        *self = Crest::new();
    }

    /// One frame of the larger channel's linear peak and RMS; silence is ignored.
    pub fn push(&mut self, dt: f32, peak: f32, rms: f32) {
        if rms <= 1e-4 || dt <= 0.0 {
            return;
        }
        self.frames.push_back((dt, peak, rms * rms));
        let mut total: f32 = self.frames.iter().map(|f| f.0).sum();
        while total > WINDOW && self.frames.len() > 1 {
            total -= self.frames.pop_front().map_or(0.0, |f| f.0);
        }
        let pk = self.frames.iter().fold(0.0f32, |m, f| m.max(f.1));
        let e = self.frames.iter().map(|f| f.2 * f.0).sum::<f32>() / total;
        let db = |x: f32| 20.0 * x.max(1e-6).log10();
        let v = db(pk) - db(e.sqrt());
        self.value = Some(v);
        self.since += dt;
        if self.since >= POINT {
            self.since = 0.0;
            self.line.push_back(v);
            while self.line.len() > LINE {
                self.line.pop_front();
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_steady_level_has_no_range_and_two_levels_have_about_their_difference() {
        let mut l = Lra::new();
        for _ in 0..300 {
            l.add(-20.0);
        }
        assert!(l.range() < 0.2, "{}", l.range());
        let mut l = Lra::new();
        for _ in 0..400 {
            l.add(-20.0);
        }
        for _ in 0..400 {
            l.add(-30.0);
        }
        let r = l.range();
        assert!((r - 10.0).abs() < 0.3, "{r}");
        let (lo, hi) = l.bounds.unwrap();
        assert!(lo < -29.0 && hi > -21.0, "{lo} {hi}");
        assert_eq!(l.shape().iter().sum::<u32>(), 800);
        l.clear();
        assert_eq!(l.range(), 0.0);
        assert_eq!(l.shape().iter().sum::<u32>(), 0);
    }

    #[test]
    fn values_under_the_absolute_gate_and_far_below_the_mean_are_left_out() {
        let mut l = Lra::new();
        l.add(f64::NEG_INFINITY);
        l.add(-80.0);
        assert_eq!(l.n, 0);
        for _ in 0..500 {
            l.add(-20.0);
        }
        for _ in 0..5 {
            l.add(-65.0); // far under the relative gate
        }
        assert!(l.range() < 0.5, "{}", l.range());
    }

    #[test]
    fn the_shape_puts_each_value_in_its_one_lu_bin() {
        let mut l = Lra::new();
        l.add(-23.4);
        let s = l.shape();
        assert_eq!(s[36], 1); // -60 is bin 0, so -23.4 is bin 36
        assert_eq!(s.iter().sum::<u32>(), 1);
    }

    #[test]
    fn crest_factor_of_a_sine_is_3_db_and_a_spiky_signal_reads_higher() {
        let mut c = Crest::new();
        for _ in 0..200 {
            c.push(1.0 / 30.0, std::f32::consts::SQRT_2 * 0.1, 0.1);
        }
        assert!((c.value.unwrap() - 3.01).abs() < 0.05, "{:?}", c.value);
        let mut c = Crest::new();
        for _ in 0..200 {
            c.push(1.0 / 30.0, 0.4, 0.05);
        }
        assert!((c.value.unwrap() - 18.06).abs() < 0.1, "{:?}", c.value);
        assert!(c.line.len() > 20 && c.line.len() <= LINE);
        let before = c.line.len();
        c.push(0.03, 0.0, 0.0); // silence changes nothing
        assert_eq!(c.line.len(), before);
    }
}
