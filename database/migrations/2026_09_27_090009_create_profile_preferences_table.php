<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('profile_preferences', function (Blueprint $table) {
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
};
