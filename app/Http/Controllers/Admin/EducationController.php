<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\EducationVideos;
use App\Services\Education\EducationCatalog;
use App\Services\ProtectedMedia;
use App\Services\Team\TeamActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EducationController extends Controller
{
    public function __construct(private EducationCatalog $catalog) {}

    private function categories(string $section)
    {
        return $section === 'reviews' ? DB::table('education_klass_categories') : $this->catalog->categories($section);
    }

    public function index(Request $request, string $section)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150', 'page' => 'sometimes|integer|min:1']);

        return response()->json($this->categories($section)->when($data['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))->orderBy('id')->paginate(20));
    }

    public function save(Request $request, string $section, ?int $category = null)
    {
        $query = $this->categories($section);
        $data = $request->validate(['name' => 'required|string|max:255', ...($section === 'reviews' ? ['price' => 'required|integer|min:0|max:1000000'] : [])]);
        DB::transaction(function () use ($request, $query, $section, $category, $data): void {
            $old = $category ? (clone $query)->where('id', $category)->lockForUpdate()->first() : null;
            abort_if($category && ! $old, 404);
            $data['updated_at'] = now();
            if ($category) {
                (clone $query)->where('id', $category)->update($data);
            } else {
                $category = $query->insertGetId($data + ['created_at' => now()] + (! in_array($section, ['reviews', 'competition'], true) ? ['type' => $section] : []));
            }
            TeamActivity::record($request->user(), 'admin.education.category.saved', match ($section) {
                'reviews' => 'App\\Models\\EducationKlassCategory', 'competition' => 'App\\Models\\KataCompetitions', default => 'App\\Models\\EducationKataCategory',
            }, $category, ['old' => (array) $old, 'new' => $data, 'section' => $section], 'admin');
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $section)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($request, $section, $data): void {
            foreach (collect($data['ids'])->sort()->values() as $id) {
                $query = $this->categories($section)->where('id', $id);
                $row = (clone $query)->lockForUpdate()->first();
                abort_unless($row, 404);
                $used = $section === 'reviews'
                    ? DB::table('education_klass_videos')->where('education_klass_category_id', $id)->exists()
                        || DB::table('student_tournaments')->where('education_klass_category_id', $id)->exists()
                    : $this->catalog->videos($section)->where($this->catalog->categoryKey($section), $id)->exists();
                abort_if($used, 409, __('admin.in_use'));
                $query->delete();
                TeamActivity::record($request->user(), 'admin.education.category.deleted', match ($section) {
                    'reviews' => 'App\\Models\\EducationKlassCategory', 'competition' => 'App\\Models\\KataCompetitions', default => 'App\\Models\\EducationKataCategory',
                }, $id, ['old' => (array) $row, 'new' => null, 'section' => $section], 'admin');
            }
        });

        return response()->json(['ok' => true]);
    }

    public function videos(Request $request, string $section, int $category)
    {
        abort_unless($this->catalog->categories($section)->where('id', $category)->exists(), 404);
        $data = $request->validate(['search' => 'nullable|string|max:150', 'page' => 'sometimes|integer|min:1']);
        $rows = $this->catalog->videos($section)->where($this->catalog->categoryKey($section), $category)
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where('title', 'like', '%'.$s.'%'))->orderBy('id')->paginate(20);
        $rows->through(fn ($row) => ['id' => $row->id, 'title' => $row->title,
            'video_url' => "/api/admin/education/{$section}/videos/{$row->id}/file/video",
            'poster_url' => $row->poster_path ? "/api/admin/education/{$section}/videos/{$row->id}/file/poster" : null]);

        return response()->json($rows);
    }

    public function saveVideo(Request $request, string $section, int $category, EducationVideos $videos, ?int $video = null)
    {
        $data = $request->validate(['title' => 'required|string|max:255',
            'video' => [$video ? 'nullable' : 'required', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-msvideo,video/mpeg', 'max:102400'],
            'poster' => 'nullable|image|max:5120']);
        $videos->save($request->user(), $section, $category, $video, $data['title'], $request->file('video'), $request->file('poster'));

        return response()->json(['ok' => true]);
    }

    public function deleteVideos(Request $request, string $section, int $category, EducationVideos $videos)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct', 'confirmed' => 'required|accepted']);
        $videos->delete($request->user(), $section, $category, $data['ids']);

        return response()->json(['ok' => true]);
    }

    public function file(string $section, int $video, string $field, ProtectedMedia $media)
    {
        abort_unless(in_array($field, ['video', 'poster'], true), 404);
        $row = $this->catalog->video($section, $video);

        return $media->response($row->{$field === 'video' ? 'path' : 'poster_path'});
    }
}
