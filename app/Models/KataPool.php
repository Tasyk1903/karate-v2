<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KataPool extends Model
{
    protected $guarded = false;

    protected function casts(): array
    {
        return [
            'students' => 'array',
            'winner_1' => 'boolean',
            'winner_2' => 'boolean',
            'winner_3' => 'boolean',
            'referee_score' => 'float',
            'judge1_score' => 'float',
            'judge2_score' => 'float',
            'judge3_score' => 'float',
            'judge4_score' => 'float',
            'total_score' => 'float',
            'min_score' => 'float',
            'max_score' => 'float',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function listTournament(): BelongsTo
    {
        return $this->belongsTo(ListTournament::class, 'list_id');
    }

    public function getStudents(?array $students)
    {
        if (empty($students)) {
            return collect();
        }

        return User::query()->whereIn('id', $students)->get();
    }

    public function setRefereeScoreAttribute(mixed $value): void
    {
        $this->attributes['referee_score'] = $value === null || $value === '' ? null : str_replace(',', '.', (string) $value);
    }

    public function setJudge1ScoreAttribute(mixed $value): void
    {
        $this->attributes['judge1_score'] = $value === null || $value === '' ? null : str_replace(',', '.', (string) $value);
    }

    public function setJudge2ScoreAttribute(mixed $value): void
    {
        $this->attributes['judge2_score'] = $value === null || $value === '' ? null : str_replace(',', '.', (string) $value);
    }

    public function setJudge3ScoreAttribute(mixed $value): void
    {
        $this->attributes['judge3_score'] = $value === null || $value === '' ? null : str_replace(',', '.', (string) $value);
    }

    public function setJudge4ScoreAttribute(mixed $value): void
    {
        $this->attributes['judge4_score'] = $value === null || $value === '' ? null : str_replace(',', '.', (string) $value);
    }

    public function setTotalScoreAttribute(mixed $value): void
    {
        $this->attributes['total_score'] = $value === null || $value === '' ? null : str_replace(',', '.', (string) $value);
    }

    public function setMinScoreAttribute(mixed $value): void
    {
        $this->attributes['min_score'] = $value === null || $value === '' ? null : str_replace(',', '.', (string) $value);
    }

    public function setMaxScoreAttribute(mixed $value): void
    {
        $this->attributes['max_score'] = $value === null || $value === '' ? null : str_replace(',', '.', (string) $value);
    }
}
