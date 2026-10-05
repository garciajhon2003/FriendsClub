<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies;

use Illuminate\Support\ServiceProvider;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;
use Liberu\SocialNetwork\Hobbies\Models\ProfilePreference;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Hobbies depend on profiles, communities and events, never the other way
 * round, so the relations pointing at a hobby are attached from here.
 */
final class HobbiesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Profile::resolveRelationUsing('hobbies', fn (Profile $profile) => $profile
            ->belongsToMany(Hobby::class, 'hobby_profile', 'profile_id', 'hobby_id', relation: 'hobbies')
            ->withPivot('level')
            ->withTimestamps());

        Profile::resolveRelationUsing('preferences', fn (Profile $profile) => $profile
            ->hasOne(ProfilePreference::class, 'profile_id'));

        Community::resolveRelationUsing('hobby', fn (Community $community) => $community
            ->belongsTo(Hobby::class, 'hobby_id', relation: 'hobby'));

        Event::resolveRelationUsing('hobby', fn (Event $event) => $event
            ->belongsTo(Hobby::class, 'hobby_id', relation: 'hobby'));
    }
}
