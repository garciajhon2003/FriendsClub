<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Media\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Liberu\SocialNetwork\Media\Actions\CreateAlbum;
use Liberu\SocialNetwork\Media\Actions\DeleteAlbum;
use Liberu\SocialNetwork\Media\Actions\UpdateAlbum;
use Liberu\SocialNetwork\Media\Models\Album;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "My albums": group media and choose who can see each album. Every write goes
 * through the album actions of social-network-media.
 */
final class Albums extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var array<string, string> */
    private const array PRIVACY = ['public' => 'Everyone', 'friends_only' => 'Friends only', 'private' => 'Only me'];

    protected string $view = 'social-network-media-filament::pages.albums';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'My albums';

    protected static ?string $navigationLabel = 'Albums';

    private ?Profile $viewer = null;

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Album::query()
                ->withCount('media')
                ->where('owner_profile_id', $this->viewer()->getKey()))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Album $record): ?string => $record->description === null ? null : str($record->description)->limit(90)->toString()),
                TextColumn::make('media_count')
                    ->label('Items')
                    ->sortable(),
                TextColumn::make('privacy')
                    ->label('Who can see it')
                    ->badge()
                    ->icon(fn (string $state): string => match ($state) {
                        'public' => 'heroicon-m-globe-alt',
                        'friends_only' => 'heroicon-m-users',
                        default => 'heroicon-m-lock-closed',
                    })
                    ->color(fn (string $state): string => $state === 'public' ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => self::PRIVACY[$state] ?? $state),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('privacy')->options(self::PRIVACY),
            ])
            ->recordActions([
                Action::make('openMedia')
                    ->label('View media')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->url(fn (Album $record): string => Assets::getUrl(['filters' => ['album_id' => ['value' => (string) $record->getKey()]]])),
                EditAction::make()
                    ->schema(fn (Schema $schema): Schema => $schema->components($this->formComponents()))
                    ->fillForm(fn (Album $record): array => $record->only(['name', 'description', 'privacy']))
                    ->using(fn (Album $record, array $data): Album => $this->attempt(
                        fn () => app(UpdateAlbum::class)->handle($this->viewer(), $record, $this->attributes($data)),
                    )),
                Action::make('delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Its media is kept and just leaves the album.')
                    ->action(fn (Album $record) => $this->attempt(
                        fn () => app(DeleteAlbum::class)->handle($this->viewer(), $record),
                        'Album deleted',
                    )),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No albums yet')
            ->emptyStateDescription('Create one to group your photos and videos.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->model(Album::class)
                ->label('New album')
                ->icon('heroicon-o-plus')
                ->schema(fn (Schema $schema): Schema => $schema->components($this->formComponents()))
                ->using(fn (array $data): Album => $this->attempt(
                    fn () => app(CreateAlbum::class)->handle($this->viewer(), $this->attributes($data)),
                )),
            Action::make('media')
                ->label('My media')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->url(fn (): string => Assets::getUrl()),
        ];
    }

    /** @return list<mixed> */
    private function formComponents(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255),
            Textarea::make('description')->rows(3),
            Select::make('privacy')
                ->label('Who can see it')
                ->options(self::PRIVACY)
                ->default('private')
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => filled($data['description'] ?? null) ? $data['description'] : null,
            'privacy' => $data['privacy'] ?? 'private',
        ];
    }

    /**
     * Runs a module action and turns its refusals into a notification instead
     * of an error page.
     */
    private function attempt(Closure $callback, ?string $success = null): mixed
    {
        try {
            $result = $callback();
        } catch (InvalidArgumentException|AuthorizationException|HttpException $exception) {
            Notification::make()
                ->title($exception instanceof InvalidArgumentException ? $exception->getMessage() : 'You are not allowed to do that.')
                ->danger()
                ->send();

            throw new Halt();
        }

        if ($success !== null) {
            Notification::make()->title($success)->success()->send();
        }

        return $result;
    }
}
