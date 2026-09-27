<?php

use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\RegistrationServiceProvider;
use App\Providers\SocialNetworkAuthorizationServiceProvider;
use Liberu\Foundation\ModuleManager\ModuleManagerServiceProvider;
use App\Providers\FriendsClubServiceProvider;

return [
    ModuleManagerServiceProvider::class,
    RegistrationServiceProvider::class,
    SocialNetworkAuthorizationServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
    FriendsClubServiceProvider::class,
];
