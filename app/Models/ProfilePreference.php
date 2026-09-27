<?php

namespace App\Models;

use App\Enums\ClubModality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Liberu\SocialNetwork\Profiles\Models\Profile;

class ProfilePreference extends Model
{
    protected $fillable = [
        'modality',
        'max_distance_km',
        'available_days',
        'time_slots',
        'languages',
    ];

    protected function casts(): array
    {
        return [
            'modality' => ClubModality::class,
            'max_distance_km' => 'integer',
            'available_days' => 'array',
            'time_slots' => 'array',
            'languages' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id', 'id');
    }
}
