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
        Schema::table('kata_pools', function (Blueprint $table) {
            $table->boolean('winner_1')->default(false);
            $table->boolean('winner_2')->default(false);
            $table->boolean('winner_3')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kata_pools', function (Blueprint $table) {
            $table->dropColumn('winner_1');
            $table->dropColumn('winner_2');
            $table->dropColumn('winner_3');
        });
    }
};
