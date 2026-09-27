<?php

use App\Models\Hobby;
use Database\Seeders\HobbySeeder;

use function Pest\Laravel\seed;

it('seeds the hobby catalog without duplicates', function () {
    seed(HobbySeeder::class);
    seed(HobbySeeder::class);

    expect(Hobby::count())->toBe(11)
        ->and(Hobby::where('slug', 'reposteria')->exists())->toBeTrue();
});

it('lists only active hobbies in order', function () {
    seed(HobbySeeder::class);
    Hobby::where('slug', 'cocina')->update(['is_active' => false]);

    expect(Hobby::active()->first()->slug)->toBe('reposteria');
});
