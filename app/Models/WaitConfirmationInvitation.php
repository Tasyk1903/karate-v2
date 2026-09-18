<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaitConfirmationInvitation extends Model
{
    protected $fillable = [
        'email',
        'target_role',
        'inviting_id',
        'confirmed',
        'organization_id',
        'accepted_user_id',
    ];

    protected function casts(): array
    {
        return [
            'confirmed' => 'boolean',
        ];
    }
}
