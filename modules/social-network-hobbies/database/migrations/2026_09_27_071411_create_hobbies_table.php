<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hobby catalog. Keeps the file name it had in the host: databases that
 * already ran it recognise it by that name.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('hobbies', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->string('icon')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hobbies');
    }
};
