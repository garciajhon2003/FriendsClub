<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\SocialNetwork\Profiles\Models\Profile;

final class SocialNetworkAuthorizationServiceProvider extends ServiceProvider
{
    /**
     * Abilities granted by role, not by owning what is passed to them. For the
     * first two the profile is the actor, so the ownership rule below would let
     * every member moderate reports or verify their own profile; analytics gets
     * a metric name, which the team rule below would always deny, hiding it from
     * holders of the "view analytics" permission. Their module gates decide.
     */
    private const ROLE_ABILITIES = [
        'social-network.moderation.decide',
        'social-network.profiles.verify',
        'social-network.analytics.view',
        'social-network.analytics.record',
    ];

    public function boot(): void
    {
        Gate::before(function (?object $user, string $ability, array $arguments): ?bool {
            if (! str_starts_with($ability, 'social-network.')) {
                return null;
            }

            if ($user === null) {
                return false;
            }

            if (method_exists($user, 'isAdmin') && $user->isAdmin()) {
                return true;
            }

            if (in_array($ability, self::ROLE_ABILITIES, true)) {
                return null;
            }

            $subject = $arguments[0] ?? null;

            if ($subject instanceof Profile) {
                if ($ability === 'social-network.profiles.view' && $subject->getAttribute('visibility') === 'public') {
                    return true;
                }

                $ownsProfile = (string) $subject->getAttribute('user_id') === (string) $user->getAuthIdentifier();

                // With a second subject (the event, community, publication… acted on),
                // owning the profile is not enough: the module gate also checks that
                // object, e.g. that the event is yours. Deny strangers here, defer the rest.
                if (count($arguments) > 1) {
                    return $ownsProfile ? null : false;
                }

                return $ownsProfile;
            }

            if (is_int($subject) || is_string($subject)) {
                $team = Team::query()->find($subject);

                return $team !== null && $user->belongsToTeam($team);
            }

            return false;
        });
    }
}
