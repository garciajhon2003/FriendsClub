<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Feed\Filament\Contracts;

use Carbon\CarbonInterface;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Turns feed entries of one item type into cards. The feed only stores
 * pointers (item_type + item_id), so it cannot know what a "post" or an "event"
 * looks like; a composition registers one presenter per type, collected from the
 * container by TAG. Types without a presenter show as a generic card.
 *
 * @phpstan-type FeedCard array{
 *     author: string,
 *     title: ?string,
 *     body: ?string,
 *     published_at: ?CarbonInterface,
 *     reactions: array<string, int>,
 *     my_reactions: list<string>,
 *     comments: list<array{author: string, body: string, at: ?CarbonInterface}>,
 *     comment_count: int,
 * }
 */
interface FeedItemPresenter
{
    public const string TAG = 'social-network-feed-filament.presenters';

    /** The item_type (morph class) this presenter understands. */
    public function itemType(): string;

    /** Human name of the type, for the feed filters ("Posts"). */
    public function label(): string;

    /**
     * Cards for the given items, keyed by item id. Items the viewer may not see
     * (deleted, private) are left out and their entries are skipped.
     *
     * @param  list<string>  $itemIds
     * @return array<string, FeedCard>
     */
    public function present(Profile $viewer, array $itemIds): array;

    /** @return array<string, string> reaction key => label with emoji; empty when items cannot be reacted to */
    public function reactions(): array;

    /** Adds the reaction, or removes it when the viewer already reacted that way. */
    public function toggleReaction(Profile $viewer, string $itemId, string $reaction): void;

    /** Whether items of this type accept comments. */
    public function acceptsComments(): bool;

    public function comment(Profile $viewer, string $itemId, string $body): void;

    /** @return array<string, string> reason key => label; empty when items cannot be reported */
    public function reportReasons(): array;

    /** Files a report about the item for the moderators. */
    public function report(Profile $viewer, string $itemId, string $reason, ?string $details): void;
}
