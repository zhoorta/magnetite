<?php

use App\Support\SearchQuery;

it('takes plain words and folds them', function () {
    $q = SearchQuery::parse('Jáfumega  RECADOS recados');

    expect($q->words)->toBe(['jafumega', 'recados'])->and($q->year)->toBeNull()->and($q->tracksOnly())->toBeFalse();
});

it('reads the filters', function () {
    $q = SearchQuery::parse('bowie year:1960..1969 dr:>=12 codec:FLAC,alac artist:"david bowie" album:heroes');

    expect($q->words)->toBe(['bowie'])->and($q->year)->toBe([1960, 1969])->and($q->dr)->toBe([12, null])
        ->and($q->codecs)->toBe(['flac', 'alac'])->and($q->artist)->toBe(['david', 'bowie'])->and($q->album)->toBe(['heroes'])
        ->and($q->tracksOnly())->toBeTrue();
});

it('reads every way to write a range', function (string $text, ?array $range) {
    expect(SearchQuery::parse("year:$text x")->year)->toBe($range);
})->with([
    ['1983', [1983, 1983]], ['1960..1969', [1960, 1969]], ['..1969', [null, 1969]], ['1990..', [1990, null]],
    ['>1990', [1991, null]], ['>=1990', [1990, null]], ['<1990', [null, 1989]], ['<=1990', [null, 1990]],
    ['soon', null], ['..', null], ['', null],
]);

it('keeps what is not a filter as words', function () {
    expect(SearchQuery::parse('ac:dc year:soon "two words"')->words)->toBe(['ac:dc', 'year:soon', 'two', 'words']);
});

it('knows when there is nothing to search for', function () {
    expect(SearchQuery::parse('   ')->isEmpty())->toBeTrue()->and(SearchQuery::parse('year:1999')->isEmpty())->toBeFalse()->and(SearchQuery::parse('x')->isEmpty())->toBeFalse();
});
