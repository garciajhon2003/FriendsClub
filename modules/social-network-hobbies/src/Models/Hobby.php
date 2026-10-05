<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * A hobby of the catalog. Profiles practise it at a level, and communities and
 * events are about one.
 */
final class Hobby extends Model
{
    protected $table = 'hobbies';

    protected $fillable = ['name', 'slug', 'icon', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** Active hobbies, in catalog order. */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order');
    }

    /** @return BelongsToMany<Profile, $this> */
    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'hobby_profile', 'hobby_id', 'profile_id')
            ->withPivot('level')
            ->withTimestamps();
    }

    /** @return HasMany<Community, $this> */
    public function communities(): HasMany
    {
        return $this->hasMany(Community::class, 'hobby_id');
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'hobby_id');
    }

    /** A hobby with communities or events cannot be deleted: their foreign keys restrict it. */
    public function isInUse(): bool
    {
        return $this->communities()->exists() || $this->events()->exists();
    }

    protected static function booted(): void
    {
        self::saving(function (Hobby $hobby): void {
            if (blank($hobby->slug)) {
                $hobby->slug = Str::slug($hobby->name);
            }
        });
    }
}
