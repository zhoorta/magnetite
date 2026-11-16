<?php

namespace App\Support;

/** The rules for a username and a password, shared by `magnetite:user` and the web set-up. */
class UserRules
{
    /** @return list<mixed> */
    public static function username(): array
    {
        return ['required', 'string', 'max:64', 'regex:/^[\p{L}\p{N}][\p{L}\p{N}._-]*$/u'];
    }

    /** @return list<mixed> */
    public static function password(): array
    {
        return ['required', 'string', 'min:8'];
    }

    public static function normalise(string $username): string
    {
        return mb_strtolower(trim($username));
    }
}
