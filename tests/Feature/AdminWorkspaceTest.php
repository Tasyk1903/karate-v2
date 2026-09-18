<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use App\Services\Account\Agreements;
use App\Services\Team\TeamActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $id = DB::table('roles')->where('name', $role)->value('id');
        if (! $id) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insertOrIgnore(['id' => $id, 'name' => $role]);
        }

        return User::forceCreate(['name' => $role, 'first_name' => $role, 'last_name' => 'Person',
            'email' => uniqid().'@example.test', 'password' => Hash::make('Password1234'), 'role_id' => $id]);
    }

    public function test_landing_and_role_redirects(): void
    {
        $this->get('/')->assertOk();
        config(['mobile_app.ios_url' => null, 'mobile_app.android_url' => null]);
        $this->getJson('/api/public/app-links')->assertOk()
            ->assertExactJson(['ios' => null, 'android' => null, 'contact_email' => __('about.contacts.email')]);
        $this->actingAs($this->user('Organization'))->get('/')->assertRedirect('/panel');
        $this->actingAs($this->user('super_admin'))->get('/')->assertRedirect('/panel/admin/feed');
        $this->getJson('/api/auth/user')->assertOk()->assertJsonPath('user.capabilities.super_admin', true);
    }

    public function test_every_admin_section_is_closed_to_non_admin_roles(): void
    {
        foreach (['Organization', 'Secretary', 'Coach', 'Student', 'Judge', 'Master', 'Admin'] as $role) {
            $this->actingAs($this->user($role));
            foreach (['feed', 'agreements', 'organizations', 'activity', 'directories/regions', 'directories/scales', 'education/kihon'] as $path) {
                $this->getJson('/api/admin/'.$path)->assertForbidden();
            }
            $this->postJson('/api/admin/directories/regions', ['name' => 'Forbidden'])->assertForbidden();
            $this->putJson('/api/admin/agreements/1', [])->assertForbidden();
            $this->deleteJson('/api/admin/organizations', ['ids' => [1], 'confirmed' => true])->assertForbidden();
            $this->get('/api/admin/education/kihon/videos/1/file/video')->assertForbidden();
        }
        $this->assertDatabaseMissing('regions', ['name' => 'Forbidden']);
    }

    public function test_pivot_admin_role_works_without_legacy_role(): void
    {
        $admin = $this->user('super_admin');
        DB::table('model_has_roles')->insert(['model_type' => User::class, 'model_id' => $admin->id, 'role_id' => $admin->role_id]);
        $admin->forceFill(['role_id' => null])->save();
        $this->actingAs($admin)->getJson('/api/admin/organizations')->assertOk();
    }

    public function test_mixed_admin_organization_role_does_not_loop_through_consent_gate(): void
    {
        $admin = $this->user('super_admin');
        $organization = $this->user('Organization');
        DB::table('model_has_roles')->insert(['model_type' => User::class, 'model_id' => $admin->id, 'role_id' => $organization->role_id]);
        DB::table('agreements')->insert(['id' => 2, 'type' => 'privacy_policy', 'description' => 'Policy']);
        $this->actingAs($admin)->getJson('/api/auth/user')->assertOk()->assertJsonPath('user.agreements_required', false);
        $this->get('/')->assertRedirect('/panel/admin/feed');
        $this->getJson('/api/admin/agreements')->assertOk();
    }

    public function test_moderator_sees_other_cities_and_edits_without_changing_author(): void
    {
        $student = $this->user('Student');
        $city = DB::table('feed_regions')->insertGetId(['city' => 'City']);
        $post = Post::create(['user_id' => $student->id, 'text' => 'Original', 'city_id' => $city]);
        $admin = $this->user('super_admin');
        $this->actingAs($admin)->getJson('/api/admin/feed')->assertOk()->assertJsonPath('data.0.id', $post->id);
        $this->putJson('/api/admin/feed/'.$post->id, ['text' => 'Moderated'])->assertOk();
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'user_id' => $student->id, 'text' => 'Moderated']);
        $this->assertDatabaseHas('activity_log', ['causer_id' => $admin->id, 'target_user_id' => $student->id, 'subject_id' => $post->id]);
        $this->deleteJson('/api/admin/feed/'.$post->id, ['confirmed' => true])->assertOk();
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_bilingual_agreement_versions_sanitization_and_acceptance_snapshot(): void
    {
        $admin = $this->user('super_admin');
        $body = ['version' => null, 'description' => '<p>Русский текст</p><script>alert(1)</script>', 'description_en' => '<h2>English terms</h2><a href="javascript:alert(1)">bad</a>'];
        $this->actingAs($admin)->putJson('/api/admin/agreements/2', $body)->assertOk();
        $document = DB::table('agreements')->find(2);
        $this->assertStringNotContainsString('script', $document->description);
        $this->assertStringNotContainsString('javascript', $document->description_en);
        $service = app(Agreements::class);
        $version = $service->version($document);
        $student = $this->user('Student');
        app()->setLocale('en');
        $service->accept($student, 2, $version);
        $this->assertDatabaseHas('agreement_acceptances', ['user_id' => $student->id, 'locale' => 'en', 'content' => $document->description_en]);
        app()->setLocale('ru');
        $this->assertTrue($service->pending($student)->isEmpty());
        $this->actingAs($admin)->putJson('/api/admin/agreements/2', $body)->assertConflict();
        $this->putJson('/api/admin/agreements/2', array_replace($body, ['version' => $version, 'description_en' => '<p>New terms</p>']))->assertOk();
        $this->assertTrue($service->pending($student)->isNotEmpty());
        $this->actingAs($student)->withHeader('Accept-Language', 'en')->getJson('/api/panel/account/agreements/2')->assertOk()->assertJsonPath('content', '<p>New terms</p>');
    }

    public function test_organizations_create_update_codes_and_dependency_protection(): void
    {
        $this->user('Organization');
        $admin = $this->user('super_admin');
        $this->actingAs($admin)->postJson('/api/admin/organizations', ['name' => 'Federation', 'email' => 'fed@example.test', 'password' => 'Password1234'])->assertOk();
        $org = User::where('email', 'fed@example.test')->firstOrFail();
        $this->assertTrue($org->hasProjectRole('Organization'));
        $this->assertDatabaseHas('organization_join_codes', ['organization_id' => $org->id]);
        $this->putJson('/api/admin/organizations/'.$org->id, ['name' => 'Updated', 'email' => 'fed@example.test', 'password' => 'NewPassword123'])->assertOk();
        $this->assertTrue(Hash::check('NewPassword123', $org->fresh()->password));
        $coach = $this->user('Coach');
        $coach->forceFill(['organization_id' => $org->id])->save();
        $this->deleteJson('/api/admin/organizations', ['ids' => [$org->id], 'confirmed' => true])->assertConflict();
        $this->assertNotNull(User::find($org->id));
    }

    public function test_directories_and_bulk_delete_are_atomic(): void
    {
        $this->actingAs($this->user('super_admin'))->postJson('/api/admin/directories/regions', ['name' => 'Region A'])->assertOk();
        $id = DB::table('regions')->where('name', 'Region A')->value('id');
        $this->putJson('/api/admin/directories/regions/'.$id, ['name' => 'Region B'])->assertOk();
        $this->deleteJson('/api/admin/directories/regions', ['ids' => [$id, 999999], 'confirmed' => true])->assertNotFound();
        $this->assertDatabaseHas('regions', ['id' => $id, 'name' => 'Region B']);
        $scale = DB::table('scales')->whereNotNull('slug')->first();
        $this->putJson('/api/admin/directories/scales/'.$scale->id, ['name' => 'New scale name', 'slug' => 'invalid'])->assertOk();
        $this->assertDatabaseHas('scales', ['id' => $scale->id, 'slug' => $scale->slug]);
        $this->deleteJson('/api/admin/directories/scales', ['ids' => [$scale->id], 'confirmed' => true])->assertConflict();
    }

    public function test_catalog_scoping_upload_replace_delete_and_private_media(): void
    {
        $this->actingAs($this->user('super_admin'));
        foreach (['kata_attestation', 'kihon', 'ido_geiko', 'competition', 'reviews'] as $section) {
            $this->postJson('/api/admin/education/'.$section, ['name' => $section, 'price' => 1200])->assertOk();
            $this->getJson('/api/admin/education/'.$section)->assertOk()->assertJsonPath('data.0.name', $section);
        }
        $category = DB::table('education_kata_categories')->where('type', 'kihon')->value('id');
        $base = '/api/admin/education/kihon/'.$category.'/videos';
        $this->post($base, ['title' => 'Technique', 'video' => UploadedFile::fake()->create('lesson.mp4', 20, 'video/mp4'), 'poster' => UploadedFile::fake()->image('cover.jpg')], ['Accept' => 'application/json'])->assertOk();
        $row = DB::table('education_kata_videos')->first();
        Storage::disk('protected')->assertExists($row->path);
        Storage::disk('public')->assertMissing($row->path);
        $this->get('/api/admin/education/kihon/videos/'.$row->id.'/file/video')->assertOk();
        $this->get('/api/admin/education/ido_geiko/videos/'.$row->id.'/file/video')->assertNotFound();
        $this->post($base.'/'.$row->id, ['title' => 'Revised', 'video' => UploadedFile::fake()->create('new.mp4', 20, 'video/mp4')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('protected')->assertMissing($row->path);
        Storage::disk('protected')->assertMissing($row->poster_path);
        $this->deleteJson($base, ['ids' => [$row->id], 'confirmed' => true])->assertOk();
        $this->assertDatabaseMissing('education_kata_videos', ['id' => $row->id]);
    }

    public function test_catalog_replacement_rolls_back_both_record_and_new_file_if_audit_fails(): void
    {
        $this->actingAs($this->user('super_admin'));
        $category = DB::table('education_kata_categories')->insertGetId(['name' => 'Kihon', 'type' => 'kihon']);
        Storage::disk('protected')->put('videos/old.mp4', 'old video');
        $id = DB::table('education_kata_videos')->insertGetId(['title' => 'Old', 'education_kata_category_id' => $category, 'path' => 'videos/old.mp4']);
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into "activity_log"')) {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        $this->post('/api/admin/education/kihon/'.$category.'/videos/'.$id,
            ['title' => 'New', 'video' => UploadedFile::fake()->create('new.mp4', 20, 'video/mp4')], ['Accept' => 'application/json'])->assertServerError();
        $this->assertDatabaseHas('education_kata_videos', ['id' => $id, 'title' => 'Old', 'path' => 'videos/old.mp4']);
        $this->assertSame(['videos/old.mp4'], Storage::disk('protected')->allFiles());
    }

    public function test_category_delete_and_comment_moderation_preserve_access_boundaries(): void
    {
        $admin = $this->user('super_admin');
        $student = $this->user('Student');
        $city = DB::table('feed_regions')->insertGetId(['city' => 'City']);
        $post = Post::create(['user_id' => $student->id, 'text' => 'Post', 'city_id' => $city]);
        $comment = Comment::create(['user_id' => $student->id, 'post_id' => $post->id, 'text' => 'Comment']);
        $this->actingAs($admin)->getJson('/api/admin/feed/'.$post->id.'/comments')->assertOk()->assertJsonPath('data.0.text', 'Comment');
        $this->putJson('/api/admin/feed/'.$post->id.'/comments/'.$comment->id, ['text' => 'Moderated'])->assertOk();
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'user_id' => $student->id, 'text' => 'Moderated']);
        $this->deleteJson('/api/admin/feed/'.$post->id.'/comments/'.$comment->id, ['confirmed' => true])->assertOk()->assertJsonPath('comments_count', 0);
        $this->postJson('/api/admin/education/reviews', ['name' => 'Review', 'price' => 10])->assertOk();
        $id = DB::table('education_klass_categories')->where('name', 'Review')->value('id');
        $this->deleteJson('/api/admin/education/reviews', ['ids' => [$id], 'confirmed' => true])->assertOk();
        $this->assertDatabaseMissing('education_klass_categories', ['id' => $id]);
    }

    public function test_activity_actor_target_legacy_values_redaction_and_pagination(): void
    {
        $admin = $this->user('super_admin');
        $student = $this->user('Student');
        for ($i = 0; $i < 32; $i++) {
            TeamActivity::record($admin, 'student.updated', User::class, $student->id, ['old' => ['weight' => 50, 'password' => 'secret-old'], 'new' => ['weight' => 51, 'password' => 'secret-new']]);
        }
        $this->actingAs($admin)->getJson('/api/admin/activity?actor='.$admin->id.'&target='.$student->email)->assertOk()->assertJsonPath('total', 32)->assertJsonCount(30, 'data');
        $id = DB::table('activity_log')->max('id');
        $this->getJson('/api/admin/activity/'.$id)->assertOk()->assertJsonPath('changes.0.field', 'weight')->assertJsonPath('changes.0.old', 50)->assertJsonPath('changes.0.new', 51)->assertDontSee('secret-old');
        $this->assertStringNotContainsString('secret-new', DB::table('activity_log')->find($id)->properties);
        $this->getJson('/api/admin/activity?actor=Nobody')->assertJsonPath('total', 0);
        $this->getJson('/api/admin/activity?target=Student%20Person&to=2099-12-31')->assertOk()->assertJsonPath('total', 32);
    }
}
