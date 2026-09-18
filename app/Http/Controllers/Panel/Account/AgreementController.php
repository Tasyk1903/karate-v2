<?php

namespace App\Http\Controllers\Panel\Account;

use App\Http\Controllers\Controller;
use App\Services\Account\AccountAccess;
use App\Services\Account\Agreements;
use App\Services\Account\SafeContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AgreementController extends Controller
{
    public function index(Request $request, Agreements $agreements): JsonResponse
    {
        AccountAccess::authorizeReader($request->user());
        $accepted = DB::table('agreement_acceptances')->where('user_id', $request->user()->id)->get(['agreement_id', 'version', 'accepted_at'])->keyBy(fn ($row) => $row->agreement_id.':'.$row->version);
        $rows = DB::table('agreements')->orderBy('id')->paginate(20);
        $rows->through(function ($document) use ($agreements, $accepted) {
            $version = $agreements->version($document);

            return ['id' => $document->id, 'type' => $document->type, 'version' => $version,
                'required' => isset(Agreements::REQUIRED[$document->id]), 'accepted_at' => $accepted->get($document->id.':'.$version)?->accepted_at];
        });

        return response()->json($rows);
    }

    public function show(Request $request, int $agreement, Agreements $agreements): JsonResponse
    {
        AccountAccess::authorizeReader($request->user());
        $document = DB::table('agreements')->where('id', $agreement)->first();
        abort_unless($document, 404);
        $version = $agreements->version($document);

        return response()->json(['id' => $document->id, 'type' => $document->type, 'content' => SafeContent::html($agreements->content($document)), 'content_locale' => $agreements->contentLocale($document), 'version' => $version,
            'accepted_at' => DB::table('agreement_acceptances')->where(['user_id' => $request->user()->id, 'agreement_id' => $agreement, 'version' => $version])->value('accepted_at')]);
    }

    public function accept(Request $request, int $agreement, Agreements $agreements): JsonResponse
    {
        AccountAccess::authorizeReader($request->user());
        app()->setLocale($request->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
        $data = $request->validate(['version' => ['required', 'string', 'size:64'], 'accepted' => ['required', 'accepted']]);
        $agreements->accept($request->user(), $agreement, $data['version']);

        return response()->json(['ok' => true, 'agreements_required' => $agreements->pending($request->user())->isNotEmpty()]);
    }
}
