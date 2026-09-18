<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('agreement_id');
            $table->string('version', 64);
            $table->string('agreement_type');
            $table->longText('content');
            $table->timestamp('accepted_at');
            $table->unique(['user_id', 'agreement_id', 'version'], 'agreement_acceptance_version');
        });
        Schema::table('user_alert_user', function (Blueprint $table): void {
            $table->index(['user_id', 'read_at'], 'user_alert_read_status');
        });
    }

    public function down(): void
    {
        Schema::table('user_alert_user', fn (Blueprint $table) => $table->dropIndex('user_alert_read_status'));
        Schema::dropIfExists('agreement_acceptances');
    }
};
