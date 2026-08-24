<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccurateItem extends Model
{
    protected $table = 'accurate_items';
    protected $guarded = [];
    protected $casts = ['raw' => 'array'];
}
