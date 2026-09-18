<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationPayment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'last_checked_at' => 'datetime', 'fulfilled_at' => 'datetime'];
}
