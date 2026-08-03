<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

// Formulario escrito a mao de proposito: seis campos nao justificam depender da
// API de forms do Filament, e assim o comportamento fica todo testavel aqui.
class Cadastro extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Conta';

    protected static ?string $navigationLabel = 'Cadastro';

    protected static ?string $title = 'Cadastro da conta';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'cadastro';

    protected string $view = 'filament.pages.cadastro';

    public string $nome = '';

    public string $razao_social = '';

    public string $documento = '';

    public string $email = '';

    public string $telefone = '';

    public string $fuso_horario = 'America/Sao_Paulo';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    public function mount(): void
    {
        $conta = $this->conta();

        if (! $conta) {
            return;
        }

        $this->nome = (string) $conta->nome;
        $this->razao_social = (string) $conta->razao_social;
        $this->documento = (string) $conta->documento;
        $this->email = (string) $conta->email;
        $this->telefone = (string) $conta->telefone;
        $this->fuso_horario = (string) ($conta->fuso_horario ?: 'America/Sao_Paulo');
    }

    // Sempre a conta do usuario logado: nao existe caminho para alcancar outra.
    private function conta(): ?Tenant
    {
        $tenantId = auth()->user()?->tenant_id;

        return $tenantId ? Tenant::find($tenantId) : null;
    }

    public function salvar(): void
    {
        $this->validate([
            'nome'         => 'required|string|max:120',
            'razao_social' => 'nullable|string|max:160',
            'documento'    => 'nullable|string|max:32',
            'email'        => 'nullable|email|max:160',
            'telefone'     => 'nullable|string|max:32',
            'fuso_horario' => 'required|timezone',
        ], [], [
            'nome'         => 'nome',
            'razao_social' => 'razão social',
            'fuso_horario' => 'fuso horário',
        ]);

        $conta = $this->conta();

        if (! $conta) {
            return;
        }

        $conta->update([
            'nome'         => trim($this->nome),
            'razao_social' => trim($this->razao_social) ?: null,
            'documento'    => trim($this->documento) ?: null,
            'email'        => trim($this->email) ?: null,
            'telefone'     => trim($this->telefone) ?: null,
            'fuso_horario' => $this->fuso_horario,
        ]);

        Notification::make()->success()->title('Cadastro salvo')->send();
    }

    public function fusos(): array
    {
        return [
            'America/Sao_Paulo'   => 'Brasília (GMT-3)',
            'America/Manaus'      => 'Manaus (GMT-4)',
            'America/Rio_Branco'  => 'Rio Branco (GMT-5)',
            'America/Fortaleza'   => 'Fortaleza (GMT-3)',
            'America/Belem'       => 'Belém (GMT-3)',
            'America/Recife'      => 'Recife (GMT-3)',
            'America/Cuiaba'      => 'Cuiabá (GMT-4)',
            'America/Noronha'     => 'Fernando de Noronha (GMT-2)',
        ];
    }
}
