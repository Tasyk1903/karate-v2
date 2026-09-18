<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_klass_videos', fn (Blueprint $table) => $table->index(['reviewer_id', 'is_payment', 'is_review', 'id'], 'education_reviewer_queue'));
        Schema::table('kata_pools', fn (Blueprint $table) => $table->index(['list_id', 'round', 'id'], 'kata_judge_stage'));
    }

    public function down(): void
    {
        Schema::table('education_klass_videos', fn (Blueprint $table) => $table->dropIndex('education_reviewer_queue'));
        Schema::table('kata_pools', fn (Blueprint $table) => $table->dropIndex('kata_judge_stage'));
    }
};
