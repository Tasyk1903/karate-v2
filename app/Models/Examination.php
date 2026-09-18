<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Examination extends Model
{
    protected $guarded = false;

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organization_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'examination_student', 'examination_id', 'student_id')
            ->withTimestamps();
    }
}
