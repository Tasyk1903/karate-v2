<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTournament extends Model
{
    protected $table = 'student_tournaments';

    protected $guarded = false;

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listTournament(): BelongsTo
    {
        return $this->belongsTo(ListTournament::class);
    }

    public function educationKlassCategory(): BelongsTo
    {
        return $this->belongsTo(EducationKlassCategory::class);
    }

    public function onlineKataFirstRoundCategory(): BelongsTo
    {
        return $this->belongsTo(EducationKlassCategory::class, 'online_kata_first_round_category_id');
    }

    public function onlineKataSecondRoundCategory(): BelongsTo
    {
        return $this->belongsTo(EducationKlassCategory::class, 'online_kata_second_round_category_id');
    }
}
