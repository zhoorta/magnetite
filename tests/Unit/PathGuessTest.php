<?php

use App\Models\Track;
use App\Support\PathGuess;
use App\Support\ReleaseMatch;

it('reads artist, album and year from a folder name', function (string $folder, ?string $artist, string $album, ?int $year) {
    expect(PathGuess::folder($folder))->toBe(['artist' => $artist, 'album' => $album, 'year' => $year]);
})->with([
    ['Daft Punk - Discovery (2001) [FLAC]', 'Daft Punk', 'Discovery', 2001],
    ['music/Daft Punk - Discovery', 'Daft Punk', 'Discovery', null],
    ['Daft Punk - 2001 - Discovery', 'Daft Punk', 'Discovery', 2001],
    ['Portishead/Dummy [24-96]', 'Portishead', 'Dummy', null],
    ['Pink_Floyd_-_The_Wall', 'Pink Floyd', 'The Wall', null],
    ['Dummy', null, 'Dummy', null],
]);

it('has nothing to say about the library root', function () {
    expect(PathGuess::folder('.')['album'])->toBe('');
});

it('reads number, disc and title from a file name', function (string $path, ?int $number, ?int $disc, string $title) {
    $f = PathGuess::file($path);
    expect([$f['number'], $f['disc'], $f['title']])->toBe([$number, $disc, $title]);
})->with([
    ['a/03 - Digital Love.flac', 3, null, 'Digital Love'],
    ['a/03. Digital Love.mp3', 3, null, 'Digital Love'],
    ['a/1-03 Digital Love.flac', 3, 1, 'Digital Love'],
    ['a/Digital_Love.flac', null, null, 'Digital Love'],
    ['a/1979.flac', null, null, '1979'],
]);

it('puts CD folders under their parent with the disc number', function () {
    expect(PathGuess::groupKey('Band - Live/CD2/01 x.flac'))->toBe(['Band - Live', 2])
        ->and(PathGuess::groupKey('Band - Live/01 x.flac'))->toBe(['Band - Live', null])
        ->and(PathGuess::groupKey('x.flac'))->toBe(['.', null]);
});

it('takes an artist prefix shared by all file names out of the titles and orders by disc and number', function () {
    $tracks = collect([
        new Track(['path' => 'Live/CD2/01 - Band - Two.flac']),
        new Track(['path' => 'Live/CD1/02 - Band - Three.flac']),
        new Track(['path' => 'Live/CD1/01 - Band - One.flac']),
    ])->each(fn ($t, $i) => $t->id = $i + 1);

    $g = PathGuess::group('Live', $tracks);

    expect($g['artist'])->toBe('Band')
        ->and(array_column($g['tracks'], 'title'))->toBe(['One', 'Three', 'Two'])
        ->and(array_column($g['tracks'], 'disc'))->toBe([1, 1, 2]);
});

it('matches files to a release by length in order', function () {
    $m = ReleaseMatch::compare([180.4, 200.0, null, 300.0], [181.0, 210.0, 100.0, 299.0]);

    expect($m['matched'])->toBe([true, false, false, true])
        ->and($m['count'])->toBe(2)
        ->and($m['score'])->toBe(0.5);
});

it('divides by the longer list when the counts differ', function () {
    expect(ReleaseMatch::compare([100.0, 200.0], [100.0, 200.0, 300.0, 400.0])['score'])->toBe(0.5);
});
