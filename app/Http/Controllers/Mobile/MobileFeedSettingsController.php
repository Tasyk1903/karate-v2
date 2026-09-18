<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\FeedRegion;
use App\Models\User;
use App\Services\Feed\FeedReader;
use App\Services\Team\TeamActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class MobileFeedSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $city = FeedRegion::find($user->city_id);
        $org = User::role('Organization')->find($user->selected_organization);

        return response()->json(['city_id' => $city?->id, 'organization_id' => $org?->id,
            'city' => $city ? ['id' => $city->id, 'name' => $city->city] : null,
            'organization' => $org ? ['id' => $org->id, 'name' => $org->name ?: $org->full_name] : null]);
    }

    public function options(Request $request, FeedReader $reader): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(['cities', 'organizations'])], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $cities = $data['type'] === 'cities';
        $query = $cities ? FeedRegion::query()->select('id', 'city') : User::role('Organization')->select('id', 'name', 'first_name', 'last_name');
        if (! empty($data['search'])) {
            $search = '%'.$data['search'].'%';
            $query->where(fn ($q) => $cities ? $q->where('city', 'like', $search) : $q->where('name', 'like', $search)->orWhere('first_name', 'like', $search)->orWhere('last_name', 'like', $search));
        }
        $items = $query->orderBy($cities ? 'city' : 'name')->orderBy('id')->paginate(20);

        return response()->json(['data' => $items->map(fn ($item) => ['id' => $item->id, 'name' => $cities ? $item->city : ($item->name ?: $item->full_name)])->values(), 'meta' => $reader->meta($items)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['city_id' => ['required', 'integer', 'exists:feed_regions,id'], 'organization_id' => ['present', 'nullable', 'integer']]);
        if ($data['organization_id']) {
            abort_unless(User::role('Organization')->whereKey($data['organization_id'])->exists(), 422);
        }
        DB::transaction(function () use ($request, $data) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);
            $old = $user->only('city_id', 'selected_organization');
            $user->forceFill(['city_id' => $data['city_id'], 'selected_organization' => $data['organization_id']])->save();
            TeamActivity::record($user, 'mobile.feed.settings.updated', User::class, $user->id, ['old' => $old, 'new' => $user->only('city_id', 'selected_organization')]);
            $request->user()->setRawAttributes($user->getAttributes(), true);
        });

        return $this->show($request);
    }
}
