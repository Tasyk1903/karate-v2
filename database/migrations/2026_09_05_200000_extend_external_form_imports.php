<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_external')->default(false)->index());
        Schema::table('external_form_students', function (Blueprint $table): void {
            $table->index('user_id');
            $table->dropUnique('external_form_students_user_id_unique');
            $table->string('identity_key', 64)->nullable();
            $table->json('profile_snapshot')->nullable();
            $table->index(['external_form_id', 'identity_key']);
        });
        Schema::create('external_form_coaches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_form_id')->constrained()->cascadeOnDelete();
            $table->string('identity_key', 64);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unique(['external_form_id', 'identity_key']);
            $table->timestamps();
        });
        Schema::create('external_form_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->uuid('group_id')->unique();
            $table->unique(['external_form_id', 'tournament_id', 'number'], 'external_group_identity');
            $table->timestamps();
        });
        Schema::table('tournament_student_lists', fn (Blueprint $table) => $table->foreignId('source_external_form_id')->nullable()->constrained('external_forms')->nullOnDelete());
        Schema::create('external_form_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_form_id')->constrained()->cascadeOnDelete();
            $table->uuid('row_id');
            $table->string('category', 30);
            $table->foreignId('tournament_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('tournament_student_lists')->nullOnDelete();
            $table->unique(['external_form_id', 'row_id', 'category'], 'external_application_identity');
            $table->timestamps();
        });
        Schema::create('external_form_import_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('revision', 64);
            $table->boolean('sync_profiles')->default(false);
            $table->string('status')->default('queued');
            $table->json('result')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
            $table->index(['external_form_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_form_import_runs');
        Schema::dropIfExists('external_form_applications');
        Schema::table('tournament_student_lists', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_external_form_id'));
        Schema::dropIfExists('external_form_groups');
        Schema::dropIfExists('external_form_coaches');
        // Multiple rows can now intentionally reference one student; do not restore the old unique constraint.
        Schema::table('external_form_students', function (Blueprint $table): void {
            $table->dropIndex(['external_form_id', 'identity_key']);
            $table->dropColumn(['identity_key', 'profile_snapshot']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_external'));
    }
};
