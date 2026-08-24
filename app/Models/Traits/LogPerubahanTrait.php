<?php

namespace App\Models\Traits;

use App\Models\LogPerubahan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait LogPerubahanTrait
{
    protected static function bootLogPerubahanTrait()
    {
        static::created(function (Model $model) {
            LogPerubahan::create([
                'model_type' => get_class($model),
                'model_id' => $model->id,
                'user_id' => Auth::check() ? Auth::id() : 1, // Default ke user ID 1 jika tidak ada auth
                'aksi' => 'create',
                'data_baru' => $model->getAttributes(),
                'keterangan' => 'Data baru dibuat'
            ]);
        });

        static::updated(function (Model $model) {
            $changes = [];
            $original = $model->getOriginal();

            foreach ($model->getChanges() as $key => $value) {
                if ($key !== 'updated_at') {
                    $changes[$key] = [
                        'lama' => $original[$key] ?? null,
                        'baru' => $value
                    ];
                }
            }

            if (!empty($changes)) {
                LogPerubahan::create([
                    'model_type' => get_class($model),
                    'model_id' => $model->id,
                    'user_id' => Auth::check() ? Auth::id() : 1,
                    'aksi' => 'update',
                    'data_lama' => $original,
                    'data_baru' => $model->getAttributes(),
                    'keterangan' => 'Data diubah: ' . json_encode($changes)
                ]);
            }
        });

        static::deleted(function (Model $model) {
            LogPerubahan::create([
                'model_type' => get_class($model),
                'model_id' => $model->id,
                'user_id' => Auth::check() ? Auth::id() : 1,
                'aksi' => 'delete',
                'data_lama' => $model->getOriginal(),
                'keterangan' => 'Data dihapus'
            ]);
        });
    }

    public function logPerubahan()
    {
        return $this->morphMany(LogPerubahan::class, 'model');
    }
}