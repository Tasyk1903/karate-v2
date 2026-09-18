<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_success_passport')->default(false);
            $table->boolean('is_success_brand')->default(false);
            $table->boolean('is_success_insurance')->default(false);
            $table->boolean('is_success_iko_card')->default(false);
            $table->boolean('is_success_certificate')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_success_passport');
            $table->dropColumn('is_success_brand');
            $table->dropColumn('is_success_insurance');
            $table->dropColumn('is_success_iko_card');
            $table->dropColumn('is_success_certificate');
        });
    }
};
