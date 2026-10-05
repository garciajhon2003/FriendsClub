<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\SocialGraph\Filament\Pages;

use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\SocialGraph\Actions\BlockProfile;
use Liberu\SocialNetwork\SocialGraph\Actions\CreateRelationship;
use Liberu\SocialNetwork\SocialGraph\Actions\SuggestProfiles;
use Liberu\SocialNetwork\SocialGraph\Filament\Pages\Concerns\InteractsWithGraph;

/**
 * "People you may know": SuggestProfiles' picks, with follow, friend and
 * block one click away.
 */
final class Suggestions extends Page implements HasTable
{
    use InteractsWithGraph;
    use InteractsWithTable;

    protected string $view = 'social-network-social-graph-filament::pages.suggestions';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'People you may know';

    protected static ?string $navigationLabel = 'Suggestions';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Profile::query()->whereKey($this->suggestedIds()))
            ->columns([
                TextColumn::make('name')
                    ->label('Person')
                    ->state(fn (Profile $record): string => $this->nameOf((string) $record->getKey()))
                    ->description(fn (Profile $record): string => '@'.$record->handle)
                    ->weight('bold'),
                TextColumn::make('bio')
                    ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : str($state)->limit(100)->toString())
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('follow')
                    ->icon('heroicon-o-user-plus')
                    ->button()
                    ->action(fn (Profile $record) => $this->attempt(
                        fn () => app(CreateRelationship::class)->follow($this->viewer(), $record),
                        'Following',
                    )),
                Action::make('befriend')
                    ->label('Add friend')
                    ->icon('heroicon-o-hand-raised')
                    ->color('gray')
                    ->action(fn (Profile $record) => $this->attempt(
                        fn () => app(CreateRelationship::class)->friend($this->viewer(), $record),
                        'Friend request sent',
                    )),
                Action::make('block')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->iconButton()
                    ->tooltip('Block')
                    ->requiresConfirmation()
                    ->action(fn (Profile $record) => $this->attempt(
                        fn () => app(BlockProfile::class)->handle($this->viewer(), $record),
                        'Blocked',
                    )),
            ])
            ->paginated(false)
            ->emptyStateHeading('No suggestions right now')
            ->emptyStateDescription('Come back later: new members show up here.')
            ->emptyStateIcon('heroicon-o-sparkles');
    }

    /** @return list<string> */
    private function suggestedIds(): array
    {
        return app(SuggestProfiles::class)
            ->for($this->viewer(), (int) config('social-network-social-graph.suggestion_limit', 10))
            ->modelKeys();
    }
}
