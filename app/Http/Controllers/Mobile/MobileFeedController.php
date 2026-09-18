<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Rules\FeedText;
use App\Services\Feed\FeedAccess;
use App\Services\Feed\FeedDiscussion;
use App\Services\Feed\FeedPosts;
use App\Services\Feed\FeedReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MobileFeedController extends Controller
{
    public function __construct(private FeedAccess $access, private FeedReader $reader, private FeedPosts $posts, private FeedDiscussion $discussion) {}

    public function index(Request $request): JsonResponse
    {
        $scopes = $request->user()->hasProjectRole('Coach') ? ['all', 'mine', 'students', 'coaches', 'organization'] : ['all', 'mine', 'organization'];
        $data = $request->validate(['scope' => ['sometimes', Rule::in($scopes)], 'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,30']]);
        $posts = $this->access->query($request->user(), $data['scope'] ?? 'all')->orderByDesc('id')->paginate($data['per_page'] ?? 10);

        return response()->json(['data' => $this->reader->posts($posts->getCollection(), $request->user()), 'meta' => $this->reader->meta($posts)]);
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        return response()->json(['post' => $this->reader->post($this->access->post($request->user(), $post->id), $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        return $this->save($request, $post);
    }

    private function save(Request $request, ?Post $post = null): JsonResponse
    {
        if ($post) {
            $this->access->post($request->user(), $post->id, true);
        }
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:5000', new FeedText],
            'media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,mp4,mov,avi', 'max:51200'],
            'remove_media' => ['sometimes', 'boolean'],
        ]);
        $saved = $this->posts->save($request->user(), $post?->id, $data, $request->file('media'));

        return response()->json(['post' => $this->reader->post($saved, $request->user())], $post ? 200 : 201);
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->posts->delete($request->user(), $post->id);

        return response()->json(['message' => 'ok']);
    }

    public function toggleReaction(Request $request, Post $post): JsonResponse
    {
        $this->access->post($request->user(), $post->id);
        $data = $request->validate(['type' => ['required', Rule::in([...FeedReader::REACTIONS, 'heart'])]]);
        [$post] = $this->discussion->react($request->user(), $post->id, $data['type']);

        return response()->json(['post' => $this->reader->post($post, $request->user())]);
    }
}
