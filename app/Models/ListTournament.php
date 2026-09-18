<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ListTournament extends Model
{
    protected $guarded = false;

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function templateStudentList(): BelongsTo
    {
        return $this->belongsTo(TemplateStudentList::class);
    }

    public function tournamentStudentLists(): HasMany
    {
        return $this->hasMany(TournamentStudentList::class);
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'tournament_student_lists', 'list_tournament_id', 'student_id');
    }

    public function kataPools(): HasMany
    {
        return $this->hasMany(KataPool::class, 'list_id');
    }

    public function pools(): HasMany
    {
        return $this->hasMany(Pool::class, 'list_id');
    }
}
