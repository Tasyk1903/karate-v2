<?php

namespace App\Services\Examinations;

use App\Models\Examination;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CoachExaminationEnrollment
{
    public function authorize(User $coach, Examination $exam): void
    {
        abort_unless($coach->hasProjectRole('Coach') && $coach->organization_id
            && (int) $exam->organization_id === (int) $coach->organization_id, 403);
    }

    public function attach(User $coach, Examination $exam, array $ids): array
    {
        return DB::transaction(function () use ($coach, $exam, $ids) {
            $exam = Examination::query()->lockForUpdate()->findOrFail($exam->id);
            $this->authorize($coach, $exam);
            $students = User::query()->role('Student')->where('coach_id', $coach->id)
                ->where('organization_id', $coach->organization_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'first_name', 'last_name']);
            abort_unless($students->count() === count($ids), 403);
            $attached = array_map('intval', $exam->students()->syncWithoutDetaching($students->modelKeys())['attached'] ?? []);
            if (! $attached) {
                throw ValidationException::withMessages(['student_ids' => __('examinations.already_attached')]);
            }
            TeamActivity::record($coach, 'mobile.examination.students.attached', Examination::class, $exam->id,
                ['organization_id' => $coach->organization_id, 'old' => null, 'new' => ['students' => $students->whereIn('id', $attached)->toArray()], 'selected_ids' => $ids]);

            return $attached;
        });
    }

    public function detach(User $coach, Examination $exam, int $studentId): bool
    {
        return DB::transaction(function () use ($coach, $exam, $studentId) {
            $exam = Examination::query()->lockForUpdate()->findOrFail($exam->id);
            $this->authorize($coach, $exam);
            $student = User::query()->lockForUpdate()->findOrFail($studentId);
            abort_unless($student->hasProjectRole('Student') && (int) $student->coach_id === (int) $coach->id, 403);
            $removed = $exam->students()->detach($student->id) > 0;
            if ($removed) {
                TeamActivity::record($coach, 'mobile.examination.student.detached', Examination::class, $exam->id,
                    ['organization_id' => $coach->organization_id, 'old' => ['students' => [$student->only('id', 'first_name', 'last_name')]], 'new' => null]);
            }

            return $removed;
        });
    }
}
