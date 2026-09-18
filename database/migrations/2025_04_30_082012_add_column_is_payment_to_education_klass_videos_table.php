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
        Schema::table('education_klass_videos', function (Blueprint $table) {
            $table->boolean('is_payment')->default(false);
            $table->boolean('is_review')->default(false);
            $table->foreignId('education_klass_category_id')->constrained('education_klass_categories');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('education_klass_videos', function (Blueprint $table) {
            $table->dropColumn('is_payment');
            $table->dropColumn('is_review');
        });
    }
};
