//! Shuffle and repeat, as the web player (`resources/js/magnetite/order.js`, which this follows): the queue is never reordered,
//! `order` is the list of queue indices in play order (the identity when shuffle is off), and `next`, `previous` and the prefetch
//! follow it. Shuffle: off or on (every track at random). Repeat: off, `all` (the queue again) or `one` (the track again, when it ends). Pure; tested here.
//! (The web also shuffles by albums; here a queue is one album or one playlist, so there is nothing to group.)

use std::time::{SystemTime, UNIX_EPOCH};

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Shuffle {
    Off,
    Tracks,
}

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Repeat {
    Off,
    All,
    One,
}

impl Shuffle {
    pub fn id(self) -> &'static str {
        match self {
            Shuffle::Off => "off",
            Shuffle::Tracks => "tracks",
        }
    }
    pub fn from_id(id: &str) -> Shuffle {
        match id {
            "tracks" | "albums" => Shuffle::Tracks, // "albums" was a mode of an earlier version
            _ => Shuffle::Off,
        }
    }
    pub fn cycle(self) -> Shuffle {
        match self {
            Shuffle::Off => Shuffle::Tracks,
            Shuffle::Tracks => Shuffle::Off,
        }
    }
}

impl Repeat {
    pub fn id(self) -> &'static str {
        match self {
            Repeat::Off => "off",
            Repeat::All => "all",
            Repeat::One => "one",
        }
    }
    pub fn from_id(id: &str) -> Repeat {
        match id {
            "all" => Repeat::All,
            "one" => Repeat::One,
            _ => Repeat::Off,
        }
    }
    pub fn cycle(self) -> Repeat {
        match self {
            Repeat::Off => Repeat::All,
            Repeat::All => Repeat::One,
            Repeat::One => Repeat::Off,
        }
    }
}

/// A small random source (xorshift): the shuffle needs luck, not security, and no crate for it.
pub struct Rng(u64);

impl Rng {
    pub fn new() -> Rng {
        let seed = SystemTime::now().duration_since(UNIX_EPOCH).map_or(0x9E37_79B9_7F4A_7C15, |d| d.as_nanos() as u64);
        Rng(seed | 1)
    }

    /// A number in 0..1.
    pub fn next_f32(&mut self) -> f32 {
        self.0 ^= self.0 << 13;
        self.0 ^= self.0 >> 7;
        self.0 ^= self.0 << 17;
        ((self.0 >> 40) as f32) / (1u64 << 24) as f32
    }
}

fn shuffled(items: &[usize], rand: &mut dyn FnMut() -> f32) -> Vec<usize> {
    let mut a = items.to_vec();
    for i in (1..a.len()).rev() {
        let j = ((rand() * (i + 1) as f32) as usize).min(i);
        a.swap(i, j);
    }
    a
}

/// The order for a queue of `len` tracks. `first` (a queue index) plays first, so turning shuffle on never jumps away from the
/// playing track; none picks the start at random (a new round).
pub fn make_order(len: usize, mode: Shuffle, first: Option<usize>, rand: &mut dyn FnMut() -> f32) -> Vec<usize> {
    let all: Vec<usize> = (0..len).collect();
    if mode == Shuffle::Off || len < 2 {
        return all;
    }
    let rest = shuffled(&all.iter().copied().filter(|i| Some(*i) != first).collect::<Vec<_>>(), rand);

    match first {
        Some(f) => std::iter::once(f).chain(rest).collect(),
        None => rest,
    }
}

/// Where to go after `index`: the queue index and the order to use from then on, or none at the end. With repeat `All` the end
/// wraps: a fresh order that does not start with the track that just ended when there is a choice.
pub fn advance(len: usize, order: &[usize], index: usize, shuffle: Shuffle, repeat: Repeat, rand: &mut dyn FnMut() -> f32) -> Option<(usize, Vec<usize>)> {
    let at = order.iter().position(|i| *i == index);
    if let Some(a) = at {
        if a + 1 < order.len() {
            return Some((order[a + 1], order.to_vec()));
        }
    }
    if repeat != Repeat::All || len == 0 {
        return None;
    }

    let mut next = make_order(len, shuffle, None, rand);
    if next.len() > 1 && next[0] == index && shuffle != Shuffle::Off {
        next.rotate_left(1);
    }

    Some((next[0], next))
}

/// The queue index before `index` in the order, or none at the start.
pub fn previous(order: &[usize], index: usize) -> Option<usize> {
    let at = order.iter().position(|i| *i == index)?;
    (at > 0).then(|| order[at - 1])
}

/// The queue index that plays after `index` without wrapping (what to prefetch), or none.
pub fn upcoming(order: &[usize], index: usize) -> Option<usize> {
    let at = order.iter().position(|i| *i == index)?;
    order.get(at + 1).copied()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn seeded(seed: u64) -> impl FnMut() -> f32 {
        let mut s = seed;
        move || {
            s = s.wrapping_mul(6364136223846793005).wrapping_add(1442695040888963407);
            ((s >> 40) as f32) / (1u64 << 24) as f32
        }
    }
    fn sorted(mut v: Vec<usize>) -> Vec<usize> {
        v.sort();
        v
    }

    #[test]
    fn off_is_the_queue_as_it_is_and_a_short_queue_is_never_shuffled() {
        assert_eq!(make_order(6, Shuffle::Off, Some(3), &mut seeded(1)), [0, 1, 2, 3, 4, 5]);
        assert_eq!(make_order(1, Shuffle::Tracks, None, &mut seeded(1)), [0]);
        assert!(make_order(0, Shuffle::Tracks, None, &mut seeded(1)).is_empty());
    }

    #[test]
    fn shuffled_every_track_once_the_playing_one_first() {
        for s in 1..20 {
            let o = make_order(6, Shuffle::Tracks, Some(3), &mut seeded(s));
            assert_eq!(o[0], 3);
            assert_eq!(sorted(o), [0, 1, 2, 3, 4, 5]);
        }
    }

    #[test]
    fn the_end_stops_or_wraps_with_a_fresh_order_that_does_not_repeat_the_last_track() {
        let order = vec![0, 1, 2, 3, 4, 5];
        let go = |idx, sh, rp, seed| advance(6, &order, idx, sh, rp, &mut seeded(seed));
        assert_eq!(go(2, Shuffle::Off, Repeat::Off, 1).map(|x| x.0), Some(3));
        assert!(go(5, Shuffle::Off, Repeat::Off, 1).is_none());
        assert!(go(5, Shuffle::Tracks, Repeat::One, 1).is_none()); // repeat one is the player's own business
        assert_eq!(go(5, Shuffle::Off, Repeat::All, 1).map(|x| x.0), Some(0));
        for s in 1..40 {
            let (next, o) = go(5, Shuffle::Tracks, Repeat::All, s).unwrap();
            assert_ne!(next, 5);
            assert_eq!(sorted(o), [0, 1, 2, 3, 4, 5]);
        }
    }

    #[test]
    fn previous_and_upcoming_follow_the_order_and_stop_at_its_ends() {
        let o = [2, 0, 1];
        assert_eq!((previous(&o, 0), previous(&o, 2), previous(&o, 9)), (Some(2), None, None));
        assert_eq!((upcoming(&o, 0), upcoming(&o, 1), upcoming(&o, 9)), (Some(1), None, None));
    }

    #[test]
    fn the_modes_cycle_and_ids_round_trip() {
        assert_eq!(Shuffle::Off.cycle().cycle(), Shuffle::Off);
        assert_eq!(Repeat::Off.cycle().cycle().cycle(), Repeat::Off);
        assert_eq!(Shuffle::from_id(Shuffle::Tracks.id()), Shuffle::Tracks);
        for r in [Repeat::Off, Repeat::All, Repeat::One] {
            assert_eq!(Repeat::from_id(r.id()), r);
        }
        assert_eq!((Shuffle::from_id("x"), Repeat::from_id("x")), (Shuffle::Off, Repeat::Off));
        assert_eq!(Shuffle::from_id("albums"), Shuffle::Tracks); // an old saved choice still means shuffle
    }

    #[test]
    fn the_random_source_stays_in_range() {
        let mut r = Rng::new();
        for _ in 0..1000 {
            let v = r.next_f32();
            assert!((0.0..1.0).contains(&v));
        }
    }
}
