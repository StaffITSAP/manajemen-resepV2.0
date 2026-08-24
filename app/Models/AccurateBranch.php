<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccurateBranch extends Model
{
    protected $table = 'accurate_branches';
    protected $guarded = [];

    public function jobOrders()
    {
        return $this->hasMany(AccurateJobOrder::class, 'branch_id');
    }
}
