<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentStudentList extends Model
{
    protected $guarded = false;

    public function listTournament(): BelongsTo
    {
        return $this->belongsTo(ListTournament::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
