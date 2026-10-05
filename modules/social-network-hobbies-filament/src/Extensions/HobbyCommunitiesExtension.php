<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Filament\Extensions;

use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Liberu\SocialNetwork\Communities\Filament\Contracts\CommunitiesPageExtension;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;

/**
 * The hobby of each community on the Communities page: column, filter, a
 * required field when creating one, and an entry in its details.
 */
final class HobbyCommunitiesExtension implements CommunitiesPageExtension
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
                ->required(),
        ];
    }

    public function detailEntries(): array
    {
        return [
            TextEntry::make('hobby.name')->label('Hobby')->badge()->placeholder('—'),
        ];
    }

    public function saved(Community $community, array $data): void
    {
        $community->forceFill(['hobby_id' => $data['hobby_id'] ?? null])->save();
    }
}
