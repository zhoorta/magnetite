import test from 'node:test';
import assert from 'node:assert/strict';
import { parse, complete, pick, COMMANDS } from '../../resources/js/magnetite/dense/commands.js';
import { titleCase } from '../../resources/js/magnetite/dense/text.js';

test('a typed line is a command and an argument', () => {
    assert.deepEqual(parse('move  Jazz '), { name: 'move', arg: 'Jazz', typed: 'move', ambiguous: [] });
    assert.equal(parse('ANALYZE').name, 'analyze');
    assert.equal(parse('analyse').name, 'analyze', 'the British spelling the buttons use');
    assert.equal(parse('mv rock').name, 'move');
    assert.equal(parse('new Ambient Beats').arg, 'Ambient Beats');
});

test('a prefix works while only one command has it', () => {
    assert.equal(parse('anal').name, 'analyze');
    assert.equal(parse('cov').name, 'cover');
    const m = parse('s');
    assert.equal(m.name, null);
    assert.ok(m.ambiguous.includes('share') && m.ambiguous.includes('sort'));
    assert.equal(parse('nope').name, null);
    assert.deepEqual(parse('nope').ambiguous, []);
    assert.equal(parse('').name, null);
});

test('every command has a name that the parser finds', () => {
    for (const [name] of COMMANDS) assert.equal(parse(name).name, name);
});

test('completion offers command names, then the arguments of the command', () => {
    assert.deepEqual(complete('an'), ['analyze']);
    assert.ok(complete('').length === COMMANDS.length);
    const ctx = { folders: ['Jazz', 'Rock', 'Jazz Fusion'], themes: ['terminal', 'graphite'] };
    assert.deepEqual(complete('move j', ctx), ['move Jazz', 'move Jazz Fusion']);
    assert.deepEqual(complete('mv R', ctx), ['move Rock'], 'an alias completes to the command');
    assert.deepEqual(complete('theme g', ctx), ['theme graphite']);
    assert.deepEqual(complete('info ', ctx), ['info 0', 'info 1', 'info 2']);
    assert.deepEqual(complete('sort y'), ['sort year']);
    assert.deepEqual(complete('delete x'), [], 'a command without arguments completes to nothing');
});

test('pick finds a name by exact or unique prefix, any case', () => {
    assert.equal(pick('jazz', ['Jazz', 'Jazz Fusion']), 'Jazz');
    assert.equal(pick('jazz f', ['Jazz', 'Jazz Fusion']), 'Jazz Fusion');
    assert.equal(pick('ja', ['Jazz', 'Jazz Fusion']), null, 'two start with it');
    assert.equal(pick('', ['Jazz']), null);
    assert.equal(pick('x', ['Jazz']), null);
});

test('title case keeps small words small, but not at the ends, and leaves shouting and mixed case alone', () => {
    assert.equal(titleCase('the book i read'), 'The Book I Read');
    assert.equal(titleCase('first week / last week… carefree'), 'First Week / Last Week… Carefree');
    assert.equal(titleCase("don't worry about the government"), "Don't Worry About the Government");
    assert.equal(titleCase('a day in the life'), 'A Day in the Life');
    assert.equal(titleCase('WHO IS IT?'), 'WHO IS IT?');
    assert.equal(titleCase('paul McCartney and iPhone'), 'Paul McCartney and iPhone');
    assert.equal(titleCase('rock-and-roll'), 'Rock-And-Roll');
    assert.equal(titleCase('  spaced   out  '), '  Spaced   Out  ');
    assert.equal(titleCase(''), '');
    assert.equal(titleCase('what is this for'), 'What Is This For');
});
