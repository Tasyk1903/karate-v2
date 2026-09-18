<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_student_lists', function (Blueprint $table) {
            $table->index(['user_id', 'sort_order', 'name'], 'tsl_owner_sort_idx');
            $table->index(['user_id', 'list_type', 'kata_type', 'gender'], 'tsl_owner_type_idx');
            $table->index(['user_id', 'age_from', 'age_to', 'weight_from', 'weight_to'], 'tsl_owner_age_weight_idx');
            $table->index(['user_id', 'rang_from', 'rang_to'], 'tsl_owner_rank_idx');
        });
    }

    public function down(): void
    {
        Schema::table('template_student_lists', function (Blueprint $table) {
            $table->dropIndex('tsl_owner_sort_idx');
            $table->dropIndex('tsl_owner_type_idx');
            $table->dropIndex('tsl_owner_age_weight_idx');
            $table->dropIndex('tsl_owner_rank_idx');
        });
    }
};
