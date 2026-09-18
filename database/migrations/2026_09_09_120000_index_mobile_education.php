<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_kata_categories', fn (Blueprint $table) => $table->index(['type', 'id'], 'education_section_page'));
        Schema::table('education_klass_videos', fn (Blueprint $table) => $table->index(['student_id', 'is_payment', 'id'], 'education_paid_student'));
        foreach (['education_klass_videos' => ['path'], 'education_kata_videos' => ['path', 'poster_path'], 'kata_competitions_videos' => ['path', 'poster_path']] as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('education_kata_categories', fn (Blueprint $table) => $table->dropIndex('education_section_page'));
        Schema::table('education_klass_videos', fn (Blueprint $table) => $table->dropIndex('education_paid_student'));
        foreach (['education_klass_videos' => ['path'], 'education_kata_videos' => ['path', 'poster_path'], 'kata_competitions_videos' => ['path', 'poster_path']] as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->dropIndex([$column]);
                }
            });
        }
    }
};
