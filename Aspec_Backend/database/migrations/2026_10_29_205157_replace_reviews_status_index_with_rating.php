<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->index(
                ['reviewed_profile_id', 'status_id', 'rating'],
                'reviews_reviewed_profile_status_rating_index'
            );
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex('reviews_reviewed_profile_id_status_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->index(
                ['reviewed_profile_id', 'status_id'],
                'reviews_reviewed_profile_id_status_id_index'
            );
        });

        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex('reviews_reviewed_profile_status_rating_index');
        });
    }
};
