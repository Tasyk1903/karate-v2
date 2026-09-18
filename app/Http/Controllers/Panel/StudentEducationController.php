<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Education\EducationAccess;
use App\Services\Education\EducationCatalog;
use App\Services\ProtectedMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StudentEducationController extends Controller
{
    public function __construct(private EducationAccess $access, private EducationCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasProjectRole('Student'), 403);

        return response()->json(['data' => collect(EducationAccess::SECTIONS)
            ->reject(fn ($section) => $section === 'works')
            ->filter(fn ($section) => $this->access->allowed($request->user(), $section))
            ->values()]);
    }

    public function categories(Request $request, string $section): JsonResponse
    {
        $this->authorize($request, $section);
        $data = $request->validate(['search' => 'nullable|string|max:100', 'page' => 'sometimes|integer|min:1']);
        $page = $this->catalog->categories($section)->select('id', 'name')
            ->when($data['search'] ?? '', fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(20);

        return response()->json($page);
    }

    public function videos(Request $request, string $section, int $category): JsonResponse
    {
        $this->authorize($request, $section);
        abort_unless($this->catalog->categories($section)->where('id', $category)->exists(), 404);
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $page = $this->catalog->videos($section)->select('id', 'title', 'poster_path')
            ->where($this->catalog->categoryKey($section), $category)->orderBy('id')->paginate(20);
        $page->through(fn ($row) => $this->catalog->formatVideo($section, $row, false));

        return response()->json($page);
    }

    public function file(Request $request, string $section, int $video, string $field, ProtectedMedia $media): Response
    {
        $this->authorize($request, $section);
        abort_unless(in_array($field, ['video', 'poster'], true), 404);
        $row = $this->catalog->video($section, $video);

        return $media->response($row->{$field === 'video' ? 'path' : 'poster_path'});
    }

    private function authorize(Request $request, string $section): void
    {
        abort_unless($request->user()->hasProjectRole('Student') && $section !== 'works', 403);
        $this->access->authorize($request->user(), $section);
    }
}
