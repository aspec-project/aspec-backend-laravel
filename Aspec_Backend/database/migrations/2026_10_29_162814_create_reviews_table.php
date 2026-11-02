<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('reviewer_profile_id')
                ->constrained('member_profiles')
                ->restrictOnDelete();

            $table->foreignUuid('reviewed_profile_id')
                ->constrained('member_profiles')
                ->restrictOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->text('comment');

            $table->foreignUuid('status_id')
                ->constrained('review_statuses')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['reviewer_profile_id', 'reviewed_profile_id']);
            $table->index(['reviewed_profile_id', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
