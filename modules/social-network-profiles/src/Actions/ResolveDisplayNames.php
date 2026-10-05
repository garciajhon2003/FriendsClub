<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Profiles\Actions;

use Illuminate\Database\Eloquent\Builder;
use Liberu\SocialNetwork\Profiles\Models\Profile;

/**
 * Names to show for profiles: the user's name when the composition configures
 * social-network-profiles.user_model, otherwise the profile handle.
 */
final class ResolveDisplayNames
{
    /**
     * @param  list<string>  $profileIds
     * @return array<string, string> profile id => display name, in the given order
     */
    public function handle(array $profileIds): array
    {
        $query = Profile::query()->whereKey($profileIds);
        if ($this->usesUserNames()) {
            $query->with('user');
        }
        $profiles = $query->get()->keyBy(fn (Profile $profile): string => (string) $profile->getKey());

        $names = [];
        foreach ($profileIds as $id) {
            $profile = $profiles->get($id);
            $names[$id] = $profile === null ? $id : $this->nameOf($profile);
        }

        return $names;
    }

    /**
     * Profiles whose name or handle contains $search, for pickers.
     *
     * @param  list<string>  $exceptIds
     * @return array<string, string> profile id => display name
     */
    public function search(string $search, array $exceptIds = [], int $limit = 20): array
    {
        $ids = Profile::query()
            ->whereKeyNot($exceptIds)
            ->where(function (Builder $query) use ($search): void {
                $query->where('handle', 'like', "%{$search}%");
                if ($this->usesUserNames()) {
                    $query->orWhereHas('user', fn (Builder $user): Builder => $user->where('name', 'like', "%{$search}%"));
                }
            })
            ->limit($limit)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $this->handle($ids);
    }

    private function nameOf(Profile $profile): string
    {
        if ($profile->relationLoaded('user')) {
            $name = $profile->user?->getAttribute('name');
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        return (string) $profile->handle;
    }

    private function usesUserNames(): bool
    {
        return filled(config('social-network-profiles.user_model'));
    }
}
