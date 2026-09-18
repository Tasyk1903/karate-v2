<?php

namespace App\Services\Tournaments\Payments;

use App\Models\OnlineKataApplication;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\CoachTournamentEnrollment;
use App\Services\Tournaments\MobileTournamentAccess;
use App\Services\Tournaments\StudentTournamentListAssignmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class KataPaymentReconciler
{
    public function markDetached(Tournament $tournament, int $studentId, User $actor): void
    {
        if (app(CoachTournamentEnrollment::class)->personalMemberships($tournament, $studentId)->exists()) {
            return;
        }
        $applications = OnlineKataApplication::where('tournament_id', $tournament->id)->where('student_id', $studentId)
            ->where('status', 'fulfilled')->whereNotNull('fulfilled_at')->lockForUpdate()->get();
        foreach ($applications as $a) {
            $a->update(['status' => 'detached']);
            TeamActivity::record($actor, 'online_kata.payment.detached', Tournament::class, $a->tournament_id,
                ['old' => ['status' => 'fulfilled'], 'new' => ['status' => 'detached'],
                    'application_id' => $a->id, 'student_id' => $studentId, 'tournament_id' => $tournament->id]);
        }
    }

    public function apply(OnlineKataApplication $application, array $payment): OnlineKataApplication
    {
        return DB::transaction(function () use ($application, $payment) {
            // Enrollment mutations lock the tournament before the application.
            $tournament = Tournament::withTrashed()->lockForUpdate()->find($application->tournament_id);
            $a = OnlineKataApplication::lockForUpdate()->findOrFail($application->id);
            if ($a->fulfilled_at || in_array($a->status, ['conflict', 'detached', 'canceled'], true)) {
                return $a;
            }
            $old = $a->only(['status', 'provider_id', 'error_code']);
            $value = (string) data_get($payment, 'amount.value', '');
            $validAmount = preg_match('/^\d+\.\d{2}$/D', $value) && (int) str_replace('.', '', $value) === (int) $a->amount_minor;
            $valid = $validAmount && data_get($payment, 'amount.currency') === $a->currency
                && data_get($payment, 'recipient.account_id') === $a->shop_id
                && data_get($payment, 'metadata.applicationId') === $a->id
                && data_get($payment, 'metadata.paymentType') === 'online_kata'
                && filled($payment['id'] ?? null) && (! $a->provider_id || $a->provider_id === $payment['id']);
            if (! $valid) {
                $a->forceFill(['status' => 'conflict', 'error_code' => 'payment_mismatch']);
            } else {
                $a->provider_id = $payment['id'];
                $url = data_get($payment, 'confirmation.confirmation_url');
                if ($url && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https') {
                    $a->payment_url = $url;
                }
                if (($payment['status'] ?? '') === 'canceled') {
                    $a->status = 'canceled';
                    $a->error_code = null;
                } elseif (($payment['status'] ?? '') === 'succeeded' && ($payment['paid'] ?? false) === true) {
                    $payer = User::lockForUpdate()->find($a->payer_id);
                    $student = User::lockForUpdate()->find($a->student_id);
                    User::whereKey($student?->coach_id)->lockForUpdate()->first();
                    $allowed = $tournament && $payer && $student
                        && app(MobileTournamentAccess::class)->canPay($payer, $student, $tournament)
                        && DB::table('education_klass_categories')->where('id', $a->category_id)->exists()
                        && $a->video_path && Storage::disk('protected')->exists($a->video_path)
                        && ! app(CoachTournamentEnrollment::class)->personalMemberships($tournament, $student->id)->exists();
                    if (! $allowed) {
                        $a->status = 'conflict';
                        $a->error_code = 'enrollment_closed';
                    } else {
                        $entry = StudentTournament::firstOrCreate(['tournament_id' => $tournament->id, 'student_id' => $student->id]);
                        $entryOld = $entry->only(['list_tournament_id', 'online_kata_first_round_video_path', 'online_kata_first_round_category_id']);
                        $entry->forceFill(['online_kata_first_round_video_path' => $a->video_path, 'online_kata_first_round_category_id' => $a->category_id])->save();
                        app(StudentTournamentListAssignmentService::class)->assignToBestList($entry, $student, $tournament);
                        TeamActivity::record($payer, 'mobile.online_kata.enrolled', StudentTournament::class, $entry->id,
                            ['old' => $entryOld, 'new' => $entry->only(array_keys($entryOld)), 'application_id' => $a->id, 'tournament_id' => $tournament->id]);
                        $a->status = 'fulfilled';
                        $a->fulfilled_at = now();
                        $a->error_code = null;
                    }
                } elseif (in_array($payment['status'] ?? '', ['pending', 'waiting_for_capture'], true) && $a->status !== 'canceled') {
                    $a->status = 'pending';
                    $a->error_code = null;
                }
            }
            $a->last_checked_at = now();
            $a->save();
            $new = $a->only(array_keys($old));
            if ($old !== $new) {
                TeamActivity::record(User::find($a->payer_id), 'online_kata.payment.'.$a->status, Tournament::class, $a->tournament_id,
                    ['old' => $old, 'new' => $new, 'application_id' => $a->id, 'tournament_id' => $a->tournament_id, 'student_id' => $a->student_id, 'payment_id' => $a->provider_id]);
            }

            return $a;
        }, 3);
    }
}
