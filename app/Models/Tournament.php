<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tournament extends Model
{
    use SoftDeletes;

    public const KUMITE = 1;
    public const KATA = 2;
    public const FLAG_SYSTEM = 1;
    public const POINT_SYSTEM = 2;

    protected $guarded = false;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'date_commission' => 'datetime',
            'date_finish' => 'datetime',
            'is_online_kata' => 'boolean',
            'accepts_organization_applications' => 'boolean',
        ];
    }

    public function scale(): BelongsTo
    {
        return $this->belongsTo(Scale::class);
    }

    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function tournamentOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_tournaments', 'tournament_id', 'applicant_organizer_id')
            ->withPivot('is_success')
            ->withTimestamps();
    }

    public function treners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tournament_treners', 'tournament_id', 'trener_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'student_tournaments', 'tournament_id', 'student_id');
    }

    public function listTournaments(): HasMany
    {
        return $this->hasMany(ListTournament::class);
    }

    public function lists(): HasMany
    {
        return $this->listTournaments();
    }

    public function pools(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    public function kataPools(): HasMany
    {
        return $this->hasMany(KataPool::class);
    }

    public function listWhereExistPools(): HasMany
    {
        return $this->listTournaments()->whereHas('pools');
    }
}
