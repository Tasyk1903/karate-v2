<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_join_codes', function (Blueprint $table): void {
            $table->foreignId('organization_id')->primary()->constrained('users')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->timestamps();
        });
        Schema::table('wait_confirmation_invitations', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['organization_id', 'email', 'confirmed'], 'invitation_org_email_status');
        });
        DB::table('wait_confirmation_invitations')->orderBy('id')->chunkById(500, function ($rows): void {
            $inviters = DB::table('users')->whereIn('id', $rows->pluck('inviting_id'))->get()->keyBy('id');
            foreach ($rows as $row) {
                $inviter = $inviters->get($row->inviting_id);
                if ($inviter) {
                    DB::table('wait_confirmation_invitations')->where('id', $row->id)->update([
                        'email' => mb_strtolower(trim($row->email)),
                        'organization_id' => $inviter->organization_id ?: $inviter->id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('wait_confirmation_invitations', function (Blueprint $table): void {
            $table->dropIndex('invitation_org_email_status');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('accepted_user_id');
        });
        Schema::dropIfExists('organization_join_codes');
    }
};
