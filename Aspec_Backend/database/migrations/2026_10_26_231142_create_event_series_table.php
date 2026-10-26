<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('frequency', 32);
            $table->date('ends_on');
            $table->unsignedInteger('registration_deadline_days_before');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_series');
    }
};
