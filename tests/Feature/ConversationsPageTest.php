<?php

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Liberu\SocialNetwork\Messaging\Filament\Pages\Conversations;
use Liberu\SocialNetwork\Messaging\Models\Conversation;
use Liberu\SocialNetwork\Messaging\Models\Message;
use Liberu\SocialNetwork\Messaging\Models\MessageReaction;
use Liberu\SocialNetwork\Profiles\Models\Profile;
use Livewire\Livewire;

use function Pest\Laravel\seed;

beforeEach(function () {
    seed(DemoDataSeeder::class);

    $this->user = User::factory()->create(['name' => 'Tester', 'onboarding_completed_at' => now()]);
    $this->profile = Profile::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $this->user->getKey(),
        'handle' => 'tester',
    ]);
    $this->laura = Profile::query()->where('handle', 'laura')->firstOrFail();
    $this->marta = Profile::query()->where('handle', 'marta')->firstOrFail();

    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/** @param list<Profile> $members */
function chatBetween(Profile $creator, array $members, ?string $title = null): Conversation
{
    $conversation = Conversation::query()->create([
        'id' => (string) Str::uuid(),
        'created_by_profile_id' => $creator->id,
        'title' => $title,
        'state' => 'active',
    ]);

    foreach ([$creator, ...$members] as $member) {
        DB::table('social_conversation_members')->insert([
            'conversation_id' => $conversation->id,
            'profile_id' => $member->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $conversation;
}

function chatMessage(Conversation $conversation, Profile $sender, string $body): Message
{
    return Message::query()->create([
        'id' => (string) Str::uuid(),
        'conversation_id' => $conversation->id,
        'sender_profile_id' => $sender->id,
        'body' => $body,
        'state' => 'sent',
    ]);
}

function isMember(Conversation $conversation, Profile $profile): bool
{
    return DB::table('social_conversation_members')
        ->where(['conversation_id' => $conversation->id, 'profile_id' => $profile->id])
        ->exists();
}

it('renders the conversations page', function () {
    $this->get(route('filament.app.pages.conversations'))->assertOk();
});

it('lists only the conversations I belong to', function () {
    $mine = chatBetween($this->profile, [$this->laura]);
    $others = chatBetween($this->laura, [$this->marta]);

    Livewire::test(Conversations::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$others]);
});

it('starts a conversation with a first message', function () {
    Livewire::test(Conversations::class)
        ->callAction('create', [
            'participants' => [$this->laura->id],
            'title' => 'Ruta del domingo',
            'message' => '¿Vamos a Collserola?',
        ])
        ->assertHasNoFormErrors();

    $conversation = Conversation::where('title', 'Ruta del domingo')->firstOrFail();

    expect(isMember($conversation, $this->profile))->toBeTrue()
        ->and(isMember($conversation, $this->laura))->toBeTrue()
        ->and($conversation->messages()->value('body'))->toBe('¿Vamos a Collserola?');
});

it('opens the chat, marks it read and clears the unread badge', function () {
    $conversation = chatBetween($this->laura, [$this->profile]);
    chatMessage($conversation, $this->laura, 'Hola, ¿te apuntas?');

    expect(Conversations::getNavigationBadge())->toBe('1');

    Livewire::test(Conversations::class)
        ->mountTableAction('open', $conversation)
        ->assertMountedActionModalSee(['Hola, ¿te apuntas?', 'Laura']);

    expect(Conversations::getNavigationBadge())->toBeNull();
});

it('sends replies, including encrypted ones', function () {
    $conversation = chatBetween($this->profile, [$this->laura]);

    Livewire::test(Conversations::class)
        ->mountTableAction('open', $conversation)
        ->set('reply', 'Hola Laura')
        ->call('sendReply')
        ->assertSet('reply', '')
        ->set('reply', 'Mi dirección es secreta')
        ->set('replyEncrypted', true)
        ->call('sendReply')
        ->assertMountedActionModalSee(['Hola Laura', 'Mi dirección es secreta']);

    $encrypted = Message::query()->where('conversation_id', $conversation->id)->whereNotNull('encrypted_body')->firstOrFail();

    expect($encrypted->body)->toBeNull()
        ->and($encrypted->displayBody())->toBe('Mi dirección es secreta')
        ->and(Message::query()->where('body', 'Hola Laura')->exists())->toBeTrue();
});

it('adds and removes a reaction', function () {
    $conversation = chatBetween($this->laura, [$this->profile]);
    $message = chatMessage($conversation, $this->laura, 'Foto del pastel');

    $page = Livewire::test(Conversations::class)
        ->mountTableAction('open', $conversation)
        ->call('toggleReaction', $message->id, '❤️');

    expect(MessageReaction::query()->where(['message_id' => $message->id, 'emoji' => '❤️'])->count())->toBe(1);

    $page->call('toggleReaction', $message->id, '❤️');

    expect(MessageReaction::query()->where('message_id', $message->id)->exists())->toBeFalse();
});

it('deletes my own messages but not other people messages', function () {
    $conversation = chatBetween($this->laura, [$this->profile]);
    $mine = chatMessage($conversation, $this->profile, 'Me equivoqué');
    $hers = chatMessage($conversation, $this->laura, 'Mensaje de Laura');

    Livewire::test(Conversations::class)
        ->mountTableAction('open', $conversation)
        ->call('deleteMessage', $mine->id)
        ->assertNotified('Message deleted')
        ->call('deleteMessage', $hers->id)
        ->assertNotified('You are not allowed to do that.');

    expect(Message::query()->find($mine->id))->toBeNull()
        ->and(Message::query()->find($hers->id))->not->toBeNull();
});

it('lets members add people and the creator remove them', function () {
    $conversation = chatBetween($this->profile, [$this->laura]);

    Livewire::test(Conversations::class)
        ->callTableAction('addPeople', $conversation, ['profiles' => [$this->marta->id]])
        ->assertHasNoTableActionErrors();

    expect(isMember($conversation, $this->marta))->toBeTrue();

    Livewire::test(Conversations::class)
        ->callTableAction('removePerson', $conversation, ['profile' => $this->marta->id])
        ->assertHasNoTableActionErrors();

    expect(isMember($conversation, $this->marta))->toBeFalse();
});

it('lets me leave a conversation and hides removing people from non-creators', function () {
    $conversation = chatBetween($this->laura, [$this->profile, $this->marta]);

    Livewire::test(Conversations::class)
        ->assertTableActionHidden('removePerson', $conversation)
        ->callTableAction('leave', $conversation);

    expect(isMember($conversation, $this->profile))->toBeFalse();
});
