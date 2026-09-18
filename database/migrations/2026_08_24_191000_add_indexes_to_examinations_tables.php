<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examinations', function (Blueprint $table) {
            $table->index(['organization_id', 'date'], 'examinations_org_date_idx');
            $table->index(['organization_id', 'city'], 'examinations_org_city_idx');
            $table->index(['organization_id', 'name'], 'examinations_org_name_idx');
        });

        Schema::table('examination_student', function (Blueprint $table) {
            $table->index(['examination_id', 'student_id'], 'exam_student_exam_student_idx');
            $table->index(['student_id', 'examination_id'], 'exam_student_student_exam_idx');
        });
    }

    public function down(): void
    {
        Schema::table('examination_student', function (Blueprint $table) {
            $table->dropIndex('exam_student_exam_student_idx');
            $table->dropIndex('exam_student_student_exam_idx');
        });

        Schema::table('examinations', function (Blueprint $table) {
            $table->dropIndex('examinations_org_date_idx');
            $table->dropIndex('examinations_org_city_idx');
            $table->dropIndex('examinations_org_name_idx');
        });
    }
};
