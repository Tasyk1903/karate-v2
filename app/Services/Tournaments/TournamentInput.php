<?php

namespace App\Services\Tournaments;

use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class TournamentInput
{
    public static function validated(Request $request): array
    {
        app()->setLocale($request->input('locale') === 'en' ? 'en' : 'ru');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'region_id' => ['required', 'integer', 'exists:regions,id'],
            'scale_id' => ['required', 'integer', 'exists:scales,id'],
            'age_from' => ['required', 'integer', 'between:0,120'],
            'age_to' => ['required', 'integer', 'between:0,120', 'gte:age_from'],
            'KY_up_to_8' => ['nullable', 'boolean'], 'KY_from_8' => ['nullable', 'boolean'],
            'fight_for_third_place' => ['nullable', 'boolean'],
            'accepts_organization_applications' => ['sometimes', 'boolean'],
            'tournament_type' => ['required', 'integer', 'in:1,2'],
            'tournament_type_kata' => ['nullable', 'required_if:tournament_type,2', 'integer', 'in:1,2'],
            'is_online_kata' => ['nullable', 'boolean'],
            'tatami' => ['required', 'integer', 'between:1,999'], 'price' => ['required', 'integer', 'min:0'],
            'date_commission' => ['required', 'date'],
            'date' => ['required', 'date'], 'date_finish' => ['required', 'date', 'after_or_equal:date'],
            'address' => ['required', 'string', 'max:255'],
            'chief_judge' => ['nullable', 'string', 'max:255'], 'chief_secretary' => ['nullable', 'string', 'max:255'],
            'regulation_document' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp', 'max:20480'],
            'application_document' => ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp', 'max:20480'],
            'logo_report' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remove_regulation_document' => ['sometimes', 'boolean'],
            'remove_application_document' => ['sometimes', 'boolean'], 'remove_logo_report' => ['sometimes', 'boolean'],
        ]);
        if (Carbon::parse($data['date_commission'])->gt(Carbon::parse($data['date_finish'])->endOfDay())) {
            throw ValidationException::withMessages(['date_commission' => __('tour.commission_after_finish')]);
        }

        return $data;
    }

    public static function attributes(array $data): array
    {
        $fields = ['name', 'region_id', 'scale_id', 'age_from', 'age_to', 'tatami', 'price', 'date_commission', 'date', 'date_finish', 'address', 'chief_judge', 'chief_secretary', 'accepts_organization_applications'];
        $attributes = array_intersect_key($data, array_flip($fields));
        foreach (['KY_up_to_8', 'KY_from_8', 'fight_for_third_place'] as $key) {
            $attributes[$key] = (bool) ($data[$key] ?? false);
        }
        $attributes['tournament_type'] = (int) $data['tournament_type'];
        $attributes['tournament_type_kata'] = $attributes['tournament_type'] === Tournament::KATA ? (int) $data['tournament_type_kata'] : null;
        $attributes['is_online_kata'] = $attributes['tournament_type'] === Tournament::KATA && $attributes['tournament_type_kata'] === Tournament::POINT_SYSTEM && (bool) ($data['is_online_kata'] ?? false);

        return $attributes;
    }
}
