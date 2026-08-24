<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterSatuan extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'master_satuan';
    protected $guarded = [];

    public function barang(): HasMany
    {
        return $this->hasMany(MasterBarang::class, 'satuan_id');
    }
}
