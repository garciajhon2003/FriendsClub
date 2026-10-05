<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a profile lives: city, country and optional coordinates. Keeps the file
 * name it had in the host: databases that already ran it recognise it by that
 * name.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('social_profiles', function (Blueprint $table): void {
            $table->string('city', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_profiles', function (Blueprint $table): void {
            $table->dropColumn(['city', 'country_code', 'latitude', 'longitude']);
        });
    }
};
