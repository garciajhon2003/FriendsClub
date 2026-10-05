<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Hobbies\Filament\Resources;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource\Pages\CreateHobby;
use Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource\Pages\EditHobby;
use Liberu\SocialNetwork\Hobbies\Filament\Resources\HobbyResource\Pages\ListHobbies;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;

/**
 * The hobby catalog, for administrators. A hobby that communities or events
 * use cannot be deleted; deactivate it instead.
 */
final class HobbyResource extends Resource
{
    protected static ?string $model = Hobby::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $recordTitleAttribute = 'name';

    /** The catalog is global: it belongs to no team. */
    public static function isScopedToTenant(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(80),
                TextInput::make('slug')
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->helperText('Leave blank to generate it from the name.'),
                TextInput::make('icon')
                    ->maxLength(255)
                    ->helperText('Path of the illustration, e.g. hobbies/reposteria.png'),
                TextInput::make('sort_order')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->toggleable(),
                TextColumn::make('communities_count')
                    ->counts('communities')
                    ->label(__('Communities')),
                TextColumn::make('profiles_count')
                    ->counts('profiles')
                    ->label('People'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn (Hobby $record): bool => $record->isInUse()),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHobbies::route('/'),
            'create' => CreateHobby::route('/create'),
            'edit' => EditHobby::route('/{record}/edit'),
        ];
    }
}
