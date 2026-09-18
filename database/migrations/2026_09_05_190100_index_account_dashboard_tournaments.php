<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', fn (Blueprint $table) => $table->index(['organization_id', 'deleted_at', 'date', 'id'], 'tournaments_dashboard_date'));
    }

    public function down(): void
    {
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropIndex('tournaments_dashboard_date'));
    }
};
