<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('social_engagements', function (Blueprint $table): void {
            $table->index('actor_profile_id', 'social_engagements_actor_profile_id_index');
            $table->dropUnique('social_engagement_unique_actor_target');
        });
    }

    public function down(): void
    {
        Schema::table('social_engagements', function (Blueprint $table): void {
            $table->unique(['actor_profile_id', 'target_type', 'target_id', 'kind', 'reaction_type'], 'social_engagement_unique_actor_target');
            $table->dropIndex('social_engagements_actor_profile_id_index');
        });
    }
};
