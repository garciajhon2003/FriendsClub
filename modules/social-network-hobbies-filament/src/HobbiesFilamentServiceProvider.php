<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Filament;

use Illuminate\Support\ServiceProvider;
use Liberu\SocialNetwork\Communities\Filament\Contracts\CommunitiesPageExtension;
use Liberu\SocialNetwork\Events\Filament\Contracts\EventsPageExtension;
use Liberu\SocialNetwork\Hobbies\Filament\Extensions\HobbyCommunitiesExtension;
use Liberu\SocialNetwork\Hobbies\Filament\Extensions\HobbyEventsExtension;

final class HobbiesFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The Communities and Events pages show, filter and ask for the hobby.
        $this->app->tag([HobbyCommunitiesExtension::class], CommunitiesPageExtension::TAG);
        $this->app->tag([HobbyEventsExtension::class], EventsPageExtension::TAG);
    }
}
