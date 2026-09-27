<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_communities', function (Blueprint $table) {
            $table->foreignId('hobby_id')->nullable()->constrained('hobbies')->restrictOnDelete();
        });

        Schema::table('social_events', function (Blueprint $table) {
            $table->foreignId('hobby_id')->nullable()->constrained('hobbies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('social_communities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hobby_id');
        });

        Schema::table('social_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hobby_id');
        });
    }
};
