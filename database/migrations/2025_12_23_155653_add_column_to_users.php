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
        Schema::table('users', function (Blueprint $table) {
            $table->string('patronymic')->nullable();
            $table->string('number_brand')->nullable();
            $table->string('number_iko')->nullable();
            $table->string('number_certificate')->nullable();
            $table->string('last_examination_date')->nullable();
            $table->string('last_examination_city')->nullable();
            $table->string('last_receiving')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('number_brand');
            $table->dropColumn('number_iko');
            $table->dropColumn('number_certificate');
            $table->dropColumn('last_examination_date');
            $table->dropColumn('last_examination_city');
            $table->dropColumn('last_receiving');
        });
    }
};
