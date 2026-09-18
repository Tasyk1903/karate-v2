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
        Schema::table('list_tournaments', function (Blueprint $table) {
            $table->unsignedTinyInteger('finalists_count')->nullable()->after('template_student_list_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('list_tournaments', function (Blueprint $table) {
            $table->dropColumn('finalists_count');
        });
    }
};
