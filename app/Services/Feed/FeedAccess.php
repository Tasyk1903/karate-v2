<?php

namespace App\Services\Feed;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class FeedAccess
{
    public function query(User $user, string $scope = 'all'): Builder
    {
        if ($user->hasProjectRole('super_admin') && ! $user->is_external) {
            return Post::query();
        }

        return Post::query()->where('city_id', $user->city_id)->whereHas('user')
            ->when($user->selected_organization, fn ($q) => $q->where('selected_organization', $user->selected_organization))
            ->when($scope === 'organization', fn ($q) => $q->where('selected_organization', $user->selected_organization ?: $user->organization_id))
            ->when($scope === 'mine', fn ($q) => $q->where('user_id', $user->id))
            ->when($scope === 'students', fn ($q) => $q->whereHas('user', fn ($q) => $q->role('Student')->where('coach_id', $user->id)))
            ->when($scope === 'coaches', fn ($q) => $q->whereHas('user', fn ($q) => $q->role('Coach')));
    }

    public function post(User $user, int $id, bool $owner = false, bool $lock = false): Post
    {
        $post = $this->query($user)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($id);
        abort_if($owner && (int) $post->user_id !== (int) $user->id && ! $user->hasProjectRole('super_admin'), 403);

        return $post;
    }
}
