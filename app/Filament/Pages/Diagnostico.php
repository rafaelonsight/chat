<?php

namespace App\Filament\Pages;

use App\Services\Diagnostico as Verificador;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Saude do sistema numa tela.
 *
 * A rotina de verificacao existia so como comando de console com --alertar. Isso serve para a
 * madrugada, nao para a pergunta que aparece no meio do atendimento: "esta lento, e o
 * sistema?". Sem tela, a resposta exigia alguem com acesso ao servidor — e enquanto isso o
 * atendente adivinha.
 */
class Diagnostico extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?string $navigationParentItem = 'Conta';

    protected static ?string $navigationLabel = 'Diagnóstico';

    protected static ?string $title = 'Diagnóstico';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'diagnostico';

    protected string $view = 'filament.pages.diagnostico';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->admin;
    }

    /**
     * Insignia com o numero de problemas, para aparecer no menu sem precisar abrir a tela.
     *
     * So conta os CRITICOS: aviso na insignia treina a ignorar insignia, e ai o dia do
     * problema de verdade ela nao e vista.
     */
    public static function getNavigationBadge(): ?string
    {
        $criticos = count(app(Verificador::class)->criticos());

        return $criticos > 0 ? (string) $criticos : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('verificar')
                ->label('Verificar de novo')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $problemas = app(Verificador::class)->verificar();

                    Notification::make()
                        ->status($problemas === [] ? 'success' : 'warning')
                        ->title($problemas === [] ? 'Tudo certo' : count($problemas).' ponto(s) de atenção')
                        ->send();
                }),
        ];
    }

    /**
     * Junta o que foi verificado com o que deu problema.
     *
     * A chave e o encaixe: quem nao aparece na lista de problemas esta bem. Assim a tela nao
     * repete nenhuma regra do servico — se uma verificacao nova entrar lá, aparece aqui.
     *
     * @return array<int, array<string, mixed>>
     */
    public function itens(): array
    {
        $problemas = collect(app(Verificador::class)->verificar())->keyBy('chave');

        $itens = [];

        foreach (Verificador::COBERTURA as $chave => $descricao) {
            $problema = $problemas->get($chave);

            $itens[] = [
                'descricao' => $descricao,
                'nivel'     => $problema['nivel'] ?? 'ok',
                'mensagem'  => $problema['mensagem'] ?? null,
            ];
        }

        // Problema com chave que a cobertura nao conhece ainda assim aparece: verificacao
        // nova no servico nao pode ficar invisivel aqui so porque esqueceram de descreve-la.
        foreach ($problemas as $chave => $problema) {
            if (! array_key_exists($chave, Verificador::COBERTURA)) {
                $itens[] = [
                    'descricao' => $chave,
                    'nivel'     => $problema['nivel'],
                    'mensagem'  => $problema['mensagem'],
                ];
            }
        }

        return $itens;
    }

    public function tudoCerto(): bool
    {
        return app(Verificador::class)->verificar() === [];
    }
}
