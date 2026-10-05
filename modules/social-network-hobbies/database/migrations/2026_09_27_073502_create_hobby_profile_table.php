<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hobbies of each profile, with the level it practises them at. Keeps the
 * file name it had in the host: databases that already ran it recognise it by
 * that name.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('hobby_profile', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('profile_id')->constrained('social_profiles')->cascadeOnDelete();
            $table->foreignId('hobby_id')->constrained('hobbies')->cascadeOnDelete();
            $table->string('level', 16)->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'hobby_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hobby_profile');
    }
};
