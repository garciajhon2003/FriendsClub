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
use Liberu\SocialNetwork\SocialGraph\Actions\BlockProfile;
use Liberu\SocialNetwork\SocialGraph\Actions\ListOwnedBlocks;
use Liberu\SocialNetwork\SocialGraph\Actions\UnblockProfile;
use Liberu\SocialNetwork\SocialGraph\Filament\Pages\Concerns\InteractsWithGraph;
use Liberu\SocialNetwork\SocialGraph\Models\Block;

/**
 * "Blocked people": block and unblock profiles through the actions of
 * social-network-social-graph.
 */
final class Blocks extends Page implements HasTable
{
    use InteractsWithGraph;
    use InteractsWithTable;

    protected string $view = 'social-network-social-graph-filament::pages.blocks';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-no-symbol';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'Blocked people';

    protected static ?string $navigationLabel = 'Blocks';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->myBlocks())
            ->columns([
                TextColumn::make('person')
                    ->state(fn (Block $record): string => $this->nameOf((string) $record->target_profile_id))
                    ->weight('bold'),
                TextColumn::make('created_at')
                    ->label('Blocked')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('unblock')
                    ->icon('heroicon-o-lock-open')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('They will be able to find and follow you again. Earlier follows and friendships are not restored.')
                    ->action(fn (Block $record) => $this->attempt(
                        fn () => app(UnblockProfile::class)->handle($this->viewer(), $this->profile((string) $record->target_profile_id)),
                        'Unblocked',
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('You have not blocked anyone')
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('block')
                ->label('Block someone')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->modalDescription('They will stop following you and any friendship or request between you is removed.')
                ->schema([$this->personPicker(except: fn (): array => $this->myBlocks()->pluck('target_profile_id')->map(fn (mixed $id): string => (string) $id)->all())])
                ->action(fn (array $data) => $this->attempt(
                    fn () => app(BlockProfile::class)->handle($this->viewer(), $this->profile($data['profile'])),
                    'Blocked',
                )),
        ];
    }

    /** ListOwnedBlocks' rule (blocks I made) as a query the table can paginate. */
    private function myBlocks(): Builder
    {
        app(ListOwnedBlocks::class)->handle($this->viewer());

        return Block::query()->where('source_profile_id', $this->viewer()->getKey());
    }
}
