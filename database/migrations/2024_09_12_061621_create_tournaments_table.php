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
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('region_id')->nullable()->constrained('regions')->nullOnDelete();
            $table->foreignId('scale_id')->nullable()->constrained('scales')->nullOnDelete();
            $table->integer('age_from');
            $table->integer('age_to');
            $table->dateTime('date_commission');
            $table->integer('tatami');
            $table->boolean('KY_up_to_8')->default(false);
            $table->boolean('KY_from_8')->default(false);
            $table->boolean('fight_for_third_place')->default(false);
            $table->integer('price');
            $table->string('address');
            $table->dateTime('date');
            $table->dateTime('date_finish');
            $table->string('regulation_document')->nullable();
            $table->string('application_document')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
