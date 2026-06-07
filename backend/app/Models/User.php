<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = [
        'email',
        'is_admin',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public $timestamps = true;
}
