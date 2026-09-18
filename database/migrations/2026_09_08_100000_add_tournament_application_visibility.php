<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', fn (Blueprint $table) => $table->boolean('accepts_organization_applications')->default(false)->index());
        Schema::table('organization_tournaments', fn (Blueprint $table) => $table->index(['tournament_id', 'applicant_organizer_id'], 'organization_tournament_lookup'));
        Schema::table('tournament_treners', fn (Blueprint $table) => $table->foreignId('organization_application_id')->nullable()->constrained('organization_tournaments')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('tournament_treners', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_application_id'));
        Schema::table('tournaments', fn (Blueprint $table) => $table->dropColumn('accepts_organization_applications'));
        Schema::table('organization_tournaments', fn (Blueprint $table) => $table->dropIndex('organization_tournament_lookup'));
    }
};
