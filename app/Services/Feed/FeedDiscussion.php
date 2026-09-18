<?php

namespace App\Services\Feed;

use App\Models\Comment;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;

final class FeedDiscussion
{
    public function __construct(private FeedAccess $access) {}

    public function comment(User $user, int $postId, array $data, ?int $commentId = null, bool $delete = false): array
    {
        return DB::transaction(function () use ($user, $postId, $data, $commentId, $delete) {
            $post = $this->access->post($user, $postId, lock: true);
            if ($commentId) {
                $comment = Comment::where('post_id', $postId)->lockForUpdate()->findOrFail($commentId);
                abort_unless((int) $comment->user_id === (int) $user->id || ($user->hasProjectRole('super_admin') && ! $user->is_external), 403);
            } else {
                $parent = ! empty($data['parent_id']) ? Comment::where('post_id', $postId)->findOrFail($data['parent_id']) : null;
                $comment = new Comment(['post_id' => $postId, 'user_id' => $user->id, 'parent_id' => $parent ? ($parent->parent_id ?: $parent->id) : null]);
            }
            $old = $comment->exists ? $comment->getAttributes() : null;
            if ($delete) {
                $ids = Comment::where('parent_id', $comment->id)->pluck('id')->push($comment->id);
                Reaction::where('reactable_type', $comment->getMorphClass())->whereIn('reactable_id', $ids)->delete();
                $comment->delete();
            } else {
                $comment->fill(['text' => trim($data['text'])])->save();
            }
            $admin = $user->hasProjectRole('super_admin');
            TeamActivity::record($user, ($admin ? 'admin' : 'mobile').'.feed.comment.'.($delete ? 'deleted' : ($commentId ? 'updated' : 'created')), Comment::class, $comment->id,
                ['post_id' => $postId, 'old' => $old, 'new' => $delete ? null : $comment->getAttributes()], $admin ? 'admin' : 'panel');

            return [$post, $comment];
        }, 3);
    }

    public function react(User $user, int $postId, string $type, ?int $commentId = null): array
    {
        return DB::transaction(function () use ($user, $postId, $type, $commentId) {
            $post = $this->access->post($user, $postId, lock: true);
            $target = $commentId ? Comment::where('post_id', $postId)->lockForUpdate()->findOrFail($commentId) : $post;
            $old = $target->reactions()->where('user_id', $user->id)->first()?->type;
            $normalized = $old === 'heart' ? 'love' : $old;
            $type = $type === 'heart' ? 'love' : $type;
            $target->reactions()->where('user_id', $user->id)->delete();
            if ($normalized !== $type) {
                $target->reactions()->create(['user_id' => $user->id, 'type' => $type]);
            }
            TeamActivity::record($user, 'mobile.feed.'.($commentId ? 'comment' : 'post').'.reaction', $target::class, $target->id,
                ['post_id' => $postId, 'old' => ['type' => $old], 'new' => ['type' => $normalized === $type ? null : $type]]);

            return [$post, $target];
        }, 3);
    }
}
