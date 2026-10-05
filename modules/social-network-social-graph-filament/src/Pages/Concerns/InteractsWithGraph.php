<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\SocialGraph\Filament\Pages\Concerns;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Liberu\SocialNetwork\Profiles\Actions\GetProfile;
use Liberu\SocialNetwork\Profiles\Actions\ResolveDisplayNames;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * What the four social graph pages share: the signed-in profile, display
 * names, a people picker and turning module refusals into notifications.
 */
trait InteractsWithGraph
{
    private ?Profile $viewer = null;

    public function viewer(): Profile
    {
        abort_unless(auth()->check(), 404);

        return $this->viewer ??= app(GetProfile::class)->forUser(auth()->id());
    }

    private function nameOf(string $profileId): string
    {
        return app(ResolveDisplayNames::class)->handle([$profileId])[$profileId] ?? '—';
    }

    private function profile(string $profileId): Profile
    {
        return Profile::query()->findOrFail($profileId);
    }

    /**
     * A searchable picker of other profiles.
     *
     * @param  Closure(): list<string>|null  $except  ids to leave out, besides me
     */
    private function personPicker(string $name = 'profile', string $label = 'Person', bool $multiple = false, ?Closure $except = null): Select
    {
        return Select::make($name)
            ->label($label)
            ->required()
            ->multiple($multiple)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => app(ResolveDisplayNames::class)->search(
                $search,
                [(string) $this->viewer()->getKey(), ...($except === null ? [] : $except())],
            ))
            ->getOptionLabelUsing(fn (mixed $value): ?string => $value === null ? null : $this->nameOf((string) $value))
            ->getOptionLabelsUsing(fn (array $values): array => app(ResolveDisplayNames::class)->handle(array_map('strval', $values)));
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
