<?php

namespace App\Services\Feed;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class FeedReader
{
    public const REACTIONS = ['love', 'funny', 'like', 'fire', 'sad'];

    private const AUTHOR = 'user:id,name,first_name,last_name,avatar';

    public function posts(Collection $posts, User $viewer): Collection
    {
        $posts->load(self::AUTHOR);
        $counts = Comment::query()->whereIn('post_id', $posts->modelKeys())->selectRaw('post_id, count(*) as total')->groupBy('post_id')->pluck('total', 'post_id');
        $reactions = $this->reactions(Post::class, $posts->modelKeys(), $viewer);

        return $posts->map(fn ($post) => [
            'id' => $post->id, 'text' => $post->text, 'scope' => $post->selected_organization ? 'organization' : 'all',
            'created_at' => $post->created_at?->toISOString(), 'is_mine' => (int) $post->user_id === (int) $viewer->id,
            'author' => $this->author($post->user),
            'attachment' => $post->image ? ['type' => in_array(Str::lower(pathinfo($post->image, PATHINFO_EXTENSION)), ['mp4', 'mov', 'avi', 'webm']) ? 'video' : 'image', 'url' => asset('storage/'.$post->image)] : null,
            'reactions' => $reactions[$post->id] ?? ['counts' => [], 'selected' => null],
            'comments_count' => (int) ($counts[$post->id] ?? 0),
        ])->values();
    }

    public function post(Post $post, User $viewer): array
    {
        return $this->posts($post->newCollection([$post]), $viewer)->first();
    }

    public function comments(Collection $comments, User $viewer): Collection
    {
        $comments->load(self::AUTHOR);
        $counts = Comment::query()->whereIn('parent_id', $comments->modelKeys())->selectRaw('parent_id, count(*) as total')->groupBy('parent_id')->pluck('total', 'parent_id');
        $reactions = $this->reactions(Comment::class, $comments->modelKeys(), $viewer);

        return $comments->map(fn ($comment) => [
            'id' => $comment->id, 'parent_id' => $comment->parent_id, 'text' => $comment->text,
            'created_at' => $comment->created_at?->toISOString(), 'is_mine' => (int) $comment->user_id === (int) $viewer->id,
            'author' => $this->author($comment->user), 'replies_count' => (int) ($counts[$comment->id] ?? 0),
            'reactions' => $reactions[$comment->id] ?? ['counts' => [], 'selected' => null],
        ])->values();
    }

    private function reactions(string $model, array $ids, User $viewer): array
    {
        $type = (new $model)->getMorphClass();
        $base = Reaction::query()->where('reactable_type', $type)->whereIn('reactable_id', $ids);
        $grouped = (clone $base)->selectRaw('reactable_id, type, count(*) as total')->groupBy('reactable_id', 'type')->get();
        $selected = (clone $base)->where('user_id', $viewer->id)->pluck('type', 'reactable_id');
        $result = [];
        foreach ($ids as $id) {
            $value = $selected[$id] ?? null;
            $result[$id] = ['counts' => [], 'selected' => $value === 'heart' ? 'love' : $value];
        }
        foreach ($grouped as $row) {
            $key = $row->type === 'heart' ? 'love' : $row->type;
            $result[$row->reactable_id]['counts'][$key] = ($result[$row->reactable_id]['counts'][$key] ?? 0) + (int) $row->total;
        }

        return $result;
    }

    private function author(?User $user): array
    {
        return ['id' => $user?->id, 'name' => $user ? (trim($user->full_name) ?: $user->name) : '—',
            'avatar_url' => $user?->avatar ? asset('storage/'.$user->avatar) : null];
    }

    public function meta($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()];
    }
}
