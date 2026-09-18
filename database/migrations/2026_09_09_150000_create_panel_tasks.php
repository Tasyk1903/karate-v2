<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained('users');
            $t->unsignedBigInteger('organization_id');
            $t->string('kind', 40);
            $t->string('locale', 2);
            $t->string('fingerprint', 64)->index();
            $t->json('context');
            $t->string('status', 20)->default('queued');
            $t->string('path')->nullable();
            $t->string('filename')->nullable();
            $t->string('content_type')->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_tasks');
    }
};
