<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\OrganizationTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\OrganizationTournamentApplications;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OrganizationApplicationController extends Controller
{
    private function authorizeActor(Request $request): int
    {
        app()->setLocale($request->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
        abort_unless($request->user()->hasAnyProjectRole(['Organization', 'Secretary']), 403);

        return (int) TournamentLifecycle::organizationId($request->user());
    }

    public function index(Request $request): JsonResponse
    {
        $org = $this->authorizeActor($request);
        $data = $request->validate(['view' => ['required', 'in:discover,incoming,outgoing'], 'search' => ['nullable', 'string', 'max:100']]);
        $search = trim($data['search'] ?? '');
        if ($data['view'] === 'discover') {
            $page = Tournament::where('organization_id', '!=', $org)->where('accepts_organization_applications', true)->whereHas('championship')
                ->whereDate('date_finish', '>=', today())->where(fn ($q) => $q->whereNull('date_commission')->orWhere('date_commission', '>=', now()))
                ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
                ->with('championship:id,name,banner')->orderBy('date')->orderBy('id')->paginate(20);
            $existing = OrganizationTournament::where('applicant_organizer_id', $org)->whereIn('tournament_id', $page->getCollection()->pluck('id'))->latest('id')->get()->unique('tournament_id')->keyBy('tournament_id');
            $page->through(fn ($t) => $this->summary($t) + ['application_id' => $existing[$t->id]->id ?? null, 'status' => $this->status($existing[$t->id]->is_success ?? null), 'can_apply' => $request->user()->hasProjectRole('Organization') && ! isset($existing[$t->id])]);
        } else {
            $page = OrganizationTournament::whereHas('tournament', fn ($q) => $q->whereHas('championship')->when($data['view'] === 'incoming', fn ($q) => $q->where('organization_id', $org)))
                ->when($data['view'] === 'outgoing', fn ($q) => $q->where('applicant_organizer_id', $org))
                ->when($search !== '', fn ($q) => $q->whereHas('tournament', fn ($q) => $q->where('name', 'like', '%'.$search.'%')))
                ->with(['tournament.championship:id,name,banner', 'organization:id,name,first_name,last_name'])->latest('id')->paginate(20);
            $page->through(fn ($a) => $this->summary($a->tournament) + ['application_id' => $a->id, 'organization' => $a->organization?->name ?: $a->organization?->full_name, 'status' => $this->status($a->is_success),
                'can_decide' => $data['view'] === 'incoming' && TournamentLifecycle::canManage($request->user(), $a->tournament), 'can_open_team' => $data['view'] === 'outgoing' && $a->is_success === 'accepted']);
        }

        return response()->json($page);
    }

    public function apply(Request $request, Tournament $tournament, OrganizationTournamentApplications $service): JsonResponse
    {
        $this->authorizeActor($request);
        $application = $service->apply($request->user(), $tournament);

        return response()->json(['id' => $application->id, 'status' => $this->status($application->is_success)]);
    }

    public function decision(Request $request, OrganizationTournament $application, OrganizationTournamentApplications $service): JsonResponse
    {
        $this->authorizeActor($request);
        $data = $request->validate(['status' => ['required', 'in:accepted,canceled']]);
        $service->decide($request->user(), $application, $data['status']);

        return response()->json(['ok' => true]);
    }

    public function team(Request $request, OrganizationTournament $application): JsonResponse
    {
        $org = $this->authorizeActor($request);
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        abort_unless((int) $application->applicant_organizer_id === $org && $application->is_success === 'accepted' && $application->tournament?->championship, 403);
        $page = User::role('Coach')->where('organization_id', $org)->select(['id', 'first_name', 'last_name', 'club'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('last_name', 'like', '%'.$request->string('search').'%')->orWhere('first_name', 'like', '%'.$request->string('search').'%')))
            ->orderBy('last_name')->orderBy('id')->paginate(20);
        $attached = DB::table('tournament_treners')->where('tournament_id', $application->tournament_id)->whereIn('trener_id', $page->getCollection()->pluck('id'))->get()->keyBy('trener_id');
        $page->through(fn ($coach) => ['id' => $coach->id, 'name' => $coach->full_name, 'club' => $coach->club, 'attached' => isset($attached[$coach->id]), 'can_detach' => (int) ($attached[$coach->id]->organization_application_id ?? 0) === $application->id]);

        return response()->json(['tournament' => $this->summary($application->tournament), 'coaches' => $page, 'can_manage_team' => TournamentLifecycle::commissionOpen($application->tournament)]);
    }

    public function coaches(Request $request, OrganizationTournament $application, OrganizationTournamentApplications $service): JsonResponse
    {
        $this->authorizeActor($request);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['integer', 'min:1', 'distinct'], 'attach' => ['required', 'boolean']]);
        $service->coaches($request->user(), $application, $data['ids'], $data['attach']);

        return response()->json(['ok' => true]);
    }

    private function summary(Tournament $tournament): array
    {
        return ['id' => $tournament->id, 'name' => $tournament->name, 'championship' => $tournament->championship?->name, 'date' => $tournament->date?->format('d.m.Y'),
            'date_finish' => $tournament->date_finish?->format('d.m.Y'), 'address' => $tournament->address, 'price' => $tournament->price, 'active' => TournamentLifecycle::active($tournament)];
    }

    private function status(?string $value): string
    {
        return in_array($value, ['accepted', 'canceled'], true) ? $value : 'pending';
    }
}
