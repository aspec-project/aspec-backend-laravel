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
        Schema::create('event_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('event_instance_id')
                ->constrained('event_instances')
                ->restrictOnDelete();

            $table->foreignUuid('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->unique(['event_instance_id', 'user_id']);
            $table->index('user_id');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
    }
};
