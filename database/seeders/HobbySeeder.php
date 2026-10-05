<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;

class HobbySeeder extends Seeder
{
    public function run(): void
    {
        $hobbies = [
            'Cocina',
            'Repostería',
            'Lectura',
            'Arte y Manualidades',
            'Miniaturas',
            'Fotografía',
            'Deportes',
            'Senderismo',
            'Bienestar',
            'Realities',
            'DIY',
        ];

        foreach ($hobbies as $order => $name) {
            Hobby::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $order + 1],
            );
        }
    }
}
