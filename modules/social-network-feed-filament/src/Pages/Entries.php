<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Feed\Filament\Pages;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Liberu\SocialNetwork\Feed\Actions\GetFeed;
use Liberu\SocialNetwork\Feed\Actions\UpdateFeedControls;
use Liberu\SocialNetwork\Feed\Filament\Contracts\FeedItemPresenter;
use Liberu\SocialNetwork\Feed\Models\FeedEntry;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The member's home feed: the entries GetFeed returns, shown as cards drawn by
 * the FeedItemPresenter registered for each item type, with reactions,
 * comments, hiding and the feed controls (order and types).
 */
final class Entries extends Page
{
    private const int PAGE_SIZE = 20;

    protected string $view = 'social-network-feed-filament::pages.entries';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Social Network';

    protected static ?string $title = 'Home';

    protected static ?string $navigationLabel = 'Feed';

    /** How many entries are shown; "Load more" raises it. */
    public int $limit = self::PAGE_SIZE;

    private ?Profile $viewer = null;

    /** @var array<string, FeedItemPresenter>|null item type => presenter */
    private ?array $presenters = null;

    /** @var list<FeedEntry>|null */
    private ?array $entries = null;

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    /**
     * Cards in feed order. Entries whose presenter does not return the item
     * (deleted, or no longer visible to me) are skipped.
     *
     * @return list<array{entry_id: string, type_label: string, card: array<string, mixed>, reactions: array<string, string>, can_comment: bool, can_report: bool}>
     */
    public function cards(): array
    {
        $entries = $this->entries();
        $cardsByType = [];

        foreach (collect($entries)->groupBy('item_type') as $type => $group) {
            $presenter = $this->presenterFor((string) $type);
            $ids = $group->pluck('item_id')->map(fn (mixed $id): string => (string) $id)->all();
            $cardsByType[$type] = $presenter?->present($this->viewer(), $ids)
                ?? array_fill_keys($ids, $this->genericCard((string) $type));
        }

        $cards = [];
        foreach ($entries as $entry) {
            $card = $cardsByType[$entry->item_type][(string) $entry->item_id] ?? null;
            if ($card === null) {
                continue;
            }

            $presenter = $this->presenterFor($entry->item_type);
            $cards[] = [
                'entry_id' => (string) $entry->getKey(),
                'type_label' => $this->typeLabel($entry->item_type),
                'card' => $card,
                'reactions' => $presenter?->reactions() ?? [],
                'can_comment' => $presenter?->acceptsComments() ?? false,
                'can_report' => ($presenter?->reportReasons() ?? []) !== [],
            ];
        }

        return $cards;
    }

    public function hasMore(): bool
    {
        return count($this->entries()) >= $this->limit && $this->limit < (int) config('social-network-feed.max_page_size', 100);
    }

    public function loadMore(): void
    {
        $this->limit = min($this->limit + self::PAGE_SIZE, (int) config('social-network-feed.max_page_size', 100));
        $this->entries = null;
    }

    public function toggleReaction(string $entryId, string $reaction): void
    {
        $entry = $this->entry($entryId);
        $presenter = $this->presenterFor($entry->item_type);
        abort_unless($presenter !== null && array_key_exists($reaction, $presenter->reactions()), 422);

        $this->attemptOutsideAction(fn () => $presenter->toggleReaction($this->viewer(), (string) $entry->item_id, $reaction));
    }

    public function hideEntry(string $entryId): void
    {
        $entry = $this->entry($entryId);
        $controls = $this->controls();

        $this->attemptOutsideAction(fn () => app(UpdateFeedControls::class)->handle($this->viewer(), [
            ...$controls,
            'hidden_items' => [...$controls['hidden_items'], (string) $entry->item_id],
        ]), 'Hidden from your feed');

        $this->entries = null;
    }

    public function commentAction(): Action
    {
        return Action::make('comment')
            ->label('Comment')
            ->icon('heroicon-o-chat-bubble-left')
            ->color('gray')
            ->size('sm')
            ->link()
            ->modalHeading('Write a comment')
            ->schema([
                Textarea::make('body')
                    ->hiddenLabel()
                    ->required()
                    ->rows(3)
                    ->maxLength(10000),
            ])
            ->modalSubmitActionLabel('Comment')
            ->action(function (array $data, array $arguments): void {
                $entry = $this->entry((string) ($arguments['entry'] ?? ''));
                $presenter = $this->presenterFor($entry->item_type);
                abort_unless($presenter?->acceptsComments() === true, 422);

                $this->attempt(
                    fn () => $presenter->comment($this->viewer(), (string) $entry->item_id, $data['body']),
                    'Comment posted',
                );
            });
    }

    public function reportAction(): Action
    {
        return Action::make('report')
            ->label('Report')
            ->icon('heroicon-o-flag')
            ->color('gray')
            ->size('sm')
            ->link()
            ->modalHeading('Report this to the moderators')
            ->modalDescription('Moderators review every report. The author is not told who reported it.')
            ->schema(fn (array $arguments): array => [
                Select::make('reason')
                    ->required()
                    ->options($this->presenterFor($this->entry((string) ($arguments['entry'] ?? ''))->item_type)?->reportReasons() ?? []),
                Textarea::make('details')
                    ->label('Anything else the moderators should know?')
                    ->rows(3)
                    ->maxLength(10000),
            ])
            ->modalSubmitActionLabel('Send report')
            ->action(function (array $data, array $arguments): void {
                $entry = $this->entry((string) ($arguments['entry'] ?? ''));
                $presenter = $this->presenterFor($entry->item_type);
                abort_unless($presenter !== null && $presenter->reportReasons() !== [], 422);

                $this->attempt(
                    fn () => $presenter->report($this->viewer(), (string) $entry->item_id, $data['reason'], filled($data['details'] ?? null) ? $data['details'] : null),
                    'Thanks, the moderators will review it',
                );
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('feedSettings')
                ->label('Feed settings')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->fillForm(fn (): array => [
                    'mode' => $this->controls()['mode'],
                    'filters' => $this->controls()['filters'],
                    'unhide' => false,
                ])
                ->schema([
                    Radio::make('mode')
                        ->label('Order')
                        ->options(['ranked' => 'Most relevant first', 'chronological' => 'Most recent first'])
                        ->required(),
                    CheckboxList::make('filters')
                        ->label('Only show')
                        ->helperText('Leave everything unticked to see all types.')
                        ->options(fn (): array => $this->typeOptions()),
                    Toggle::make('unhide')
                        ->label(fn (): string => 'Show the '.count($this->controls()['hidden_items']).' hidden items again')
                        ->visible(fn (): bool => $this->controls()['hidden_items'] !== []),
                ])
                ->action(function (array $data): void {
                    $this->attempt(fn () => app(UpdateFeedControls::class)->handle($this->viewer(), [
                        'mode' => $data['mode'],
                        'filters' => array_values($data['filters'] ?? []),
                        'hidden_items' => ($data['unhide'] ?? false) ? [] : $this->controls()['hidden_items'],
                    ]), 'Feed updated');

                    $this->entries = null;
                }),
        ];
    }

    /** @return list<FeedEntry> */
    private function entries(): array
    {
        return $this->entries ??= app(GetFeed::class)->handle($this->viewer(), $this->limit)->all();
    }

    private function entry(string $entryId): FeedEntry
    {
        return FeedEntry::query()
            ->where('viewer_profile_id', $this->viewer()->getKey())
            ->findOrFail($entryId);
    }

    /** @return array{mode: string, filters: list<string>, hidden_items: list<string>} */
    private function controls(): array
    {
        $controls = DB::table('social_feed_controls')->where('profile_id', $this->viewer()->getKey())->first();
        $decode = fn (?string $json): array => array_values(array_filter((array) json_decode((string) $json, true), 'is_string'));

        return [
            'mode' => (string) ($controls->mode ?? 'ranked'),
            'filters' => $decode($controls->filters ?? null),
            'hidden_items' => $decode($controls->hidden_items ?? null),
        ];
    }

    /** @return array<string, string> item type => label, for the types I have presenters for or entries of */
    private function typeOptions(): array
    {
        $types = FeedEntry::query()
            ->where('viewer_profile_id', $this->viewer()->getKey())
            ->distinct()
            ->pluck('item_type')
            ->merge(array_keys($this->presenters()))
            ->unique();

        return $types->mapWithKeys(fn (string $type): array => [$type => $this->typeLabel($type)])->sort()->all();
    }

    /** @return array<string, FeedItemPresenter> */
    private function presenters(): array
    {
        if ($this->presenters === null) {
            $this->presenters = [];
            foreach (app()->tagged(FeedItemPresenter::TAG) as $presenter) {
                $this->presenters[$presenter->itemType()] = $presenter;
            }
        }

        return $this->presenters;
    }

    private function presenterFor(string $itemType): ?FeedItemPresenter
    {
        return $this->presenters()[$itemType] ?? null;
    }

    private function typeLabel(string $itemType): string
    {
        return $this->presenterFor($itemType)?->label()
            ?? str(class_basename(Relation::getMorphedModel($itemType) ?? $itemType))->headline()->plural()->toString();
    }

    /** @return array<string, mixed> */
    private function genericCard(string $itemType): array
    {
        return [
            'author' => '',
            'title' => str(class_basename(Relation::getMorphedModel($itemType) ?? $itemType))->headline()->toString(),
            'body' => null,
            'published_at' => null,
            'reactions' => [],
            'my_reactions' => [],
            'comments' => [],
            'comment_count' => 0,
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

    /** attempt() for the cards' own buttons, which are not Filament actions. */
    private function attemptOutsideAction(Closure $callback, ?string $success = null): mixed
    {
        try {
            return $this->attempt($callback, $success);
        } catch (Halt) {
            return null;
        }
    }
}
