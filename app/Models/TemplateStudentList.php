<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TemplateStudentList extends Model
{
    public const KUMITE = 'kumite';
    public const KATA = 'kata';

    public const PERSONAL = 'personal';
    public const GROUP = 'group';
    public const FLAG = 'flag';

    protected $fillable = [
        'name',
        'age_from',
        'age_to',
        'weight_from',
        'weight_to',
        'rang_from',
        'rang_to',
        'gender',
        'user_id',
        'list_type',
        'kata_type',
        'sort_order',
    ];

    protected $casts = [
        'age_from' => 'integer',
        'age_to' => 'integer',
        'weight_from' => 'integer',
        'weight_to' => 'integer',
        'rang_from' => 'integer',
        'rang_to' => 'integer',
        'sort_order' => 'integer',
    ];
}
