<?php

use App\Models\Track;
use App\Support\FileTruth;

/** An analysed track as the analyzer would store it, with the parts the test cares about replaced. */
function analysed(array $over = [], array $columns = []): Track
{
    $analysis = array_replace_recursive([
        'sample_rate' => 44100,
        'effective_bits' => 16,
        'source' => ['codec' => 'flac', 'declared_bits' => 16],
        'loudness' => ['integrated' => -12.0, 'range' => 5.0, 'true_peak_db' => -1.0, 'sample_peak_db' => -1.2],
        'clipping' => ['runs' => 0],
        'gaps' => ['count' => 0, 'longest_ms' => 0],
        'dc_offset' => 0.0,
        'dual_mono' => false,
        'inverted_channels' => false,
        'spectrum' => ['cutoff_hz' => null, 'cliff_db' => null, 'content_hz' => 22000, 'db' => []],
        'decode_errors' => [],
        'missing_seconds' => 0,
    ], $over);

    return Track::make($columns + ['id' => 1, 'title' => 'T', 'artist' => 'A', 'path' => 'a.flac', 'duration' => 200, 'dr' => 10, 'analysis' => $analysis]);
}

function codes(Track $t, array $copies = []): array
{
    return array_column(FileTruth::flags($t, $copies), 'code');
}

it('says nothing about a clean file', function () {
    expect(FileTruth::flags(analysed()))->toBe([]);
});

it('says nothing about a track that has not been analysed', function () {
    expect(FileTruth::flags(Track::make(['title' => 'x'])))->toBe([]);
});

it('flags a lossless file with the cut-off of a lossy encoder', function () {
    $t = analysed(['spectrum' => ['cutoff_hz' => 16000, 'cliff_db' => 44.2]]);

    expect(codes($t))->toBe(['lossy_source']);
    expect(FileTruth::flags($t)[0])->toMatchArray(['level' => 'problem'])
        ->and(FileTruth::flags($t)[0]['detail'])->toContain('44 dB')->toContain('16 kHz')->toContain('FLAC');
});

it('does not call a lossy file lossy, nor a lossless one with a cut-off near Nyquist', function () {
    expect(codes(analysed(['source' => ['codec' => 'mp3'], 'spectrum' => ['cutoff_hz' => 16000, 'cliff_db' => 40]])))->toBe([])
        ->and(codes(analysed(['spectrum' => ['cutoff_hz' => 21000, 'cliff_db' => 35]])))->toBe([]);
});

it('treats every PCM and lossless codec as lossless', function () {
    foreach (['flac', 'alac', 'pcm_s16le', 'pcm_s24le', 'wavpack'] as $codec) {
        expect(FileTruth::isLossless($codec))->toBeTrue($codec);
    }
    foreach (['mp3', 'aac', 'vorbis', 'opus', null] as $codec) {
        expect(FileTruth::isLossless($codec))->toBeFalse((string) $codec);
    }
});

it('flags hi-res files with nothing above CD bandwidth, but not real hi-res', function () {
    expect(codes(analysed(['sample_rate' => 96000, 'spectrum' => ['content_hz' => 21500]])))->toBe(['upsampled'])
        ->and(codes(analysed(['sample_rate' => 96000, 'spectrum' => ['content_hz' => 28000]])))->toBe([])
        ->and(codes(analysed(['sample_rate' => 44100, 'spectrum' => ['content_hz' => 21500]])))->toBe([]);
});

it('flags a bit depth that is only padding', function () {
    expect(codes(analysed(['effective_bits' => 16, 'source' => ['declared_bits' => 24]])))->toBe(['padded_bits'])
        ->and(codes(analysed(['effective_bits' => 24, 'source' => ['declared_bits' => 24]])))->toBe([]);
});

it('grades clipping by how many flat-topped peaks there are', function () {
    $level = fn (int $runs) => collect(FileTruth::flags(analysed(['clipping' => ['runs' => $runs]])))->firstWhere('code', 'clipping')['level'] ?? null;

    expect($level(3))->toBeNull()
        ->and($level(10))->toBe('warn')
        ->and($level(99))->toBe('warn')
        ->and($level(100))->toBe('problem');
});

it('reports damage: decode errors, a short file, inverted channels', function () {
    $t = analysed(['decode_errors' => ['Header missing'], 'missing_seconds' => 12.5, 'inverted_channels' => true]);

    expect(codes($t))->toBe(['decode_errors', 'cut_short', 'inverted'])
        ->and(collect(FileTruth::flags($t))->pluck('level')->unique()->all())->toBe(['problem']);
});

it('notes the smaller things without calling them problems', function () {
    $t = analysed(['gaps' => ['count' => 2, 'longest_ms' => 80], 'dual_mono' => true, 'loudness' => ['true_peak_db' => 0.4]]);

    expect(codes($t))->toBe(['true_peak', 'gaps', 'dual_mono'])
        ->and(collect(FileTruth::flags($t))->pluck('level')->unique()->all())->toBe(['note']);
});

it('warns about a heavily compressed master, a high true peak and DC offset', function () {
    $t = analysed(['loudness' => ['true_peak_db' => 1.6], 'dc_offset' => 0.025], ['dr' => 5]);

    expect(codes($t))->toBe(['crushed', 'true_peak', 'dc_offset'])
        ->and(collect(FileTruth::flags($t))->pluck('level')->unique()->all())->toBe(['warn']);
});

it('words the dynamic range scale', function () {
    expect(FileTruth::drClass(null))->toBeNull()
        ->and(FileTruth::drClass(15))->toBe('excellent')
        ->and(FileTruth::drClass(12))->toBe('good')
        ->and(FileTruth::drClass(9))->toBe('fair')
        ->and(FileTruth::drClass(7))->toBe('poor');
});

it('finds exact duplicates by decoded hash and other copies by artist, title and length', function () {
    $tracks = collect([
        Track::make(['id' => 1, 'title' => 'Song', 'artist' => 'Band', 'path' => 'a/1.flac', 'duration' => 200.0, 'pcm_sha1' => str_repeat('a', 40)]),
        Track::make(['id' => 2, 'title' => 'Song (copy)', 'artist' => 'Other', 'path' => 'b/2.flac', 'duration' => 10.0, 'pcm_sha1' => str_repeat('a', 40)]),
        Track::make(['id' => 3, 'title' => 'SONG', 'artist' => 'band', 'path' => 'c/3.mp3', 'duration' => 201.0, 'pcm_sha1' => str_repeat('b', 40)]),
        Track::make(['id' => 4, 'title' => 'Song', 'artist' => 'Band', 'path' => 'd/4.mp3', 'duration' => 260.0, 'pcm_sha1' => str_repeat('c', 40)]),
        Track::make(['id' => 5, 'title' => 'Alone', 'artist' => 'Band', 'path' => 'e/5.mp3', 'duration' => 100.0, 'pcm_sha1' => null]),
    ]);

    $copies = FileTruth::copies($tracks);

    expect($copies[1])->toHaveCount(2)
        ->and(collect($copies[1])->firstWhere('id', 2)['exact'])->toBeTrue()
        ->and(collect($copies[1])->firstWhere('id', 3)['exact'])->toBeFalse()
        ->and(array_column($copies[3], 'id'))->toBe([1])   // a different length (260 s) is another recording
        ->and($copies)->not->toHaveKeys([4, 5]);

    $t = analysed([], ['id' => 1]);
    expect(codes($t, $copies[1]))->toBe(['duplicate', 'same_recording']);
});
