<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Account\Agreements;
use App\Services\Account\MobileAgreements;
use App\Services\Account\SafeContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileAgreementController extends Controller
{
    public function index(Request $request, Agreements $agreements): JsonResponse
    {
        $accepted = DB::table('agreement_acceptances')->where('user_id', $request->user()->id)
            ->get(['agreement_id', 'version', 'accepted_at'])->keyBy(fn ($row) => $row->agreement_id.':'.$row->version);
        $rows = DB::table('agreements')->orderBy('id')->paginate(20);
        $rows->through(function ($document) use ($agreements, $accepted, $request) {
            $version = $agreements->version($document);
            $flag = Agreements::REQUIRED[$document->id] ?? null;

            return ['id' => $document->id, 'title' => $agreements->title($document), 'version' => $version,
                'required' => $flag !== null,
                'accepted_at' => $flag && ! $request->user()->$flag ? null : $accepted->get($document->id.':'.$version)?->accepted_at];
        });

        return response()->json($rows);
    }

    public function show(int $agreement, Agreements $agreements): JsonResponse
    {
        $document = DB::table('agreements')->where('id', $agreement)->first();
        abort_unless($document, 404);

        return response()->json(['id' => $document->id, 'title' => $agreements->title($document),
            'version' => $agreements->version($document), 'content' => SafeContent::html($agreements->content($document)), 'content_locale' => $agreements->contentLocale($document),
            'required' => isset(Agreements::REQUIRED[$document->id])]);
    }

    public function accept(Request $request, int $agreement, Agreements $agreements): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'size:64'], 'accepted' => ['required', 'accepted']]);
        $agreements->accept($request->user(), $agreement, $data['version']);

        return response()->json(['agreements_required' => app(MobileAgreements::class)->required($request->user()->fresh())]);
    }
}
