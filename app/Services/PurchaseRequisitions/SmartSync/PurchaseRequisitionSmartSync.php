<?php

namespace App\Services\PurchaseRequisitions\SmartSync;

use App\Jobs\PurchaseRequisitions\SyncPurchaseRequisitionItemUnitsBatch;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class PurchaseRequisitionSmartSync
{
    public const LOCK_KEY = 'purchase_requisition_smart_sync';
    public const LOCK_TTL_SECONDS = 21600;
    public const BATCH_SIZE = 50;
    public const REQUEST_DELAY_MS = 500;
    public const INTER_BATCH_DELAY_SECONDS = 10;
    public const QUEUE_CONNECTION = 'purchase_requisition_sync';
    public const QUEUE_NAME = 'purchase-requisition-sync';

    /**
     * @return array{status:string, lock_owner?:string}
     */
    public function start(): array
    {
        $store = Cache::getStore();
        if (! $store instanceof LockProvider) {
            throw new RuntimeException('Cache store tidak mendukung atomic lock untuk Smart Sync.');
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return ['status' => 'already_running'];
        }

        $owner = $lock->owner();

        try {
            SyncPurchaseRequisitionItemUnitsBatch::dispatch($owner);
        } catch (Throwable $e) {
            static::releaseLock($owner);

            throw $e;
        }

        return [
            'status' => 'started',
            'lock_owner' => $owner,
        ];
    }

    public static function releaseLock(string $owner): void
    {
        $lock = Cache::restoreLock(self::LOCK_KEY, $owner);

        if (method_exists($lock, 'isOwnedByCurrentProcess') && ! $lock->isOwnedByCurrentProcess()) {
            return;
        }

        $lock->release();
    }

    public static function ownsLock(string $owner): bool
    {
        $lock = Cache::restoreLock(self::LOCK_KEY, $owner);

        return method_exists($lock, 'isOwnedByCurrentProcess')
            && $lock->isOwnedByCurrentProcess();
    }
}
