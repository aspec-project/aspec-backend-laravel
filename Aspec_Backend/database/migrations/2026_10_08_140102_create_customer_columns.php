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
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            // Sem trial_ends_at: a coluna já existe em users (create_users_table) e é reutilizada.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // trial_ends_at não é apagada aqui: pertence à create_users_table, não a esta migration.
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex([
                'stripe_id',
            ]);

            $table->dropColumn([
                'stripe_id',
                'pm_type',
                'pm_last_four',
            ]);
        });
    }
};
