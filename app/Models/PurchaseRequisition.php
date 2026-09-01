<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequisition extends Model
{
    protected $table = 'purchase_requisitions';
    protected $guarded = [];

    protected $casts = [
        'trans_date' => 'date',
        'payload'    => 'array',
        'response'   => 'array',
        'synced_at'  => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(AccurateBranch::class, 'accurate_branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionItem::class, 'purchase_requisition_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(PurchaseRequisitionActivityLog::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('superadmin') || $user->hasPermission('view_purchase_requisition_all')) {
            return $query;
        }

        if ($user->hasPermission('view_purchase_requisition_own')) {
            return $query->where('user_id', $user->id);
        }

        return $query->whereRaw('0 = 1');
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->hasRole('superadmin') || $user->hasPermission('view_purchase_requisition_all')) {
            return true;
        }

        return $user->hasPermission('view_purchase_requisition_own')
            && filled($this->user_id)
            && (int) $this->user_id === (int) $user->id;
    }

    public function isPendingApprovalEditable(): bool
    {
        return $this->status === 'submitted'
            && blank($this->approved_at)
            && blank($this->rejected_at)
            && blank($this->accurate_id)
            && blank($this->accurate_number);
    }
}
