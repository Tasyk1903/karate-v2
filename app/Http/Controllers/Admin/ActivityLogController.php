<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\ActivitySubjects;
use App\Services\Admin\AuditValues;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150', 'actor' => 'nullable|string|max:150', 'target' => 'nullable|string|max:150',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''), 'timezone' => 'nullable|timezone', 'page' => 'sometimes|integer|min:1']);
        $query = DB::table('activity_log');
        if (! empty($data['search'])) {
            $query->where(fn ($q) => $q->where('description', 'like', '%'.$data['search'].'%')->orWhere('subject_type', 'like', '%'.$data['search'].'%')->orWhere('subject_id', $data['search']));
        }
        if (! empty($data['actor'])) {
            $query->where('causer_type', User::class)->whereIn('causer_id', $this->users($data['actor']));
        }
        if (! empty($data['target'])) {
            $ids = $this->users($data['target']);
            $query->where(function ($q) use ($ids): void {
                $q->whereIn('target_user_id', clone $ids)
                    ->orWhere(fn ($q) => $q->where('subject_type', User::class)->whereIn('subject_id', clone $ids));
                foreach (['student_id', 'user_id', 'target_user_id', 'trainer_id', 'coach_id'] as $key) {
                    foreach (['', 'old->', 'new->', 'attributes->'] as $prefix) {
                        $q->orWhereIn('properties->'.$prefix.$key, clone $ids);
                    }
                }
            });
        }
        $timezone = $data['timezone'] ?? 'UTC';
        $rows = $query->when($data['from'] ?? null, fn ($q, $date) => $q->where('created_at', '>=', CarbonImmutable::parse($date, $timezone)->startOfDay()->utc()))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->where('created_at', '<=', CarbonImmutable::parse($date, $timezone)->endOfDay()->utc()))->orderByDesc('id')->paginate(30);
        $ids = collect();
        foreach ($rows as $row) {
            $props = json_decode($row->properties ?? '{}', true) ?: [];
            $row->target_user_id ??= AuditValues::target((string) $row->subject_type, (int) $row->subject_id, $props);
            $ids->push($row->causer_id, $row->target_user_id);
        }
        $users = User::withTrashed()->whereIn('id', $ids->filter()->unique())->get(['id', 'name', 'first_name', 'last_name', 'email'])->keyBy('id');
        $names = app(ActivitySubjects::class)->names($rows->getCollection());
        $rows->through(fn ($row) => $this->summary($row, $users, $names));

        return response()->json($rows);
    }

    public function show(int $activity)
    {
        $row = DB::table('activity_log')->find($activity);
        abort_unless($row, 404);
        $props = AuditValues::safe(json_decode($row->properties ?? '{}', true) ?: []);
        $row->target_user_id ??= AuditValues::target((string) $row->subject_type, (int) $row->subject_id, $props);
        $users = User::withTrashed()->whereIn('id', array_filter([$row->causer_id, $row->target_user_id]))->get(['id', 'name', 'first_name', 'last_name', 'email'])->keyBy('id');
        $old = $props['old'] ?? null;
        $new = $props['new'] ?? $props['attributes'] ?? null;
        $changes = [];
        foreach (array_unique(array_merge(array_keys((array) $old), array_keys((array) $new))) as $key) {
            $before = ((array) $old)[$key] ?? null;
            $after = ((array) $new)[$key] ?? null;
            if ($before !== $after) {
                $changes[] = ['field' => $key, 'old' => $before, 'new' => $after];
            }
        }

        return response()->json($this->summary($row, $users, app(ActivitySubjects::class)->names(collect([$row]))) + ['changes' => $changes,
            'context' => collect($props)->except(['old', 'new', 'attributes'])->all()]);
    }

    private function users(string $search)
    {
        $query = DB::table('users')->select('id');
        foreach (preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$part.'%')
                ->orWhere('first_name', 'like', '%'.$part.'%')->orWhere('last_name', 'like', '%'.$part.'%')
                ->orWhere('email', 'like', '%'.$part.'%')->when(ctype_digit($part), fn ($q) => $q->orWhere('id', (int) $part)));
        }

        return $query;
    }

    private function summary(object $row, $users, array $names = []): array
    {
        $person = fn ($id) => $id ? ['id' => $id, 'name' => $users->get($id)?->full_name ?: $users->get($id)?->name ?: '#'.$id, 'email' => $users->get($id)?->email] : null;

        $properties = json_decode($row->properties ?? '{}', true) ?: [];
        $name = $row->subject_type === User::class ? $person($row->subject_id)['name'] ?? null : ($names[$row->subject_type.':'.$row->subject_id] ?? data_get($properties, 'new.name') ?? data_get($properties, 'old.name'));

        return ['id' => $row->id, 'event' => $row->event ?: $row->description, 'object_type' => class_basename((string) $row->subject_type), 'object_name' => $name,
            'object_id' => $row->subject_id, 'actor' => $person($row->causer_id), 'target' => $person($row->target_user_id),
            'created_at' => $row->created_at, 'source' => $row->log_name];
    }
}
