<?php

namespace App\Providers;

use App\Livewire\ExploreClubs;
use App\Models\Hobby;
use App\Models\ProfilePreference;
use Illuminate\Support\ServiceProvider;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Communities\Models\Membership;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Livewire\Livewire;

class FriendsClubServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Perfil → hobbies (muchos a muchos, con nivel)
        Profile::resolveRelationUsing('hobbies', function (Profile $profile) {
            return $profile->belongsToMany(Hobby::class, 'hobby_profile', 'profile_id', 'hobby_id', relation: 'hobbies')
                ->withPivot('level')
                ->withTimestamps();
        });

        // Perfil → preferencias (uno a uno)
        Profile::resolveRelationUsing('preferences', function (Profile $profile) {
            return $profile->hasOne(ProfilePreference::class, 'profile_id', 'id');
        });

        // Club → hobby
        Community::resolveRelationUsing('hobby', function (Community $community) {
            return $community->belongsTo(Hobby::class, 'hobby_id', 'id', 'hobby');
        });

        // Club → miembros
        Community::resolveRelationUsing('memberships', function (Community $community) {
            return $community->hasMany(Membership::class, 'community_id', 'id');
        });

        // Evento → hobby
        Event::resolveRelationUsing('hobby', function (Event $event) {
            return $event->belongsTo(Hobby::class, 'hobby_id', 'id', 'hobby');
        });

        // Componentes Livewire de FriendsClub
        Livewire::component('explore-clubs', ExploreClubs::class);
    }
}
