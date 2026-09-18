<?php

namespace App\Services\Team;

use App\Models\User;
use App\Models\WaitConfirmationInvitation;
use App\Services\Students\StudentProfileSetup;
use Illuminate\Support\Facades\DB;

final class JoinCoach
{
    public function available(User $student): bool
    {
        return ! $student->trashed() && ! $student->is_external
            && $student->projectRoleNames() === ['Student'] && ! $student->coach_id
            && ! app(StudentProfileSetup::class)->required($student);
    }

    public function preview(User $student, string $code): array
    {
        abort_unless($this->available($student), 409, __('mobile_students.coach_join_unavailable'));

        return $this->summary(app(CoachInvitations::class)->coach($code));
    }

    public function join(User $actor, string $code, int $coachId): User
    {
        return DB::transaction(function () use ($actor, $code, $coachId): User {
            $resolved = app(CoachInvitations::class)->coach($code);
            abort_unless((int) $resolved->id === $coachId, 409, __('mobile_students.coach_join_changed'));
            // Use the same coach -> student locking order as invitation acceptance.
            $coach = User::query()->lockForUpdate()->findOrFail($coachId);
            abort_unless($coach->hasProjectRole('Coach') && ! $coach->is_external, 409, __('mobile_students.coach_join_changed'));
            $student = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless(! $student->is_external && $student->projectRoleNames() === ['Student'], 403);
            // A retry after a lost response must not create another event or account.
            if ((int) $student->coach_id === $coachId) {
                return $student;
            }
            abort_unless($this->available($student), 409, __('mobile_students.coach_join_unavailable'));
            $old = $student->only(['coach_id', 'organization_id']);
            $student->forceFill(['coach_id' => $coachId, 'organization_id' => $coach->organization_id])->save();
            $invitations = WaitConfirmationInvitation::query()->where('target_role', 'Student')
                ->where('inviting_id', $coachId)->where('email', $student->email)->where('confirmed', false)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($invitations as $invitation) {
                $invitation->forceFill(['confirmed' => true, 'accepted_user_id' => $student->id])->save();
            }
            TeamActivity::record($student, 'student.coach.joined', User::class, $student->id,
                ['old' => $old, 'new' => $student->only(['coach_id', 'organization_id']),
                    'confirmed_invitation_ids' => $invitations->modelKeys(), 'source' => 'authenticated_student'], 'mobile');

            return $student;
        }, 3);
    }

    private function summary(User $coach): array
    {
        return ['id' => $coach->id, 'name' => trim($coach->full_name) ?: $coach->name, 'club' => $coach->club];
    }
}
