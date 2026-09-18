<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coach_join_codes', function (Blueprint $table) {
            $table->foreignId('coach_id')->primary()->constrained('users')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->timestamps();
        });
        Schema::table('wait_confirmation_invitations', function (Blueprint $table) {
            $table->string('target_role', 16)->default('Coach');
            $table->index(['inviting_id', 'target_role', 'confirmed', 'email'], 'invitation_coach_pending');
        });
        DB::table('wait_confirmation_invitations')->whereIn('inviting_id', User::withTrashed()->role('Coach')->select('id'))
            ->update(['target_role' => 'Student', 'organization_id' => null]);
    }

    public function down(): void
    {
        Schema::table('wait_confirmation_invitations', function (Blueprint $table) {
            $table->dropIndex('invitation_coach_pending');
            $table->dropColumn('target_role');
        });
        Schema::dropIfExists('coach_join_codes');
    }
};
