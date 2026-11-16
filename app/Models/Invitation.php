<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** A single-use link that lets one person create their own account. Only the hash of the token is stored. */
class Invitation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** Make an invitation; returns it with the plain token, which exists nowhere else. */
    public static function issue(User $by, ?string $label, int $days): array
    {
        $token = Str::random(40);

        $invitation = static::create([
            'token_hash' => static::hash($token),
            'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
            'created_by' => $by->id,
            'expires_at' => now()->addDays($days),
        ]);

        return [$invitation, $token];
    }

    /** A password reset link for an existing user (shown once, short-lived). */
    public static function issueReset(User $by, User $target, int $hours = 24): array
    {
        $token = Str::random(40);

        $reset = static::create([
            'token_hash' => static::hash($token),
            'label' => null,
            'user_id' => $target->id,
            'created_by' => $by->id,
            'expires_at' => now()->addHours($hours),
        ]);

        return [$reset, $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The usable invitation (or, with $reset, password reset) behind a token. The two kinds never answer each other's links. */
    public static function findUsable(string $token, bool $reset = false): ?self
    {
        return static::usable()->kind($reset)->where('token_hash', static::hash($token))->first();
    }

    public function scopeKind(Builder $query, bool $reset): void
    {
        $reset ? $query->whereNotNull('user_id') : $query->whereNull('user_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
