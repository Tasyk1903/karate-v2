<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\OnlineKataApplication;
use App\Models\Tournament;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\OnlineKataPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileKataPaymentController extends Controller
{
    public function index(Request $request, OnlineKataPaymentService $payments)
    {
        $page = OnlineKataApplication::with('student:id,first_name,last_name')->where('payer_id', $request->user()->id)
            ->when($request->filled('tournament_id'), fn ($q) => $q->where('tournament_id', $request->integer('tournament_id')))
            ->orderByDesc('created_at')->orderBy('id')->paginate(20);

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => $payments->format($a)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, OnlineKataApplication $application, OnlineKataPaymentService $payments)
    {
        abort_unless((int) $application->payer_id === (int) $request->user()->id, 403);

        return response()->json(['payment' => $payments->format($payments->sync($application))]);
    }

    public function retry(Request $request, OnlineKataApplication $application)
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        abort_unless((int) $application->payer_id === (int) $request->user()->id, 403);
        DB::transaction(function () use ($application, $request) {
            Tournament::withTrashed()->lockForUpdate()->find($application->tournament_id);
            $a = OnlineKataApplication::lockForUpdate()->findOrFail($application->id);
            abort_unless($a->status === 'canceled', 422);
            $a->update(['active_key' => null]);
            TeamActivity::record($request->user(), 'online_kata.retry.allowed', Tournament::class, $a->tournament_id,
                ['old' => ['active_key' => 'reserved'], 'new' => ['active_key' => null], 'application_id' => $a->id]);
        });

        return response()->json(['ok' => true]);
    }
}
