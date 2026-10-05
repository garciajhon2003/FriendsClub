<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Communities\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Liberu\SocialNetwork\Profiles\Models\Profile;

final class Community extends Model
{
    use SoftDeletes;

    protected $table = 'social_communities';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'owner_profile_id', 'name', 'slug', 'description', 'visibility', 'rules', 'modality', 'city', 'latitude', 'longitude', 'max_members', 'meeting_day', 'meeting_time', 'timezone'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'latitude' => 'float', 'longitude' => 'float', 'max_members' => 'integer', 'meeting_day' => 'integer'];
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'community_id');
    }

    /** Whether a capped community has no active spot left; uncapped ones never fill. */
    public function isFull(): bool
    {
        return $this->max_members !== null
            && $this->memberships()->where('status', 'active')->count() >= $this->max_members;
    }

    /**
     * Public communities, the viewer's own, and private ones the viewer is an active member of.
     */
    #[Scope]
    protected function visibleTo(Builder $query, Profile $viewer): void
    {
        $query->where(fn (Builder $visible): Builder => $visible
            ->where('social_communities.visibility', 'public')
            ->orWhere('social_communities.owner_profile_id', $viewer->getKey())
            ->orWhereExists(fn (QueryBuilder $membership): QueryBuilder => $membership
                ->selectRaw('1')
                ->from('social_community_memberships')
                ->whereColumn('social_community_memberships.community_id', 'social_communities.id')
                ->where('social_community_memberships.profile_id', $viewer->getKey())
                ->where('social_community_memberships.status', 'active')));
    }
}
