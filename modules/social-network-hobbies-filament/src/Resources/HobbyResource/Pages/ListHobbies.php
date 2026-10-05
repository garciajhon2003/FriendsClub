<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource;

final class ListHobbies extends ListRecords
{
    protected static string $resource = HobbyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
