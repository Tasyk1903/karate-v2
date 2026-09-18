<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\EducationKlassCategory;
use App\Models\EducationPayment;
use App\Services\Education\EducationWorkPayments;
use App\Services\Education\StudentEducationWorks;
use Illuminate\Http\Request;

final class MobileStudentEducationController extends Controller
{
    public function options(Request $request, StudentEducationWorks $works)
    {
        $works->authorize($request->user());

        return response()->json(['data' => EducationKlassCategory::orderBy('name')->get(['id', 'name', 'price'])]);
    }

    public function store(Request $request, StudentEducationWorks $works)
    {
        return response()->json(['data' => $works->format($works->save($request->user(), $request), true)], 201);
    }

    public function update(Request $request, int $work, StudentEducationWorks $works)
    {
        return response()->json(['data' => $works->format($works->save($request->user(), $request, $work), true)]);
    }

    public function destroy(Request $request, int $work, StudentEducationWorks $works)
    {
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $works->delete($request->user(), $work);

        return response()->json(['deleted' => true]);
    }

    public function pay(Request $request, int $work, EducationWorkPayments $payments)
    {
        $request->validate(['accepted' => ['required', 'accepted'], 'retry' => ['sometimes', 'boolean']]);

        return response()->json(['payment' => $payments->format($payments->create($request->user(), $work, $request->boolean('retry')))]);
    }

    public function payment(Request $request, int $work, StudentEducationWorks $works, EducationWorkPayments $payments)
    {
        $works->query($request->user())->findOrFail($work);
        $a = EducationPayment::where('work_id', $work)->where('payer_id', $request->user()->id)->latest()->first();

        return response()->json(['payment' => $a ? $payments->format($payments->sync($a)) : null]);
    }
}
