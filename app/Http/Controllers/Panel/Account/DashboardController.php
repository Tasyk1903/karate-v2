<?php

namespace App\Http\Controllers\Panel\Account;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\User;
use App\Models\WaitConfirmationInvitation;
use App\Services\Account\AccountAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $organization = AccountAccess::organizationId($request->user());
        $tournaments = Tournament::query()->where('organization_id', $organization)->whereHas('championship');
        $upcoming = (clone $tournaments)->where('date', '>=', today())->orderBy('date')->orderBy('id')->limit(6)
            ->with('championship:id,name')->get(['id', 'name', 'championship_id', 'date']);

        return response()->json([
            'trainers' => User::role('Coach')->where('organization_id', $organization)->count(),
            'students' => User::role('Student')->whereHas('coach', fn ($query) => $query->where('organization_id', $organization))->count(),
            'pending' => WaitConfirmationInvitation::where('organization_id', $organization)->where('confirmed', false)->count(),
            'unread' => $request->user()->userAlerts()->wherePivotNull('read_at')->count(),
            'upcoming' => $upcoming->map(fn ($tournament) => ['id' => $tournament->id, 'name' => $tournament->name, 'championship' => $tournament->championship->name,
                'date' => $tournament->date?->format('Y-m-d'), 'path' => '/panel/tournaments/'.$tournament->championship_id.'/items/'.$tournament->id.'/edit']),
        ]);
    }
}
