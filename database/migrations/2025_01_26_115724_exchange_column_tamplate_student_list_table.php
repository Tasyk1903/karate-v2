<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('template_student_lists', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->integer('age_from')->nullable()->change();
            $table->integer('age_to')->nullable()->change();
            $table->integer('weight_from')->nullable()->change();
            $table->integer('weight_to')->nullable()->change();
            $table->integer('rang_from')->nullable()->change();
            $table->integer('rang_to')->nullable()->change();
            $table->string('gender')->nullable()->change();
            $table->string('list_type')->default('kumite');
            $table->string('kata_type')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('template_student_lists', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
            $table->integer('age_from')->nullable(false)->change();
            $table->integer('age_to')->nullable(false)->change();
            $table->integer('weight_from')->nullable(false)->change();
            $table->integer('weight_to')->nullable(false)->change();
            $table->integer('rang_from')->nullable(false)->change();
            $table->integer('rang_to')->nullable(false)->change();
            $table->string('gender')->nullable(false)->change();
            $table->dropColumn('list_type');
            $table->dropColumn('kata_type');
        });
    }
};
