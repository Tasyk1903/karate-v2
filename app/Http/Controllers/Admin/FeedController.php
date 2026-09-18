<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Feed\FeedPosts;
use Illuminate\Http\Request;

final class FeedController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150', 'page' => 'sometimes|integer|min:1']);
        $rows = Post::query()->with('user:id,name,first_name,last_name,avatar')->withCount('comments')
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('text', 'like', '%'.$s.'%')
                ->orWhereHas('user', fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('last_name', 'like', '%'.$s.'%'))))
            ->latest('id')->paginate(15);
        $rows->through(fn ($post) => ['id' => $post->id, 'text' => $post->text, 'created_at' => $post->created_at,
            'author' => $post->user?->full_name ?: $post->user?->name, 'user_id' => $post->user_id,
            'image' => $post->image ? '/storage/'.ltrim($post->image, '/') : null, 'comments_count' => $post->comments_count]);

        return response()->json($rows);
    }

    public function update(Request $request, int $post, FeedPosts $posts)
    {
        $data = $request->validate(['text' => 'present|nullable|string|max:10000', 'remove_media' => 'sometimes|boolean']);
        $posts->save($request->user(), $post, $data, null);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $post, FeedPosts $posts)
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $posts->delete($request->user(), $post);

        return response()->json(['ok' => true]);
    }
}
