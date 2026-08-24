<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Super Admin',
                'email' => 'superadmin@example.com',
                'password' => Hash::make('password'),
                'role' => 'superadmin',
            ],
            [
                'name' => 'Owner',
                'email' => 'owner@example.com',
                'password' => Hash::make('password'),
                'role' => 'owner',
            ],
            [
                'name' => 'Staff',
                'email' => 'staff@example.com',
                'password' => Hash::make('password'),
                'role' => 'staff',
            ],
            [
                'name' => 'Kitchen',
                'email' => 'kitchen@example.com',
                'password' => Hash::make('password'),
                'role' => 'kitchen',
            ],
        ];

        foreach ($users as $userData) {
            $user = User::create($userData);
            
            // Assign role melalui pivot table
            $role = Role::where('name', $userData['role'])->first();
            if ($role) {
                $user->roles()->attach($role);
            }
        }
    }
}