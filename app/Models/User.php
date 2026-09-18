<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'first_name',
        'last_name',
        'email',
        'password',
        'organization_id',
        'coach_id',
        'height',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_external' => 'boolean',
        ];
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(self::class, 'coach_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(self::class, 'organization_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->last_name . ' ' . $this->first_name);
    }

    public function getAgeAttribute(): ?string
    {
        if (! $this->birthday) {
            return null;
        }

        $age = Carbon::parse($this->birthday)->age;
        $suffix = match (true) {
            $age % 10 === 1 && $age % 100 !== 11 => 'год',
            in_array($age % 10, [2, 3, 4], true) && ! in_array($age % 100, [12, 13, 14], true) => 'года',
            default => 'лет',
        };

        return "{$age} {$suffix}";
    }

    public function getCoachShortAttribute(): ?string
    {
        $lastName = $this->coach?->last_name;
        $firstName = $this->coach?->first_name;

        if ($lastName) {
            return trim($lastName . ' ' . ($firstName ? mb_substr($firstName, 0, 1) . '.' : ''));
        }

        $raw = trim((string) ($this->trener_from_ankieta ?? ''));
        if ($raw === '') {
            return null;
        }

        $parts = preg_split('/\s+/u', $raw);
        $fallbackLastName = $parts[0] ?? '';
        $fallbackFirstName = $parts[1] ?? '';

        return trim($fallbackLastName . ' ' . ($fallbackFirstName ? mb_substr($fallbackFirstName, 0, 1) . '.' : ''));
    }

    public function getCoachLineAttribute(): string
    {
        return trim(($this->coach?->club ?? '') . ' ' . ($this->coach_short ?? ''));
    }

    public function examinations(): BelongsToMany
    {
        return $this->belongsToMany(Examination::class, 'examination_student', 'student_id', 'examination_id')
            ->withTimestamps();
    }

    public function userAlerts(): BelongsToMany
    {
        return $this->belongsToMany(UserAlert::class, 'user_alert_user')
            ->withPivot('read_at')
            ->withTimestamps();
    }

    public function scopeRole(Builder $query, string $role): Builder
    {
        return $query->where(function (Builder $query) use ($role): void {
            if (Schema::hasTable('model_has_roles')) {
                $query->whereExists(function ($subQuery) use ($role): void {
                    $subQuery->selectRaw('1')
                        ->from('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->whereColumn('model_has_roles.model_id', 'users.id')
                        ->where('model_has_roles.model_type', self::class)
                        ->where('roles.name', $role);
                });
            }

            $roleIds = DB::table('roles')->where('name', $role)->pluck('id');
            if ($roleIds->isNotEmpty()) {
                $query->orWhereIn('role_id', $roleIds);
            }
        });
    }

    public function hasProjectRole(string $role): bool
    {
        if (! Schema::hasTable('roles')) {
            return false;
        }

        $roleIds = DB::table('roles')->where('name', $role)->pluck('id');

        if ($roleIds->contains($this->role_id)) {
            return true;
        }

        if (! Schema::hasTable('model_has_roles')) {
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', self::class)
            ->where('model_has_roles.model_id', $this->id)
            ->where('roles.name', $role)
            ->exists();
    }

    public function hasAnyProjectRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasProjectRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function projectRoleNames(): array
    {
        if (! Schema::hasTable('roles')) {
            return [];
        }

        $roles = collect();

        if ($this->role_id) {
            $legacyRole = DB::table('roles')->where('id', $this->role_id)->value('name');

            if ($legacyRole) {
                $roles->push($legacyRole);
            }
        }

        if (Schema::hasTable('model_has_roles')) {
            $spatieRoles = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', self::class)
                ->where('model_has_roles.model_id', $this->id)
                ->pluck('roles.name');

            $roles = $roles->merge($spatieRoles);
        }

        return $roles->filter()->unique()->values()->all();
    }

    public function getEffectiveCompetitiveRecordStartDate(): ?Carbon
    {
        if (! Schema::hasColumn('users', 'competitive_record_starts_at') || ! $this->competitive_record_starts_at) {
            return null;
        }

        $startDate = Carbon::parse($this->competitive_record_starts_at)->startOfDay();

        return $startDate->lte(Carbon::today()) ? $startDate : null;
    }

    public function shouldCountCompetitiveResultAt(Carbon|string|null $date): bool
    {
        $effectiveStartDate = $this->getEffectiveCompetitiveRecordStartDate();

        if (! $effectiveStartDate || ! $date) {
            return true;
        }

        return Carbon::parse($date)->startOfDay()->gte($effectiveStartDate);
    }
}
