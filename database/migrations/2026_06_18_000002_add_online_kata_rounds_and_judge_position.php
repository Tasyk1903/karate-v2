<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_tournaments', function (Blueprint $table) {
            $table->string('online_kata_first_round_video_path')->nullable()->after('online_kata_video_path');
            $table->foreignId('online_kata_first_round_category_id')
                ->nullable()
                ->after('online_kata_first_round_video_path')
                ->constrained('education_klass_categories')
                ->nullOnDelete();
            $table->string('online_kata_second_round_video_path')->nullable()->after('online_kata_first_round_category_id');
            $table->foreignId('online_kata_second_round_category_id')
                ->nullable()
                ->after('online_kata_second_round_video_path')
                ->constrained('education_klass_categories')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('judge_position')->nullable()->after('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('student_tournaments', function (Blueprint $table) {
            $table->dropForeign(['online_kata_first_round_category_id']);
            $table->dropForeign(['online_kata_second_round_category_id']);
            $table->dropColumn([
                'online_kata_first_round_video_path',
                'online_kata_first_round_category_id',
                'online_kata_second_round_video_path',
                'online_kata_second_round_category_id',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('judge_position');
        });
    }
};
