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
        Schema::create('kata_pools', function (Blueprint $table) {
            $table->id();
            $table->string('participant_number')->nullable();
            $table->foreignId('tournament_id')->constrained('tournaments')->cascadeOnDelete();
            $table->foreignId('list_id')->constrained('list_tournaments')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('referee_score')->nullable();
            $table->string('judge1_score')->nullable();
            $table->string('judge2_score')->nullable();
            $table->string('judge3_score')->nullable();
            $table->string('judge4_score')->nullable();
            $table->float('total_score')->nullable();
            $table->float('min_score')->nullable();
            $table->float('max_score')->nullable();
            $table->integer('rank')->nullable();
            $table->string('tatami')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kata_pools');
    }
};
