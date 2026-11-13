import { test } from 'node:test';
import assert from 'node:assert/strict';
import { darkest, luminance, readableOn, isDark, overlap, Bars, groupBands, follow, level01, palette, posterText, metaColumns, showText } from '../../resources/js/magnetite/visualizers/poster.js';

const colors = { bg: '#101010', surface: '#202020', text: '#eeeeee', accent: '#ff0000', accent2: '#00ff00' };

test('band groups take the loudest band of their range, as 0..1 between the two levels', () => {
    const bands = new Float32Array(31).fill(-120);
    bands[0] = -25; // loudest of the first group, at the top of the scale
    bands[30] = -50; // halfway up
    const out = groupBands(bands, 2, -75, -25);
    assert.equal(out[0], 1);
    assert.equal(out[1], 0.5);
    assert.equal(groupBands(bands, 31, -75, -25).length, 31);
    assert.ok(groupBands(new Float32Array(31).fill(-120), 40, -75, -25).every((v) => v === 0), 'silence is zero, also with more groups than bands');
});

test('bars rise with the attack and fall with the release', () => {
    const bars = new Bars(1);
    const loud = new Float32Array(31).fill(-25);
    const quiet = new Float32Array(31).fill(-120);
    const cfg = { min: -75, max: -25, attack: 0.05, release: 1 };
    bars.update(loud, 0.05, cfg);
    assert.ok(bars.v[0] > 0.6, 'one attack time gets most of the way up');
    const up = bars.v[0];
    bars.update(quiet, 0.05, cfg);
    assert.ok(bars.v[0] < up && bars.v[0] > up * 0.7, 'the release is much slower');
});

test('follow reaches about 99% after the given seconds, and level01 clamps', () => {
    assert.ok(Math.abs(follow(0, 1, 0.5, 0.5) - 0.99) < 0.01);
    assert.equal(level01(0, -50, -6), 0);
    assert.equal(level01(1, -50, -6), 1);
    assert.ok(level01(0.1, -50, -6) > 0.5);
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
