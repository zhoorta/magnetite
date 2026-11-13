<?php

use App\Support\Guide;
use Illuminate\Support\Str;

it('shows the overview and every page of the guide to anyone', function () {
    $this->get(route('guide'))->assertOk()->assertSee('Documentation');

    foreach (Guide::PAGES as $slug => $title) {
        expect(is_file(Guide::path($slug)))->toBeTrue("docs/guide/{$slug}.md is missing");

        $this->get(route('guide', $slug))->assertOk()->assertSee($title);
    }
});

it('answers 404 for a page that is not in the list, and never reads a path from the address', function () {
    $this->get('/docs/nothing-here')->assertNotFound();
    $this->get('/docs/README')->assertNotFound();
    $this->get('/docs/..%2Fdocker')->assertNotFound();
    $this->get('/docs/install.md')->assertNotFound();

    expect(Guide::html('../docker'))->toBeNull();
});

it('links between pages only to pages that exist, as addresses of this site', function () {
    foreach ([null, ...array_keys(Guide::PAGES)] as $slug) {
        $html = Guide::html($slug);

        expect($html)->not->toContain('.md"');

        preg_match_all('#href="'.preg_quote(url('/docs'), '#').'/?([^"\#]*)#', $html, $found);
        foreach ($found[1] as $target) {
            expect($target === '' || isset(Guide::PAGES[$target]))->toBeTrue("{$slug} links to a missing page: {$target}");
        }
    }
});

it('lists every page in the overview', function () {
    $overview = file_get_contents(Guide::path(null));

    foreach (array_keys(Guide::PAGES) as $slug) {
        expect($overview)->toContain("({$slug}.md)");
    }
});

it('shows raw HTML in a page as text', function () {
    expect(Str::markdown('<script>alert(1)</script>', ['html_input' => 'escape']))->not->toContain('<script>');
});

it('gives the pages before and after a page', function () {
    expect(Guide::neighbours(null))->toBe([null, ['install', 'Install']])
        ->and(Guide::neighbours('install'))->toBe([null, ['first-run', 'First run']])
        ->and(Guide::neighbours('troubleshooting')[1])->toBeNull();
});
