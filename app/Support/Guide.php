<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The guide for people who install and use Magnetite: Markdown files in `docs/guide`, shown at `/docs`.
 * Only the pages listed here can be opened, so no path ever comes from the request. The files are written
 * to read well on GitHub too: a link to another page is `page.md` there and `/docs/page` here.
 */
class Guide
{
    /** slug => title, in reading order. The file is `docs/guide/{slug}.md`; the overview is `README.md`. */
    public const PAGES = [
        'install' => 'Install',
        'first-run' => 'First run',
        'library' => 'The library',
        'meters' => 'Meters and posters',
        'file-analysis' => 'File analysis',
        'themes' => 'Themes',
        'sharing' => 'Sharing and playlists',
        'clients' => 'Clients',
        'configuration' => 'Configuration',
        'troubleshooting' => 'Troubleshooting',
    ];

    public static function path(?string $slug): string
    {
        return base_path('docs/guide/'.($slug ?? 'README').'.md');
    }

    /** The page as HTML, or null when there is no such page. `null` is the overview. */
    public static function html(?string $slug): ?string
    {
        if ($slug !== null && ! isset(self::PAGES[$slug])) {
            return null;
        }

        $text = @file_get_contents(self::path($slug));
        if ($text === false) {
            return null;
        }

        // Raw HTML in a page is shown as text, and only ordinary links are kept.
        $html = Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);

        // `page.md` and `page.md#part` become this site's addresses; `README.md` is the overview.
        return preg_replace_callback('/href="(README|[a-z-]+)\.md(#[^"]*)?"/', function (array $m) {
            $to = $m[1] === 'README' ? route('guide') : route('guide', $m[1]);

            return 'href="'.e($to.($m[2] ?? '')).'"';
        }, $html);
    }

    /** The pages before and after `$slug` in reading order: `[previous, next]`, each `[slug, title]` or null. */
    public static function neighbours(?string $slug): array
    {
        $slugs = array_keys(self::PAGES);
        $at = $slug === null ? -1 : array_search($slug, $slugs, true);
        $entry = fn (int $i) => isset($slugs[$i]) ? [$slugs[$i], self::PAGES[$slugs[$i]]] : null;

        return [$at > 0 ? $entry($at - 1) : null, $entry($at + 1)];
    }
}
