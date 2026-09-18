<?php

namespace App\Services\Education;

use App\Models\EducationKlassVideo;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Validation\ValidationException;

final class ReviewAssignment
{
    public function first(): ?int
    {
        foreach (User::role('Master')->orderBy('id')->lazyById(100) as $user) {
            if ($this->eligible($user)) {
                return $user->id;
            }
        }

        return null;
    }

    public function eligible(?User $user): bool
    {
        return $user && ! $user->is_external && $user->projectRoleNames() === ['Master'];
    }

    public function requireForPayment(User $student, EducationKlassVideo $work): void
    {
        $reviewer = User::lockForUpdate()->find($work->reviewer_id);
        if ($this->eligible($reviewer)) {
            return;
        }
        $id = $this->first();
        $reviewer = $id ? User::lockForUpdate()->find($id) : null;
        if (! $this->eligible($reviewer)) {
            throw ValidationException::withMessages(['payment' => __('education_work.reviewer_unavailable')]);
        }
        $before = $work->reviewer_id;
        $work->update(['reviewer_id' => $reviewer->id]);
        TeamActivity::record($student, 'education.work.assigned', EducationKlassVideo::class, $work->id,
            ['student_id' => $student->id, 'old' => ['reviewer_id' => $before], 'new' => ['reviewer_id' => $reviewer->id], 'source' => 'payment']);
    }
}
