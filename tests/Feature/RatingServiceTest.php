<?php

namespace Tests\Feature;

use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\MobileAccessToken;
use App\Models\Pool;
use App\Models\Region;
use App\Models\Scale;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\RatingService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RatingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();
        Carbon::setTestNow('2026-08-24');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::reguard();

        parent::tearDown();
    }

    public function test_kumite_rating_counts_podium_wins_wazari_and_ippon_for_selected_year(): void
    {
        $region = Region::query()->create(['name' => 'Ростовская обл.']);
        $scale = Scale::query()->create(['name' => 'Городской', 'slug' => Scale::CITY, 'is_rating' => true]);
        [$organization, $coach] = $this->organizationWithCoach();
        $gold = $this->student($organization, $coach, 'Иванов', 'Иван', '2016-02-01', 'm', 28, '8 кю');
        $silver = $this->student($organization, $coach, 'Петров', 'Петр', '2016-03-01', 'm', 29, '8 кю');
        $bronze = $this->student($organization, $coach, 'Сидоров', 'Сидор', '2016-04-01', 'm', 28, '8 кю');

        $tournament = $this->tournament($organization, $region, $scale, Tournament::KUMITE, '2026-05-10');
        $list = $this->listTournament($tournament);

        Pool::query()->create([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'student_id' => $gold->id,
            'opponent_id' => $silver->id,
            'winner_id' => $gold->id,
            'round' => 'final',
            'type' => 'final',
            'student_wazari_count' => 2,
            'student_ippon' => true,
        ]);

        Pool::query()->create([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'student_id' => $bronze->id,
            'opponent_id' => $silver->id,
            'winner_id' => $bronze->id,
            'round' => 'third',
            'type' => '3rd',
        ]);

        $oldTournament = $this->tournament($organization, $region, $scale, Tournament::KUMITE, '2023-05-10');
        $oldList = $this->listTournament($oldTournament);
        Pool::query()->create([
            'tournament_id' => $oldTournament->id,
            'list_id' => $oldList->id,
            'student_id' => $silver->id,
            'opponent_id' => $gold->id,
            'winner_id' => $silver->id,
            'round' => 'final',
            'type' => 'final',
        ]);

        $result = app(RatingService::class)->resolve([
            'year' => '2026',
            'discipline' => 'kumite',
            'age_band' => '10-11',
            'gender' => 'm',
            'weight_category' => '30',
        ]);

        $items = collect($result['groups'])->firstWhere('key', 'weight-10-11-m-30')['items'];

        $this->assertSame([
            ['name' => 'Иванов Иван', 'points' => 97, 'wins' => 1],
            ['name' => 'Петров Петр', 'points' => 60, 'wins' => 0],
            ['name' => 'Сидоров Сидор', 'points' => 32, 'wins' => 1],
        ], collect($items)->map(fn (array $item) => [
            'name' => $item['full_name'],
            'points' => $item['rating_points'],
            'wins' => $item['wins_count'],
        ])->all());
    }

    public function test_rating_is_global_by_default_and_organization_filter_is_explicit(): void
    {
        $region = Region::query()->create(['name' => 'Москва']);
        $scale = Scale::query()->create(['name' => 'Городской', 'slug' => Scale::CITY, 'is_rating' => true]);
        [$firstOrganization, $firstCoach] = $this->organizationWithCoach('Первая организация');
        [$secondOrganization, $secondCoach] = $this->organizationWithCoach('Вторая организация');
        $firstStudent = $this->student($firstOrganization, $firstCoach, 'Альфа', 'Спортсмен', '2016-01-01', 'm', 25, '8 кю');
        $secondStudent = $this->student($secondOrganization, $secondCoach, 'Бета', 'Спортсмен', '2016-01-01', 'm', 25, '8 кю');

        foreach ([[$firstOrganization, $firstCoach, $firstStudent], [$secondOrganization, $secondCoach, $secondStudent]] as [$organization, $coach, $student]) {
            $opponent = $this->student($organization, $coach, 'Соперник', (string) $student->id, '2016-01-01', 'm', 25, '10 кю');
            $tournament = $this->tournament($organization, $region, $scale, Tournament::KUMITE, '2026-06-01');
            $list = $this->listTournament($tournament);
            Pool::query()->create([
                'tournament_id' => $tournament->id,
                'list_id' => $list->id,
                'student_id' => $student->id,
                'opponent_id' => $opponent->id,
                'winner_id' => $student->id,
                'round' => 'final',
                'type' => 'final',
            ]);
        }

        $global = app(RatingService::class)->resolve([
            'year' => '2026',
            'discipline' => 'kumite',
            'age_band' => '10-11',
            'gender' => 'm',
            'weight_category' => '30',
            'organization_id' => null,
        ]);
        $filtered = app(RatingService::class)->resolve([
            'year' => '2026',
            'discipline' => 'kumite',
            'age_band' => '10-11',
            'gender' => 'm',
            'weight_category' => '30',
            'organization_id' => $firstOrganization->id,
        ]);

        $globalNames = collect(collect($global['groups'])->firstWhere('key', 'weight-10-11-m-30')['items'])
            ->pluck('full_name')
            ->all();
        $filteredNames = collect(collect($filtered['groups'])->firstWhere('key', 'weight-10-11-m-30')['items'])
            ->pluck('full_name')
            ->all();

        $this->assertContains('Альфа Спортсмен', $globalNames);
        $this->assertContains('Бета Спортсмен', $globalNames);
        $this->assertSame(['Альфа Спортсмен'], $filteredNames);

        foreach (['Organization', 'Coach'] as $roleName) {
            $roleId = DB::table('roles')->insertGetId(['name' => $roleName, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $roleId, 'name' => $roleName]);
            foreach ($roleName === 'Organization' ? [$firstOrganization, $secondOrganization] : [$firstCoach, $secondCoach] as $account) {
                $account->forceFill(['role_id' => $roleId])->save();
            }
        }
        MobileAccessToken::create(['user_id' => $firstCoach->id, 'name' => 'test', 'token' => hash('sha256', 'rating-test'), 'expires_at' => now()->addMonth()]);
        $this->acceptMobileAgreements($firstCoach);
        $this->withToken('rating-test');
        $mobile = $this->getJson('/api/mobile/rating?year=2026&discipline=kumite&organization_id='.$firstOrganization->id)->assertOk();
        $names = collect($mobile['groups'])->flatMap(fn ($g) => $g['items'])->pluck('name');
        $this->assertContains('Альфа Спортсмен', $names);
        $this->assertNotContains('Бета Спортсмен', $names);
        $this->assertArrayHasKey($secondOrganization->id, $mobile['filter_options']['organizations']);
        $this->assertSame($firstOrganization->id, $mobile['filters']['organization_id']);
    }

    public function test_rating_normalizes_discipline_and_dependent_weight_filters(): void
    {
        $kata = app(RatingService::class)->resolve(['discipline' => 'kata', 'weight_category' => '30', 'view_mode' => 'p4p']);
        $this->assertNull($kata['filters']['weight_category']);
        $this->assertSame('all', $kata['filters']['view_mode']);
        $this->assertSame([], $kata['weightOptions']);
        $kumite = app(RatingService::class)->resolve(['discipline' => 'kumite', 'weight_category' => '90+', 'age_band' => '8-9', 'gender' => 'f']);
        $this->assertNull($kumite['filters']['weight_category']);
        $p4p = app(RatingService::class)->resolve(['discipline' => 'kumite', 'weight_category' => '30', 'view_mode' => 'p4p']);
        $this->assertNull($p4p['filters']['weight_category']);
    }

    public function test_kata_rating_counts_podium_points_and_filters_by_year(): void
    {
        $region = Region::query()->create(['name' => 'Краснодар']);
        $scale = Scale::query()->create(['name' => 'Областной', 'slug' => Scale::REGION, 'is_rating' => true]);
        [$organization, $coach] = $this->organizationWithCoach();
        $gold = $this->student($organization, $coach, 'Ката', 'Первый', '2015-01-01', 'f', 35, '7 кю');
        $silver = $this->student($organization, $coach, 'Ката', 'Второй', '2015-01-01', 'f', 36, '7 кю');

        $tournament = $this->tournament($organization, $region, $scale, Tournament::KATA, '2026-04-20');
        $list = $this->listTournament($tournament);
        KataPool::query()->create([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'student_id' => $gold->id,
            'winner_1' => true,
        ]);
        KataPool::query()->create([
            'tournament_id' => $tournament->id,
            'list_id' => $list->id,
            'student_id' => $silver->id,
            'winner_2' => true,
        ]);

        $result2026 = app(RatingService::class)->resolve([
            'year' => '2026',
            'discipline' => 'kata',
            'age_band' => '10-11',
            'gender' => 'f',
        ]);
        $result2023 = app(RatingService::class)->resolve([
            'year' => '2023',
            'discipline' => 'kata',
            'age_band' => '10-11',
            'gender' => 'f',
        ]);

        $kataGroup = collect($result2026['groups'])->firstWhere('key', 'age-10-11-f');

        $this->assertNotNull($kataGroup, 'Expected kata group age-10-11-f to exist.');

        $items = collect($kataGroup['items']);

        $this->assertSame([
            ['name' => 'Ката Первый', 'points' => 3, 'wins' => 1],
            ['name' => 'Ката Второй', 'points' => 2, 'wins' => 0],
        ], $items->map(fn (array $item) => [
            'name' => $item['full_name'],
            'points' => $item['rating_points'],
            'wins' => $item['wins_count'],
        ])->all());
        $this->assertSame(0, $result2023['summary']['groups_count']);
    }

    private function organizationWithCoach(string $organizationName = 'Организация'): array
    {
        $organization = User::query()->create([
            'name' => $organizationName,
            'email' => uniqid('org', true).'@example.test',
            'password' => 'password',
        ]);
        $coach = User::query()->create([
            'name' => $organizationName.' тренер',
            'first_name' => 'Тренер',
            'last_name' => $organizationName,
            'email' => uniqid('coach', true).'@example.test',
            'password' => 'password',
            'organization_id' => $organization->id,
            'club' => $organizationName.' клуб',
        ]);

        return [$organization, $coach];
    }

    private function student(User $organization, User $coach, string $lastName, string $firstName, string $birthday, string $gender, int $weight, string $rank): User
    {
        return User::query()->create([
            'name' => $lastName.' '.$firstName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => uniqid('student', true).'@example.test',
            'password' => 'password',
            'organization_id' => $organization->id,
            'coach_id' => $coach->id,
            'birthday' => $birthday,
            'age' => (string) Carbon::parse($birthday)->age,
            'gender' => $gender,
            'weight' => $weight,
            'rang' => $rank,
        ]);
    }

    private function tournament(User $organization, Region $region, Scale $scale, int $type, string $date): Tournament
    {
        return Tournament::query()->create([
            'name' => uniqid('Турнир ', true),
            'organization_id' => $organization->id,
            'region_id' => $region->id,
            'scale_id' => $scale->id,
            'age_from' => 8,
            'age_to' => 17,
            'date_commission' => $date,
            'tatami' => 1,
            'price' => 0,
            'address' => 'Адрес',
            'date' => $date,
            'date_finish' => $date,
            'tournament_type' => $type,
        ]);
    }

    private function listTournament(Tournament $tournament): ListTournament
    {
        $template = TemplateStudentList::query()->create([
            'name' => 'Контрольный список',
            'age_from' => 10,
            'age_to' => 11,
            'weight_from' => 0,
            'weight_to' => 30,
            'rang_from' => 8,
            'rang_to' => 8,
            'gender' => 'm',
            'user_id' => $tournament->organization_id,
            'list_type' => $tournament->tournament_type === Tournament::KATA ? TemplateStudentList::KATA : TemplateStudentList::KUMITE,
            'kata_type' => $tournament->tournament_type === Tournament::KATA ? TemplateStudentList::PERSONAL : null,
            'sort_order' => 1,
        ]);

        return ListTournament::query()->create([
            'tournament_id' => $tournament->id,
            'template_student_list_id' => $template->id,
            'tatami' => 1,
        ]);
    }
}
