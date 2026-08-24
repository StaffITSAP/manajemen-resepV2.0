<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected ?int $tmpRoleId = null;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Username auto jika kosong
        if (blank($data['username'] ?? null)) {
            $base = $data['name'] ?? ($data['email'] ?? 'user');
            $data['username'] = UserResource::makeUniqueUsername($base);
        }

        // AMBIL role_id dari $data (SEKARANG ADA karena tidak di-dehydrated(false))
        $this->tmpRoleId = isset($data['role_id']) ? (int) $data['role_id'] : null;

        // jika user tidak memilih → fallback ke role 'staff' bila ada
        if (! $this->tmpRoleId) {
            $this->tmpRoleId = (int) (Role::where('name', 'staff')->value('id') ?? 0);
        }

        // jangan biarkan kolom fiktif ikut insert ke users
        unset($data['role_id']);

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->tmpRoleId) {
            // Pivot user_role
            $this->record->roles()->sync([$this->tmpRoleId]);

            // Opsional: isi kolom users.role agar kompatibel dengan kode lama
            $roleName = Role::find($this->tmpRoleId)?->name;
            if ($roleName) {
                $this->record->update(['role' => $roleName]);
            }
        }
    }
}
