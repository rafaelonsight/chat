<?php

namespace App\Filament\Pages\Auth;

use Illuminate\Support\Str;

class Login extends \Filament\Auth\Pages\Login
{
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'],
        ];
    }
}
