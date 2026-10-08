#!/usr/bin/env node
// Measures one audio file and prints the result as JSON. Run by `php artisan magnetite:analyze`:
//   node bin/measure.mjs --ffmpeg /path/to/ffmpeg --ffprobe /path/to/ffprobe /absolute/path/to/file
// The file is only read. ffmpeg decodes it to raw 32-bit PCM and the analysis in
// resources/js/magnetite/analysis/ does the rest.

import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { TrackAnalysis } from '../resources/js/magnetite/analysis/track-analysis.js';

const run = promisify(execFile);

function args(argv) {
    const out = { ffmpeg: 'ffmpeg', ffprobe: 'ffprobe', file: null };
    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--ffmpeg') out.ffmpeg = argv[++i];
        else if (argv[i] === '--ffprobe') out.ffprobe = argv[++i];
        else out.file = argv[i];
    }

    return out;
}

async function probe(ffprobe, file) {
    const { stdout } = await run(ffprobe, [
        '-v', 'error', '-select_streams', 'a:0',
        '-show_entries', 'stream=codec_name,sample_rate,channels,bits_per_raw_sample,bits_per_sample,bit_rate',
        '-show_entries', 'format=duration,bit_rate',
        '-of', 'json', '--', file,
    ]);
    const { streams = [], format = {} } = JSON.parse(stdout);
    const s = streams[0];
    if (!s) throw new Error('no audio stream');

    const bits = Number(s.bits_per_raw_sample) || Number(s.bits_per_sample) || null;

    return {
        codec: s.codec_name,
        sample_rate: Number(s.sample_rate),
        channels: Number(s.channels),
        declared_bits: bits,
        bit_rate: Number(s.bit_rate || format.bit_rate) || null,
        duration: Number(format.duration) || null,
    };
}

async function decode(ffmpeg, file, info) {
    const channels = info.channels === 1 ? 1 : 2; // anything beyond stereo is downmixed
    const analysis = new TrackAnalysis({ sampleRate: info.sample_rate, channels });
    const argv = ['-nostdin', '-v', 'error', '-i', file, '-map', '0:a:0', '-vn', '-f', 's32le', '-acodec', 'pcm_s32le'];
    if (info.channels > 2) argv.push('-ac', '2');
    argv.push('pipe:1');

    const child = spawn(ffmpeg, argv, { stdio: ['ignore', 'pipe', 'pipe'] });
    let stderr = '';
    child.stderr.on('data', (d) => {
        if (stderr.length < 4000) stderr += d;
    });
    const closed = new Promise((resolve) => child.on('close', resolve));

    for await (const chunk of child.stdout) analysis.push(chunk);

    const code = await closed;
    const errors = stderr.split('\n').map((l) => l.trim()).filter(Boolean).slice(0, 20);
    if (code !== 0 && analysis.frames === 0) throw new Error(`ffmpeg failed: ${errors.join(' | ') || `exit ${code}`}`);

    return { result: analysis.finish(), errors };
}

try {
    const { ffmpeg, ffprobe, file } = args(process.argv.slice(2));
    if (!file) throw new Error('usage: measure.mjs [--ffmpeg path] [--ffprobe path] file');

    const info = await probe(ffprobe, file);
    const { result, errors } = await decode(ffmpeg, file, info);

    // A file that decodes to noticeably less audio than its header promises was cut short.
    const short = info.duration && result.duration < info.duration - 1 ? Math.round((info.duration - result.duration) * 10) / 10 : 0;

    process.stdout.write(JSON.stringify({ ...result, source: info, decode_errors: errors, missing_seconds: short }));
} catch (e) {
    process.stderr.write(`${e.message}\n`);
    process.exit(1);
}
