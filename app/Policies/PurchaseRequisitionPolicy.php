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
        if ($ability === 'update') {
            return null;
        }

        return $user->hasRole('superadmin') ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('view_purchase_requisition_own')
            || $user->hasPermission('view_purchase_requisition_all');
    }

    public function view(User $user, PurchaseRequisition $purchaseRequisition): bool
    {
        return $purchaseRequisition->isVisibleTo($user);
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
        return ($user->hasRole('superadmin') || $user->hasPermission('edit_purchase_requisition'))
            && $purchaseRequisition->isVisibleTo($user)
            && $purchaseRequisition->isPendingApprovalEditable();
    }

    public function delete(User $user, PurchaseRequisition $purchaseRequisition): bool
    {
        return false;
    }
}
