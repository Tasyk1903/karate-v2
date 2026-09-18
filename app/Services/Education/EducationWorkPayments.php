<?php

namespace App\Services\Education;

use App\Models\EducationKlassVideo;
use App\Models\EducationPayment;
use App\Models\User;
use App\Services\Account\PaymentOffer;
use App\Services\MediaStorage;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\Payments\YooKassaGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EducationWorkPayments
{
    public function __construct(private YooKassaGateway $gateway) {}

    public function create(User $student, int $workId, bool $retry = false): EducationPayment
    {
        if (! config('yookassa.shop_id') || ! config('yookassa.api_key')) {
            throw ValidationException::withMessages(['payment' => __('online_kata.not_configured')]);
        }
        $payment = DB::transaction(function () use ($student, $workId, $retry) {
            $student = User::lockForUpdate()->findOrFail($student->id);
            app(PaymentOffer::class)->requireAccepted($student);
            $work = app(StudentEducationWorks::class)->query($student)->lockForUpdate()->findOrFail($workId);
            abort_if($work->is_payment || $work->is_review, 409);
            $key = 'education:'.$workId;
            $existing = EducationPayment::where('active_key', $key)->lockForUpdate()->first();
            if ($existing && (! $retry || $existing->status !== 'canceled')) {
                return $existing;
            }
            app(ReviewAssignment::class)->requireForPayment($student, $work);
            if ($existing) {
                $existing->update(['active_key' => null]);
            }
            $minor = $work->price_minor ?? (int) round($work->category->price * 100);
            abort_unless($minor > 0 && filter_var($student->email, FILTER_VALIDATE_EMAIL), 422, __('online_kata.invalid_application'));
            $work->update(['price_minor' => $minor]);
            $id = (string) Str::uuid();
            $amount = ['value' => number_format($minor / 100, 2, '.', ''), 'currency' => 'RUB'];
            $description = Str::limit(__('education_work.payment_description').' #'.$work->id, 128, '');
            $a = EducationPayment::create(['id' => $id, 'active_key' => $key, 'work_id' => $workId, 'payer_id' => $student->id,
                'amount_minor' => $minor, 'currency' => 'RUB', 'shop_id' => (string) config('yookassa.shop_id'), 'status' => 'creating',
                'payload' => ['amount' => $amount, 'capture' => true, 'description' => $description,
                    'metadata' => ['paymentType' => 'education_work', 'applicationId' => $id],
                    'confirmation' => ['type' => 'redirect', 'return_url' => url('/online-kata/payment/complete').'?work='.$workId],
                    'receipt' => ['customer' => ['email' => $student->email], 'items' => [[
                        'description' => $description, 'quantity' => '1.00', 'amount' => $amount,
                        'vat_code' => (int) config('yookassa.vat_code', 1), 'payment_mode' => 'full_payment', 'payment_subject' => 'service',
                    ]]],
                ]]);
            $this->log($a, null);

            return $a;
        }, 3);

        return $this->sync($payment);
    }

    public function sync(EducationPayment $a): EducationPayment
    {
        if (in_array($a->status, ['fulfilled', 'canceled', 'conflict'], true) || $a->last_checked_at?->gt(now()->subSeconds(5))) {
            return $a;
        }
        if (! $a->provider_id && $a->created_at->lte(now()->subHours(23))) {
            return DB::transaction(function () use ($a) {
                $locked = EducationPayment::lockForUpdate()->findOrFail($a->id);
                if ($locked->provider_id || $locked->status !== 'creating') {
                    return $locked;
                }
                $old = $locked->status;
                $locked->update(['status' => 'conflict', 'error_code' => 'creation_unknown']);
                $this->log($locked, $old);

                return $locked;
            });
        }
        try {
            $payment = $a->provider_id ? $this->gateway->fetch($a->provider_id) : $this->gateway->create($a->payload, $a->id);

            return $this->apply($a, $payment);
        } catch (\Throwable $error) {
            EducationPayment::whereKey($a->id)->whereIn('status', ['creating', 'pending'])->update(['last_checked_at' => now(), 'error_code' => 'provider_unavailable']);

            return $a->fresh();
        }
    }

    public function confirm(string $id): bool
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $id)) {
            return false;
        }
        $payment = $this->gateway->fetch($id);
        if (($payment['id'] ?? null) !== $id || data_get($payment, 'metadata.paymentType') !== 'education_work') {
            return false;
        }
        $a = EducationPayment::where('provider_id', $id)->first() ?? EducationPayment::find(data_get($payment, 'metadata.applicationId'));
        if (! $a) {
            return false;
        }
        $this->apply($a, $payment);

        return true;
    }

    private function apply(EducationPayment $payment, array $provider): EducationPayment
    {
        return DB::transaction(function () use ($payment, $provider) {
            $student = User::lockForUpdate()->find($payment->payer_id);
            $work = EducationKlassVideo::lockForUpdate()->find($payment->work_id);
            $a = EducationPayment::lockForUpdate()->findOrFail($payment->id);
            if (in_array($a->status, ['fulfilled', 'canceled', 'conflict'], true)) {
                return $a;
            }
            $old = $a->status;
            $value = (string) data_get($provider, 'amount.value', '');
            $valid = preg_match('/^\d+\.\d{2}$/D', $value) && (int) str_replace('.', '', $value) === (int) $a->amount_minor
                && data_get($provider, 'amount.currency') === $a->currency
                && data_get($provider, 'recipient.account_id') === $a->shop_id
                && data_get($provider, 'metadata.applicationId') === $a->id
                && data_get($provider, 'metadata.paymentType') === 'education_work'
                && filled($provider['id'] ?? null) && (! $a->provider_id || $a->provider_id === $provider['id']);
            if (! $valid) {
                $a->status = 'conflict';
                $a->error_code = 'payment_mismatch';
            } else {
                $a->provider_id = $provider['id'];
                if (($provider['status'] ?? '') === 'canceled') {
                    $a->status = 'canceled';
                } elseif (($provider['status'] ?? '') === 'succeeded' && ($provider['paid'] ?? false) === true) {
                    if (! $student || ! $work || $student->projectRoleNames() !== ['Student']
                        || ! app(EducationAccess::class)->allowed($student, 'works') || (int) $work->student_id !== (int) $student->id
                        || $work->is_payment || $work->is_review || ! app(MediaStorage::class)->exists('protected', $work->path)) {
                        $a->status = 'conflict';
                        $a->error_code = 'work_unavailable';
                    } elseif (! app(ReviewAssignment::class)->eligible(User::lockForUpdate()->find($work->reviewer_id))) {
                        $a->status = 'conflict';
                        $a->error_code = 'reviewer_unavailable';
                    } else {
                        $work->update(['is_payment' => true]);
                        $a->status = 'fulfilled';
                        $a->fulfilled_at = now();
                        TeamActivity::record($student, 'education.work.paid', EducationKlassVideo::class, $work->id,
                            ['old' => ['is_payment' => false], 'new' => ['is_payment' => true], 'payment_id' => $a->id]);
                    }
                } else {
                    $a->status = 'pending';
                    $url = data_get($provider, 'confirmation.confirmation_url');
                    if (filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https') {
                        $a->payment_url = $url;
                    }
                }
                if ($a->status !== 'conflict') {
                    $a->error_code = null;
                }
            }
            $a->last_checked_at = now();
            $a->save();
            if ($old !== $a->status) {
                $this->log($a, $old);
            }

            return $a;
        }, 3);
    }

    public function format(EducationPayment $a): array
    {
        return ['id' => $a->id, 'work_id' => (int) $a->work_id, 'status' => $a->status, 'error_code' => $a->error_code,
            'amount' => number_format($a->amount_minor / 100, 2, '.', ''), 'currency' => $a->currency,
            'payment_url' => $a->status === 'pending' ? $a->payment_url : null];
    }

    private function log(EducationPayment $a, ?string $old): void
    {
        TeamActivity::record(User::find($a->payer_id), 'education.payment.'.$a->status, EducationKlassVideo::class, $a->work_id,
            ['old' => ['status' => $old], 'new' => ['status' => $a->status], 'application_id' => $a->id, 'amount_minor' => $a->amount_minor]);
    }
}
