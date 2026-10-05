<?php

namespace App\Providers;

use App\Filament\Extensions\PublicationFeedPresenter;
use App\Models\User;
use Illuminate\Support\ServiceProvider;
use Liberu\SocialNetwork\Feed\Filament\Contracts\FeedItemPresenter;

class FriendsClubServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // El feed muestra las publicaciones y permite reaccionar y comentar
        $this->app->tag([PublicationFeedPresenter::class], FeedItemPresenter::TAG);
    }

    public function boot(): void
    {
        // Los perfiles muestran el nombre del usuario en lugar de su handle
        if (blank(config('social-network-profiles.user_model'))) {
            config(['social-network-profiles.user_model' => User::class]);
        }
    }
}
