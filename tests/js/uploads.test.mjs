import test from 'node:test';
import assert from 'node:assert/strict';
import { checkZips, sizeText, uploadNote } from '../../resources/js/magnetite/uploads.js';

const f = (name, mb = 1) => ({ name, size: mb * 1024 * 1024 });

test('only zips under the limit are taken, without repeats', () => {
    const { files, error } = checkZips([f('a.zip'), f('b.ZIP'), f('c.mp3'), f('big.zip', 600), f('a.zip')], [], 500);
    assert.deepEqual(files.map((x) => x.name), ['a.zip', 'b.ZIP']);
    assert.match(error, /big\.zip is larger than 500 MB/);
    assert.deepEqual(checkZips([f('a.zip')], [f('a.zip')], 500).files, []);
    assert.equal(checkZips([f('a.zip')], [], 500).error, null);
});

test('sizes read in MB and GB', () => {
    assert.equal(sizeText(1.5 * 1024 ** 2), '1.5 MB');
    assert.equal(sizeText(2 * 1024 ** 3), '2.00 GB');
});

test('the status line follows the upload and then the scan', () => {
    assert.equal(uploadNote({ busy: true, progress: 45 }), 'Uploading 45%');
    assert.equal(uploadNote({ busy: true, progress: 100 }), 'Unpacking…');
    assert.equal(uploadNote({ scan: { state: 'queued' } }), 'Scan queued');
    assert.equal(uploadNote({ scan: { state: 'running', processed: 120 } }), 'Scanning… 120 files');
    assert.equal(uploadNote({ scan: { state: 'done' }, done: { added: 7 } }), 'Library updated: 7 new');
    assert.equal(uploadNote({ scan: { state: 'done' } }), '');
    assert.equal(uploadNote({}), '');
});
