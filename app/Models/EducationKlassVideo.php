<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationKlassVideo extends Model
{
    protected $table = 'education_klass_videos';

    protected $guarded = false;

    protected function casts(): array
    {
        return ['is_review' => 'boolean', 'is_payment' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(EducationKlassCategory::class, 'education_klass_category_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(EducationPayment::class, 'work_id');
    }
}
