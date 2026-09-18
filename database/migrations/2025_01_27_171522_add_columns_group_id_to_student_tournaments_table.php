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
        Schema::table('tournament_student_lists', function (Blueprint $table) {
            $table->uuid('group_id')->nullable()->index(); // Для идентификации группы

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournament_student_lists', function (Blueprint $table) {
            $table->dropColumn('group_id');
        });
    }
};
