<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preferências dos emails de eventos (ligadas por omissão: o membro desliga-as na conta) e
     * marca da última agenda semanal enviada, para nunca enviar mais do que uma por semana.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notify_new_events')->default(true);
            $table->boolean('notify_weekly_agenda')->default(true);
            $table->timestamp('weekly_agenda_sent_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['notify_new_events', 'notify_weekly_agenda', 'weekly_agenda_sent_at']);
        });
    }
};
