# Themes

A theme sets the colours, the corner radius and the fonts of the whole page, the meters included. Nine themes come with Magnetite.

## Switching

- The palette button in the top bar lists the themes.
- `t` and `T` step to the next and previous theme, on any page.
- The **Themes** page shows them as cards; a click applies one.

The choice is saved in the browser.

## Editing

On the Themes page, **Edit a copy** opens the editor for the selected theme: a preview and a live meter on the left, the settings on the right. The whole page follows the draft while it is edited; **Cancel** or `Esc` puts the theme in use back.

- **Colours**: six of them (see the format below).
- **Corners**: the radius of boxes and buttons.
- **Font**: one of 16 bundled fonts, in groups (sans, rounded and display, serif, mono, pixel and terminal).
- **Meter font**: another font for the text on the meters, or the same as Font.

**Save as a new theme** keeps it. Built-in themes cannot be changed; editing one always makes a copy. A custom theme can be edited again or deleted.

Custom themes are stored in the browser, not in the account. To use one in another browser, export it there and import it here.

## Export and import

Under the cards, **Download JSON** and **Copy JSON** export the selected theme. **Import a theme** takes pasted JSON or a file.

An imported theme is checked before it is used: the colours must be hex values, the radius a length, and the font a plain list of font names. A theme that names a font Magnetite does not bundle falls back to the next font in its list.

## The format

    {
      "id": "terminal",
      "name": "Terminal",
      "colors": {
        "bg": "#000000",
        "surface": "#07140a",
        "text": "#b6ffbd",
        "muted": "#4f8a57",
        "accent": "#39ff6a",
        "accent2": "#b6ff3a"
      },
      "radius": "0rem",
      "font": "ui-monospace, 'SF Mono', Menlo, monospace"
    }

| Key | What it is |
|---|---|
| `bg` | Page background |
| `surface` | Boxes, cards and the top bar |
| `text` | Text |
| `muted` | Secondary text and lines |
| `accent` | Selection, links, the main colour of the meters |
| `accent2` | Second accent |
| `radius` | Corner radius, for example `0rem` or `0.5rem` |
| `font` | Font list for the page |
| `meterFont` | Optional font list for the text on the meters |

A theme does not choose which meters show; that is set per account (see [Meters and posters](meters.md)). The mobile apps and the terminal client use a theme's colours and ignore its fonts.

Next: [Sharing and playlists](sharing.md).
