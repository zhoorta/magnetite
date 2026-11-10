<?php

namespace App\Support;

use App\Models\Album;
use App\Models\Folder;
use Illuminate\Support\Collection;

/**
 * One user's folders as the library page needs them: children and albums by parent (0 is the top level), a flat list in tree
 * order for the pickers (with the depth), and the number of albums in each folder including its subfolders.
 */
class FolderTree
{
    /**
     * @param  Collection<int, Collection<int, Folder>>  $childrenOf  folders by parent id
     * @param  Collection<int, Collection<int, Album>>  $albumsIn  albums by folder id
     * @param  list<array{id: int, parent_id: int|null, name: string, depth: int}>  $list
     * @param  array<int, int>  $albumCount  albums per folder id, subfolders included
     */
    private function __construct(
        public readonly Collection $childrenOf,
        public readonly Collection $albumsIn,
        public readonly array $list,
        public readonly array $albumCount,
    ) {}

    /**
     * @param  Collection<int, Folder>  $folders  the user's folders, in the order to show them
     * @param  Collection<int, Album>  $albums  the user's albums
     */
    public static function of(Collection $folders, Collection $albums): self
    {
        $childrenOf = $folders->groupBy(fn (Folder $f) => $f->parent_id ?? 0);
        $albumsIn = $albums->groupBy(fn (Album $a) => $a->folder_id ?? 0);
        $list = [];
        $albumCount = [];

        $walk = function (int $parent, int $depth) use (&$walk, $childrenOf, $albumsIn, &$list, &$albumCount): int {
            $total = 0;
            foreach ($childrenOf[$parent] ?? [] as $f) {
                $list[] = ['id' => $f->id, 'parent_id' => $f->parent_id, 'name' => $f->name, 'depth' => $depth];
                $albumCount[$f->id] = count($albumsIn[$f->id] ?? []) + $walk($f->id, $depth + 1);
                $total += $albumCount[$f->id];
            }

            return $total;
        };
        $walk(0, 1);

        return new self($childrenOf, $albumsIn, $list, $albumCount);
    }
}
