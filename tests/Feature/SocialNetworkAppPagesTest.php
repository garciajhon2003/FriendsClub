<?php

use App\Models\User;
use Liberu\Foundation\Organizations\Models\Team;

it('renders the social network page', function (string $route) {
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $team = Team::factory()->create(['user_id' => $user->id]);
    $user->forceFill(['current_team_id' => $team->id])->save();

    $this->actingAs($user)
        ->get(route($route))
        ->assertOk();
})->with([
    'filament.app.pages.dashboard',
    'filament.app.pages.search',
    'filament.app.pages.events',
    'filament.app.pages.communities',
    'filament.app.pages.conversations',
    'filament.app.pages.engagements',
    'filament.app.pages.assets',
    'filament.app.pages.publications',
    'filament.app.pages.notifications',
    'filament.app.pages.entries',
    'filament.app.pages.reports',
    'filament.app.pages.blocks',
    'filament.app.pages.lists',
    'filament.app.pages.relationships',
    'filament.app.pages.suggestions',
    'filament.app.pages.edit-profile',
    'filament.app.pages.social-core-settings',
]);
