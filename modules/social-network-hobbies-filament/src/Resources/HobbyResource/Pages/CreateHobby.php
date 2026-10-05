<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource;

final class CreateHobby extends CreateRecord
{
    protected static string $resource = HobbyResource::class;
}
