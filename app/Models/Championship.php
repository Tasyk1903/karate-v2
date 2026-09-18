<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Championship extends Model
{
    use SoftDeletes;

    protected $guarded = false;

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    public function externalForms(): HasMany
    {
        return $this->hasMany(ExternalForm::class);
    }
}
