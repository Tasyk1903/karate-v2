<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_klass_videos', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_minor')->nullable();
        });
        Schema::create('education_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('work_id')->index();
            $table->unsignedBigInteger('payer_id')->index();
            $table->string('active_key')->nullable()->unique();
            $table->string('provider_id')->nullable()->unique();
            $table->string('status')->default('creating');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('shop_id');
            $table->json('payload');
            $table->text('payment_url')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_payments');
        Schema::table('education_klass_videos', fn (Blueprint $table) => $table->dropColumn('price_minor'));
    }
};
