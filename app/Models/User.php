<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Contracts\LibraryStore;
use App\Services\FerriteStore;
use App\Services\LocalStore;
use App\Support\TrackLibrary;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'store'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'meters' => 'array',
            'ui' => 'array',
            'store' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        // A default limit for new users (MAGNETITE_DEFAULT_QUOTA_GB); administrators and explicit values are left alone.
        static::creating(function (User $user) {
            $default = config('magnetite.default_quota_gb');

            if ($default !== null && $user->quota_mb === null && ! $user->is_admin) {
                $user->quota_mb = (int) round($default * 1024);
            }
        });
    }

    /** Sign every device out: API tokens (the apps) and, with the database driver, browser sessions (except one to keep). */
    public function revokeAccess(?string $keepSession = null): void
    {
        $this->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $this->id)
                ->when($keepSession, fn ($q) => $q->where('id', '!=', $keepSession))
                ->delete();
        }
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }

    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    /** Where this user's music files live. */
    public function store(): LibraryStore
    {
        $config = $this->store;

        if (($config['driver'] ?? null) === 'ferrite') {
            return new FerriteStore($this, $config['url'], $config['token']);
        }

        return new LocalStore($this);
    }

    /** Absolute path of this user's folder in the music folder (it may not exist yet). */
    public function libraryPath(): string
    {
        return TrackLibrary::userPath($this);
    }
}
