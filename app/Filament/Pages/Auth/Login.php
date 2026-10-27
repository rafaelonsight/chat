<?php

namespace App\Filament\Pages\Auth;

use Illuminate\Support\Str;

class Login extends \Filament\Auth\Pages\Login
{
    // Registrada pelo AgendaPanelProvider na edicao Agenda; fora do painel do chat.
    protected static bool $isDiscovered = false;

    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'],
        ];
    }
}
