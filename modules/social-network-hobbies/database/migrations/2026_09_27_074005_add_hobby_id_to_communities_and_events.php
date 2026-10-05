<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hobby a community or an event is about. A hobby in use cannot be deleted.
 * Keeps the file name it had in the host: databases that already ran it
 * recognise it by that name.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('social_communities', function (Blueprint $table): void {
            $table->foreignId('hobby_id')->nullable()->constrained('hobbies')->restrictOnDelete();
        });

        Schema::table('social_events', function (Blueprint $table): void {
            $table->foreignId('hobby_id')->nullable()->constrained('hobbies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_communities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hobby_id');
        });

        Schema::table('social_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hobby_id');
        });
    }
};
