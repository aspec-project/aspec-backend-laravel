<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('event_instances', function (Blueprint $table) {
            $table->boolean('is_online')->default(false);
            $table->string('online_url')->nullable();

            $table->string('address')->nullable()->change();
            $table->decimal('latitude', 10, 7)->nullable()->change();
            $table->decimal('longitude', 10, 7)->nullable()->change();
        });
    }

    public function down(): void
    {
        $hasOnlineEventsWithoutPhysicalAddress = DB::table('event_instances')
            ->whereNull('address')
            ->orWhereNull('latitude')
            ->orWhereNull('longitude')
            ->exists();

        if ($hasOnlineEventsWithoutPhysicalAddress) {
            throw new RuntimeException(
                'Não é possível reverter a migration enquanto houver eventos sem morada ou coordenadas.'
            );
        }

        Schema::table('event_instances', function (Blueprint $table) {
            $table->string('address')->nullable(false)->change();
            $table->decimal('latitude', 10, 7)->nullable(false)->change();
            $table->decimal('longitude', 10, 7)->nullable(false)->change();

            $table->dropColumn(['is_online', 'online_url']);
        });
    }
};
