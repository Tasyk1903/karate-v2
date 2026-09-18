<?php

namespace App\Services\Education;

use App\Models\EducationKlassCategory;
use App\Models\EducationKlassVideo;
use App\Models\EducationPayment;
use App\Models\User;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\Kata\KataVideoUpload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class StudentEducationWorks
{
    public function query(User $student): Builder
    {
        $this->authorize($student);

        return EducationKlassVideo::where('student_id', $student->id)->with(['student.coach', 'category'])
            ->withExists(['payments as payment_locked' => fn ($q) => $q->whereIn('status', ['creating', 'pending', 'conflict'])])
            ->addSelect(['legacy_transactions_count' => DB::table('transactions')->selectRaw('COUNT(*)')->whereColumn('education_klass_video_id', 'education_klass_videos.id')]);
    }

    public function authorize(User $student): void
    {
        abort_unless($student->projectRoleNames() === ['Student'] && ! $student->is_external, 403);
        app(EducationAccess::class)->authorize($student, 'works');
    }

    public function mutable(EducationKlassVideo $work): bool
    {
        return ! $work->is_review && ! ($work->payment_locked ?? EducationPayment::where('work_id', $work->id)->whereIn('status', ['creating', 'pending', 'conflict'])->exists());
    }

    public function format(EducationKlassVideo $work, bool $detail = false): array
    {
        return app(CoachEducationWorks::class)->format($work, $detail) + [
            'category_id' => (int) $work->education_klass_category_id,
            'price' => number_format(($work->price_minor ?? round($work->category->price * 100)) / 100, 2, '.', ''),
            'is_payment' => (bool) $work->is_payment,
            'can_edit' => $this->mutable($work),
            'can_delete' => $this->mutable($work) && ! ($work->legacy_transactions_count ?? DB::table('transactions')->where('education_klass_video_id', $work->id)->exists()),
            'can_pay' => ! $work->is_payment && ! $work->is_review,
        ];
    }

    public function save(User $student, Request $request, ?int $id = null): EducationKlassVideo
    {
        $this->authorize($student);
        $rules = KataVideoUpload::rules();
        if ($id) {
            $rules = array_map(fn ($r) => $r === 'required' ? 'nullable' : $r, $rules);
        }
        $data = $request->validate(['category_id' => ['required', 'integer', 'exists:education_klass_categories,id'], 'video' => $rules]);
        if ($id) {
            $this->query($student)->findOrFail($id);
        }
        $path = $request->file('video')?->store('education-works', 'protected');
        $oldPath = null;
        try {
            $work = DB::transaction(function () use ($student, $id, $path, $data, &$oldPath) {
                $student = User::lockForUpdate()->findOrFail($student->id);
                $this->authorize($student);
                $work = $id ? $this->query($student)->lockForUpdate()->findOrFail($id) : new EducationKlassVideo;
                abort_unless(! $id || $this->mutable($work), 409);
                $category = EducationKlassCategory::lockForUpdate()->findOrFail($data['category_id']);
                // A paid service cannot be silently upgraded to a different category.
                abort_unless(! $work->is_payment || (int) $work->education_klass_category_id === (int) $category->id, 409);
                $before = $work->exists ? $work->only(['path', 'education_klass_category_id', 'price_minor']) : null;
                if (! $id) {
                    $work->student_id = $student->id;
                    $work->reviewer_id = app(ReviewAssignment::class)->first();
                }
                if (! $id || (int) $work->education_klass_category_id !== (int) $category->id || $work->price_minor === null) {
                    $work->price_minor = (int) round($category->price * 100);
                }
                $work->education_klass_category_id = $category->id;
                if ($path) {
                    $oldPath = $work->path;
                    $work->path = $path;
                }
                $work->save();
                TeamActivity::record($student, $id ? 'education.work.updated' : 'education.work.created', EducationKlassVideo::class, $work->id,
                    ['old' => $before, 'new' => $work->only(['path', 'education_klass_category_id', 'price_minor']), 'self' => true]);

                return $work;
            }, 3);
        } catch (\Throwable $error) {
            if ($path) {
                Storage::disk('protected')->delete($path);
            }
            throw $error;
        }
        $this->cleanup($oldPath);

        return $work->load(['student.coach', 'category']);
    }

    public function delete(User $student, int $id): void
    {
        $path = DB::transaction(function () use ($student, $id) {
            $student = User::lockForUpdate()->findOrFail($student->id);
            $work = $this->query($student)->lockForUpdate()->findOrFail($id);
            abort_unless($this->mutable($work), 409);
            $before = $work->only(['student_id', 'path', 'education_klass_category_id']);
            // Legacy transaction records require retaining their subject for accounting.
            abort_if(DB::table('transactions')->where('education_klass_video_id', $id)->exists(), 409);
            $work->delete();
            TeamActivity::record($student, 'education.work.deleted', EducationKlassVideo::class, $id, ['old' => $before, 'new' => null, 'self' => true]);

            return $work->path;
        }, 3);
        $this->cleanup($path);
    }

    private function cleanup(?string $path): void
    {
        if ($path && ! EducationKlassVideo::where('path', $path)->exists()) {
            Storage::disk('protected')->delete($path);
        }
    }
}
