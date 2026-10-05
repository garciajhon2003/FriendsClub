<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Moderation\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Liberu\SocialNetwork\Moderation\Actions\DecideReport;
use Liberu\SocialNetwork\Moderation\Models\ModerationDecision;
use Liberu\SocialNetwork\Moderation\Models\ModerationReport;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Actions\ResolveDisplayNames;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Moderators see the moderation queue and decide on reports; every other
 * member sees the reports they filed and how they ended. Writes go through
 * the actions of social-network-moderation.
 */
final class Reports extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Reasons a member can pick when reporting something.
     *
     * @var array<string, string>
     */
    public const array REASONS = [
        'spam' => 'Spam',
        'harassment' => 'Harassment or bullying',
        'hate' => 'Hate speech',
        'violence' => 'Violence or threats',
        'nudity' => 'Nudity or sexual content',
        'misinformation' => 'False information',
        'other' => 'Something else',
    ];

    /** @var array<string, array{label: string, color: string}> */
    private const array DECISIONS = [
        'dismiss' => ['label' => 'Dismiss (no action)', 'color' => 'gray'],
        'warn' => ['label' => 'Warn the author', 'color' => 'warning'],
        'remove' => ['label' => 'Remove the content', 'color' => 'danger'],
        'suspend' => ['label' => 'Suspend the author', 'color' => 'danger'],
        'ban' => ['label' => 'Ban the author', 'color' => 'danger'],
    ];

    /** Report states that still need a decision. */
    private const array PENDING_STATES = ['open', 'under_review', 'appealed'];

    /** Attributes tried, in order, to describe the reported content. */
    private const array TARGET_LABEL_ATTRIBUTES = ['title', 'name', 'body', 'handle'];

    protected string $view = 'social-network-moderation-filament::pages.reports';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $navigationLabel = 'Reports';

    private ?Profile $viewer = null;

    public static function getNavigationBadge(): ?string
    {
        if (! self::currentUserModerates()) {
            return null;
        }

        $open = ModerationReport::query()->whereIn('state', self::PENDING_STATES)->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getTitle(): string
    {
        return $this->moderates() ? 'Moderation queue' : 'My reports';
    }

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ModerationReport::query()
                ->when(! $this->moderates(), fn (Builder $query): Builder => $query->where('reporter_profile_id', $this->viewer()->getKey())))
            ->columns([
                TextColumn::make('reason')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (string $state): string => self::REASONS[$state] ?? ucfirst($state)),
                TextColumn::make('target')
                    ->label('Reported content')
                    ->state(fn (ModerationReport $record): string => $this->targetLabel($record))
                    ->description(fn (ModerationReport $record): ?string => filled($record->details) ? '“'.str((string) $record->details)->squish()->limit(90).'”' : null)
                    ->wrap(),
                TextColumn::make('reporter')
                    ->label('Reported by')
                    ->state(fn (ModerationReport $record): string => $this->nameOf((string) $record->reporter_profile_id))
                    ->visible(fn (): bool => $this->moderates()),
                TextColumn::make('state')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->ucfirst()->toString())
                    ->color(fn (string $state): string => match ($state) {
                        'open', 'appealed' => 'warning',
                        'under_review' => 'info',
                        'resolved' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('outcome')
                    ->badge()
                    ->state(fn (ModerationReport $record): ?string => $this->latestDecision($record)?->action)
                    ->formatStateUsing(fn (string $state): string => str(self::DECISIONS[$state]['label'] ?? $state)->before(' (')->toString())
                    ->color(fn (string $state): string => self::DECISIONS[$state]['color'] ?? 'gray')
                    ->placeholder('Pending'),
                TextColumn::make('created_at')
                    ->label('Reported')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(['open' => 'Open', 'under_review' => 'Under review', 'appealed' => 'Appealed', 'resolved' => 'Resolved', 'closed' => 'Closed'])
                    ->multiple()
                    ->default(fn (): array => $this->moderates() ? self::PENDING_STATES : []),
                SelectFilter::make('reason')
                    ->options(self::REASONS),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema(fn (Schema $schema): Schema => $this->detailsSchema($schema)),
                Action::make('decide')
                    ->label('Decide')
                    ->icon('heroicon-o-scale')
                    ->button()
                    ->visible(fn (ModerationReport $record): bool => $this->moderates() && in_array($record->state, self::PENDING_STATES, true))
                    ->modalDescription(fn (ModerationReport $record): string => 'Reported for “'.(self::REASONS[$record->reason] ?? $record->reason).'”: '.$this->targetLabel($record))
                    ->schema([
                        Select::make('action')
                            ->label('Decision')
                            ->options(collect(self::DECISIONS)->map(fn (array $decision): string => $decision['label'])->all())
                            ->required(),
                        Textarea::make('reason')
                            ->label('Why')
                            ->helperText('Kept with the decision for the record.')
                            ->rows(3)
                            ->maxLength(5000),
                    ])
                    ->action(fn (ModerationReport $record, array $data) => $this->attempt(
                        fn () => app(DecideReport::class)->handle($this->viewer(), $record, $data['action'], filled($data['reason'] ?? null) ? $data['reason'] : null),
                        'Decision recorded',
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(fn (): string => $this->moderates() ? 'Nothing to review' : 'You have not reported anything')
            ->emptyStateDescription(fn (): string => $this->moderates() ? 'New reports from members will appear here.' : 'Use “Report” on a post when something breaks the rules.')
            ->emptyStateIcon('heroicon-o-shield-check')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(25);
    }

    private function detailsSchema(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('reason')->badge()->color('danger')->formatStateUsing(fn (string $state): string => self::REASONS[$state] ?? $state),
                        TextEntry::make('state')->badge()->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->ucfirst()->toString()),
                        TextEntry::make('created_at')->label('Reported')->dateTime('D j M Y, H:i'),
                    ]),
                    TextEntry::make('target')
                        ->label('Reported content')
                        ->state(fn (ModerationReport $record): string => $this->targetLabel($record, limit: 500)),
                    TextEntry::make('details')->label('Details from the reporter')->placeholder('—'),
                    TextEntry::make('reporter')
                        ->label('Reported by')
                        ->state(fn (ModerationReport $record): string => $this->nameOf((string) $record->reporter_profile_id))
                        ->visible(fn (): bool => $this->moderates()),
                ]),
            Section::make('Decisions')
                ->schema([
                    RepeatableEntry::make('decisions')
                        ->hiddenLabel()
                        ->state(fn (ModerationReport $record): array => ModerationDecision::query()
                            ->where('report_id', $record->getKey())
                            ->latest()
                            ->get()
                            ->map(fn (ModerationDecision $decision): array => [
                                'action' => self::DECISIONS[$decision->action]['label'] ?? $decision->action,
                                'reason' => $decision->reason,
                                'by' => $this->moderates() ? $this->nameOf((string) $decision->actor_profile_id) : 'A moderator',
                                'at' => $decision->created_at,
                            ])
                            ->all())
                        ->schema([
                            TextEntry::make('action')->hiddenLabel()->weight('bold'),
                            TextEntry::make('reason')->hiddenLabel()->placeholder('No reason given'),
                            TextEntry::make('by')->hiddenLabel()->color('gray')->size('sm'),
                        ])
                        ->placeholder('No decision yet.'),
                ]),
        ]);
    }

    private static function currentUserModerates(): bool
    {
        $user = auth()->user();

        return $user !== null && Gate::forUser($user)->allows('social-network.moderation.decide', [new Profile(['user_id' => $user->getAuthIdentifier()])]);
    }

    private function moderates(): bool
    {
        return self::currentUserModerates();
    }

    private function latestDecision(ModerationReport $report): ?ModerationDecision
    {
        return ModerationDecision::query()->where('report_id', $report->getKey())->latest('id')->first();
    }

    private function nameOf(string $profileId): string
    {
        return app(ResolveDisplayNames::class)->handle([$profileId])[$profileId] ?? '—';
    }

    /**
     * A readable description of the reported content. The module does not know
     * which content types exist, so it loads the target generically.
     */
    private function targetLabel(ModerationReport $report, int $limit = 80): string
    {
        $class = Relation::getMorphedModel($report->target_type) ?? $report->target_type;
        $type = class_basename($class);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return $type;
        }

        $query = $class::query();
        if (method_exists($class, 'bootSoftDeletes')) {
            $query->withTrashed();
        }
        $target = $query->find($report->target_id);
        if ($target === null) {
            return $type.' (no longer available)';
        }

        foreach (self::TARGET_LABEL_ATTRIBUTES as $attribute) {
            $value = $target->getAttribute($attribute);
            if (is_string($value) && trim($value) !== '') {
                return $type.': '.str($value)->squish()->limit($limit);
            }
        }

        return $type;
    }

    /**
     * Runs a module action and turns its refusals into a notification instead
     * of an error page.
     */
    private function attempt(Closure $callback, ?string $success = null): mixed
    {
        try {
            $result = $callback();
        } catch (InvalidArgumentException|AuthorizationException $exception) {
            Notification::make()
                ->title($exception instanceof AuthorizationException ? 'You are not allowed to do that.' : $exception->getMessage())
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
