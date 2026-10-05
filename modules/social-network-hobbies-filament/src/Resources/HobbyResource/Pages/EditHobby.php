<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource;

final class EditHobby extends EditRecord
{
    protected static string $resource = HobbyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (): bool => $this->record->isInUse()),
        ];
    }
}
