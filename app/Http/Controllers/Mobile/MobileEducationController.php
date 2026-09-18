<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Education\CoachEducationWorks;
use App\Services\Education\EducationAccess;
use App\Services\Education\EducationCatalog;
use App\Services\Education\StudentEducationWorks;
use App\Services\ProtectedMedia;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MobileEducationController extends Controller
{
    public function __construct(private EducationAccess $access, private EducationCatalog $catalog, private CoachEducationWorks $works) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => collect(EducationAccess::SECTIONS)
            ->filter(fn ($section) => $this->access->allowed($request->user(), $section))
            ->map(fn ($section) => ['id' => $section])->values()]);
    }

    public function categories(Request $request, string $section): JsonResponse
    {
        $this->access->authorize($request->user(), $section);
        $query = $this->catalog->categories($section)->select('id', 'name');

        return $this->page($request, $query, 'name', fn ($row) => ['id' => $row->id, 'title' => $row->name]);
    }

    public function videos(Request $request, string $section, int $category): JsonResponse
    {
        $this->access->authorize($request->user(), $section);
        $parent = $this->catalog->categories($section)->where('id', $category)->first();
        abort_unless($parent, 404);
        $query = $this->catalog->videos($section)->select('id', 'title', 'path', 'poster_path')
            ->where($this->catalog->categoryKey($section), $category);

        return $this->page($request, $query, 'title', fn ($row) => $this->catalog->formatVideo($section, $row));
    }

    public function works(Request $request): JsonResponse
    {
        $this->access->authorize($request->user(), 'works');
        if ($request->user()->projectRoleNames() === ['Student']) {
            $works = app(StudentEducationWorks::class);

            return $this->page($request, $works->query($request->user()), null, fn ($row) => $works->format($row));
        }
        $query = $this->works->query($request->user())->select('id', 'student_id', 'education_klass_category_id', 'is_review');

        return $this->page($request, $query, null, fn ($row) => $this->works->format($row));
    }

    public function work(Request $request, int $work): JsonResponse
    {
        $this->access->authorize($request->user(), 'works');
        if ($request->user()->projectRoleNames() === ['Student']) {
            $works = app(StudentEducationWorks::class);

            return response()->json(['data' => $works->format($works->query($request->user())->findOrFail($work), true)]);
        }

        return response()->json(['data' => $this->works->format($this->works->query($request->user())->findOrFail($work), true)]);
    }

    public function catalogFile(Request $request, string $section, int $video, string $field, ProtectedMedia $media): Response
    {
        $this->access->authorize($request->user(), $section);
        abort_unless(in_array($field, ['video', 'poster'], true), 404);
        $row = $this->catalog->video($section, $video);

        return $media->response($row->{$field === 'video' ? 'path' : 'poster_path'});
    }

    public function workFile(Request $request, int $work, ProtectedMedia $media): Response
    {
        $this->access->authorize($request->user(), 'works');

        if ($request->user()->projectRoleNames() === ['Student']) {
            return $media->response(app(StudentEducationWorks::class)->query($request->user())->findOrFail($work)->path);
        }

        return $media->response($this->works->query($request->user())->findOrFail($work)->path);
    }

    private function page(Request $request, Builder|EloquentBuilder $query, ?string $searchColumn, callable $format): JsonResponse
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'search' => 'nullable|string|max:100']);
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            if ($searchColumn) {
                $query->where($searchColumn, 'like', '%'.$search.'%');
            } else {
                $query->whereHas('student', fn ($q) => $q->where(fn ($names) => $names
                    ->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')));
            }
        }
        $page = $query->orderBy('id')->paginate(20);

        return response()->json(['data' => $page->getCollection()->map($format)->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }
}
