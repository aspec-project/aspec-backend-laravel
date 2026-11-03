<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('review_id')
                ->constrained('reviews')
                ->cascadeOnDelete();

            $table->foreignUuid('reporter_profile_id')
                ->nullable()
                ->constrained('member_profiles')
                ->nullOnDelete();

            $table->string('reason', 100);
            $table->text('comment')->nullable();
            $table->string('source', 16);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(
                ['review_id', 'source', 'resolved_at'],
                'review_reports_review_source_resolved_index'
            );
        });

        $flaggedStatusId = DB::table('review_statuses')
            ->where('name', 'Flagged')
            ->value('id');

        if ($flaggedStatusId === null) {
            return;
        }

        $now = now();

        DB::table('reviews')
            ->where('status_id', $flaggedStatusId)
            ->orderBy('id')
            ->chunkById(500, function ($reviews) use ($now): void {
                DB::table('review_reports')->insert(
                    $reviews->map(fn (object $review): array => [
                        'id' => (string) Str::uuid(),
                        'review_id' => $review->id,
                        'reporter_profile_id' => null,
                        'reason' => 'language_filter',
                        'comment' => null,
                        'source' => 'automatic',
                        'resolved_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            }, 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
    }
};
