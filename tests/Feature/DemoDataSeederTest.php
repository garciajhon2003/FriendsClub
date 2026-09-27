<?php

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Communities\Models\Membership;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Profiles\Models\Profile;

use function Pest\Laravel\seed;

it('seeds demo people, clubs and events without duplicating on re-run', function () {
    seed(DemoDataSeeder::class);
    seed(DemoDataSeeder::class);

    expect(User::where('email', 'like', '%@friendsclub.test')->count())->toBe(10)
        ->and(Community::count())->toBe(6)
        ->and(Event::count())->toBe(5);
});

it('links demo data through the new relations', function () {
    seed(DemoDataSeeder::class);

    $club = Community::where('slug', 'amantes-de-la-reposteria')->first();
    $alejandra = Profile::where('handle', 'alejandra')->first();

    expect($club->hobby->slug)->toBe('reposteria')
        ->and($club->city)->toBe('Barcelona')
        ->and(Membership::where('community_id', $club->id)->count())->toBe(3)
        ->and($alejandra->hobbies)->toHaveCount(3)
        ->and($alejandra->preferences->languages)->toBe(['es']);
});
