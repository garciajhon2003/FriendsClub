<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\SocialNetwork\Communities\Enums\CommunityModality;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * What a profile looks for when matched with communities: modality, how far it
 * will travel, which days and time slots suit it, and its languages.
 */
final class ProfilePreference extends Model
{
    protected $table = 'profile_preferences';

    protected $fillable = ['modality', 'max_distance_km', 'available_days', 'time_slots', 'languages'];

    protected function casts(): array
    {
        return [
            'modality' => CommunityModality::class,
            'max_distance_km' => 'integer',
            'available_days' => 'array',
            'time_slots' => 'array',
            'languages' => 'array',
        ];
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }
}
