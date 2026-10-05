<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Communities\Enums\CommunityModality;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Communities\Models\Membership;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Hobbies\Models\Hobby;
use Liberu\SocialNetwork\Profiles\Models\Profile;

class DemoDataSeeder extends Seeder
{
    private const CITIES = [
        'Barcelona' => [41.3873974, 2.1686090],
        'Madrid' => [40.4167754, -3.7037902],
        'Valencia' => [39.4699075, -0.3762881],
    ];

    private const TIMEZONE = 'Europe/Madrid';

    /** @var array<string, int> slug del hobby => id */
    private array $hobbyIds = [];

    public function run(): void
    {
        $this->call(HobbySeeder::class);
        $this->hobbyIds = Hobby::pluck('id', 'slug')->all();

        $profiles = $this->seedPeople();
        $this->seedClubs($profiles);
        $this->seedEvents($profiles);

        $this->call(SocialActivitySeeder::class);
    }

    /** @return array<string, Profile> */
    private function seedPeople(): array
    {
        $people = [
            ['Alejandra', 'Barcelona', ['miniaturas' => 'avanzado', 'reposteria' => 'intermedio', 'fotografia' => 'principiante']],
            ['Laura', 'Barcelona', ['reposteria' => 'avanzado', 'cocina' => 'intermedio']],
            ['Marta', 'Barcelona', ['reposteria' => 'intermedio', 'lectura' => 'avanzado']],
            ['Javier', 'Barcelona', ['senderismo' => 'avanzado', 'fotografia' => 'principiante']],
            ['Carmen', 'Barcelona', ['lectura' => 'avanzado', 'bienestar' => 'intermedio']],
            ['Sofía', 'Madrid', ['fotografia' => 'intermedio', 'senderismo' => 'principiante']],
            ['Carlos', 'Madrid', ['fotografia' => 'avanzado', 'deportes' => 'intermedio']],
            ['Elena', 'Madrid', ['miniaturas' => 'intermedio', 'arte-y-manualidades' => 'avanzado']],
            ['Lucía', 'Valencia', ['bienestar' => 'intermedio', 'lectura' => 'principiante']],
            ['Pablo', 'Valencia', ['deportes' => 'avanzado', 'senderismo' => 'intermedio']],
        ];

        $profiles = [];

        foreach ($people as $index => [$name, $city, $hobbies]) {
            $handle = Str::slug($name);
            $email = "{$handle}@friendsclub.test";

            $user = User::where('email', $email)->first()
                ?? User::factory()->create(['name' => $name, 'email' => $email]);

            $profile = Profile::query()->firstOrCreate(
                ['user_id' => $user->getKey()],
                ['id' => (string) Str::uuid(), 'handle' => $handle, 'visibility' => 'public'],
            );

            [$lat, $lng] = self::CITIES[$city];
            $profile->forceFill([
                'city' => $city,
                'country_code' => 'ES',
                'latitude' => $lat + $index * 0.004,
                'longitude' => $lng + $index * 0.004,
            ])->save();

            $pivot = [];
            foreach ($hobbies as $slug => $level) {
                $pivot[$this->hobbyIds[$slug]] = ['level' => $level];
            }
            $profile->hobbies()->sync($pivot);

            $profile->preferences()->updateOrCreate([], [
                'max_distance_km' => 15,
                'available_days' => [4, 6, 7],
                'time_slots' => ['evening'],
                'languages' => ['es'],
            ]);

            $profiles[$handle] = $profile;
        }

        return $profiles;
    }

    /** @param array<string, Profile> $profiles */
    private function seedClubs(array $profiles): void
    {
        // [nombre, hobby, modalidad, ciudad, plazas, día (1=lunes…7=domingo), hora, miembros (el primero es el dueño)]
        $clubs = [
            ['Amantes de la Repostería', 'reposteria', CommunityModality::InPerson, 'Barcelona', 6, 7, '17:00', ['laura', 'marta', 'alejandra']],
            ['Senderismo Juntos', 'senderismo', CommunityModality::InPerson, 'Barcelona', 8, 6, '09:00', ['javier', 'alejandra']],
            ['Fotografía Urbana', 'fotografia', CommunityModality::Online, null, 12, 3, '19:00', ['carlos', 'sofia', 'alejandra', 'javier']],
            ['Miniaturas y Dioramas', 'miniaturas', CommunityModality::InPerson, 'Barcelona', 6, 4, '19:00', ['alejandra']],
            ['Lecturas que Conectan', 'lectura', CommunityModality::Online, null, 20, 2, '20:00', ['marta', 'carmen', 'lucia']],
            ['Bienestar en Equilibrio', 'bienestar', CommunityModality::InPerson, 'Valencia', 15, 7, '10:00', ['lucia']],
        ];

        foreach ($clubs as [$name, $hobby, $modality, $city, $max, $day, $time, $members]) {
            $owner = $profiles[$members[0]];

            $club = Community::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'id' => (string) Str::uuid(),
                    'owner_profile_id' => $owner->getKey(),
                    'name' => $name,
                    'description' => "Club de {$name} para compartir y aprender juntos.",
                    'visibility' => 'public',
                ],
            );

            [$lat, $lng] = $city ? self::CITIES[$city] : [null, null];
            $club->forceFill([
                'hobby_id' => $this->hobbyIds[$hobby],
                'modality' => $modality->value,
                'city' => $city,
                'latitude' => $lat,
                'longitude' => $lng,
                'max_members' => $max,
                'meeting_day' => $day,
                'meeting_time' => $time,
                'timezone' => self::TIMEZONE,
            ])->save();

            foreach ($members as $position => $handle) {
                Membership::query()->updateOrCreate(
                    ['community_id' => $club->getKey(), 'profile_id' => $profiles[$handle]->getKey()],
                    ['role' => $position === 0 ? 'owner' : 'member', 'status' => 'active'],
                );
            }
        }
    }

    /** @param array<string, Profile> $profiles */
    private function seedEvents(array $profiles): void
    {
        // [título, hobby, dueño, días desde hoy, hora, duración en horas, plazas, lugar]
        $events = [
            ['Café y Manualidades', 'arte-y-manualidades', 'elena', 5, '19:00', 2, 12, 'La Botega Café, Barcelona'],
            ['Senderismo al Atardecer', 'senderismo', 'javier', 7, '17:00', 3, 18, 'Parc de Collserola, Barcelona'],
            ['Taller de Fotografía Urbana', 'fotografia', 'carlos', 10, '18:00', 2, 15, 'Centro Cultural, Barcelona'],
            ['Club de lectura online', 'lectura', 'marta', 12, '20:00', 1, null, 'Online'],
            ['Repostería de Primavera', 'reposteria', 'laura', 14, '11:00', 2, 10, 'Espai Creatiu, Barcelona'],
        ];

        foreach ($events as [$title, $hobby, $owner, $days, $time, $hours, $capacity, $place]) {
            $startsAt = Carbon::now(self::TIMEZONE)->addDays($days)->setTimeFromTimeString($time);

            $event = Event::query()->firstOrCreate(
                ['title' => $title],
                [
                    'id' => (string) Str::uuid(),
                    'owner_profile_id' => $profiles[$owner]->getKey(),
                    'state' => 'published',
                    'visibility' => 'public',
                    'timezone' => self::TIMEZONE,
                    'description' => "{$title}: una actividad para conocer gente con tus mismos intereses.",
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addHours($hours),
                    'capacity' => $capacity,
                    'location' => ['name' => $place],
                ],
            );

            $event->forceFill(['hobby_id' => $this->hobbyIds[$hobby]])->save();
        }
    }
}
