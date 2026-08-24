<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Buat permissions
        $permissions = [
            ['name' => 'view_master_satuan', 'description' => 'View Master Satuan'],
            ['name' => 'create_master_satuan', 'description' => 'Create Master Satuan'],
            ['name' => 'edit_master_satuan', 'description' => 'Edit Master Satuan'],
            ['name' => 'delete_master_satuan', 'description' => 'Delete Master Satuan'],
            
            ['name' => 'view_master_barang', 'description' => 'View Master Barang'],
            ['name' => 'create_master_barang', 'description' => 'Create Master Barang'],
            ['name' => 'edit_master_barang', 'description' => 'Edit Master Barang'],
            ['name' => 'delete_master_barang', 'description' => 'Delete Master Barang'],
            
            ['name' => 'view_resep', 'description' => 'View Resep'],
            ['name' => 'create_resep', 'description' => 'Create Resep'],
            ['name' => 'edit_resep', 'description' => 'Edit Resep'],
            ['name' => 'delete_resep', 'description' => 'Delete Resep'],
            
            ['name' => 'view_produksi', 'description' => 'View Produksi'],
            ['name' => 'create_produksi', 'description' => 'Create Produksi'],
            ['name' => 'edit_produksi', 'description' => 'Edit Produksi'],
            ['name' => 'delete_produksi', 'description' => 'Delete Produksi'],
            
            ['name' => 'export_excel', 'description' => 'Export Excel'],
            ['name' => 'manage_users', 'description' => 'Manage Users'],
        ];

        foreach ($permissions as $permission) {
            Permission::create($permission);
        }

        // Buat roles
        $roles = [
            ['name' => 'superadmin', 'description' => 'Super Administrator'],
            ['name' => 'owner', 'description' => 'Owner'],
            ['name' => 'staff', 'description' => 'Staff'],
            ['name' => 'kitchen', 'description' => 'Kitchen Staff'],
        ];

        foreach ($roles as $role) {
            Role::create($role);
        }

        // Assign permissions to roles
        $superadmin = Role::where('name', 'superadmin')->first();
        $owner = Role::where('name', 'owner')->first();
        $staff = Role::where('name', 'staff')->first();
        $kitchen = Role::where('name', 'kitchen')->first();

        // Superadmin gets all permissions
        $superadmin->permissions()->attach(Permission::all());

        // Owner gets most permissions except user management
        $ownerPermissions = Permission::where('name', '!=', 'manage_users')->get();
        $owner->permissions()->attach($ownerPermissions);

        // Staff permissions
        $staffPermissions = Permission::whereIn('name', [
            'view_master_satuan', 'view_master_barang', 'create_master_barang', 'edit_master_barang',
            'view_resep', 'create_resep', 'edit_resep',
            'view_produksi', 'create_produksi', 'edit_produksi'
        ])->get();
        $staff->permissions()->attach($staffPermissions);

        // Kitchen permissions
        $kitchenPermissions = Permission::whereIn('name', [
            'view_resep', 'view_produksi', 'create_produksi', 'edit_produksi'
        ])->get();
        $kitchen->permissions()->attach($kitchenPermissions);
    }
}