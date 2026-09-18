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
        Schema::create('external_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_id')->constrained('championships')->cascadeOnDelete();
            $table->string('organization_name');
            $table->string('token')->unique();
            $table->enum('status', ['open','closed'])->default('open');
            $table->json('data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_forms');
    }
};
