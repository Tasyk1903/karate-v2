<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agreements', fn (Blueprint $table) => $table->text('description_en')->nullable());
        Schema::table('agreement_acceptances', fn (Blueprint $table) => $table->string('locale', 2)->default('ru'));
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->unsignedBigInteger('target_user_id')->nullable()->index();
            $table->index(['causer_id', 'id'], 'activity_actor_page');
            $table->index(['created_at', 'id'], 'activity_date_page');
        });
    }

    public function down(): void
    {
        Schema::table('agreements', fn (Blueprint $table) => $table->dropColumn('description_en'));
        Schema::table('agreement_acceptances', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_actor_page');
            $table->dropIndex('activity_date_page');
            $table->dropColumn('target_user_id');
        });
    }
};
