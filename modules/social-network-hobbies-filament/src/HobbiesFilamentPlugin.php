<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class HobbiesFilamentPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'module-social-network-hobbies-filament';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([Resources\HobbyResource::class]);
    }

    public function boot(Panel $panel): void {}
}
