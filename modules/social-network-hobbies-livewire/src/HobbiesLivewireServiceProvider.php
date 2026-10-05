<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Livewire;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

final class HobbiesLivewireServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/social-network-hobbies-livewire.php', 'social-network-hobbies-livewire');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'social-network-hobbies-livewire');
        Livewire::component('social-network-hobbies-explore', Components\Explore::class);
    }
}
