<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Services\Feed\FeedDiscussion;
use Illuminate\Http\Request;

final class CommentController extends Controller
{
    public function index(Request $request, int $post)
    {
        Post::findOrFail($post);
        $request->validate(['page' => 'sometimes|integer|min:1']);
        $rows = Comment::where('post_id', $post)->with('user:id,name,first_name,last_name')->orderBy('id')->paginate(25);
        $rows->through(fn ($comment) => ['id' => $comment->id, 'text' => $comment->text, 'parent_id' => $comment->parent_id,
            'author' => $comment->user?->full_name ?: $comment->user?->name, 'created_at' => $comment->created_at]);

        return response()->json($rows);
    }

    public function update(Request $request, int $post, int $comment, FeedDiscussion $discussion)
    {
        $data = $request->validate(['text' => 'required|string|max:5000']);
        $discussion->comment($request->user(), $post, $data, $comment);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $post, int $comment, FeedDiscussion $discussion)
    {
        $request->validate(['confirmed' => 'required|accepted']);
        $discussion->comment($request->user(), $post, [], $comment, true);

        return response()->json(['ok' => true, 'comments_count' => Post::findOrFail($post)->comments()->count()]);
    }
}
