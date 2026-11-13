import { test } from 'node:test';
import assert from 'node:assert/strict';
import { Reference, groupDb, stateOf, darkest, luminance, readableOn, isDark, overlap, Bars, follow, palette, posterText, metaColumns, showText } from '../../resources/js/magnetite/visualizers/poster.js';

const colors = { bg: '#101010', surface: '#202020', text: '#eeeeee', accent: '#ff0000', accent2: '#00ff00' };

test('band groups take the loudest band of their range, in dB', () => {
    const bands = new Float32Array(31).fill(-120);
    bands[0] = -25;
    bands[30] = -50;
    const out = groupDb(bands, 2);
    assert.equal(out[0], -25);
    assert.equal(out[1], -50);
    assert.equal(groupDb(bands, 31).length, 31);
    assert.ok(groupDb(new Float32Array(31).fill(-120), 40).every((v) => v === -120), 'silence stays silent, also with more groups than bands');
});

test('bars rise with the attack and fall with the release', () => {
    const bars = new Bars(1);
    const loud = new Float32Array(31).fill(-25);
    const quiet = new Float32Array(31).fill(-120);
    const cfg = { range: 40, attack: 0.05, release: 1 };
    bars.update(loud, 0.05, cfg);
    assert.ok(bars.v[0] > 0.6, 'one attack time gets most of the way up');
    const up = bars.v[0];
    bars.update(quiet, 0.05, cfg);
    assert.ok(bars.v[0] < up && bars.v[0] > up * 0.7, 'the release is much slower');
});

test('the scale follows the track: the same music 20 dB louder fills the bars the same way', () => {
    const music = (offset) => Float32Array.from({ length: 31 }, (_, i) => -45 + offset - i * 0.8);
    const settle = (offset) => {
        const bars = new Bars(9);
        for (let f = 0; f < 120; f++) bars.update(music(offset), 1 / 30, { range: 40, attack: 0.05, release: 0.4 });

        return Array.from(bars.v);
    };
    const quiet = settle(0);
    const loud = settle(20);
    quiet.forEach((v, i) => assert.ok(Math.abs(v - loud[i]) < 0.02, `group ${i}: ${v} vs ${loud[i]}`));
    assert.ok(quiet[0] > 0.9 && quiet[8] < 0.7, 'the loudest group is near the top, the quietest is well below it');
});

test('the reference rises fast, snaps to a first loud sound, and falls slowly', () => {
    const ref = new Reference();
    ref.update(-20, 0.01);
    assert.ok(ref.db >= -50, 'it jumps to within 30 dB of a first loud sound');
    for (let i = 0; i < 100; i++) ref.update(-20, 0.05);
    assert.ok(Math.abs(ref.db + 20) < 0.01, 'and then follows it');
    ref.update(-120, 1);
    assert.ok(Math.abs(ref.db - -22) < 1e-9, 'a quiet second drops it 2 dB');
    assert.ok(Math.abs(new Reference(-30).norm(-30, 40) - 1) < 1e-9 && new Reference(-30).norm(-80, 40) === 0, 'the peak is the top, 40 dB below is the bottom');
    assert.ok(new Reference(-30).norm(-50, 40) < 0.5, 'the curve keeps middle levels below the middle');
});

test('follow reaches about 99% after the given seconds', () => {
    assert.ok(Math.abs(follow(0, 1, 0.5, 0.5) - 0.99) < 0.01);
});

test('the colours are the poster\'s own, until a colour is unset or the theme is followed', () => {
    const params = { theme: 0, paper: '#f6efdc', ink: null, a: '#fb6a1c' };
    assert.deepEqual(palette(params, colors, ['paper', 'ink', 'a']), { paper: '#f6efdc', ink: '#eeeeee', a: '#fb6a1c' });
    assert.deepEqual(palette({ ...params, theme: 1 }, colors, ['paper', 'ink', 'a']), { paper: '#101010', ink: '#eeeeee', a: '#fb6a1c' }, 'level 1: paper and ink from the theme, the accent stays');
    assert.deepEqual(palette({ ...params, theme: 2 }, colors, ['paper', 'ink', 'a']), { paper: '#101010', ink: '#eeeeee', a: '#ff0000' }, 'level 2: all from the theme');
});

test('the poster text comes from the track, with fallbacks', () => {
    const say = posterText({ artist: 'New Order', title: 'Blue Monday', album: 'Power', year: 1983, number: 1, duration: 449, codec: 'flac', truth: { bits: 24, sample_rate: 96000, dr: 9, dr_class: 'fair' } });
    assert.deepEqual(say, { name: 'New Order', title: 'Blue Monday', album: 'Power', year: '1983', no: '01', dur: '7:29', fmt: 'FLAC · 24-bit / 96 kHz', dr: 'DR9' });
    assert.deepEqual(metaColumns(say), [['1983', '01 · 7:29'], ['Power'], ['FLAC · 24-bit / 96 kHz', 'DR9']]);

    const bare = posterText({ title: 'Only a title' });
    assert.equal(bare.name, 'Only a title');
    assert.equal(bare.title, '', 'the title is not printed twice');
    assert.equal(posterText(null).name, 'magnetite');
    assert.deepEqual(metaColumns(posterText(null)), [[], [], []]);
});

test('text is left out when it is switched off or the cell is small', () => {
    assert.equal(showText({ text: 1 }, 0.5), true);
    assert.equal(showText({ text: 0 }, 0.5), false);
    assert.equal(showText({ text: 1 }, 0.2), false);
});

test('overlaps darken on light paper and lighten on dark paper', () => {
    assert.equal(isDark('#101010'), true);
    assert.equal(isDark('#fff'), false);
    assert.equal(isDark('rebeccapurple'), false);
    assert.equal(overlap('#f6efdc'), 'multiply');
    assert.equal(overlap('#16181d'), 'screen');
});

test('the readable colour is the one with the most contrast', () => {
    assert.ok(luminance('#ffffff') > 0.99 && luminance('#000') < 0.01);
    assert.equal(readableOn('#f2500f', ['#e6e8ef', '#1a1a1a']), '#1a1a1a');
    assert.equal(readableOn('#101010', ['#1a1a1a', '#f6efdc']), '#f6efdc');
    assert.equal(darkest('#eeeeee', '#101010', '#888888'), '#101010');
});

test('state is kept per canvas context and per poster', () => {
    const a = {};
    const b = {};
    const make = () => ({ n: 0 });
    const other = () => ({ bars: [] });
    stateOf(a, make).n = 5;
    assert.equal(stateOf(a, make).n, 5);
    assert.equal(stateOf(b, make).n, 0, 'another canvas has its own');
    assert.deepEqual(stateOf(a, other), { bars: [] }, 'another poster on the same canvas does not get this poster\'s state');
    assert.equal(stateOf(a, make).n, 5, 'and does not lose it');
});
