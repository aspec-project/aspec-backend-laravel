<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeekDay extends Model
{
    public $incrementing = false;
    protected $keyType = 'int';
    
    protected $fillable = [
        'id',
        'name',
    ];
}
