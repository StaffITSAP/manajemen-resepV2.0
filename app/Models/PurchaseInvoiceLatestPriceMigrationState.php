<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceLatestPriceMigrationState extends Model
{
    protected $table = 'purchase_invoice_latest_price_migration_states';
    protected $guarded = [];
    protected $casts = [
        'candidates' => 'array',
        'completed_at' => 'datetime',
        'incremental_run_upper_trans_date' => 'date',
        'incremental_completed_upper_trans_date' => 'date',
    ];
}
