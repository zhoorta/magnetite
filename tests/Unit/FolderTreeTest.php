<?php

use App\Models\Album;
use App\Models\Folder;
use App\Support\FolderTree;

function folder(int $id, ?int $parent, string $name): Folder
{
    return (new Folder)->forceFill(['id' => $id, 'parent_id' => $parent, 'name' => $name]);
}

function albumIn(int $id, ?int $folder): Album
{
    return (new Album)->forceFill(['id' => $id, 'folder_id' => $folder]);
}

it('lists folders in tree order with their depth and counts albums including subfolders', function () {
    $tree = FolderTree::of(
        collect([folder(1, null, 'Rock'), folder(2, 1, 'Live'), folder(3, null, 'Jazz'), folder(4, 2, 'Bootlegs')]),
        collect([albumIn(10, 1), albumIn(11, 2), albumIn(12, 4), albumIn(13, null), albumIn(14, 3)]),
    );

    expect(collect($tree->list)->map(fn ($f) => $f['depth'].':'.$f['name'])->all())->toBe(['1:Rock', '2:Live', '3:Bootlegs', '1:Jazz'])
        ->and($tree->albumCount)->toBe([4 => 1, 2 => 2, 1 => 3, 3 => 1])
        ->and($tree->albumsIn[0]->pluck('id')->all())->toBe([13])
        ->and($tree->childrenOf[0]->pluck('name')->all())->toBe(['Rock', 'Jazz']);
});

it('is empty without folders', function () {
    $tree = FolderTree::of(collect(), collect([albumIn(1, null)]));

    expect($tree->list)->toBe([])->and($tree->albumCount)->toBe([])->and($tree->albumsIn[0])->toHaveCount(1);
});
