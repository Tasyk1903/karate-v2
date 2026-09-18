<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pools', function (Blueprint $table) {
            $table->unsignedTinyInteger('student_wazari_count')->default(0)->after('absent_opponent');
            $table->unsignedTinyInteger('opponent_wazari_count')->default(0)->after('student_wazari_count');
            $table->boolean('student_ippon')->default(false)->after('opponent_wazari_count');
            $table->boolean('opponent_ippon')->default(false)->after('student_ippon');
        });
    }

    public function down(): void
    {
        Schema::table('pools', function (Blueprint $table) {
            $table->dropColumn([
                'student_wazari_count',
                'opponent_wazari_count',
                'student_ippon',
                'opponent_ippon',
            ]);
        });
    }
};
