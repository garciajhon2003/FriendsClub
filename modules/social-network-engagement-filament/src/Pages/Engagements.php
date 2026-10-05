<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Engagement\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Liberu\SocialNetwork\Engagement\Actions\DeleteEngagement;
use Liberu\SocialNetwork\Engagement\Actions\UpdateEngagement;
use Liberu\SocialNetwork\Engagement\Models\Engagement;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Your activity": every reaction, comment, share and bookmark made from my
 * profile. Engagements are created from the content itself; here they can be
 * reviewed, comments edited and anything undone, through the module's actions.
 */
final class Engagements extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var array<string, array{label: string, icon: string, color: string}> */
    private const array KINDS = [
        'reaction' => ['label' => 'Reaction', 'icon' => 'heroicon-o-heart', 'color' => 'danger'],
        'comment' => ['label' => 'Comment', 'icon' => 'heroicon-o-chat-bubble-bottom-center-text', 'color' => 'info'],
        'reply' => ['label' => 'Reply', 'icon' => 'heroicon-o-arrow-uturn-left', 'color' => 'info'],
        'share' => ['label' => 'Share', 'icon' => 'heroicon-o-arrow-path-rounded-square', 'color' => 'success'],
        'bookmark' => ['label' => 'Saved', 'icon' => 'heroicon-o-bookmark', 'color' => 'warning'],
    ];

    /** @var array<string, string> */
    private const array REACTIONS = ['like' => '👍 Like', 'love' => '❤️ Love', 'celebrate' => '🎉 Celebrate', 'insightful' => '💡 Insightful'];

    /** Attributes tried, in order, to describe the content an engagement points to. */
    private const array TARGET_LABEL_ATTRIBUTES = ['title', 'name', 'body', 'handle'];

    protected string $view = 'social-network-engagement-filament::pages.engagements';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'Your activity';

    protected static ?string $navigationLabel = 'Engagements';

    private ?Profile $viewer = null;

    /** @var array<string, string> "type:id" => label, filled as rows render */
    private array $targetLabels = [];

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Engagement::query()->where('actor_profile_id', $this->viewer()->getKey()))
            ->columns([
                TextColumn::make('kind')
                    ->label('What')
                    ->badge()
                    ->icon(fn (string $state): string => self::KINDS[$state]['icon'] ?? 'heroicon-o-sparkles')
                    ->color(fn (string $state): string => self::KINDS[$state]['color'] ?? 'gray')
                    ->formatStateUsing(fn (string $state, Engagement $record): string => $state === 'reaction'
                        ? (self::REACTIONS[$record->reaction_type] ?? 'Reaction')
                        : (self::KINDS[$state]['label'] ?? ucfirst($state))),
                TextColumn::make('target')
                    ->label('On')
                    ->state(fn (Engagement $record): string => $this->targetLabel($record))
                    ->description(fn (Engagement $record): ?string => filled($record->body) ? '“'.str((string) $record->body)->squish()->limit(100).'”' : null)
                    ->wrap(),
                TextColumn::make('target_type')
                    ->label('Content type')
                    ->formatStateUsing(fn (string $state): string => class_basename(Relation::getMorphedModel($state) ?? $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('What')
                    ->options(collect(self::KINDS)->map(fn (array $kind): string => $kind['label'])->all()),
                SelectFilter::make('reaction_type')
                    ->label('Reaction')
                    ->options(self::REACTIONS),
            ])
            ->recordActions([
                Action::make('editComment')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (Engagement $record): bool => in_array($record->kind, ['comment', 'reply'], true))
                    ->fillForm(fn (Engagement $record): array => ['body' => $record->body])
                    ->schema([
                        Textarea::make('body')
                            ->label('Comment')
                            ->required()
                            ->rows(4)
                            ->maxLength((int) config('social-network-engagement.comment_max_length', 10000)),
                    ])
                    ->action(fn (Engagement $record, array $data) => $this->attempt(
                        fn () => app(UpdateEngagement::class)->handle($this->viewer(), $record, $data['body']),
                        'Comment updated',
                    )),
                Action::make('undo')
                    ->label(fn (Engagement $record): string => match ($record->kind) {
                        'reaction' => 'Remove reaction',
                        'bookmark' => 'Unsave',
                        'share' => 'Undo share',
                        default => 'Delete',
                    })
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Engagement $record) => $this->attempt(
                        fn () => app(DeleteEngagement::class)->handle($this->viewer(), $record),
                        'Done',
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No activity yet')
            ->emptyStateDescription('Reactions, comments, shares and saved posts you make will show up here.')
            ->emptyStateIcon('heroicon-o-heart')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }

    /**
     * A readable description of the content an engagement points to. The
     * module does not know which content types exist, so it loads the target
     * model generically and uses its first non-empty descriptive attribute.
     */
    private function targetLabel(Engagement $engagement): string
    {
        $key = $engagement->target_type.':'.$engagement->target_id;

        return $this->targetLabels[$key] ??= (function () use ($engagement): string {
            $class = Relation::getMorphedModel($engagement->target_type) ?? $engagement->target_type;
            $fallback = class_basename($class);

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                return $fallback;
            }

            $target = $class::query()->find($engagement->target_id);
            if ($target === null) {
                return $fallback.' (no longer available)';
            }

            foreach (self::TARGET_LABEL_ATTRIBUTES as $attribute) {
                $value = $target->getAttribute($attribute);
                if (is_string($value) && trim($value) !== '') {
                    return str($value)->squish()->limit(80)->toString();
                }
            }

            return $fallback;
        })();
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
