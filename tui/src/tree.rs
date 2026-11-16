//! The album list as the web library shows it: the user's folders (nested), each with the albums directly in it, and the albums
//! without a folder after them. Pure: `rows` turns folders, albums and the set of open folders into the lines of the list.

use crate::api::Album;
use serde::Deserialize;
use std::collections::BTreeSet;

#[derive(Deserialize, Clone, Debug, PartialEq)]
pub struct Folder {
    pub id: u64,
    pub parent_id: Option<u64>,
    pub name: String,
    /// Albums directly in the folder (not in its subfolders).
    #[serde(default)]
    pub album_count: u64,
}

#[derive(Clone, Debug, PartialEq)]
pub enum Row {
    /// `albums`: how many albums are in it and below it.
    Folder { id: u64, depth: usize, name: String, open: bool, albums: usize },
    /// `index` into the album list.
    Album { index: usize, depth: usize },
}

impl Row {
    pub fn depth(&self) -> usize {
        match self {
            Row::Folder { depth, .. } | Row::Album { depth, .. } => *depth,
        }
    }
}

/// Folders whose parent is missing (a stale list) count as top-level.
fn parent_of(folders: &[Folder], f: &Folder) -> Option<u64> {
    f.parent_id.filter(|p| *p != f.id && folders.iter().any(|x| x.id == *p))
}

/// The folder and everything below it.
fn below(folders: &[Folder], id: u64) -> BTreeSet<u64> {
    let mut set = BTreeSet::from([id]);
    loop {
        let before = set.len();
        for f in folders {
            if parent_of(folders, f).is_some_and(|p| set.contains(&p)) {
                set.insert(f.id);
            }
        }
        if set.len() == before {
            return set;
        }
    }
}

/// The ids of the folders above a folder, nearest first.
pub fn ancestors(folders: &[Folder], id: u64) -> Vec<u64> {
    let mut out = vec![];
    let mut at = folders.iter().find(|f| f.id == id);
    while let Some(p) = at.and_then(|f| parent_of(folders, f)) {
        if out.contains(&p) {
            break; // a loop in the data
        }
        out.push(p);
        at = folders.iter().find(|f| f.id == p);
    }
    out
}

/// The lines of the list. With no folders it is just the albums, as before. `albums` keeps its own order (already sorted);
/// folders are in name order. An album whose folder is unknown shows with the loose ones.
pub fn rows(folders: &[Folder], albums: &[Album], open: &BTreeSet<u64>) -> Vec<Row> {
    let mut out = vec![];
    let in_folder = |a: &Album| a.folder_id.filter(|id| folders.iter().any(|f| f.id == *id));

    fn walk(out: &mut Vec<Row>, folders: &[Folder], albums: &[Album], open: &BTreeSet<u64>, parent: Option<u64>, depth: usize, in_folder: &dyn Fn(&Album) -> Option<u64>) {
        let mut mine: Vec<&Folder> = folders.iter().filter(|f| parent_of(folders, f) == parent).collect();
        mine.sort_by_cached_key(|f| f.name.to_lowercase());
        for f in mine {
            let ids = below(folders, f.id);
            let count = albums.iter().filter(|a| in_folder(a).is_some_and(|id| ids.contains(&id))).count();
            let is_open = open.contains(&f.id);
            out.push(Row::Folder { id: f.id, depth, name: f.name.clone(), open: is_open, albums: count });
            if is_open {
                walk(out, folders, albums, open, Some(f.id), depth + 1, in_folder);
                for (index, _) in albums.iter().enumerate().filter(|(_, a)| in_folder(a) == Some(f.id)) {
                    out.push(Row::Album { index, depth: depth + 1 });
                }
            }
        }
    }

    walk(&mut out, folders, albums, open, None, 0, &in_folder);
    for (index, _) in albums.iter().enumerate().filter(|(_, a)| in_folder(a).is_none()) {
        out.push(Row::Album { index, depth: 0 });
    }
    out
}

/// What the left of a row is: indentation and, for a folder, the arrow.
pub fn prefix(row: &Row) -> String {
    let pad = "  ".repeat(row.depth());
    match row {
        Row::Folder { open, .. } => format!("{pad}{} ", if *open { '▾' } else { '▸' }),
        Row::Album { .. } => format!("{pad}  "),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn album(id: u64, title: &str, folder: Option<u64>) -> Album {
        serde_json::from_value(serde_json::json!({ "id": id, "title": title, "folder_id": folder })).unwrap()
    }
    fn folder(id: u64, parent: Option<u64>, name: &str) -> Folder {
        Folder { id, parent_id: parent, name: name.into(), album_count: 0 }
    }
    fn names(rows: &[Row], albums: &[Album]) -> Vec<String> {
        rows.iter()
            .map(|r| match r {
                Row::Folder { name, depth, open, albums, .. } => format!("{}{}{name} ({albums})", " ".repeat(*depth), if *open { "v" } else { ">" }),
                Row::Album { index, depth } => format!("{}{}", " ".repeat(*depth), albums[*index].title),
            })
            .collect()
    }

    #[test]
    fn without_folders_the_list_is_the_albums() {
        let albums = vec![album(1, "A", None), album(2, "B", None)];
        assert_eq!(names(&rows(&[], &albums, &BTreeSet::new()), &albums), ["A", "B"]);
    }

    #[test]
    fn folders_first_in_name_order_closed_by_default_then_the_loose_albums() {
        let folders = vec![folder(2, None, "Rock"), folder(1, None, "jazz"), folder(3, Some(1), "Live")];
        let albums = vec![album(1, "Kind of Blue", Some(1)), album(2, "Loose", None), album(3, "At Newport", Some(3)), album(4, "Low", Some(2))];
        let r = rows(&folders, &albums, &BTreeSet::new());
        assert_eq!(names(&r, &albums), [">jazz (2)", ">Rock (1)", "Loose"]); // the count includes the subfolder
    }

    #[test]
    fn an_open_folder_shows_its_subfolders_then_its_albums_indented() {
        let folders = vec![folder(1, None, "Jazz"), folder(3, Some(1), "Live")];
        let albums = vec![album(1, "Kind of Blue", Some(1)), album(3, "At Newport", Some(3))];
        let r = rows(&folders, &albums, &BTreeSet::from([1, 3]));
        assert_eq!(names(&r, &albums), ["vJazz (2)", " vLive (1)", "  At Newport", " Kind of Blue"]);
        // a closed parent hides what an open child would show
        assert_eq!(names(&rows(&folders, &albums, &BTreeSet::from([3])), &albums), [">Jazz (2)"]);
    }

    #[test]
    fn an_album_in_an_unknown_folder_is_loose_and_a_stale_parent_is_top_level() {
        let folders = vec![folder(5, Some(99), "Orphan")];
        let albums = vec![album(1, "A", Some(77)), album(2, "B", Some(5))];
        assert_eq!(names(&rows(&folders, &albums, &BTreeSet::new()), &albums), [">Orphan (1)", "A"]);
    }

    #[test]
    fn ancestors_nearest_first_and_a_loop_ends() {
        let folders = vec![folder(1, None, "a"), folder(2, Some(1), "b"), folder(3, Some(2), "c")];
        assert_eq!(ancestors(&folders, 3), [2, 1]);
        assert!(ancestors(&folders, 1).is_empty());
        let looped = vec![folder(1, Some(2), "a"), folder(2, Some(1), "b")];
        assert!(ancestors(&looped, 1).len() <= 2);
    }

    #[test]
    fn prefix_indents_and_shows_the_arrow() {
        assert_eq!(prefix(&Row::Folder { id: 1, depth: 1, name: "x".into(), open: false, albums: 0 }), "  ▸ ");
        assert_eq!(prefix(&Row::Album { index: 0, depth: 2 }), "      ");
    }
}
