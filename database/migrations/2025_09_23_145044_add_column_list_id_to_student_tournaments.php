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
        Schema::table('student_tournaments', function (Blueprint $table) {
            $table->foreignId('list_tournament_id')->nullable()->constrained('list_tournaments')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournament_student_lists', function (Blueprint $table) {
            $table->dropForeign(['list_tournament_id']);
            $table->dropColumn('list_tournament_id');
        });
    }
};
