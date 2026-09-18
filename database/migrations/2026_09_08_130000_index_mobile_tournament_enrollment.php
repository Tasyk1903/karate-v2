<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_tournaments', fn (Blueprint $t) => $t->index(['tournament_id', 'student_id', 'id'], 'mobile_enrollment_student'));
        Schema::table('tournament_student_lists', fn (Blueprint $t) => $t->index(['student_id', 'group_id', 'list_tournament_id'], 'mobile_personal_membership'));
        Schema::table('users', fn (Blueprint $t) => $t->index(['coach_id', 'deleted_at', 'last_name', 'first_name', 'id'], 'mobile_coach_student_search'));
    }

    public function down(): void
    {
        Schema::table('student_tournaments', fn (Blueprint $t) => $t->dropIndex('mobile_enrollment_student'));
        Schema::table('tournament_student_lists', fn (Blueprint $t) => $t->dropIndex('mobile_personal_membership'));
        Schema::table('users', fn (Blueprint $t) => $t->dropIndex('mobile_coach_student_search'));
    }
};
