//! Making folders and moving albums from the album list: `N` asks for a name, `x` marks albums, `v` chooses a folder for the
//! marked ones (or the selected one). The server does the work (`POST /folders`, `PUT /albums/{id}/folder`); this holds the small
//! pieces of state and the pure parts (the list of places to move to, the wording), which are tested.

use crate::theme::{c, Theme};
use crate::tree::{self, Folder};
use ratatui::{
    layout::{Constraint, Flex, Layout, Rect},
    style::{Modifier, Style},
    text::{Line, Span},
    widgets::{Block, Borders, Clear, List, ListItem, ListState},
    Frame,
};
use std::collections::BTreeSet;

/// `N`: the name being typed, and the folder it goes into (none: the top level).
pub struct Prompt {
    pub text: String,
    pub parent: Option<u64>,
}

/// `v`: the places an album can go, the highlighted one, and the albums that go (ids).
pub struct Mover {
    pub targets: Vec<(Option<u64>, String)>,
    pub sel: usize,
    pub albums: Vec<u64>,
}

pub const NAME_MAX: usize = 100; // the server's limit

/// The top level, then every folder in tree order, indented by depth.
pub fn targets(folders: &[Folder]) -> Vec<(Option<u64>, String)> {
    let all: BTreeSet<u64> = folders.iter().map(|f| f.id).collect();
    let mut out = vec![(None, "(top level, no folder)".to_string())];
    for r in tree::rows(folders, &[], &all) {
        if let tree::Row::Folder { id, depth, name, .. } = r {
            out.push((Some(id), format!("{}{name}", "  ".repeat(depth))));
        }
    }
    out
}

pub fn moved_note(n: usize, to: &str) -> String {
    format!("moved {n} album{} to {}", if n == 1 { "" } else { "s" }, to.trim())
}

pub fn draw_mover(f: &mut Frame, m: &Mover, th: &Theme) {
    let (text, muted, accent) = (c(th.text), c(th.muted), c(th.accent));
    let n = m.albums.len();
    let title = format!(" Move {n} album{} to… ", if n == 1 { "" } else { "s" });
    let width = 60.min(f.area().width);
    let height = (m.targets.len() as u16 + 4).min(f.area().height);
    let [row] = Layout::vertical([Constraint::Length(height)]).flex(Flex::Center).areas(f.area());
    let [area]: [Rect; 1] = Layout::horizontal([Constraint::Length(width)]).flex(Flex::Center).areas(row);
    f.render_widget(Clear, area);
    let block = Block::new().borders(Borders::ALL).title(title).title_bottom(Line::from(Span::styled(" j/k choose · enter move · esc cancel ", Style::new().fg(muted)))).border_style(Style::new().fg(accent)).style(Style::new().bg(c(th.bg)).fg(text));
    let items: Vec<ListItem> = m.targets.iter().map(|(_, name)| ListItem::new(name.clone())).collect();
    let mut state = ListState::default();
    state.select(Some(m.sel));
    f.render_stateful_widget(List::new(items).block(block).highlight_style(Style::new().fg(c(th.bg)).bg(accent).add_modifier(Modifier::BOLD)), area, &mut state);
}

#[cfg(test)]
mod tests {
    use super::*;

    fn folder(id: u64, parent: Option<u64>, name: &str) -> Folder {
        Folder { id, parent_id: parent, name: name.into(), album_count: 0 }
    }

    #[test]
    fn targets_are_the_top_level_then_every_folder_in_tree_order() {
        let folders = vec![folder(2, None, "Rock"), folder(1, None, "Jazz"), folder(3, Some(1), "Live")];
        let t = targets(&folders);
        assert_eq!(t.iter().map(|(id, _)| *id).collect::<Vec<_>>(), [None, Some(1), Some(3), Some(2)]);
        assert_eq!(t[2].1, "  Live");
        assert_eq!(targets(&[]).len(), 1);
    }

    #[test]
    fn the_note_says_how_many_and_where() {
        assert_eq!(moved_note(1, "Jazz"), "moved 1 album to Jazz");
        assert_eq!(moved_note(3, "  Live"), "moved 3 albums to Live");
    }
}
