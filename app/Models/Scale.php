<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Scale extends Model
{
    public const CLOSED_CLUB = 'closed_club';
    public const INTERCLUB = 'interclub';
    public const CITY = 'city';
    public const REGION = 'region';
    public const FEDERAL_DISTRICT = 'federal_district';
    public const ALL_RUSSIAN = 'all_russian';
    public const INTERNATIONAL = 'international';
    public const RUSSIAN_CHAMPIONSHIP = 'russian_championship';

    protected $guarded = false;

    protected function casts(): array
    {
        return [
            'is_rating' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
