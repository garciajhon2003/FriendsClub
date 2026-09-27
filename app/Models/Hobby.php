<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Profiles\Models\Profile;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Hobby extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'hobby_profile')
            ->withPivot('level')
            ->withTimestamps();
    }

    public function communities(): HasMany
    {
        return $this->hasMany(Community::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
