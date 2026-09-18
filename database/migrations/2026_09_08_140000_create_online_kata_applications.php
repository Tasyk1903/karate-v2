<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_kata_applications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('active_key', 64)->nullable()->unique();
            $t->unsignedBigInteger('payer_id')->index();
            $t->unsignedBigInteger('student_id');
            $t->unsignedBigInteger('tournament_id');
            $t->unsignedBigInteger('championship_id');
            $t->unsignedBigInteger('category_id');
            $t->string('video_path')->nullable();
            $t->unsignedInteger('amount_minor');
            $t->string('currency', 3)->default('RUB');
            $t->string('shop_id');
            $t->string('provider_id')->nullable()->unique();
            $t->text('payment_url')->nullable();
            $t->json('payload');
            $t->string('status')->default('creating');
            $t->string('error_code')->nullable();
            $t->timestamp('fulfilled_at')->nullable();
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamps();
            $t->index(['payer_id', 'tournament_id', 'created_at'], 'kata_payment_owner_tournament');
            $t->index(['status', 'updated_at'], 'kata_payment_maintenance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_kata_applications');
    }
};
