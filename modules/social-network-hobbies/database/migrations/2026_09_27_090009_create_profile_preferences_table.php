<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each profile looks for when matched with communities. Keeps the file
 * name it had in the host: databases that already ran it recognise it by that
 * name.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('profile_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('profile_id')->unique()->constrained('social_profiles')->cascadeOnDelete();
            $table->string('modality', 16)->nullable();
            $table->unsignedSmallInteger('max_distance_km')->nullable();
            $table->json('available_days')->nullable();
            $table->json('time_slots')->nullable();
            $table->json('languages')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_preferences');
    }
};
