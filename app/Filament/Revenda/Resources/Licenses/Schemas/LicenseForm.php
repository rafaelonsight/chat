<?php

namespace App\Filament\Revenda\Resources\Licenses\Schemas;

use App\Models\License;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class LicenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')
                ->label('Status')
                ->options(License::STATUS)
                ->required(),

            TextInput::make('plano')
                ->label('Plano')
                ->maxLength(60),

            DateTimePicker::make('vence_em')
                ->label('Vence em')
                ->native(false)
                ->helperText('Só importa enquanto o status for "Período de teste" — nos demais status, quem decide é o status.'),

            Textarea::make('motivo')
                ->label('Motivo da mudança')
                ->rows(3)
                ->helperText('Por que suspendeu, cancelou ou reativou — fica registrado para quem olhar depois.'),
        ]);
    }
}
