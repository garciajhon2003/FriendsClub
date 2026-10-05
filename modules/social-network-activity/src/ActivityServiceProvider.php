<?php

declare(strict_types=1);

namespace Liberu\SocialNetwork\Activity;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Liberu\SocialNetwork\Activity\Listeners\SharePublishedPublication;
use Liberu\SocialNetwork\Activity\Listeners\WithdrawDeletedPublication;
use Liberu\SocialNetwork\Publishing\Events\PublicationPublished;
use Liberu\SocialNetwork\Publishing\Models\Publication;

/**
 * The wiring between modules. Each domain module announces what happened
 * (events) and knows nothing about the others; this module listens and makes
 * the rest of the network react. Listeners run in the same request, so the
 * effect is visible right away without a queue worker.
 */
final class ActivityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(PublicationPublished::class, SharePublishedPublication::class);

        Publication::deleted(fn (Publication $publication) => $this->app->make(WithdrawDeletedPublication::class)->handle($publication));
    }
}
