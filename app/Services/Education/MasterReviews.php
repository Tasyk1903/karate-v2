<?php

namespace App\Services\Education;

use App\Models\EducationKlassVideo;
use App\Models\User;
use App\Services\Exports\ExportLabels;
use App\Services\Team\TeamActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MasterReviews
{
    public const FIELDS = ['description', 'point', 'detail_point', 'recommendation', 'is_review'];

    public function query(User $user): Builder
    {
        abort_unless($user->projectRoleNames() === ['Master'] && ! $user->is_external, 403);

        return EducationKlassVideo::where('reviewer_id', $user->id)->where('is_payment', true)
            ->with(['student:id,first_name,last_name,coach_id,rang', 'student.coach:id,first_name,last_name,club', 'category:id,name']);
    }

    public function revision(EducationKlassVideo $work): string
    {
        return hash('sha256', json_encode($work->only([...self::FIELDS, 'student_id', 'reviewer_id', 'is_payment', 'path', 'education_klass_category_id', 'updated_at'])));
    }

    public function format(EducationKlassVideo $work, bool $detail = false): array
    {
        $student = $work->student;
        $data = ['id' => $work->id, 'title' => $student?->full_name ?: __('staff.deleted_student'),
            'coach' => $student?->coach?->full_name, 'club' => $student?->coach?->club,
            'rank' => ExportLabels::rank($student?->rang), 'category' => $work->category?->name,
            'is_review' => (bool) $work->is_review, 'point' => $work->is_review ? $work->point : null,
            'detail_point' => $work->is_review ? $work->detail_point : null];
        if ($detail) {
            $data += ['revision' => $this->revision($work), 'review' => $work->only(self::FIELDS),
                'video_url' => $work->path ? url('/api/mobile/files/master/'.$work->id) : null];
        }

        return $data;
    }

    public function update(User $actor, int $id, Request $request): EducationKlassVideo
    {
        $rules = ['revision' => 'required|string|size:64', 'is_review' => 'required|boolean', 'confirmed' => 'sometimes|boolean'];
        foreach (['description', 'recommendation'] as $field) {
            $rules[$field] = 'present|nullable|string|max:10000';
        }
        // Legacy review marks are text, not tournament scores. Do not invent a grading scale.
        foreach (['point', 'detail_point'] as $field) {
            $rules[$field] = 'present|nullable|string|max:100';
        }
        foreach (['student_id', 'reviewer_id', 'is_payment', 'path', 'education_klass_category_id', 'video'] as $field) {
            $rules[$field] = 'prohibited';
        }
        $data = $request->validate($rules);

        return DB::transaction(function () use ($actor, $id, $data) {
            $actor = User::lockForUpdate()->findOrFail($actor->id);
            $work = $this->query($actor)->lockForUpdate()->findOrFail($id);
            $payload = array_intersect_key($data, array_flip(self::FIELDS));
            $candidate = clone $work;
            $candidate->fill($payload);
            if (! $candidate->isDirty()) {
                return $work;
            }
            abort_unless(hash_equals($this->revision($work), $data['revision']), 409, __('staff.stale'));
            abort_if($work->is_review && ! $data['is_review'] && ! ($data['confirmed'] ?? false), 422, __('staff.confirm_reopen'));
            $before = $work->only(self::FIELDS);
            $work->fill($payload);
            if ($work->isDirty()) {
                $work->save();
                TeamActivity::record($actor, 'education.review.updated', EducationKlassVideo::class, $work->id,
                    ['student_id' => $work->student_id, 'reviewer_id' => $actor->id, 'old' => $before, 'new' => $work->only(self::FIELDS)]);
            }

            return $work->refresh();
        }, 3);
    }
}
