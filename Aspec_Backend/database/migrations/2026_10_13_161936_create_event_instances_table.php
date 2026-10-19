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
        Schema::create('event_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('category_id')
                ->constrained('event_categories')
                ->restrictOnDelete();

            $table->foreignUuid('location_id')
                ->constrained('locations')
                ->restrictOnDelete();

            $table->string('name');
            $table->date('event_date');
            $table->time('event_start_time');
            $table->time('event_end_time');
            $table->text('description');
            $table->string('address');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedInteger('max_attendees');
            $table->dateTime('registration_deadline');
            $table->text('organizer_notes')->nullable();

            $table->timestamps();
            $table->index(['event_date', 'event_start_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_instances');
    }
};
