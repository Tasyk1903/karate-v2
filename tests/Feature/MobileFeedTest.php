<?php

namespace Tests\Feature;

use App\Jobs\DeleteUnusedFeedMedia;
use App\Models\Comment;
use App\Models\FeedRegion;
use App\Models\MobileAccessToken;
use App\Models\Post;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Feed\FeedPosts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileFeedTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $org;

    private FeedRegion $city;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        foreach (['Organization', 'Coach', 'Student'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
        $this->city = FeedRegion::create(['city' => 'Moscow']);
        $this->org = $this->user('Organization', ['name' => 'Organization']);
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id, 'city_id' => $this->city->id, 'avatar' => 'avatars/coach.jpg']);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'feed-test'), 'expires_at' => now()->addMonth()]);
        $this->acceptMobileAgreements($this->coach);
        $this->withToken('feed-test');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create($extra + ['first_name' => 'Alex', 'last_name' => 'Author', 'email' => Str::uuid().'@example.test', 'password' => 'password',
            'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function makePost(array $extra = []): Post
    {
        return Post::create($extra + ['user_id' => $this->coach->id, 'city_id' => $this->city->id, 'selected_organization' => null, 'text' => 'Post']);
    }

    private function comment(Post $post, array $extra = []): Comment
    {
        return Comment::create($extra + ['post_id' => $post->id, 'user_id' => $this->coach->id, 'parent_id' => null, 'text' => 'Comment']);
    }

    private function logs(): int
    {
        return DB::table('activity_log')->where('event', 'like', 'mobile.feed.%')->count();
    }

    public static function actorRoles(): array
    {
        return [['Coach'], ['Student']];
    }

    private function actorRole(string $role): void
    {
        $this->coach->update(['role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    public function test_region_and_audience_filters_match_legacy_and_are_not_cosmetic(): void
    {
        $student = $this->user('Student', ['coach_id' => $this->coach->id]);
        $otherStudent = $this->user('Student', ['coach_id' => $this->user('Coach')->id]);
        $mine = $this->makePost();
        $child = $this->makePost(['user_id' => $student->id, 'selected_organization' => $this->org->id]);
        $this->makePost(['user_id' => $otherStudent->id]);
        $this->makePost(['city_id' => FeedRegion::create(['city' => 'Other'])->id]);
        $this->getJson('/api/mobile/feed')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/mobile/feed?scope=students')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $child->id);
        $this->getJson('/api/mobile/feed?scope=coaches')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->getJson('/api/mobile/feed?scope=organization')->assertOk()->assertJsonPath('data.0.id', $child->id);
        $this->getJson('/api/mobile/feed?scope=mine')->assertOk()->assertJsonPath('data.0.id', $mine->id);
        $this->coach->forceFill(['selected_organization' => $this->org->id])->save();
        $this->getJson('/api/mobile/feed')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $child->id);
        $this->coach->forceFill(['city_id' => null])->save();
        $this->getJson('/api/mobile/feed')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/mobile/feed', ['text' => 'New'])->assertUnprocessable();
        $this->getJson('/api/mobile/feed?scope=unknown')->assertUnprocessable();
        $this->getJson('/api/mobile/feed?per_page=100000')->assertUnprocessable();
    }

    public function test_settings_persist_validate_organizations_and_paginate_options(): void
    {
        for ($i = 0; $i < 25; $i++) {
            FeedRegion::create(['city' => 'Region'.$i]);
        }
        $this->getJson('/api/mobile/feed/settings/options?type=cities')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/mobile/feed/settings/options?type=cities&page=2')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/mobile/feed/settings/options?type=cities&search=Moscow')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/mobile/feed/settings/options?type=organizations')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.email');
        $this->putJson('/api/mobile/feed/settings', ['city_id' => $this->city->id, 'organization_id' => $this->coach->id])->assertUnprocessable();
        $this->putJson('/api/mobile/feed/settings', ['city_id' => 99999, 'organization_id' => null])->assertUnprocessable();
        $this->putJson('/api/mobile/feed/settings', ['city_id' => $this->city->id, 'organization_id' => $this->org->id])->assertOk();
        $this->getJson('/api/mobile/feed/settings')->assertOk()->assertJsonPath('organization.id', $this->org->id);
        $this->assertSame($this->org->id, (int) $this->coach->fresh()->selected_organization);
        $this->putJson('/api/mobile/feed/settings', ['city_id' => $this->city->id, 'organization_id' => null])->assertOk()->assertJsonPath('organization', null);
        $this->assertSame(2, $this->logs());
    }

    #[DataProvider('actorRoles')]
    public function test_every_hidden_post_and_comment_action_denies_before_read_or_mutation(string $role): void
    {
        $this->actorRole($role);
        $hidden = $this->makePost(['city_id' => FeedRegion::create(['city' => 'Hidden'])->id]);
        $comment = $this->comment($hidden);
        $this->getJson('/api/mobile/feed/'.$hidden->id)->assertNotFound();
        $this->getJson('/api/mobile/feed/'.$hidden->id.'/comments')->assertNotFound();
        $this->postJson('/api/mobile/feed/'.$hidden->id.'/comments', ['text' => 'Attack'])->assertNotFound();
        $this->postJson('/api/mobile/feed/'.$hidden->id.'/reaction', ['type' => 'love'])->assertNotFound();
        $this->putJson('/api/mobile/feed/'.$hidden->id, ['text' => 'Attack'])->assertNotFound();
        $this->deleteJson('/api/mobile/feed/'.$hidden->id)->assertNotFound();
        $this->putJson('/api/mobile/feed/comments/'.$comment->id, ['text' => 'Attack'])->assertNotFound();
        $this->deleteJson('/api/mobile/feed/comments/'.$comment->id)->assertNotFound();
        $this->postJson('/api/mobile/feed/comments/'.$comment->id.'/reaction', ['type' => 'fire'])->assertNotFound();
        $this->assertSame('Comment', $comment->fresh()->text);
        $this->assertSame('Post', $hidden->fresh()->text);
        $this->assertDatabaseCount('comments', 1);
        $this->assertDatabaseCount('reactions', 0);
        $this->assertSame(0, $this->logs());
    }

    #[DataProvider('actorRoles')]
    public function test_visible_foreign_records_are_readable_but_editing_requires_owner(string $role): void
    {
        $this->actorRole($role);
        $other = $this->user('Coach');
        $post = $this->makePost(['user_id' => $other->id]);
        $comment = $this->comment($post, ['user_id' => $other->id]);
        $this->getJson('/api/mobile/feed/'.$post->id)->assertOk()->assertJsonPath('post.is_mine', false);
        $this->putJson('/api/mobile/feed/'.$post->id, ['text' => 'Attack'])->assertForbidden();
        $this->deleteJson('/api/mobile/feed/'.$post->id)->assertForbidden();
        $this->putJson('/api/mobile/feed/comments/'.$comment->id, ['text' => 'Attack'])->assertForbidden();
        $this->deleteJson('/api/mobile/feed/comments/'.$comment->id)->assertForbidden();
        $this->assertSame(0, $this->logs());
    }

    #[DataProvider('actorRoles')]
    public function test_discussions_replies_counts_and_queries_are_bounded(string $role): void
    {
        $this->actorRole($role);
        $post = $this->makePost();
        $root = $this->comment($post);
        for ($i = 0; $i < 30; $i++) {
            $this->comment($post);
        }
        for ($i = 0; $i < 140; $i++) {
            $this->comment($post, ['parent_id' => $root->id]);
        }
        $this->getJson('/api/mobile/feed')->assertOk()->assertJsonPath('data.0.comments_count', 171)
            ->assertJsonMissingPath('data.0.comments')->assertJsonMissingPath('data.0.author.email');
        $this->getJson('/api/mobile/feed/'.$post->id.'/comments')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('data.0.replies_count', 140)->assertJsonMissingPath('data.0.replies');
        $this->getJson('/api/mobile/feed/'.$post->id.'/comments?page=3')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/mobile/feed/'.$post->id.'/comments?parent_id='.$root->id.'&page=10')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 140);
        DB::enableQueryLog();
        $this->getJson('/api/mobile/feed')->assertOk();
        $single = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 9; $i++) {
            $this->makePost();
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/mobile/feed')->assertOk();
        $this->assertLessThanOrEqual($single + 1, count(DB::getQueryLog()));
        $this->assertLessThan(15000, strlen($response->getContent()));
        DB::disableQueryLog();
        $this->makePost();
        $this->getJson('/api/mobile/feed?page=2')->assertOk()->assertJsonCount(1, 'data');
    }

    #[DataProvider('actorRoles')]
    public function test_comment_reply_edit_delete_and_all_legacy_reactions_update_immediately(string $role): void
    {
        $this->actorRole($role);
        $post = $this->makePost();
        $root = $this->postJson('/api/mobile/feed/'.$post->id.'/comments', ['text' => 'First'])->assertCreated()->assertJsonPath('post.comments_count', 1)->json('comment.id');
        $reply = $this->postJson('/api/mobile/feed/'.$post->id.'/comments', ['text' => 'Reply', 'parent_id' => $root])->assertCreated()->json('comment.id');
        $this->postJson('/api/mobile/feed/'.$post->id.'/comments', ['text' => 'Nested', 'parent_id' => $reply])->assertCreated()->assertJsonPath('comment.parent_id', $root);
        $this->putJson('/api/mobile/feed/comments/'.$reply, ['text' => 'Changed'])->assertOk()->assertJsonPath('comment.text', 'Changed');
        foreach (['love', 'funny', 'like', 'fire', 'sad'] as $type) {
            $this->postJson('/api/mobile/feed/'.$post->id.'/reaction', ['type' => $type])->assertOk()->assertJsonPath('post.reactions.selected', $type)->assertJsonPath('post.reactions.counts.'.$type, 1);
            $this->postJson('/api/mobile/feed/comments/'.$reply.'/reaction', ['type' => $type])->assertOk()->assertJsonPath('comment.reactions.selected', $type);
        }
        $this->postJson('/api/mobile/feed/'.$post->id.'/reaction', ['type' => 'sad'])->assertOk()->assertJsonPath('post.reactions.selected', null);
        $this->postJson('/api/mobile/feed/'.$post->id.'/reaction', ['type' => 'heart'])->assertOk()->assertJsonPath('post.reactions.selected', 'love');
        $this->deleteJson('/api/mobile/feed/comments/'.$root)->assertOk()->assertJsonPath('post.comments_count', 0);
        $this->assertDatabaseCount('comments', 0);
        $this->assertSame(0, Reaction::where('reactable_type', Comment::class)->count());
        $this->assertGreaterThan(10, $this->logs());
    }

    #[DataProvider('actorRoles')]
    public function test_parent_cannot_belong_to_another_post(string $role): void
    {
        $this->actorRole($role);
        $a = $this->makePost();
        $b = $this->makePost();
        $parent = $this->comment($b);
        $this->postJson('/api/mobile/feed/'.$a->id.'/comments', ['text' => 'Bad reference', 'parent_id' => $parent->id])->assertNotFound();
        $this->getJson('/api/mobile/feed/'.$a->id.'/comments?parent_id='.$parent->id)->assertNotFound();
        $this->assertDatabaseCount('comments', 1);
    }

    #[DataProvider('actorRoles')]
    public function test_text_moderation_covers_create_edit_comments_and_replies_without_losing_media(string $role): void
    {
        $this->actorRole($role);
        $this->assertNotEmpty(config('mat_phrases'));
        config(['mat_phrases' => ['blocked phrase']]);
        Storage::disk('public')->put('posts/old.jpg', 'old');
        $post = $this->makePost(['image' => 'posts/old.jpg']);
        $root = $this->comment($post);
        $reply = $this->comment($post, ['parent_id' => $root->id]);
        $this->postJson('/api/mobile/feed', ['text' => 'BLOCKED PHRASE'])->assertUnprocessable();
        $this->putJson('/api/mobile/feed/'.$post->id, ['text' => 'blocked phrase', 'remove_media' => true])->assertUnprocessable();
        foreach ([null, $root->id] as $parent) {
            $this->postJson('/api/mobile/feed/'.$post->id.'/comments', ['text' => 'blocked phrase', 'parent_id' => $parent])->assertUnprocessable();
        }
        foreach ([$root, $reply] as $comment) {
            $this->putJson('/api/mobile/feed/comments/'.$comment->id, ['text' => 'blocked phrase'])->assertUnprocessable();
        }
        $this->postJson('/api/mobile/feed/'.$post->id.'/comments', ['text' => '   '])->assertUnprocessable();
        Storage::disk('public')->assertExists('posts/old.jpg');
        $this->assertSame('posts/old.jpg', $post->fresh()->image);
        $this->assertSame(0, $this->logs());
    }

    #[DataProvider('actorRoles')]
    public function test_final_post_state_is_validated_and_replacement_cleanup_keeps_current_file(string $role): void
    {
        $this->actorRole($role);
        Storage::disk('public')->put('posts/old.jpg', 'old');
        $post = $this->makePost(['text' => '', 'image' => 'posts/old.jpg']);
        $this->putJson('/api/mobile/feed/'.$post->id, ['text' => '', 'remove_media' => true])->assertUnprocessable();
        Storage::disk('public')->assertExists('posts/old.jpg');
        $this->assertSame('posts/old.jpg', $post->fresh()->image);
        $file = UploadedFile::fake()->image('new.jpg');
        $this->post('/api/mobile/feed/'.$post->id, ['text' => '', 'media' => $file], ['Accept' => 'application/json'])->assertOk();
        $new = $post->fresh()->image;
        $this->assertNotSame('posts/old.jpg', $new);
        Storage::disk('public')->assertExists($new);
        (new DeleteUnusedFeedMedia('posts/old.jpg'))->handle();
        Storage::disk('public')->assertMissing('posts/old.jpg');
        (new DeleteUnusedFeedMedia($new))->handle();
        Storage::disk('public')->assertExists($new);
        $this->putJson('/api/mobile/feed/'.$post->id, ['text' => 'Kept text', 'remove_media' => true])->assertOk()->assertJsonPath('post.attachment', null);
        (new DeleteUnusedFeedMedia($new))->handle();
        Storage::disk('public')->assertMissing($new);
        $this->assertDatabaseCount('jobs', 2);
    }

    #[DataProvider('actorRoles')]
    public function test_database_failure_compensates_new_upload_and_preserves_previous_state(string $role): void
    {
        $this->actorRole($role);
        Storage::disk('public')->put('posts/old.jpg', 'old');
        $post = $this->makePost(['image' => 'posts/old.jpg']);
        Post::updating(fn () => throw new \RuntimeException('Simulated database failure'));
        try {
            app(FeedPosts::class)->save($this->coach, $post->id, ['text' => 'Changed'], UploadedFile::fake()->image('new.jpg'));
            $this->fail('Expected failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated database failure', $error->getMessage());
        }
        $this->assertSame(['posts/old.jpg'], Storage::disk('public')->allFiles('posts'));
        $this->assertSame('Post', $post->fresh()->text);
        $this->assertSame(0, $this->logs());
    }

    #[DataProvider('actorRoles')]
    public function test_deleting_post_removes_discussion_reactions_and_schedules_file_cleanup(string $role): void
    {
        $this->actorRole($role);
        Storage::disk('public')->put('posts/delete.jpg', 'old');
        $post = $this->makePost(['image' => 'posts/delete.jpg']);
        $comment = $this->comment($post);
        $reply = $this->comment($post, ['parent_id' => $comment->id]);
        foreach ([$post, $comment, $reply] as $target) {
            $target->reactions()->create(['type' => 'love', 'user_id' => $this->coach->id]);
        }
        $this->deleteJson('/api/mobile/feed/'.$post->id)->assertOk();
        $this->assertDatabaseCount('comments', 0);
        $this->assertDatabaseCount('reactions', 0);
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
        $this->assertDatabaseCount('jobs', 1);
        (new DeleteUnusedFeedMedia('posts/delete.jpg'))->handle();
        Storage::disk('public')->assertMissing('posts/delete.jpg');
        $this->assertSame(1, $this->logs());
    }

    #[DataProvider('actorRoles')]
    public function test_audit_failure_rolls_back_created_post_and_compensates_upload(string $role): void
    {
        $this->actorRole($role);
        DB::listen(function ($event) {
            if (str_starts_with(strtolower($event->sql), 'insert into') && str_contains($event->sql, 'activity_log')) {
                throw new \RuntimeException('Audit unavailable');
            }
        });
        try {
            app(FeedPosts::class)->save($this->coach, null, ['text' => 'Post'], UploadedFile::fake()->image('new.jpg'));
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Audit unavailable', $error->getMessage());
        }
        $this->assertDatabaseCount('posts', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('posts'));
        $this->assertSame(0, $this->logs());
    }
}
