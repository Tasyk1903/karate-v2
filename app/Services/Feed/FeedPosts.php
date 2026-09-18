<?php

namespace App\Services\Feed;

use App\Jobs\DeleteUnusedFeedMedia;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

final class FeedPosts
{
    public function __construct(private FeedAccess $access) {}

    public function save(User $user, ?int $id, array $data, ?UploadedFile $file): Post
    {
        if ($id) {
            $this->access->post($user, $id, true);
        } elseif (! $user->city_id) {
            throw ValidationException::withMessages(['city_id' => __('feed.city_required')]);
        }
        $newPath = $file?->store('posts', 'public');
        if ($file && ! $newPath) {
            throw new \RuntimeException('Could not store feed attachment.');
        }
        try {
            return DB::transaction(function () use ($user, $id, $data, $file, $newPath) {
                $post = $id ? $this->access->post($user, $id, true, true) : new Post(['user_id' => $user->id, 'city_id' => $user->city_id, 'selected_organization' => $user->selected_organization]);
                $old = $post->getAttributes();
                $text = array_key_exists('text', $data) ? trim((string) $data['text']) : (string) $post->text;
                $path = $file ? $newPath : (! empty($data['remove_media']) ? null : $post->image);
                if ($text === '' && ! $path) {
                    throw ValidationException::withMessages(['text' => __('feed.empty')]);
                }
                $post->fill(['text' => $text, 'image' => $path])->save();
                $admin = $user->hasProjectRole('super_admin');
                TeamActivity::record($user, ($admin ? 'admin' : 'mobile').'.feed.post.'.($id ? 'updated' : 'created'), Post::class, $post->id,
                    ['old' => $id ? $old : null, 'new' => $post->getAttributes()], $admin ? 'admin' : 'panel');
                if (! empty($old['image']) && $old['image'] !== $path) {
                    $this->scheduleCleanup($old['image']);
                }

                return $post;
            }, 3);
        } catch (\Throwable $error) {
            if ($newPath) {
                try {
                    (new DeleteUnusedFeedMedia($newPath))->handle();
                } catch (\Throwable $cleanup) {
                    report($cleanup);
                    Queue::connection('protected_media_cleanup')->push(new DeleteUnusedFeedMedia($newPath));
                }
            }
            throw $error;
        }
    }

    public function delete(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id) {
            $post = $this->access->post($user, $id, true, true);
            $old = $post->getAttributes();
            Reaction::where('reactable_type', (new Comment)->getMorphClass())->whereIn('reactable_id', Comment::where('post_id', $id)->select('id'))->delete();
            $post->reactions()->delete();
            $post->delete();
            $admin = $user->hasProjectRole('super_admin');
            TeamActivity::record($user, ($admin ? 'admin' : 'mobile').'.feed.post.deleted', Post::class, $id,
                ['old' => $old, 'new' => null], $admin ? 'admin' : 'panel');
            if ($old['image']) {
                $this->scheduleCleanup($old['image']);
            }
        }, 3);
    }

    private function scheduleCleanup(string $path): void
    {
        $job = new DeleteUnusedFeedMedia($path);
        Queue::connection('protected_media_cleanup')->push($job);
        DB::afterCommit(function () use ($job) {
            try {
                $job->handle();
            } catch (\Throwable $error) {
                report($error);
            }
        });
    }
}
