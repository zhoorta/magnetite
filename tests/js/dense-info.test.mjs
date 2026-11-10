import test from 'node:test';
import assert from 'node:assert/strict';
import { drText, lufsText, formatText, infoLine, findings, hasProblem, albumProblems, dockInfo, trackPosition } from '../../resources/js/magnetite/dense/info.js';

const analysed = { codec: 'mp3', truth: { analyzed: true, dr: 9, dr_class: 'fair', lufs: -10.9, sample_rate: 48000, bits: 24, declared_bits: 32, flags: [
    { code: 'note', level: 'note', label: 'Note' },
    { code: 'peak', level: 'warn', label: 'Peaks over 0 dBTP' },
    { code: 'clip', level: 'problem', label: 'Clipped' },
    { code: 'more', level: 'warn', label: 'Another' },
] } };
const bare = { codec: 'flac', truth: { analyzed: false, flags: [] } };

test('DR and loudness read like the terminal client', () => {
    assert.equal(drText(analysed.truth), 'DR9 (fair)');
    assert.equal(lufsText(analysed.truth), '−10.9 LUFS');
    assert.equal(drText(bare.truth), null);
    assert.equal(lufsText({ lufs: 1.25 }), '1.3 LUFS');
    assert.equal(lufsText({}), null);
});

test('the format line is the codec until the file is measured, then bits and rate', () => {
    assert.equal(formatText(analysed), 'MP3 · 32-bit / 48 kHz');
    assert.equal(formatText(bare), 'FLAC');
    assert.equal(formatText({ codec: 'flac', truth: { sample_rate: 44100, bits: 16 } }), 'FLAC · 16-bit / 44.1 kHz');
    assert.equal(formatText({}), '');
});

test('the info line follows the level: nothing at 0, format, DR and loudness from 1', () => {
    assert.equal(infoLine(analysed, 0), '');
    assert.equal(infoLine(analysed, 1), 'MP3 · 32-bit / 48 kHz · DR9 (fair) · −10.9 LUFS');
    assert.equal(infoLine(null, 2), '');
    assert.equal(infoLine(bare, 1), 'FLAC');
});

test('findings leave out notes, put problems first, and stop at the limit', () => {
    assert.deepEqual(findings(analysed).map((f) => f.code), ['clip', 'peak']);
    assert.deepEqual(findings(analysed, 3).map((f) => f.code), ['clip', 'peak', 'more']);
    assert.deepEqual(findings(bare), []);
    assert.deepEqual(findings({}), []);
});

test('only problems and warnings make a track a problem', () => {
    assert.equal(hasProblem(analysed), true);
    assert.equal(hasProblem(bare), false);
    assert.equal(hasProblem({ truth: { flags: [{ level: 'note' }] } }), false);
    assert.equal(albumProblems({ tracks: [analysed, bare, analysed] }), 2);
    assert.equal(albumProblems({}), 0);
});

test('the player shows findings only from level 2', () => {
    assert.deepEqual(dockInfo(analysed, 1).findings, []);
    assert.equal(dockInfo(analysed, 2).findings.length, 2);
    assert.deepEqual(dockInfo(analysed, 0), { line: '', findings: [] });
});

test('"track 6 of 11" only when the queue is that one album', () => {
    const album = [1, 2, 3].map((n) => ({ album_id: 7, edit: { track_number: n } }));
    assert.equal(trackPosition(album[1], album, 1), 'track 2 of 3');
    const mixed = [...album, { album_id: 8, edit: { track_number: 1 } }];
    assert.equal(trackPosition(album[1], mixed, 1), 'track 2');
    assert.equal(trackPosition({ album_id: 9 }, [{ album_id: 9 }, { album_id: 4 }], 0), '');
    assert.equal(trackPosition(null, [], 0), '');
});
