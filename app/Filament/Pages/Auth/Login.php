<?php

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    /**
     * Tampilkan form: ganti email => "Email atau Username",
     * password bisa di-reveal, dan tetap ada "Remember me".
     */
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getLoginFormComponent(),
                $this->getPasswordFormComponent(),   // override di bawah: .revealable()
                $this->getRememberFormComponent(),
            ])
            ->statePath('data'); // penting: supaya $data diteruskan ke proses auth bawaan
    }

    /**
     * Field login (email/username) menggantikan field email default.
     */
    protected function getLoginFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Email atau Username')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Password dengan tombol show/hide (revealable) — fitur resmi Filament Forms.
     * Docs: TextInput::password()->revealable()
     */
    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::pages/auth/login.form.password.label'))
            ->password()
            ->revealable() // 👈 show/hide password
            ->required()
            ->extraInputAttributes(['tabindex' => 2]);
    }

    /**
     * Di sini kita tentukan kredensial untuk attempt():
     * jika input 'login' valid email → pakai 'email', selain itu → 'username'.
     * (Tidak perlu override authenticate(); biarkan BaseLogin yang mengeksekusi.)
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        $loginType = filter_var($data['login'] ?? '', FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        return [
            $loginType => (string) ($data['login'] ?? ''),
            'password' => (string) ($data['password'] ?? ''),
        ];
    }

    /**
     * Pastikan error muncul di field 'login' (bukan 'email') saat gagal.
     * Contoh persis seperti pada artikel resmi komunitas Filament. 
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.login' => __('filament-panels::pages/auth/login.messages.failed'),
        ]);
    }
}
