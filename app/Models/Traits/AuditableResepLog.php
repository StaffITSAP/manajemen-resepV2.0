<?php

namespace App\Models\Traits;

use App\Models\ResepLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

trait AuditableResepLog
{
    public static function bootAuditableResepLog(): void
    {
        // CREATE
        static::created(function (Model $model) {
            $model->storeResepLog('created', [], $model->fresh()->toArray(), 'Created');
        });

        // UPDATE (diff only)
        static::updating(function (Model $model) {
            // ambil original sebelum tersimpan
            $original = $model->getOriginal();
            $dirty    = $model->getDirty();

            // bersihkan noise timestamp kalau tidak berubah signifikan
            unset($dirty['updated_at']);

            if (! empty($dirty)) {
                $old = Arr::only($original, array_keys($dirty));
                $new = Arr::only($model->getAttributes(), array_keys($dirty));
                $model->storeResepLog('updated', $old, $new, 'Updated: '.implode(', ', array_keys($dirty)));
            }
        });

        // DELETE / RESTORE
        static::deleted(function (Model $model) {
            // soft delete dianggap "deleted"
            $model->storeResepLog('deleted', $model->getOriginal(), [], 'Deleted');
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                $model->storeResepLog('restored', [], $model->fresh()->toArray(), 'Restored');
            });
        }
    }

    /**
     * Pencatat umum
     */
    protected function storeResepLog(string $action, array $old = null, array $new = null, ?string $summary = null): void
    {
        // Tentukan penandaan subjek
        $resepId       = null;
        $bahanResepId  = null;

        if ($this instanceof \App\Models\Resep) {
            $resepId = $this->id ?? null;
        }

        if ($this instanceof \App\Models\BahanResep) {
            $bahanResepId = $this->id ?? null;
            $resepId      = $this->resep_id ?? null;
        }

        ResepLog::create([
            'resep_id'        => $resepId,
            'bahan_resep_id'  => $bahanResepId,
            'model_type'      => static::class,
            'model_id'        => $this->getKey(),
            'action'          => $action,
            'user_id'         => Auth::id(),
            'summary'         => $summary,
            'changes_old'     => $old ?: null,
            'changes_new'     => $new ?: null,
        ]);
    }

    /**
     * Helper optional untuk log "view/read"
     */
    public function logViewed(?string $note = null): void
    {
        $this->storeResepLog('viewed', [], [], $note ?? 'Viewed');
    }
}
