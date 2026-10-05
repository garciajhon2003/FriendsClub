<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\SocialGraph\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\SocialNetwork\Profiles\Actions\ResolveDisplayNames;
use Liberu\SocialNetwork\SocialGraph\Actions\AddProfileToList;
use Liberu\SocialNetwork\SocialGraph\Actions\CreateList;
use Liberu\SocialNetwork\SocialGraph\Actions\RemoveProfileFromList;
use Liberu\SocialNetwork\SocialGraph\Filament\Pages\Concerns\InteractsWithGraph;
use Liberu\SocialNetwork\SocialGraph\Models\GraphList;

/**
 * "My lists": group people (e.g. "Hiking buddies"). Every write goes through
 * the list actions of social-network-social-graph.
 */
final class Lists extends Page implements HasTable
{
    use InteractsWithGraph;
    use InteractsWithTable;

    /** @var array<string, string> */
    private const array VISIBILITIES = ['public' => 'Everyone', 'followers' => 'Followers', 'private' => 'Only me'];

    protected string $view = 'social-network-social-graph-filament::pages.lists';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'My lists';

    protected static ?string $navigationLabel = 'Lists';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => GraphList::query()
                ->withCount('profiles')
                ->where('owner_profile_id', $this->viewer()->getKey()))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('members')
                    ->label('People')
                    ->badge()
                    ->color('gray')
                    ->state(fn (GraphList $record): array => array_values($this->memberNames($record)))
                    ->limitList(4)
                    ->expandableLimitedList()
                    ->placeholder('Nobody yet'),
                TextColumn::make('visibility')
                    ->label('Who can see it')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => self::VISIBILITIES[$state] ?? $state),
                TextColumn::make('profiles_count')
                    ->label('Size')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('addPeople')
                    ->label('Add people')
                    ->icon('heroicon-o-user-plus')
                    ->schema(fn (GraphList $record): array => [
                        $this->personPicker('profiles', 'People', multiple: true, except: fn (): array => array_keys($this->memberNames($record))),
                    ])
                    ->action(fn (GraphList $record, array $data) => $this->attempt(function () use ($record, $data): void {
                        foreach ((array) $data['profiles'] as $profileId) {
                            app(AddProfileToList::class)->handle($this->viewer(), $record, $this->profile((string) $profileId));
                        }
                    }, 'Added')),
                Action::make('removePerson')
                    ->label('Remove someone')
                    ->icon('heroicon-o-user-minus')
                    ->color('gray')
                    ->visible(fn (GraphList $record): bool => $record->profiles_count > 0)
                    ->schema([
                        Select::make('profile')
                            ->label('Person')
                            ->required()
                            ->options(fn (GraphList $record): array => $this->memberNames($record)),
                    ])
                    ->action(fn (GraphList $record, array $data) => $this->attempt(
                        fn () => app(RemoveProfileFromList::class)->handle($this->viewer(), $record, $this->profile($data['profile'])),
                        'Removed',
                    )),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No lists yet')
            ->emptyStateDescription('Lists help you keep track of groups of people, like your hiking buddies.')
            ->emptyStateIcon('heroicon-o-queue-list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->model(GraphList::class)
                ->label('New list')
                ->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('name')->required()->maxLength(80),
                    Select::make('visibility')
                        ->label('Who can see it')
                        ->options(self::VISIBILITIES)
                        ->default('private')
                        ->required(),
                    $this->personPicker('profiles', 'People', multiple: true)->required(false),
                ])
                ->using(fn (array $data): GraphList => $this->attempt(function () use ($data): GraphList {
                    $list = app(CreateList::class)->handle($this->viewer(), ['name' => $data['name'], 'visibility' => $data['visibility']]);

                    foreach ((array) ($data['profiles'] ?? []) as $profileId) {
                        app(AddProfileToList::class)->handle($this->viewer(), $list, $this->profile((string) $profileId));
                    }

                    return $list;
                })),
        ];
    }

    /** @return array<string, string> profile id => name */
    private function memberNames(GraphList $list): array
    {
        return app(ResolveDisplayNames::class)->handle(
            $list->profiles()->pluck('social_profiles.id')->map(fn (mixed $id): string => (string) $id)->all(),
        );
    }
}
