<?php

namespace App\Services\Tournaments;

use App\Models\OnlineKataApplication;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Account\PaymentOffer;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\Payments\KataPaymentPayload;
use App\Services\Tournaments\Payments\KataPaymentReconciler;
use App\Services\Tournaments\Payments\YooKassaGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnlineKataPaymentService
{
    public function __construct(private readonly YooKassaGateway $gateway, private readonly KataPaymentReconciler $reconciler) {}

    public function createPayment(Tournament $tournament, User $student, User $payer, string $firstRoundVideoPath, int $firstRoundCategoryId): array
    {
        $retained = false;
        try {
            if (! config('yookassa.shop_id') || ! config('yookassa.api_key')) {
                throw ValidationException::withMessages(['payment' => __('online_kata.not_configured')]);
            }
            $application = DB::transaction(function () use ($tournament, $student, $payer, $firstRoundVideoPath, $firstRoundCategoryId) {
                $tournament = Tournament::lockForUpdate()->findOrFail($tournament->id);
                $payer = User::lockForUpdate()->findOrFail($payer->id);
                $student = User::lockForUpdate()->findOrFail($student->id);
                User::whereKey($student->coach_id)->lockForUpdate()->first();
                if ($payer->projectRoleNames() === ['Student']) {
                    app(PaymentOffer::class)->requireAccepted($payer);
                }
                $key = hash('sha256', $tournament->id.':'.$student->id.':'.$payer->id);
                $existing = OnlineKataApplication::where('active_key', $key)->first();
                if ($existing) {
                    return $existing;
                }
                if (OnlineKataApplication::where('tournament_id', $tournament->id)->where('student_id', $student->id)
                    ->whereNotNull('active_key')->whereIn('status', ['creating', 'pending', 'conflict'])->exists()) {
                    throw ValidationException::withMessages(['payment' => __('online_kata.other_payer_pending')]);
                }
                abort_unless(app(MobileTournamentAccess::class)->canPay($payer, $student, $tournament), 403);
                if (app(CoachTournamentEnrollment::class)->personalMemberships($tournament, $student->id)->exists()) {
                    throw ValidationException::withMessages(['student_id' => __('mobile_tournaments.already_attached')]);
                }
                if (! filter_var($payer->email, FILTER_VALIDATE_EMAIL) || ! DB::table('education_klass_categories')->where('id', $firstRoundCategoryId)->exists()) {
                    throw ValidationException::withMessages(['payment' => __('online_kata.invalid_application')]);
                }
                $a = new OnlineKataApplication([
                    'id' => (string) Str::uuid(), 'active_key' => $key, 'payer_id' => $payer->id, 'student_id' => $student->id,
                    'tournament_id' => $tournament->id, 'championship_id' => $tournament->championship_id,
                    'category_id' => $firstRoundCategoryId, 'video_path' => $firstRoundVideoPath,
                    'amount_minor' => (int) round((float) config('yookassa.online_kata_price', 1000) * 100),
                    'currency' => 'RUB', 'shop_id' => (string) config('yookassa.shop_id'), 'status' => 'creating',
                ]);
                $a->payload = app(KataPaymentPayload::class)->make($a, $student, $payer);
                $a->save();
                TeamActivity::record($payer, 'online_kata.application.created', Tournament::class, $tournament->id,
                    ['old' => null, 'new' => ['status' => 'creating', 'amount_minor' => $a->amount_minor], 'application_id' => $a->id, 'student_id' => $student->id, 'tournament_id' => $tournament->id]);

                return $a;
            });
            $retained = $application->video_path === $firstRoundVideoPath;

            return $this->format($this->sync($application));
        } finally {
            if (! $retained) {
                Storage::disk('protected')->delete($firstRoundVideoPath);
            }
        }
    }

    public function sync(OnlineKataApplication $application): OnlineKataApplication
    {
        if ($application->fulfilled_at) {
            $tournament = Tournament::withTrashed()->find($application->tournament_id);
            if ($application->status !== 'detached' && (! $tournament || ! app(CoachTournamentEnrollment::class)->personalMemberships($tournament, $application->student_id)->exists())) {
                $application->update(['status' => 'detached']);
                TeamActivity::record(User::find($application->payer_id), 'online_kata.payment.detached', Tournament::class, $application->tournament_id,
                    ['old' => ['status' => 'fulfilled'], 'new' => ['status' => 'detached'], 'application_id' => $application->id]);
            }

            return $application;
        }
        if (in_array($application->status, ['canceled', 'conflict', 'detached'], true)) {
            return $application;
        }
        if ($application->last_checked_at?->gt(now()->subSeconds(5))) {
            return $application;
        }
        try {
            if (! $application->provider_id && $application->created_at->lte(now()->subHours(23))) {
                $application->update(['status' => 'conflict', 'error_code' => 'creation_unknown']);
                TeamActivity::record(User::find($application->payer_id), 'online_kata.payment.conflict', Tournament::class, $application->tournament_id,
                    ['old' => ['status' => 'creating'], 'new' => ['status' => 'conflict'], 'application_id' => $application->id, 'reason' => 'creation_unknown']);

                return $application;
            }
            $payment = $application->provider_id ? $this->gateway->fetch($application->provider_id) : $this->gateway->create($application->payload, $application->id);

            return $this->reconciler->apply($application, $payment);
        } catch (\Throwable $error) {
            // An uncertain provider response must not allocate a new idempotency key.
            $application->update(['last_checked_at' => now(), 'error_code' => ($payment['status'] ?? null) === 'succeeded' ? 'enrollment_processing' : 'provider_unavailable']);

            return $application->fresh();
        }
    }

    public function confirmPayment(string $paymentId): bool
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $paymentId)) {
            return false;
        }
        $payment = $this->gateway->fetch($paymentId);
        if (($payment['id'] ?? null) !== $paymentId) {
            return false;
        }
        $a = OnlineKataApplication::where('provider_id', $paymentId)->first()
            ?? OnlineKataApplication::find(data_get($payment, 'metadata.applicationId'));
        if (! $a) {
            TeamActivity::record(null, 'online_kata.legacy_payment_review', OnlineKataApplication::class, 0,
                ['old' => null, 'new' => null, 'payment_id' => $paymentId]);

            return false;
        }

        return $this->reconciler->apply($a, $payment)->status === 'fulfilled';
    }

    public function format(OnlineKataApplication $a): array
    {
        return [
            'id' => $a->id, 'student_id' => $a->student_id, 'tournament_id' => $a->tournament_id,
            'championship_id' => $a->championship_id, 'status' => $a->error_code === 'enrollment_processing' ? 'processing' : $a->status, 'error_code' => $a->error_code,
            'amount' => number_format($a->amount_minor / 100, 2, '.', ''), 'currency' => $a->currency,
            'payment_url' => $a->status === 'pending' && $a->error_code !== 'enrollment_processing' ? $a->payment_url : null,
            'can_retry' => $a->status === 'canceled' && $a->active_key !== null,
            'student_name' => trim(($a->student?->last_name ?? '').' '.($a->student?->first_name ?? '')),
        ];
    }
}
