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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('gender')->nullable();
            $table->string('birthday')->nullable();
            $table->string('passport')->nullable();
            $table->string('brand')->nullable();
            $table->string('insurance')->nullable();
            $table->string('iko_card')->nullable();
            $table->string('avatar')->nullable();
            $table->foreignId('role_id')->nullable()->constrained('roles');
            $table->foreignId('organization_id')->nullable()->constrained('users');
            $table->foreignId('coach_id')->nullable()->constrained('users');
            $table->uuid('ref_token')->nullable()->unique();
            $table->integer('age')->nullable();
            $table->integer('weight')->nullable();
            $table->float('rating')->nullable();
            $table->integer('gold_medals')->nullable();
            $table->integer('silver_medals')->nullable();
            $table->integer('bronze_medals')->nullable();
            $table->integer('wins')->nullable();
            $table->integer('losses')->nullable();
            $table->decimal('balance', 8, 2)->nullable();
            $table->string('rang')->nullable();
            $table->string('club')->nullable();
            $table->boolean('success_politic')->nullable()->default(false);
            $table->softDeletes();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
