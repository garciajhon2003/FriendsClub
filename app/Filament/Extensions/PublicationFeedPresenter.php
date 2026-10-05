<?php

namespace App\Filament\Extensions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Liberu\SocialNetwork\Engagement\Actions\CreateEngagement;
use Liberu\SocialNetwork\Engagement\Actions\DeleteEngagement;
use Liberu\SocialNetwork\Engagement\Models\Engagement;
use Liberu\SocialNetwork\Feed\Filament\Contracts\FeedItemPresenter;
use Liberu\SocialNetwork\Moderation\Actions\CreateReport;
use Liberu\SocialNetwork\Moderation\Filament\Pages\Reports;
use Liberu\SocialNetwork\Profiles\Actions\ResolveDisplayNames;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\Publishing\Models\Publication;

/**
 * Muestra las publicaciones en el feed y permite reaccionar, comentar y reportarlas.
 * Une módulos que no se conocen entre sí (feed, publishing, engagement y moderation),
 * por eso vive en la composición y no en ninguno de ellos.
 */
class PublicationFeedPresenter implements FeedItemPresenter
{
    /** Comentarios que se muestran bajo cada publicación. */
    private const COMMENTS_SHOWN = 3;

    /** @var array<string, string> */
    private const REACTION_LABELS = ['like' => '👍 Like', 'love' => '❤️ Love', 'celebrate' => '🎉 Celebrate', 'insightful' => '💡 Insightful'];

    public function itemType(): string
    {
        return (new Publication())->getMorphClass();
    }

    public function label(): string
    {
        return 'Posts';
    }

    public function present(Profile $viewer, array $itemIds): array
    {
        $publications = $this->visibleTo($viewer)->whereKey($itemIds)->get()->keyBy(fn (Publication $p): string => (string) $p->getKey());
        if ($publications->isEmpty()) {
            return [];
        }

        $ids = $publications->keys()->all();
        $engagements = Engagement::query()
            ->where('target_type', $this->itemType())
            ->whereIn('target_id', $ids)
            ->whereIn('kind', ['reaction', 'comment'])
            ->orderBy('created_at')
            ->get()
            ->groupBy('target_id');

        $names = app(ResolveDisplayNames::class)->handle(
            $publications->pluck('author_profile_id')
                ->merge($engagements->flatten()->where('kind', 'comment')->pluck('actor_profile_id'))
                ->map(fn (mixed $id): string => (string) $id)
                ->unique()->values()->all(),
        );

        return $publications->map(function (Publication $publication) use ($engagements, $names, $viewer): array {
            $onThis = $engagements->get($publication->getKey()) ?? new Collection();
            $reactions = $onThis->where('kind', 'reaction');
            $comments = $onThis->where('kind', 'comment');

            return [
                'author' => $names[(string) $publication->author_profile_id] ?? '—',
                'title' => $publication->title,
                'body' => $publication->body,
                'published_at' => $publication->published_at,
                'reactions' => $reactions->countBy('reaction_type')->all(),
                'my_reactions' => $reactions->where('actor_profile_id', $viewer->getKey())->pluck('reaction_type')->values()->all(),
                'comments' => $comments->take(-self::COMMENTS_SHOWN)->map(fn (Engagement $comment): array => [
                    'author' => $names[(string) $comment->actor_profile_id] ?? '—',
                    'body' => (string) $comment->body,
                    'at' => $comment->created_at,
                ])->values()->all(),
                'comment_count' => $comments->count(),
            ];
        })->all();
    }

    public function reactions(): array
    {
        return collect((array) config('social-network-engagement.reaction_types'))
            ->mapWithKeys(fn (string $type): array => [$type => self::REACTION_LABELS[$type] ?? ucfirst($type)])
            ->all();
    }

    public function toggleReaction(Profile $viewer, string $itemId, string $reaction): void
    {
        $this->ensureVisible($viewer, $itemId);

        $existing = Engagement::query()->where([
            'actor_profile_id' => $viewer->getKey(),
            'target_type' => $this->itemType(),
            'target_id' => $itemId,
            'kind' => 'reaction',
            'reaction_type' => $reaction,
        ])->first();

        if ($existing !== null) {
            app(DeleteEngagement::class)->handle($viewer, $existing);

            return;
        }

        app(CreateEngagement::class)->handle($viewer, [
            'target_type' => $this->itemType(),
            'target_id' => $itemId,
            'kind' => 'reaction',
            'reaction_type' => $reaction,
        ]);
    }

    public function acceptsComments(): bool
    {
        return true;
    }

    public function comment(Profile $viewer, string $itemId, string $body): void
    {
        $this->ensureVisible($viewer, $itemId);

        app(CreateEngagement::class)->handle($viewer, [
            'target_type' => $this->itemType(),
            'target_id' => $itemId,
            'kind' => 'comment',
            'body' => $body,
        ]);
    }

    public function reportReasons(): array
    {
        return Reports::REASONS;
    }

    public function report(Profile $viewer, string $itemId, string $reason, ?string $details): void
    {
        $this->ensureVisible($viewer, $itemId);

        app(CreateReport::class)->handle($viewer, $this->itemType(), $itemId, $reason, $details);
    }

    /** Misma regla que ListPublications: publicadas y públicas, o mías. */
    /** @return Builder<Publication> */
    private function visibleTo(Profile $viewer): Builder
    {
        return Publication::query()
            ->where('state', 'published')
            ->where(fn ($query) => $query->where('audience', 'public')->orWhere('author_profile_id', $viewer->getKey()));
    }

    private function ensureVisible(Profile $viewer, string $itemId): void
    {
        if (! $this->visibleTo($viewer)->whereKey($itemId)->exists()) {
            throw new InvalidArgumentException('This publication is no longer available.');
        }
    }
}
