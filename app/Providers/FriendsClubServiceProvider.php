<?php

namespace App\Providers;

use App\Models\Hobby;
use Illuminate\Support\ServiceProvider;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Profiles\Models\Profile;

class FriendsClubServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Profile::resolveRelationUsing('hobbies', function (Profile $profile) {
            return $profile->belongsToMany(Hobby::class, 'hobby_profile', 'profile_id', 'hobby_id', relation: 'hobbies')
                ->withPivot('level')
                ->withTimestamps();
        });

        Community::resolveRelationUsing('hobby', function (Community $community) {
            return $community->belongsTo(Hobby::class, 'hobby_id', 'id', 'hobby');
        });

        Event::resolveRelationUsing('hobby', function (Event $event) {
            return $event->belongsTo(Hobby::class, 'hobby_id', 'id', 'hobby');
        });
    }
}
