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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('education_klass_video_id')->constrained('education_klass_videos');
            $table->integer('amount');
            $table->string('transaction_hash')->nullable();
            $table->boolean('status');
            $table->text('description')->nullable();

            $table->string('payment_status')->nullable();
            $table->string('payment_id')->nullable();
            $table->string('payment_paid')->nullable();
            $table->string('payment_amount')->nullable();
            $table->string('payment_order_id')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
