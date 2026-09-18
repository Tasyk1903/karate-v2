<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationTournament extends Model
{
    protected $guarded = [];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_organizer_id');
    }
}
