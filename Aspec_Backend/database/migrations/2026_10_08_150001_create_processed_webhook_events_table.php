<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eventos do Stripe já processados: stripe_event_id único porque o Stripe pode entregar o mesmo
     * evento mais do que uma vez e só o primeiro é processado.
     */
    public function up(): void
    {
        Schema::create('processed_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processed_webhook_events');
    }
};
