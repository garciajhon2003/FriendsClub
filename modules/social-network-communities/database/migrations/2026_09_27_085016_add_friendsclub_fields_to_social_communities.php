<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modality, location, capacity and meeting schedule of a community.
 *
 * Keeps the file name it had in the host, where FriendsClub first added these
 * columns: databases that already ran it recognise it by that name.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('social_communities', function (Blueprint $table) {
            $table->string('modality', 16)->default('online')->index();
            $table->string('city', 120)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('max_members')->nullable();
            $table->unsignedTinyInteger('meeting_day')->nullable();
            $table->time('meeting_time')->nullable();
            $table->string('timezone', 64)->nullable();

            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('social_communities', function (Blueprint $table) {
            $table->dropIndex(['latitude', 'longitude']);
            $table->dropIndex(['modality']);
            $table->dropColumn([
                'modality',
                'city',
                'latitude',
                'longitude',
                'max_members',
                'meeting_day',
                'meeting_time',
                'timezone',
            ]);
        });
    }
};
