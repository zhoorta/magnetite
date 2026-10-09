<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;

/** Folders are private to their owner; anyone else gets a 404. */
class FolderPolicy
{
    public function update(User $user, Folder $folder): bool
    {
        return $folder->user_id === $user->id;
    }

    public function delete(User $user, Folder $folder): bool
    {
        return $folder->user_id === $user->id;
    }
}
