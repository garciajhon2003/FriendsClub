<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Media\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use InvalidArgumentException;
use Liberu\SocialNetwork\Media\Actions\DeleteMediaAsset;
use Liberu\SocialNetwork\Media\Actions\MarkMediaReady;
use Liberu\SocialNetwork\Media\Actions\RegisterMediaAsset;
use Liberu\SocialNetwork\Media\Actions\UpdateMediaAsset;
use Liberu\SocialNetwork\Media\Models\Album;
use Liberu\SocialNetwork\Media\Models\MediaAsset;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "My media": upload photos, videos, audio and files, organise them in albums
 * and describe them. Every write goes through the actions of social-network-media.
 */
final class Assets extends Page implements HasTable
{
    use InteractsWithTable;

    /** Disk uploads are stored on; must be one of filesystems.disks. */
    private const string DISK = 'public';

    /** Largest upload, in kilobytes. */
    private const int MAX_UPLOAD_KB = 51200;

    /** @var array<string, array{label: string, icon: string}> */
    private const array TYPES = [
        'image' => ['label' => 'Photo', 'icon' => 'heroicon-o-photo'],
        'video' => ['label' => 'Video', 'icon' => 'heroicon-o-film'],
        'audio' => ['label' => 'Audio', 'icon' => 'heroicon-o-musical-note'],
        'file' => ['label' => 'File', 'icon' => 'heroicon-o-document'],
    ];

    protected string $view = 'social-network-media-filament::pages.assets';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'My media';

    protected static ?string $navigationLabel = 'Media';

    /** Table filters, read from the URL so the albums page can link to one album. */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    private ?Profile $viewer = null;

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => MediaAsset::query()->with('album')->where('owner_profile_id', $this->viewer()->getKey()))
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->state(fn (MediaAsset $record): ?string => $record->type === 'image' ? $this->urlOf($record) : null)
                    ->square()
                    ->imageSize(56)
                    ->checkFileExistence(false),
                TextColumn::make('name')
                    ->label('File')
                    ->state(fn (MediaAsset $record): string => basename((string) $record->path))
                    ->description(fn (MediaAsset $record): ?string => $record->alt_text)
                    ->icon(fn (MediaAsset $record): string => self::TYPES[$record->type]['icon'] ?? 'heroicon-o-document')
                    ->searchable(['path', 'alt_text', 'captions'])
                    ->wrap(),
                TextColumn::make('album.name')
                    ->label('Album')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('size')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Number::fileSize($state, precision: 1))
                    ->sortable(),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'ready' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(self::TYPES)->map(fn (array $type): string => $type['label'])->all()),
                SelectFilter::make('album_id')
                    ->label('Album')
                    ->options(fn (): array => $this->albumOptions()),
                SelectFilter::make('state')
                    ->options(['pending' => 'Pending', 'ready' => 'Ready', 'failed' => 'Failed']),
            ])
            ->recordActions([
                Action::make('open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (MediaAsset $record): ?string => $this->urlOf($record), shouldOpenInNewTab: true),
                ActionGroup::make([
                    EditAction::make()
                        ->label('Edit details')
                        ->fillForm(fn (MediaAsset $record): array => $record->only(['album_id', 'alt_text', 'captions']))
                        ->schema([
                            Select::make('album_id')
                                ->label('Album')
                                ->options(fn (): array => $this->albumOptions())
                                ->placeholder('No album'),
                            TextInput::make('alt_text')
                                ->label('Description (alt text)')
                                ->helperText('Describes the image for people who use screen readers.')
                                ->maxLength((int) config('social-network-media.max_alt_text_length', 1000)),
                            Textarea::make('captions')
                                ->rows(3),
                        ])
                        ->using(fn (MediaAsset $record, array $data): MediaAsset => $this->attempt(
                            fn () => app(UpdateMediaAsset::class)->handle($this->viewer(), $record, [
                                'album_id' => $data['album_id'] ?? null,
                                'alt_text' => filled($data['alt_text'] ?? null) ? $data['alt_text'] : null,
                                'captions' => filled($data['captions'] ?? null) ? $data['captions'] : null,
                            ]),
                        )),
                    Action::make('markReady')
                        ->label('Check file')
                        ->icon('heroicon-o-check-badge')
                        ->visible(fn (MediaAsset $record): bool => $record->state !== 'ready')
                        ->action(fn (MediaAsset $record) => $this->attempt(
                            fn () => app(MarkMediaReady::class)->handle($this->viewer(), $record),
                            'The file is ready',
                        )),
                    Action::make('delete')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('The file is deleted from storage too.')
                        ->action(fn (MediaAsset $record) => $this->attempt(
                            fn () => app(DeleteMediaAsset::class)->handle($this->viewer(), $record),
                            'Deleted',
                        )),
                ])
                    ->label('More')
                    ->icon('heroicon-o-ellipsis-vertical'),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No media yet')
            ->emptyStateDescription('Upload photos, videos or files with the “Upload” button.')
            ->emptyStateIcon('heroicon-o-photo')
            ->paginated([12, 24, 48])
            ->defaultPaginationPageOption(12);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    FileUpload::make('files')
                        ->label('Files')
                        ->multiple()
                        ->required()
                        ->disk(self::DISK)
                        ->directory(fn (): string => 'media/'.$this->viewer()->getKey())
                        ->maxSize(self::MAX_UPLOAD_KB)
                        ->maxFiles(20),
                    Select::make('album_id')
                        ->label('Album')
                        ->options(fn (): array => $this->albumOptions())
                        ->placeholder('No album'),
                    TextInput::make('alt_text')
                        ->label('Description (alt text)')
                        ->helperText('Applied to every file in this upload. You can change it per file later.')
                        ->maxLength((int) config('social-network-media.max_alt_text_length', 1000)),
                ])
                ->action(fn (array $data) => $this->attempt(function () use ($data): void {
                    $count = 0;
                    foreach ((array) $data['files'] as $path) {
                        $asset = app(RegisterMediaAsset::class)->handle($this->viewer(), [
                            'type' => $this->typeOf((string) $path),
                            'disk' => self::DISK,
                            'path' => (string) $path,
                            'album_id' => $data['album_id'] ?? null,
                            'alt_text' => filled($data['alt_text'] ?? null) ? $data['alt_text'] : null,
                        ]);
                        app(MarkMediaReady::class)->handle($this->viewer(), $asset);
                        $count++;
                    }

                    Notification::make()->title($count === 1 ? 'File uploaded' : "{$count} files uploaded")->success()->send();
                })),
            Action::make('albums')
                ->label('Albums')
                ->icon('heroicon-o-rectangle-stack')
                ->color('gray')
                ->url(fn (): string => Albums::getUrl()),
        ];
    }

    /** image/png → image, video/mp4 → video, audio/* → audio, anything else → file. */
    private function typeOf(string $path): string
    {
        $mime = (string) Storage::disk(self::DISK)->mimeType($path);
        $family = strtok($mime, '/');

        return in_array($family, ['image', 'video', 'audio'], true) ? $family : 'file';
    }

    private function urlOf(MediaAsset $asset): ?string
    {
        try {
            return Storage::disk($asset->disk)->url($asset->path);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, string> */
    private function albumOptions(): array
    {
        return Album::query()
            ->where('owner_profile_id', $this->viewer()->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Runs module actions in one transaction and turns their refusals into a
     * notification instead of an error page.
     */
    private function attempt(Closure $callback, ?string $success = null): mixed
    {
        try {
            $result = DB::transaction($callback);
        } catch (InvalidArgumentException|AuthorizationException|HttpException $exception) {
            Notification::make()
                ->title($exception instanceof InvalidArgumentException ? $exception->getMessage() : ($exception instanceof HttpException && $exception->getMessage() !== '' ? $exception->getMessage() : 'You are not allowed to do that.'))
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
