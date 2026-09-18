<?php

namespace App\Http\Controllers;

use App\Services\Account\Agreements;
use App\Services\Account\SafeContent;
use Illuminate\Support\Facades\DB;

final class PublicAgreementController extends Controller
{
    public function __invoke(int $agreement, Agreements $agreements)
    {
        abort_unless(in_array($agreement, [1, 2, 3], true), 404);
        $document = DB::table('agreements')->find($agreement);
        abort_unless($document, 404);

        return response()->json(['title' => $agreements->title($document), 'content' => SafeContent::html($agreements->content($document)), 'content_locale' => $agreements->contentLocale($document)]);
    }
}
