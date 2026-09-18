<?php

namespace Tests\Feature;

use App\Models\MobileAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileAgreementsTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        $id = DB::table('roles')->insertGetId(['name' => 'Coach', 'guard_name' => 'web']);
        DB::table('old_roles')->insert(['id' => $id, 'name' => 'Coach']);
        $this->coach = User::forceCreate(['first_name' => 'Test', 'email' => 'coach@example.test', 'password' => 'password', 'role_id' => $id]);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'consent'), 'expires_at' => now()->addDay()]);
        $this->withToken('consent');
        foreach ([2, 3] as $id) {
            DB::table('agreements')->insert(['id' => $id, 'type' => 'Terms '.$id, 'description' => '<p>Terms</p><script>alert(1)</script>']);
        }
    }

    public function test_gate_requires_each_document_and_preserves_logout_and_document_access(): void
    {
        $this->getJson('/api/mobile/auth/user')->assertOk()->assertJsonPath('user.agreements_required', true);
        $this->getJson('/api/mobile/trainer/profile')->assertStatus(428)->assertJsonPath('code', 'agreements_required');
        $this->getJson('/api/mobile/agreements')->assertOk()->assertJsonCount(2, 'data');
        foreach ([2, 3] as $id) {
            $document = $this->getJson('/api/mobile/agreements/'.$id)->assertOk();
            $this->assertStringNotContainsString('<script', $document->json('content'));
            $data = ['version' => $document->json('version'), 'accepted' => true];
            $this->postJson('/api/mobile/agreements/'.$id.'/accept', $data)->assertOk()->assertJsonPath('agreements_required', $id === 2);
            $this->postJson('/api/mobile/agreements/'.$id.'/accept', $data)->assertOk();
        }
        $this->assertSame(2, DB::table('agreement_acceptances')->count());
        $this->assertSame(2, DB::table('activity_log')->where('event', 'agreement.accepted')->count());
        $this->getJson('/api/mobile/trainer/profile')->assertOk();
        DB::table('agreements')->where('id', 2)->update(['description' => '<p>New terms</p>']);
        $this->getJson('/api/mobile/trainer/profile')->assertStatus(428);
        $this->postJson('/api/mobile/auth/logout')->assertOk();
    }

    public function test_titles_are_readable_in_both_locales_without_changing_versions_or_consent(): void
    {
        $types = [1 => 'terms_of_service', 2 => 'privacy_policy', 3 => 'data_processing_consent'];
        foreach ($types as $id => $type) {
            DB::table('agreements')->updateOrInsert(['id' => $id], ['type' => $type, 'description' => '<p>Terms</p>']);
        }
        $titles = [
            'ru' => ['Пользовательское соглашение', 'Политика конфиденциальности', 'Согласие на обработку персональных данных'],
            'en' => ['Terms of service', 'Privacy policy', 'Personal data processing consent'],
        ];
        $versions = [];
        foreach ($titles as $locale => $expected) {
            $this->withHeader('Accept-Language', $locale);
            $rows = $this->getJson('/api/mobile/agreements')->assertOk()->json('data');
            $this->assertSame($expected, array_column($rows, 'title'));
            foreach ($rows as $row) {
                $id = $row['id'];
                $version = hash('sha256', $types[$id]."\n<p>Terms</p>");
                $this->assertSame($version, $row['version']);
                $this->getJson('/api/mobile/agreements/'.$id)->assertOk()
                    ->assertJsonPath('title', $row['title'])->assertJsonPath('version', $version);
                $versions[$id] = $version;
            }
        }
        foreach ([2, 3] as $id) {
            $this->postJson('/api/mobile/agreements/'.$id.'/accept', ['version' => $versions[$id], 'accepted' => true])->assertOk();
        }
        $this->withHeader('Accept-Language', 'ru');
        $rows = $this->getJson('/api/mobile/agreements')->assertOk()->json('data');
        $this->assertNotNull($rows[1]['accepted_at']);
        $this->assertNotNull($rows[2]['accepted_at']);
        $this->getJson('/api/mobile/auth/user')->assertJsonPath('user.agreements_required', false);
        $this->assertSame($types, DB::table('agreements')->orderBy('id')->pluck('type', 'id')->all());
    }

    public function test_custom_document_title_is_preserved_in_list_and_detail(): void
    {
        foreach (['ru', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale);
            $this->getJson('/api/mobile/agreements')->assertOk()->assertJsonPath('data.0.title', 'Terms 2');
            $this->getJson('/api/mobile/agreements/2')->assertOk()->assertJsonPath('title', 'Terms 2');
        }
    }

    public function test_stale_version_missing_confirmation_and_missing_documents_fail_closed(): void
    {
        $version = $this->getJson('/api/mobile/agreements/2')->json('version');
        $this->postJson('/api/mobile/agreements/2/accept', ['version' => $version])->assertUnprocessable();
        DB::table('agreements')->where('id', 2)->update(['description' => 'Changed']);
        $this->postJson('/api/mobile/agreements/2/accept', ['version' => $version, 'accepted' => true])->assertUnprocessable();
        $this->acceptMobileAgreements($this->coach);
        DB::table('agreements')->where('id', 3)->delete();
        $this->getJson('/api/mobile/trainer/profile')->assertStatus(428);
        $this->deleteJson('/api/mobile/agreements/2')->assertMethodNotAllowed();
        $this->withToken('invalid')->getJson('/api/mobile/agreements')->assertUnauthorized();
    }

    public function test_legacy_flags_alone_are_insufficient_and_reacceptance_restores_false_flag(): void
    {
        $this->coach->forceFill(['success_politic' => true, 'data_processing' => true])->save();
        $this->getJson('/api/mobile/trainer/profile')->assertStatus(428);
        $this->acceptMobileAgreements($this->coach);
        $this->coach->forceFill(['success_politic' => false])->save();
        $this->getJson('/api/mobile/agreements')->assertJsonPath('data.0.accepted_at', null);
        $version = $this->getJson('/api/mobile/agreements/2')->json('version');
        $this->postJson('/api/mobile/agreements/2/accept', ['version' => $version, 'accepted' => true])->assertOk()->assertJsonPath('agreements_required', false);
        $this->assertSame(2, DB::table('agreement_acceptances')->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'agreement.consent.restored']);
    }
}
