<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelTask extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['context' => 'array', 'expires_at' => 'datetime'];
    }
}
