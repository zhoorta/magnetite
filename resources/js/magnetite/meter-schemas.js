// What a theme may set on each meter: the keys of the meter's `params` (visualizers/*.js), with a label and a range. Pure data, no DOM;
// `themes.js` cleans a bundle with it and the theme studio draws its controls from it. A colour left unset (null) follows the theme.
// A test checks every key here against the meter's own `params`.

const num = (key, label, min, max, step, unit = '') => ({ key, label, type: 'number', min, max, step, unit });
const colour = (key, label) => ({ key, label, type: 'color' });

const scale = [num('min', 'Scale from', -60, 0, 1, 'dB'), num('max', 'Scale to', -12, 12, 1, 'dB')];

// The poster meters (docs/posters.md): the same settings for all, then each one's own colours. `theme` on makes every colour follow the theme.
const posterBands = [
    num('min', 'Quiet at', -96, -30, 1, 'dB'), num('max', 'Loud at', -40, 0, 1, 'dB'), num('attack', 'Rise', 0.005, 0.5, 0.005, 's'), num('release', 'Fall', 0.05, 1.5, 0.05, 's'),
];
const posterLevels = [num('min', 'Quiet at', -80, -20, 1, 'dB'), num('max', 'Loud at', -20, 0, 1, 'dB'), num('response', 'Response', 0.05, 1.5, 0.05, 's')];
const posterLook = [num('text', 'Text on the poster', 0, 1, 1), num('theme', 'Theme colours: 0 none, 1 paper and ink, 2 all', 0, 2, 1)];

export const METER_SCHEMAS = {
    'vu-wide': [
        ...scale, num('response', 'Needle response', 0.05, 1.5, 0.05, 's'), num('redFrom', 'Red zone from', -24, 6, 1, 'dB'),
        colour('face', 'Face'), colour('ink', 'Scale and labels'), colour('needle', 'Needle'), colour('zone', 'Red zone'),
    ],
    'vu-needle': [
        ...scale, num('response', 'Needle response', 0.05, 1.5, 0.05, 's'), num('redFrom', 'Red zone from', -24, 6, 1, 'dB'),
        colour('face', 'Face'), colour('ink', 'Scale, labels and needle'), colour('zone', 'Red zone'),
    ],
    'vu-leds': [
        num('min', 'Scale from', -72, -12, 1, 'dB'), num('max', 'Scale to', -12, 6, 1, 'dB'), num('segments', 'Segments', 8, 80, 1),
        num('attack', 'Attack', 0.005, 0.5, 0.005, 's'), num('release', 'Release', 0.05, 1.5, 0.05, 's'), num('hold', 'Peak hold', 0, 3, 0.1, 's'),
        num('midFrom', 'Second colour from', -30, 0, 1, 'dB'), num('hotFrom', 'Third colour from', -12, 3, 1, 'dB'),
    ],
    'magic-eye': [
        num('min', 'Open at', -60, -12, 1, 'dB'), num('max', 'Closed at', -12, 6, 1, 'dB'),
        num('attack', 'Attack', 0.02, 0.5, 0.01, 's'), num('release', 'Release', 0.1, 1, 0.05, 's'), num('openAngle', 'Shadow when quiet', 30, 120, 1, '°'),
        colour('phosphor', 'Glow'), colour('glass', 'Glass'), colour('rim', 'Rim'),
    ],
    'third-octave': [
        num('min', 'Scale from', -96, -24, 1, 'dB'), num('max', 'Scale to', -24, 6, 1, 'dB'), num('segment', 'Segment', 1, 6, 0.5, 'dB'),
        num('release', 'Release', 0.05, 1.5, 0.05, 's'), num('hold', 'Peak hold', 0, 4, 0.1, 's'), num('fall', 'Peak fall', 3, 40, 1, 'dB/s'),
    ],
    loudness: [
        num('min', 'Scale from', -60, -20, 1, 'LUFS'), num('max', 'Scale to', -20, 0, 1, 'LUFS'),
        num('target', 'Target', -40, -5, 1, 'LUFS'), num('ceiling', 'True-peak ceiling', -6, 0, 0.5, 'dBTP'), colour('warn', 'Warning'),
    ],
    spectrogram: [
        num('min', 'Darkest at', -120, -60, 1, 'dB'), num('max', 'Brightest at', -30, 0, 1, 'dB'),
        num('tilt', 'Treble tilt', 0, 6, 0.5, 'dB/oct'), num('span', 'Time span', 4, 30, 1, 's'),
    ],
    goniometer: [num('glow', 'Trace fade', 0.02, 0.5, 0.01, 's'), colour('warn', 'Warning')],
    scope: [num('thickness', 'Line weight', 1, 6, 0.5)],
    'poster-lens': [...posterLevels, ...posterLook, colour('paper', 'Paper'), colour('ink', 'Ink'), colour('a', 'Left disc'), colour('b', 'Right disc')],
    'poster-warp': [...posterLevels, ...posterLook, colour('paper', 'Paper'), colour('ink', 'Stripes'), colour('a', 'Iris')],
    'poster-lenses': [...posterBands, ...posterLook, colour('paper', 'Paper'), colour('dark', 'Sky'), colour('a', 'Sun'), colour('c', 'Lines and lenses'), colour('ink', 'Small text')],
    'poster-facets': [
        num('min', 'Quiet at', -96, -30, 1, 'dB'), num('max', 'Loud at', -40, 0, 1, 'dB'), num('speed', 'Seconds per column', 0.04, 0.5, 0.01, 's'),
        ...posterLook, colour('bg', 'Background'), colour('paper', 'Quiet cells'), colour('a', 'Loud cells'), colour('ink', 'Text'),
    ],
    'poster-arches': [...posterBands, ...posterLook, colour('paper', 'Paper'), colour('ink', 'Ink'), colour('a', 'Accent')],
    'poster-fan': [...posterBands, ...posterLook, colour('paper', 'Paper'), colour('ink', 'Ink'), colour('a', 'Accent')],
    'poster-overcome': [
        ...posterBands, num('fall', 'Circle fall', 0.05, 2, 0.05, '/s'), ...posterLook,
        colour('paper', 'Paper'), colour('ink', 'Lines and text'), colour('a', 'Left circle'), colour('b', 'Right circle'),
    ],
    'poster-pillars': [...posterBands, ...posterLook, colour('paper', 'Paper'), colour('ink', 'Text'), colour('a', 'Pillars'), colour('b', 'Lighter pillars')],
    'poster-dials': [
        num('min', 'Loudness scale from', -60, -20, 1, 'LUFS'), num('max', 'Loudness scale to', -20, 0, 1, 'LUFS'), num('response', 'Response', 0.05, 1.5, 0.05, 's'),
        ...posterLook, colour('paper', 'Paper'), colour('ink', 'Ink'), colour('a', 'Accent'),
    ],
    'poster-columns': [...posterLevels, ...posterLook, colour('paper', 'Paper'), colour('ink', 'Ink'), colour('a', 'Accent')],
    'poster-slides': [...posterBands, ...posterLook, colour('paper', 'Paper'), colour('ink', 'Ink'), colour('a', 'Domes'), colour('b', 'Bowls'), colour('c', 'Bands')],
};
