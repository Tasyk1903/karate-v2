<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Post;
use App\Rules\FeedText;
use App\Services\Feed\FeedAccess;
use App\Services\Feed\FeedDiscussion;
use App\Services\Feed\FeedReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MobileFeedCommentController extends Controller
{
    public function __construct(private FeedAccess $access, private FeedReader $reader, private FeedDiscussion $discussion) {}

    public function index(Request $request, Post $post): JsonResponse
    {
        $this->access->post($request->user(), $post->id);
        $data = $request->validate(['parent_id' => ['nullable', 'integer'], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,30']]);
        $parentId = $data['parent_id'] ?? null;
        if ($parentId) {
            Comment::where('post_id', $post->id)->whereNull('parent_id')->findOrFail($parentId);
        }
        $comments = Comment::where('post_id', $post->id)->where('parent_id', $parentId)->orderBy('id')->paginate($data['per_page'] ?? 15);

        return response()->json(['data' => $this->reader->comments($comments->getCollection(), $request->user()), 'meta' => $this->reader->meta($comments),
            'post' => $this->reader->post($post, $request->user())]);
    }

    public function store(Request $request, Post $post): JsonResponse
    {
        $this->access->post($request->user(), $post->id);
        $data = $this->text($request) + $request->validate(['parent_id' => ['nullable', 'integer']]);

        return $this->result($request, $this->discussion->comment($request->user(), $post->id, $data), 201);
    }

    public function update(Request $request, Comment $comment): JsonResponse
    {
        $this->access->post($request->user(), $comment->post_id);
        abort_unless((int) $comment->user_id === (int) $request->user()->id, 403);

        return $this->result($request, $this->discussion->comment($request->user(), $comment->post_id, $this->text($request), $comment->id));
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        return $this->result($request, $this->discussion->comment($request->user(), $comment->post_id, [], $comment->id, true), deleted: true);
    }

    public function reaction(Request $request, Comment $comment): JsonResponse
    {
        $this->access->post($request->user(), $comment->post_id);
        $data = $request->validate(['type' => ['required', Rule::in([...FeedReader::REACTIONS, 'heart'])]]);

        return $this->result($request, $this->discussion->react($request->user(), $comment->post_id, $data['type'], $comment->id));
    }

    private function text(Request $request): array
    {
        return $request->validate(['text' => ['required', 'string', 'max:2000', new FeedText]]);
    }

    private function result(Request $request, array $result, int $status = 200, bool $deleted = false): JsonResponse
    {
        [$post, $comment] = $result;
        $parent = $comment->parent_id ? Comment::where('post_id', $post->id)->find($comment->parent_id) : null;

        return response()->json(['post' => $this->reader->post($post, $request->user()),
            'parent' => $parent ? $this->reader->comments($parent->newCollection([$parent]), $request->user())->first() : null,
            'comment' => $deleted ? null : $this->reader->comments($comment->newCollection([$comment]), $request->user())->first()], $status);
    }
}
