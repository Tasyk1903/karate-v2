<?php

namespace App\Services\Examinations;

use App\Models\Examination;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;

final class StudentExaminationEnrollment
{
    public function allowed(User $student): bool
    {
        return $student->projectRoleNames() === ['Student'] && ! $student->is_external
            && $student->organization_id && User::query()->role('Coach')->whereKey($student->coach_id)
                ->where('organization_id', $student->organization_id)
                ->where('can_attach_to_examination_for_students', true)->exists();
    }

    public function authorize(User $student, Examination $exam): void
    {
        abort_unless($this->allowed($student) && (int) $exam->organization_id === (int) $student->organization_id, 403);
    }

    public function update(User $student, Examination $exam, bool $attach): bool
    {
        return DB::transaction(function () use ($student, $exam, $attach): bool {
            $exam = Examination::query()->lockForUpdate()->findOrFail($exam->id);
            $student = User::query()->lockForUpdate()->findOrFail($student->id);
            User::query()->whereKey($student->coach_id)->lockForUpdate()->first();
            $this->authorize($student, $exam);
            $changed = $attach
                ? count($exam->students()->syncWithoutDetaching([$student->id])['attached']) > 0
                : $exam->students()->detach($student->id) > 0;
            if ($changed) {
                TeamActivity::record($student, $attach ? 'examination.student.self_attached' : 'examination.student.self_detached', Examination::class, $exam->id,
                    ['self' => true, 'student_id' => $student->id, 'organization_id' => $student->organization_id,
                        'old' => ['attached' => ! $attach], 'new' => ['attached' => $attach]]);
            }

            return $changed;
        }, 3);
    }
}
