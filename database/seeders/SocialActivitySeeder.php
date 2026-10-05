<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Liberu\SocialNetwork\Analytics\Models\MetricSnapshot;
use Liberu\SocialNetwork\Communities\Models\Community;
use Liberu\SocialNetwork\Discovery\Models\DiscoveryIndex;
use Liberu\SocialNetwork\Engagement\Models\Engagement;
use Liberu\SocialNetwork\Events\Models\Event;
use Liberu\SocialNetwork\Feed\Models\FeedEntry;
use Liberu\SocialNetwork\Media\Models\MediaAsset;
use Liberu\SocialNetwork\Messaging\Models\Conversation;
use Liberu\SocialNetwork\Messaging\Models\Message;
use Liberu\SocialNetwork\Moderation\Models\ModerationReport;
use Liberu\SocialNetwork\Notifications\Models\SocialNotification;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Liberu\SocialNetwork\Publishing\Models\Publication;
use Liberu\SocialNetwork\SocialGraph\Models\GraphList;
use Liberu\SocialNetwork\SocialGraph\Models\Relationship;
use Ramsey\Uuid\Uuid;

/**
 * Actividad de ejemplo (publicaciones, reacciones, mensajes, notificaciones…) sobre las personas
 * de DemoDataSeeder y sobre cada cuenta real, para que las páginas de /app muestren contenido.
 *
 * Los ids se derivan del contenido (UUID v5), así que volver a ejecutarlo actualiza en vez de duplicar.
 */
class SocialActivitySeeder extends Seeder
{
    private const DEMO_HANDLES = ['alejandra', 'laura', 'marta', 'javier', 'carmen', 'sofia', 'carlos', 'elena', 'lucia', 'pablo'];

    private const ID_NAMESPACE = '6f1c2a52-5b1e-4d0c-9a57-2f3e8b7c1d44';

    /** @var array<string, Profile> handle => perfil */
    private array $demo = [];

    /** @var array<string, Publication> clave => publicación */
    private array $publications = [];

    public function run(): void
    {
        $this->demo = Profile::query()->whereIn('handle', self::DEMO_HANDLES)->get()->keyBy('handle')->all();

        if (count($this->demo) < 4) {
            $this->command?->warn('SocialActivitySeeder: faltan las personas de DemoDataSeeder, no se siembra actividad.');

            return;
        }

        Model::unguarded(function (): void {
            $this->seedDemoPublications();
            $this->seedDemoFollows();

            foreach ($this->realAccounts() as $profile) {
                $this->seedAccount($profile);
            }

            $this->seedModerationReports();
            $this->seedDiscoveryIndex();
            $this->seedAnalytics();
        });
    }

    /** @return Collection<int, Profile> perfiles de las cuentas que no son de ejemplo */
    private function realAccounts(): Collection
    {
        $demoUserIds = collect($this->demo)->pluck('user_id')->all();

        return User::query()->whereNotIn('id', $demoUserIds)->get()->map(fn (User $user): Profile => Profile::query()->firstOrCreate(
            ['user_id' => $user->getKey()],
            ['id' => (string) Uuid::uuid4(), 'handle' => 'user-'.$user->getKey(), 'visibility' => config('social-network-profiles.default_visibility')],
        ));
    }

    private function seedDemoPublications(): void
    {
        // [autora, clave, título, texto, hace cuántas horas]
        $posts = [
            ['laura', 'tarta-limon', null, 'Hoy probé una tarta de limón y merengue para el club. ¡Receta en los comentarios! 🍋', 5],
            ['javier', 'ruta-collserola', 'Ruta por Collserola', 'Este sábado repetimos la ruta del atardecer. Unos 9 km, nivel fácil. ¿Quién se apunta?', 9],
            ['carlos', 'fotos-gotico', null, 'Dejo algunas fotos del paseo por el Gótico. La luz de las 19:00 es increíble para la fotografía urbana.', 20],
            ['marta', 'libro-octubre', 'Lectura de octubre', 'En Lecturas que Conectan votamos el libro de octubre: "La sombra del viento". Nos vemos el jueves.', 30],
            ['alejandra', 'diorama-bosque', null, 'Terminé mi diorama del bosque encantado después de tres semanas. Miniaturas pintadas a mano.', 44],
            ['lucia', 'yoga-playa', null, 'Sesión de yoga en la playa de la Malvarrosa el domingo a las 10:00. Trae tu esterilla.', 60],
            ['elena', 'cafe-manualidades', null, 'Preparando los materiales para Café y Manualidades: cartulinas, washi tape y mucho café.', 80],
            ['sofia', 'primeras-fotos', null, 'Mis primeras fotos con la cámara nueva. Consejos bienvenidos, soy principiante.', 100],
        ];

        foreach ($posts as [$handle, $key, $title, $body, $hoursAgo]) {
            if (isset($this->demo[$handle])) {
                $this->publications[$key] = $this->publish($this->demo[$handle], $key, $title, $body, $hoursAgo);
            }
        }

        // Reacciones y comentarios cruzados entre las personas de ejemplo.
        $this->engage('marta', 'tarta-limon', 'reaction', 'like');
        $this->engage('alejandra', 'tarta-limon', 'comment', null, '¡Qué buena pinta! La probamos el domingo.');
        $this->engage('alejandra', 'ruta-collserola', 'reaction', 'love');
        $this->engage('sofia', 'fotos-gotico', 'comment', null, 'Me encantan. ¿Qué objetivo usaste?');
        $this->engage('carmen', 'libro-octubre', 'reaction', 'like');
        $this->engage('elena', 'diorama-bosque', 'share');
    }

    private function seedDemoFollows(): void
    {
        $follows = [['alejandra', 'laura'], ['laura', 'alejandra'], ['javier', 'alejandra'], ['sofia', 'carlos'], ['carlos', 'sofia'], ['marta', 'carmen'], ['lucia', 'marta']];

        foreach ($follows as [$from, $to]) {
            if (isset($this->demo[$from], $this->demo[$to])) {
                $this->relate($this->demo[$from], $this->demo[$to], 'follow', 'accepted', 'public');
            }
        }
    }

    private function seedAccount(Profile $me): void
    {
        $key = 'u'.$me->user_id;

        // Publicaciones propias → /app/publications
        $own = [
            $this->publish($me, "{$key}-hola", null, '¡Hola a todos! Me acabo de unir a FriendsClub. Me interesan el senderismo y la fotografía.', 3),
            $this->publish($me, "{$key}-ruta", 'Mi primera ruta', 'Ayer hice mi primera ruta con el club de senderismo. Gran ambiente y paisajes preciosos.', 26),
        ];
        $this->publish($me, "{$key}-borrador", 'Ideas para un club de ajedrez', 'Borrador: ¿alguien se animaría a un club de ajedrez los miércoles?', 1, 'draft');

        // Mis reacciones, comentarios y guardados → /app/engagements
        $this->engageAs($me, 'tarta-limon', 'reaction', 'like');
        $this->engageAs($me, 'ruta-collserola', 'comment', null, '¡Yo me apunto! ¿Dónde quedamos?');
        $this->engageAs($me, 'fotos-gotico', 'reaction', 'love');
        $this->engageAs($me, 'libro-octubre', 'bookmark');
        $this->engageAs($me, 'diorama-bosque', 'share');

        // Otras personas reaccionan a lo mío.
        $this->engageOn($this->demo['laura'] ?? null, $own[0], 'reaction', 'like');
        $this->engageOn($this->demo['javier'] ?? null, $own[1], 'comment', null, '¡Bienvenido! El sábado repetimos.');

        // Relaciones → /app/relationships (y dejan de salir como sugerencia)
        foreach (['javier', 'carlos', 'laura'] as $handle) {
            if (isset($this->demo[$handle])) {
                $this->relate($me, $this->demo[$handle], 'follow', 'accepted', 'public');
            }
        }
        if (isset($this->demo['sofia'])) {
            $this->relate($me, $this->demo['sofia'], 'friend', 'pending', 'public');
        }
        foreach (['alejandra', 'marta'] as $handle) {
            if (isset($this->demo[$handle])) {
                $this->relate($this->demo[$handle], $me, 'follow', 'accepted', 'public');
            }
        }

        // Listas → /app/lists
        $this->list($me, "{$key}-senderismo", 'Compañeros de senderismo', ['javier', 'alejandra', 'pablo']);
        $this->list($me, "{$key}-foto", 'Fotografía', ['carlos', 'sofia']);

        // Conversaciones → /app/conversations
        $this->conversation($me, "{$key}-ruta", 'Ruta del sábado', 'javier', [
            ['javier', '¡Hola! ¿Vienes el sábado a Collserola?', 50],
            ['me', 'Sí, cuenta conmigo. ¿A qué hora quedamos?', 45],
            ['javier', 'A las 16:30 en la parada del funicular.', 40],
        ]);
        $this->conversation($me, "{$key}-foto", null, 'carlos', [
            ['carlos', 'Vi que te interesa la fotografía. El taller del jueves tiene plazas libres.', 20],
            ['me', '¡Genial, me apunto!', 18],
        ]);

        // Archivos → /app/assets
        $this->asset($me, "{$key}-ruta.jpg", 'image', 'image/jpeg', 'Vista desde el mirador de Collserola');
        $this->asset($me, "{$key}-grupo.jpg", 'image', 'image/jpeg', 'Foto de grupo del club de senderismo');
        $this->asset($me, "{$key}-video.mp4", 'video', 'video/mp4', 'Vídeo del atardecer');

        // Notificaciones → /app/notifications
        $this->notify($me, "{$key}-follow", 'social.follow', ['from' => 'alejandra', 'message' => 'Alejandra empezó a seguirte'], 2);
        $this->notify($me, "{$key}-reaction", 'social.reaction', ['from' => 'laura', 'message' => 'A Laura le gustó tu publicación'], 3);
        $this->notify($me, "{$key}-comment", 'social.comment', ['from' => 'javier', 'message' => 'Javier comentó tu ruta'], 6);
        $this->notify($me, "{$key}-message", 'social.message', ['from' => 'carlos', 'message' => 'Carlos te envió un mensaje'], 18, read: true);
        $this->notify($me, "{$key}-event", 'social.event.reminder', ['message' => 'Senderismo al Atardecer empieza en 2 días'], 24, read: true);

        // Portada → /app/entries
        $rank = 100;
        foreach ([...array_values($this->publications), ...$own] as $publication) {
            FeedEntry::query()->updateOrCreate(['id' => $this->id("feed-{$key}-{$publication->getKey()}")], [
                'viewer_profile_id' => $me->getKey(),
                'item_type' => $publication->getMorphClass(),
                'item_id' => $publication->getKey(),
                'rank' => $rank--,
                'visible_at' => $publication->published_at,
            ]);
        }
    }

    private function seedModerationReports(): void
    {
        $reports = [
            ['sofia', 'fotos-gotico', 'spam', 'Enlaces repetidos a una tienda en los comentarios.', 'open'],
            ['carmen', 'yoga-playa', 'harassment', 'Comentario ofensivo hacia otra participante.', 'open'],
            ['laura', 'primeras-fotos', 'copyright', 'Una de las fotos parece de otra persona.', 'resolved'],
        ];

        foreach ($reports as [$reporter, $target, $reason, $details, $state]) {
            if (isset($this->demo[$reporter], $this->publications[$target])) {
                ModerationReport::query()->updateOrCreate(['id' => $this->id("report-{$reporter}-{$target}")], [
                    'reporter_profile_id' => $this->demo[$reporter]->getKey(),
                    'target_type' => $this->publications[$target]->getMorphClass(),
                    'target_id' => $this->publications[$target]->getKey(),
                    'reason' => $reason,
                    'details' => $details,
                    'state' => $state,
                ]);
            }
        }
    }

    /** Lo que encuentra /app/search: publicaciones, clubs y eventos públicos. */
    private function seedDiscoveryIndex(): void
    {
        $items = collect()
            ->merge(Publication::query()->where('state', 'published')->where('audience', 'public')->get()
                ->map(fn (Publication $p): array => [$p, $p->author_profile_id, trim(($p->title ? $p->title.'. ' : '').$p->body), $p->published_at]))
            ->merge(Community::query()->where('visibility', 'public')->get()
                ->map(fn (Community $c): array => [$c, $c->owner_profile_id, "{$c->name}. {$c->description}", $c->created_at]))
            ->merge(Event::query()->where('visibility', 'public')->get()
                ->map(fn (Event $e): array => [$e, $e->owner_profile_id, "{$e->title}. {$e->description}", $e->created_at]));

        foreach ($items as [$model, $owner, $body, $publishedAt]) {
            DiscoveryIndex::query()->updateOrCreate(['id' => $this->id("index-{$model->getMorphClass()}-{$model->getKey()}")], [
                'resource_type' => $model->getMorphClass(),
                'resource_id' => $model->getKey(),
                'owner_profile_id' => $owner,
                'visibility' => 'public',
                'body' => $body,
                'terms' => array_values(array_unique(str($body)->lower()->matchAll('/[\p{L}]{4,}/u')->all())),
                'engagement_score' => Engagement::query()->where('target_id', $model->getKey())->count(),
                'published_at' => $publishedAt,
            ]);
        }
    }

    /** Métrica "growth" semanal que lee /app/dashboard (solo la ve quien tenga permiso de analítica). */
    private function seedAnalytics(): void
    {
        foreach (range(5, 0) as $weeksAgo) {
            $end = Carbon::today()->subWeeks($weeksAgo);

            MetricSnapshot::query()->updateOrCreate(
                // Fechas como Carbon: el cast "date" las guarda con hora, y un texto Y-m-d
                // no encontraría la fila al volver a sembrar (choca con el índice único).
                ['metric' => 'growth', 'period_start' => $end->copy()->subWeek(), 'period_end' => $end],
                ['cohort_size' => 12 + (5 - $weeksAgo) * 3, 'value' => 4 + (5 - $weeksAgo) * 2.5, 'dimensions' => ['period' => 'week']],
            );
        }
    }

    private function publish(Profile $author, string $key, ?string $title, string $body, int $hoursAgo, string $state = 'published'): Publication
    {
        return Publication::query()->updateOrCreate(['id' => $this->id("publication-{$key}")], [
            'author_profile_id' => $author->getKey(),
            'kind' => $title ? 'article' : 'post',
            'state' => $state,
            'audience' => 'public',
            'title' => $title,
            'body' => $body,
            'published_at' => $state === 'published' ? now()->subHours($hoursAgo) : null,
        ]);
    }

    private function engage(string $handle, string $publication, string $kind, ?string $reaction = null, ?string $body = null): void
    {
        if (isset($this->demo[$handle], $this->publications[$publication])) {
            $this->engageOn($this->demo[$handle], $this->publications[$publication], $kind, $reaction, $body);
        }
    }

    private function engageAs(Profile $actor, string $publication, string $kind, ?string $reaction = null, ?string $body = null): void
    {
        if (isset($this->publications[$publication])) {
            $this->engageOn($actor, $this->publications[$publication], $kind, $reaction, $body);
        }
    }

    private function engageOn(?Profile $actor, Publication $target, string $kind, ?string $reaction = null, ?string $body = null): void
    {
        if ($actor === null) {
            return;
        }

        Engagement::query()->updateOrCreate(['id' => $this->id("engagement-{$actor->getKey()}-{$target->getKey()}-{$kind}")], [
            'actor_profile_id' => $actor->getKey(),
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
            'kind' => $kind,
            'reaction_type' => $reaction,
            'body' => $body,
        ]);
    }

    private function relate(Profile $from, Profile $to, string $type, string $status, string $visibility): void
    {
        Relationship::query()->updateOrCreate(['id' => $this->id("relationship-{$from->getKey()}-{$to->getKey()}-{$type}")], [
            'source_profile_id' => $from->getKey(),
            'target_profile_id' => $to->getKey(),
            'relationship_type' => $type,
            'status' => $status,
            'visibility' => $visibility,
        ]);
    }

    /** @param list<string> $handles */
    private function list(Profile $owner, string $key, string $name, array $handles): void
    {
        $list = GraphList::query()->updateOrCreate(['id' => $this->id("list-{$key}")], [
            'owner_profile_id' => $owner->getKey(),
            'name' => $name,
            'visibility' => 'private',
        ]);

        $members = collect($handles)->filter(fn (string $handle): bool => isset($this->demo[$handle]))
            ->map(fn (string $handle): string => $this->demo[$handle]->getKey())->all();
        $list->profiles()->syncWithoutDetaching($members);
    }

    /** @param list<array{0: string, 1: string, 2: int}> $messages [quién ('me' o handle), texto, hace cuántas horas] */
    private function conversation(Profile $me, string $key, ?string $title, string $with, array $messages): void
    {
        $other = $this->demo[$with] ?? null;
        if ($other === null) {
            return;
        }

        $conversation = Conversation::query()->updateOrCreate(['id' => $this->id("conversation-{$key}")], [
            'created_by_profile_id' => $other->getKey(),
            'state' => 'active',
            'title' => $title,
        ]);

        foreach ([$me, $other] as $member) {
            DB::table('social_conversation_members')->updateOrInsert(
                ['conversation_id' => $conversation->getKey(), 'profile_id' => $member->getKey()],
                ['read_at' => $member->is($me) ? null : now(), 'created_at' => now(), 'updated_at' => now()],
            );
        }

        foreach ($messages as $index => [$sender, $body, $hoursAgo]) {
            Message::query()->updateOrCreate(['id' => $this->id("message-{$key}-{$index}")], [
                'conversation_id' => $conversation->getKey(),
                'sender_profile_id' => ($sender === 'me' ? $me : $other)->getKey(),
                'body' => $body,
                'state' => 'sent',
                'created_at' => now()->subHours($hoursAgo),
            ]);
        }
    }

    private function asset(Profile $owner, string $file, string $type, string $mime, string $alt): void
    {
        MediaAsset::query()->updateOrCreate(['id' => $this->id("asset-{$file}")], [
            'owner_profile_id' => $owner->getKey(),
            'type' => $type,
            'state' => 'ready',
            'disk' => 'public',
            'path' => "demo/{$file}",
            'mime_type' => $mime,
            'size' => $type === 'video' ? 18_400_000 : 2_300_000,
            'alt_text' => $alt,
        ]);
    }

    /** @param array<string, string> $payload */
    private function notify(Profile $profile, string $key, string $kind, array $payload, int $hoursAgo, bool $read = false): void
    {
        SocialNotification::query()->updateOrCreate(['id' => $this->id("notification-{$key}")], [
            'profile_id' => $profile->getKey(),
            'kind' => $kind,
            'state' => $read ? 'read' : 'unread',
            'channel' => 'in_app',
            'payload' => $payload,
            'read_at' => $read ? now()->subHours($hoursAgo - 1) : null,
            'created_at' => now()->subHours($hoursAgo),
        ]);
    }

    private function id(string $name): string
    {
        return Uuid::uuid5(self::ID_NAMESPACE, $name)->toString();
    }
}
