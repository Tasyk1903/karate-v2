<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Account\Agreements;
use App\Services\Account\SafeContent;
use App\Services\Team\TeamActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AgreementController extends Controller
{
    private const TYPES = [1 => 'terms_of_service', 2 => 'privacy_policy', 3 => 'data_processing_consent'];

    public function index(Agreements $agreements)
    {
        return response()->json(['data' => collect(self::TYPES)->map(function ($type, $id) use ($agreements) {
            $row = DB::table('agreements')->find($id);

            return ['id' => $id, 'type' => $type, 'name' => $agreements->title((object) ['type' => $type]),
                'description' => $row ? SafeContent::html($row->description) : '',
                'description_en' => $row ? SafeContent::html($row->description_en) : '',
                'version' => $row ? $agreements->version($row) : null];
        })->values()]);
    }

    public function update(Request $request, int $agreement, Agreements $agreements)
    {
        abort_unless(isset(self::TYPES[$agreement]), 404);
        $data = $request->validate(['description' => 'required|string|max:200000', 'description_en' => 'required|string|max:200000', 'version' => 'present|nullable|string|size:64']);
        foreach (['description', 'description_en'] as $field) {
            $data[$field] = SafeContent::html($data[$field]);
            if (trim(strip_tags($data[$field])) === '') {
                throw ValidationException::withMessages([$field => __('validation.required', ['attribute' => $field])]);
            }
        }
        DB::transaction(function () use ($request, $agreement, $data, $agreements): void {
            $old = DB::table('agreements')->where('id', $agreement)->lockForUpdate()->first();
            abort_if(($old ? $agreements->version($old) : null) !== $data['version'], 409, __('admin.conflict'));
            $new = ['type' => self::TYPES[$agreement], 'description' => $data['description'], 'description_en' => $data['description_en'], 'updated_at' => now()];
            DB::table('agreements')->updateOrInsert(['id' => $agreement], $new + ($old ? [] : ['created_at' => now()]));
            TeamActivity::record($request->user(), 'admin.agreement.saved', 'App\\Models\\Agreement', $agreement, ['old' => (array) $old, 'new' => $new], 'admin');
        });

        return response()->json(['ok' => true]);
    }
}
