<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->boolean('is_online_kata')->default(false)->after('tournament_type_kata');
        });

        Schema::table('student_tournaments', function (Blueprint $table) {
            $table->string('online_kata_video_path')->nullable()->after('list_tournament_id');
            $table->foreignId('education_klass_category_id')
                ->nullable()
                ->after('online_kata_video_path')
                ->constrained('education_klass_categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_tournaments', function (Blueprint $table) {
            $table->dropForeign(['education_klass_category_id']);
            $table->dropColumn(['online_kata_video_path', 'education_klass_category_id']);
        });

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('is_online_kata');
        });
    }
};
