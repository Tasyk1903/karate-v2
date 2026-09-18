<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationKlassCategory extends Model
{
    protected $table = 'education_klass_categories';

    protected $guarded = false;

    public function videos(): HasMany
    {
        return $this->hasMany(EducationKlassVideo::class);
    }

    public function studentTournaments(): HasMany
    {
        return $this->hasMany(StudentTournament::class);
    }
}
