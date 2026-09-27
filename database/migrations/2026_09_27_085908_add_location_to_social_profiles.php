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
        Schema::table('social_profiles', function (Blueprint $table) {
            $table->string('city', 120)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_profiles', function (Blueprint $table) {
            $table->dropColumn(['city', 'country_code', 'latitude', 'longitude']);
        });
    }
};
