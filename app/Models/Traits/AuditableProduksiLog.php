<?php

namespace App\Models\Traits;

use App\Models\ProduksiLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

trait AuditableProduksiLog
{
    public static function bootAuditableProduksiLog(): void
    {
        // CREATE
        static::created(function (Model $model) {
            $model->storeProduksiLog('created', [], $model->fresh()->toArray(), 'Created');
        });

        // UPDATE
        static::updating(function (Model $model) {
            $original = $model->getOriginal();
            $dirty    = $model->getDirty();

            unset($dirty['updated_at']); // ignore noise

            if (! empty($dirty)) {
                $old = Arr::only($original, array_keys($dirty));
                $new = Arr::only($model->getAttributes(), array_keys($dirty));
                $model->storeProduksiLog('updated', $old, $new, 'Updated: ' . implode(', ', array_keys($dirty)));
            }
        });

        // DELETE
        static::deleted(function (Model $model) {
            $model->storeProduksiLog('deleted', $model->getOriginal(), [], 'Deleted');
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                $model->storeProduksiLog('restored', [], $model->fresh()->toArray(), 'Restored');
            });
        }
    }

    protected function storeProduksiLog(string $action, array $old = null, array $new = null, ?string $summary = null): void
    {
        $produksiId     = null;
        $itemProduksiId = null;

        if ($this instanceof \App\Models\Produksi) {
            $produksiId = $this->id ?? null;
        }

        if ($this instanceof \App\Models\ItemProduksi) {
            $itemProduksiId = $this->id ?? null;
            $produksiId     = $this->produksi_id ?? null;
        }

        if ($this instanceof \App\Models\BahanProduksi) {
            $itemProduksiId = $this->item_produksi_id ?? null;
            $produksiId     = $this->itemProduksi->produksi_id ?? null;
        }

        ProduksiLog::create([
            'produksi_id'     => $produksiId,
            'item_produksi_id' => $itemProduksiId,
            'model_type'      => static::class,
            'model_id'        => $this->getKey(),
            'action'          => $action,
            'user_id'         => Auth::id(),
            'summary'         => $summary,
            'changes_old'     => $old ?: null,
            'changes_new'     => $new ?: null,
        ]);
    }

    public function logViewed(?string $note = null): void
    {
        $this->storeProduksiLog('viewed', [], [], $note ?? 'Viewed');
    }
}
