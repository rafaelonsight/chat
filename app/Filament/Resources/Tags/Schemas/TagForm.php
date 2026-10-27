<?php

namespace App\Filament\Resources\Tags\Schemas;

use App\Models\Tag;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nome')
                ->label(__('ui_13030dd962f4'))
                ->required()
                ->maxLength(40)
                ->rule(fn($get,?Tag $record)=>Rule::unique('tags','nome')->where('tenant_id',\App\Support\TenantContext::get())->where('contexto',$record?->contexto ?? $get('contexto'))->ignore($record?->id))
                // Alimenta a previa do seletor de cor enquanto se digita.
                ->live(onBlur: true)
                ->helperText(__('ui_247f804fc64e')),

            // ONDE a etiqueta pode ser posta. Escolhido na criacao, e nao no uso: assim
            // "Cliente VIP" nem aparece na lista da conversa, e o atendente nao tem como
            // marcar no lugar errado — que e exatamente o que estraga o relatorio historico.
            \Filament\Forms\Components\Hidden::make('contexto')->default(fn(?Tag $record)=>$record?->contexto ?? Tag::CONTATO)->required()->rule(Rule::in(array_keys(Tag::CONTEXTOS))),

            // Grade com as 24 cores da paleta. Paleta fechada de proposito: cor
            // livre gera etiqueta ilegivel. A validacao repete a lista porque o
            // valor chega do navegador.
            ViewField::make('cor')
                ->label(__('ui_62037d43904a'))
                ->view('filament.forms.components.seletor-cor')
                ->default('cinza')
                ->required()
                ->rule(Rule::in(array_keys(Tag::PALETA)))
                ->helperText(__('ui_33006a3c4d16')),
        ]);
    }
}
