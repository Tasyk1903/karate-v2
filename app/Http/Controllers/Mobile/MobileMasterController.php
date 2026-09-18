<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Education\MasterReviews;
use App\Services\ProtectedMedia;
use Illuminate\Http\Request;

final class MobileMasterController extends Controller
{
    public function index(Request $request, MasterReviews $reviews)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'search' => 'nullable|string|max:100', 'status' => 'sometimes|in:all,pending,reviewed', 'sort' => 'sometimes|in:id,name']);
        $query = $reviews->query($request->user());
        if (($data['status'] ?? 'all') !== 'all') {
            $query->where('is_review', $data['status'] === 'reviewed');
        }
        if ($search = trim($data['search'] ?? '')) {
            $query->whereHas('student', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')));
        }
        if (($data['sort'] ?? 'id') === 'name') {
            $query->orderBy(User::select('last_name')->whereColumn('users.id', 'education_klass_videos.student_id')->limit(1))
                ->orderBy(User::select('first_name')->whereColumn('users.id', 'education_klass_videos.student_id')->limit(1));
        }
        $page = $query->orderByDesc('id')->paginate(20, ['id', 'student_id', 'education_klass_category_id', 'is_review', 'point', 'detail_point']);

        return response()->json(['data' => $page->getCollection()->map(fn ($work) => $reviews->format($work)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, int $work, MasterReviews $reviews)
    {
        return response()->json(['data' => $reviews->format($reviews->query($request->user())->findOrFail($work), true)])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $work, MasterReviews $reviews)
    {
        return response()->json(['data' => $reviews->format($reviews->update($request->user(), $work, $request), true)]);
    }

    public function video(Request $request, int $work, MasterReviews $reviews, ProtectedMedia $media)
    {
        return $media->response($reviews->query($request->user())->findOrFail($work)->path);
    }
}
