<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_form_students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_form_id')->constrained()->cascadeOnDelete();
            $table->uuid('row_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['external_form_id', 'row_id']);
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_form_students');
    }
};
