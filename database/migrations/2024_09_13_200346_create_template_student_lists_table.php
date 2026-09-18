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
        Schema::create('template_student_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('age_from');
            $table->integer('age_to');
            $table->integer('weight_from');
            $table->integer('weight_to');
            $table->integer('rang_from');
            $table->integer('rang_to');
            $table->string('gender');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('template_student_lists');
    }
};
