//! Find in a list: `\` asks for some words and the selection jumps to the first row that has all of them (case and accents do
//! not matter, the same folding as the search), `down` and `up` go to the next and previous match, `enter` stays where you are
//! and `esc` goes back to where you were. The lists are not filtered or reordered, so every other key works as before.

use crate::search::contains_all;

/// What is being asked for, and where the selection was when it began.
pub struct Find {
    pub text: String,
    pub from: usize,
}

impl Find {
    pub fn new(from: usize) -> Self {
        Find { text: String::new(), from }
    }
}

/// The rows whose text has every word of `text` (all of them when there is no word).
pub fn matches(rows: &[String], text: &str) -> Vec<usize> {
    rows.iter().enumerate().filter(|(_, r)| contains_all(r, text)).map(|(i, _)| i).collect()
}

/// The first match at or after `from`, going round to the start; none when there is no match.
pub fn first_from(found: &[usize], from: usize) -> Option<usize> {
    found.iter().copied().find(|&i| i >= from).or_else(|| found.first().copied())
}

/// The next (`dir` > 0) or previous match from `at`, going round.
pub fn step(found: &[usize], at: usize, dir: i32) -> Option<usize> {
    if dir >= 0 {
        found.iter().copied().find(|&i| i > at).or_else(|| found.first().copied())
    } else {
        found.iter().copied().rev().find(|&i| i < at).or_else(|| found.last().copied())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rows() -> Vec<String> {
        ["David Bowie Heroes", "Jáfumega Recados", "Bowie Low", "Wallflowers Heroes"].iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn finds_every_row_that_has_all_the_words() {
        assert_eq!(matches(&rows(), "bowie"), [0, 2]);
        assert_eq!(matches(&rows(), "HEROES bowie"), [0]);
        assert_eq!(matches(&rows(), "jafumega"), [1]);
        assert_eq!(matches(&rows(), "nothing"), Vec::<usize>::new());
        assert_eq!(matches(&rows(), "  ").len(), 4); // no words: every row
    }

    #[test]
    fn jumps_from_where_it_began_and_goes_round() {
        let found = [0, 2, 5];
        assert_eq!(first_from(&found, 1), Some(2));
        assert_eq!(first_from(&found, 2), Some(2));
        assert_eq!(first_from(&found, 6), Some(0)); // round to the start
        assert_eq!(first_from(&[], 3), None);
        assert_eq!((step(&found, 2, 1), step(&found, 5, 1), step(&found, 2, -1), step(&found, 0, -1)), (Some(5), Some(0), Some(0), Some(5)));
        assert_eq!(step(&[], 1, 1), None);
    }
}
