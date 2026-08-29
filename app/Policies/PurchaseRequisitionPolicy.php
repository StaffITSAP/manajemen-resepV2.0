<?php

namespace App\Policies;

use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PurchaseRequisitionPolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability): bool|null
    {
        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view_purchase_requisition');
    }

    public function view(User $user, PurchaseRequisition $purchaseRequisition): bool
    {
        return $user->hasPermission('view_purchase_requisition');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('create_purchase_requisition');
    }

    public function approve(User $user, PurchaseRequisition $purchaseRequisition): bool
    {
        return $user->hasPermission('approve_purchase_requisition');
    }

    public function reject(User $user, PurchaseRequisition $purchaseRequisition): bool
    {
        return $user->hasPermission('reject_purchase_requisition');
    }

    public function update(User $user, PurchaseRequisition $purchaseRequisition): bool
    {
        return false;
    }

    public function delete(User $user, PurchaseRequisition $purchaseRequisition): bool
    {
        return false;
    }
}
