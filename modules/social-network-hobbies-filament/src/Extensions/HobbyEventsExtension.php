<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Filament\Extensions;

use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Liberu\SocialNetwork\Events\Filament\Contracts\EventsPageExtension;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;

/**
 * The hobby of each event on the Events page: column, filter, an optional
 * field when creating or editing one, and an entry in its details.
 */
final class HobbyEventsExtension implements EventsPageExtension
{
    public function eagerLoad(): array
    {
        return ['hobby'];
    }

    public function columns(): array
    {
        return [
            TextColumn::make('hobby.name')
                ->label('Hobby')
                ->badge()
                ->placeholder('—'),
        ];
    }

    public function filters(): array
    {
        return [
            SelectFilter::make('hobby_id')
                ->label('Hobby')
                ->options(fn (): array => Hobby::query()->active()->pluck('name', 'id')->all()),
        ];
    }

    public function formComponents(): array
    {
        return [
            Select::make('hobby_id')
                ->label('Hobby')
                ->options(fn (): array => Hobby::query()->active()->pluck('name', 'id')->all())
                ->searchable()
                ->columnSpanFull(),
        ];
    }

    public function detailEntries(): array
    {
        return [
            TextEntry::make('hobby.name')->label('Hobby')->badge()->placeholder('—'),
        ];
    }

    public function fillForm(Event $event): array
    {
        return ['hobby_id' => $event->getAttribute('hobby_id')];
    }

    public function saved(Event $event, array $data): void
    {
        $event->forceFill(['hobby_id' => $data['hobby_id'] ?? null])->save();
    }
}
