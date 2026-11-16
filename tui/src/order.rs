//! Shuffle and repeat, as the web player (`resources/js/magnetite/order.js`, which this follows): the queue is never reordered,
//! `order` is the list of queue indices in play order (the identity when shuffle is off), and `next`, `previous` and the prefetch
//! follow it. Shuffle: off, `tracks` (every track at random) or `albums` (the albums at random, each one whole and in its own
//! order). Repeat: off, `all` (the queue again) or `one` (the track again, when it ends). Pure; tested here.

use std::time::{SystemTime, UNIX_EPOCH};

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Shuffle {
    Off,
    Tracks,
    Albums,
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
            Shuffle::Albums => "albums",
        }
    }
    pub fn from_id(id: &str) -> Shuffle {
        match id {
            "tracks" => Shuffle::Tracks,
            "albums" => Shuffle::Albums,
            _ => Shuffle::Off,
        }
    }
    pub fn cycle(self) -> Shuffle {
        match self {
            Shuffle::Off => Shuffle::Tracks,
            Shuffle::Tracks => Shuffle::Albums,
            Shuffle::Albums => Shuffle::Off,
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

/// The order for a queue whose tracks are on the albums `albums` (one entry per queue index, none for a track without one).
/// `first` (a queue index) plays first, so turning shuffle on never jumps away from the playing track; none picks the start at
/// random (a new round). In `Albums` mode `first` carries on through the rest of its album, then the other albums follow at
/// random, and what came before `first` in its album is last.
pub fn make_order(albums: &[Option<u64>], mode: Shuffle, first: Option<usize>, rand: &mut dyn FnMut() -> f32) -> Vec<usize> {
    let all: Vec<usize> = (0..albums.len()).collect();
    if mode == Shuffle::Off || all.len() < 2 {
        return all;
    }

    if mode == Shuffle::Tracks {
        let rest = shuffled(&all.iter().copied().filter(|i| Some(*i) != first).collect::<Vec<_>>(), rand);
        return match first {
            Some(f) => std::iter::once(f).chain(rest).collect(),
            None => rest,
        };
    }

    // Albums: the tracks of one album stay together, in queue order. A track without an album is an album of one.
    let mut groups: Vec<(Option<u64>, Vec<usize>)> = vec![];
    for &i in &all {
        match albums[i].and_then(|a| groups.iter().position(|(k, _)| *k == Some(a))) {
            Some(g) => groups[g].1.push(i),
            None => groups.push((albums[i], vec![i])),
        }
    }
    let lists: Vec<Vec<usize>> = groups.into_iter().map(|(_, v)| v).collect();
    let own = first.and_then(|f| lists.iter().position(|g| g.contains(&f)));
    let others: Vec<usize> = {
        let rest: Vec<Vec<usize>> = lists.iter().enumerate().filter(|(g, _)| Some(*g) != own).map(|(_, v)| v.clone()).collect();
        let idx: Vec<usize> = (0..rest.len()).collect();
        shuffled(&idx, rand).into_iter().flat_map(|g| rest[g].clone()).collect()
    };
    let Some(own) = own else { return others };
    let (g, f) = (&lists[own], first.unwrap());
    let at = g.iter().position(|i| *i == f).unwrap_or(0);

    g[at..].iter().copied().chain(others).chain(g[..at].iter().copied()).collect()
}

/// Where to go after `index`: the queue index and the order to use from then on, or none at the end. With repeat `All` the end
/// wraps: a fresh order that does not start with the track that just ended when there is a choice, and in `Albums` mode not
/// with the album that just ended.
pub fn advance(albums: &[Option<u64>], order: &[usize], index: usize, shuffle: Shuffle, repeat: Repeat, rand: &mut dyn FnMut() -> f32) -> Option<(usize, Vec<usize>)> {
    let at = order.iter().position(|i| *i == index);
    if let Some(a) = at {
        if a + 1 < order.len() {
            return Some((order[a + 1], order.to_vec()));
        }
    }
    if repeat != Repeat::All || albums.is_empty() {
        return None;
    }

    let mut next = make_order(albums, shuffle, None, rand);
    if next.len() > 1 && next[0] == index && shuffle != Shuffle::Off {
        next.rotate_left(1);
    }
    let album_of = |i: usize| albums.get(i).copied().flatten();
    if shuffle == Shuffle::Albums && next.len() > 1 && album_of(next[0]).is_some() && album_of(next[0]) == album_of(index) {
        // the same album twice in a row is a repeat, not a shuffle: only a one-album queue has no choice
        if let Some(other) = next.iter().position(|i| album_of(*i) != album_of(index)) {
            next.rotate_left(other);
        }
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

    // two albums (A: 0-2, B: 3-4) and a loose track
    fn queue() -> Vec<Option<u64>> {
        vec![Some(1), Some(1), Some(1), Some(2), Some(2), None]
    }
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
        assert_eq!(make_order(&queue(), Shuffle::Off, Some(3), &mut seeded(1)), [0, 1, 2, 3, 4, 5]);
        assert_eq!(make_order(&[Some(1)], Shuffle::Tracks, None, &mut seeded(1)), [0]);
        assert!(make_order(&[], Shuffle::Albums, None, &mut seeded(1)).is_empty());
    }

    #[test]
    fn tracks_every_track_once_the_playing_one_first() {
        for s in 1..20 {
            let o = make_order(&queue(), Shuffle::Tracks, Some(3), &mut seeded(s));
            assert_eq!(o[0], 3);
            assert_eq!(sorted(o), [0, 1, 2, 3, 4, 5]);
        }
    }

    #[test]
    fn albums_stay_together_and_in_order_and_the_playing_album_carries_on_first() {
        for s in 1..30 {
            let o = make_order(&queue(), Shuffle::Albums, Some(1), &mut seeded(s));
            assert_eq!(sorted(o.clone()), [0, 1, 2, 3, 4, 5]);
            assert_eq!(&o[..2], [1, 2]); // the rest of the album from the playing track
            assert_eq!(*o.last().unwrap(), 0); // what came before it is last
            let pos = |i: usize| o.iter().position(|x| *x == i).unwrap();
            assert_eq!(pos(4), pos(3) + 1); // album B whole and in order
        }
    }

    #[test]
    fn the_end_stops_or_wraps_with_a_fresh_order_that_does_not_repeat_the_last_track() {
        let q = queue();
        let order = vec![0, 1, 2, 3, 4, 5];
        let go = |idx, sh, rp, seed| advance(&q, &order, idx, sh, rp, &mut seeded(seed));
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
    fn a_wrap_in_albums_mode_does_not_start_with_the_album_that_just_ended() {
        let q = queue();
        let order = vec![0, 1, 2, 5, 3, 4]; // ends on album B
        for s in 1..60 {
            let (next, _) = advance(&q, &order, 4, Shuffle::Albums, Repeat::All, &mut seeded(s)).unwrap();
            assert_ne!(q[next], Some(2), "seed {s}");
        }
        // one album only: no choice
        let one = vec![Some(1), Some(1)];
        assert!(advance(&one, &[0, 1], 1, Shuffle::Albums, Repeat::All, &mut seeded(1)).is_some());
    }

    #[test]
    fn previous_and_upcoming_follow_the_order_and_stop_at_its_ends() {
        let o = [2, 0, 1];
        assert_eq!((previous(&o, 0), previous(&o, 2), previous(&o, 9)), (Some(2), None, None));
        assert_eq!((upcoming(&o, 0), upcoming(&o, 1), upcoming(&o, 9)), (Some(1), None, None));
    }

    #[test]
    fn the_modes_cycle_and_ids_round_trip() {
        assert_eq!(Shuffle::Off.cycle().cycle().cycle(), Shuffle::Off);
        assert_eq!(Repeat::Off.cycle().cycle().cycle(), Repeat::Off);
        for s in [Shuffle::Off, Shuffle::Tracks, Shuffle::Albums] {
            assert_eq!(Shuffle::from_id(s.id()), s);
        }
        for r in [Repeat::Off, Repeat::All, Repeat::One] {
            assert_eq!(Repeat::from_id(r.id()), r);
        }
        assert_eq!((Shuffle::from_id("x"), Repeat::from_id("x")), (Shuffle::Off, Repeat::Off));
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
