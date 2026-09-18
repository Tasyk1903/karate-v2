<?php

namespace App\Services\Education;

use App\Models\EducationKlassVideo;
use App\Models\User;
use App\Services\Exports\ExportLabels;
use Illuminate\Database\Eloquent\Builder;

final class CoachEducationWorks
{
    public function query(User $coach): Builder
    {
        return EducationKlassVideo::query()->where('is_payment', true)
            ->whereHas('student', fn (Builder $query) => $query->where('coach_id', $coach->id)->role('Student'))
            ->with(['student:id,first_name,last_name,coach_id,rang', 'student.coach:id,first_name,last_name,club', 'category:id,name']);
    }

    public function format(EducationKlassVideo $work, bool $detail = false): array
    {
        $student = $work->student;
        $data = [
            'id' => $work->id, 'title' => $student->full_name,
            'student_id' => $student->id, 'coach_name' => $student->coach?->full_name,
            'club' => $student->coach?->club, 'rank' => ExportLabels::rank($student->rang),
            'category' => $work->category?->name, 'is_review' => (bool) $work->is_review,
        ];
        if ($detail) {
            $data['video_url'] = url("/api/mobile/files/education/works/{$work->id}");
            if ($work->is_review) {
                $data['review'] = $work->only(['description', 'point', 'detail_point', 'recommendation']);
            }
        }

        return $data;
    }
}
