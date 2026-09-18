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
        Schema::table('pools', function (Blueprint $table) {
            $table->foreignId('winner_id_1rd_robbin')->after('winner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('winner_id_2rd_robbin')->after('winner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('winner_id_3rd_robbin')->after('winner_id')->nullable()->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pools', function (Blueprint $table) {
            $table->dropColumn('winner_id_1rd_robbin');
            $table->dropColumn('winner_id_2rd_robbin');
            $table->dropColumn('winner_id_3rd_robbin');
        });
    }
};
