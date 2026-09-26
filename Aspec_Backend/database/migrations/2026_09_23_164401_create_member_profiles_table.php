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
        Schema::create('member_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('sector_id')->constrained('sectors');
            $table->foreignUuid('location_id')->constrained('locations');

            $table->string('name'); // Nome Profissional
            $table->string('business_name');
            $table->string('congregation');
            $table->string('role_in_congregation');

            $table->string('logo_path')->nullable();
            $table->text('description')->nullable(); 
            $table->string('website_url')->nullable();
            $table->text('commercial_contacts')->nullable();
            $table->string('address')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_profiles');
    }
};
