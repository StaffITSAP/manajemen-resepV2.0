<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Role;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected ?int $tmpRoleId = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Ambil role yang dipilih dari form
        $this->tmpRoleId = isset($data['role_id']) ? (int) $data['role_id'] : null;
        unset($data['role_id']);

        return $data;
    }

    protected function afterSave(): void
    {
        if ($this->tmpRoleId) {
            $this->record->roles()->sync([$this->tmpRoleId]);

            $roleName = Role::find($this->tmpRoleId)?->name;
            if ($roleName) {
                $this->record->update(['role' => $roleName]);
            }
        }
    }
}
