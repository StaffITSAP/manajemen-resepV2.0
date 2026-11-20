<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class CustomLoginPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_password_field_has_a_human_readable_indonesian_label(): void
    {
        Livewire::test(Login::class)
            ->assertSee('Kata Sandi')
            ->assertDontSee('filament-panels::pages/auth/login.form.password.label');
    }
}
