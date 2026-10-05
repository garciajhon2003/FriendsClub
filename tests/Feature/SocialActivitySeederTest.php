<?php

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\SocialActivitySeeder;
use Liberu\Foundation\Organizations\Models\Team;
use Liberu\SocialNetwork\Engagement\Models\Engagement;
use Liberu\SocialNetwork\Messaging\Models\Conversation;
use Liberu\SocialNetwork\Notifications\Models\SocialNotification;
use Liberu\SocialNetwork\Publishing\Models\Publication;

use function Pest\Laravel\seed;

function activityAccount(): User
{
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    return $user;
}

it('seeds activity without duplicating on re-run', function () {
    activityAccount();
    seed(DemoDataSeeder::class);
    $counts = [Publication::count(), Engagement::count(), SocialNotification::count(), Conversation::count()];

    seed(SocialActivitySeeder::class);

    expect([Publication::count(), Engagement::count(), SocialNotification::count(), Conversation::count()])->toBe($counts)
        ->and($counts)->each->toBeGreaterThan(0);
});

it('fills the app pages of a real account', function (string $route, string $expected) {
    $user = activityAccount();
    seed(DemoDataSeeder::class);

    $this->actingAs($user)->get(route($route))->assertOk()->assertSee($expected);
})->with([
    'publicaciones' => ['filament.app.pages.publications', 'Mi primera ruta'],
    'interacciones' => ['filament.app.pages.engagements', 'Dónde quedamos'],
    'notificaciones' => ['filament.app.pages.notifications', 'Alejandra empezó a seguirte'],
    'conversaciones' => ['filament.app.pages.conversations', 'Ruta del sábado'],
    'archivos' => ['filament.app.pages.assets', 'Vista desde el mirador de Collserola'],
    'portada' => ['filament.app.pages.entries', 'tarta de limón y merengue'],
    'relaciones' => ['filament.app.pages.relationships', 'follow'],
    'listas' => ['filament.app.pages.lists', 'Compañeros de senderismo'],
]);
