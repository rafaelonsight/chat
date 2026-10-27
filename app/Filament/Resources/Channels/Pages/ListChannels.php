<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\EvolutionService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;

class ListChannels extends ListRecords
{
    protected static string $resource = ChannelResource::class;

    /**
     * UM botao, e dentro dele as opcoes que existem hoje.
     *
     * Dois botoes lado a lado davam o mesmo peso a dois caminhos que nao tem o mesmo peso — e
     * no dia em que Instagram e Messenger entrarem, seriam quatro botoes disputando o
     * cabecalho. Um menu cresce; uma fileira de botoes, nao.
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('evolution')
                    ->label(__('ui_d17e493b2df2'))
                    ->icon('heroicon-o-qr-code')
                    // Sem formulario: cria e leva direto ao codigo. Ver abaixo o porque.
                    ->schema(fn () => $this->selecaoDaConta())
                    ->modalHeading(__('ui_80711e0f6a44'))
                    ->modalSubmitActionLabel(__('ui_91678b8e0d35'))
                    ->action(function (array $data) { $this->selecionarConta($data); return $this->criarPorQrCode(); }),

                Action::make('oficial')
                    ->label(__('ui_faa78ed3c127'))
                    ->icon('heroicon-o-check-badge')
                    // Cadastro Incorporado: o popup da Meta devolve os tres identificadores
                    // sozinho. O formulario manual (Phone Number ID, WABA, token) continua
                    // existindo — e so nao e mais o primeiro caminho oferecido — em
                    // create?tipo=meta_cloud, para quem precisar dele.
                    ->url(static::getResource()::getUrl('conectar-meta')),

                Action::make('site')
                    ->label(__('ui_6756ed6ff0d6'))
                    ->icon('heroicon-o-code-bracket')
                    // Sem formulario, no mesmo espirito do QR Code: aqui nao ha nada para
                    // perguntar antes — a chave nasce sozinha (Channel::booted()), e o que
                    // falta e so mostrar o <script> para colar no site.
                    ->schema(fn () => $this->selecaoDaConta())
                    ->modalHeading(__('ui_f96f21328007'))
                    ->action(function (array $data) { $this->selecionarConta($data); return $this->criarParaSite(); }),

                Action::make('meta_social')
                    ->label(__('ui_6c9d7f0becf5'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    // Mesmo espirito do Cadastro Incorporado: FB.login() devolve as
                    // Paginas, a pessoa escolhe qual, e Messenger (sempre) e Instagram
                    // (se marcado) nascem prontos, sem digitar id nenhum.
                    ->url(static::getResource()::getUrl('conectar-meta-social')),
            ])
                ->label(__('ui_60a3100ec40b'))
                ->icon('heroicon-o-plus')
                ->button(),
        ];
    }

    /**
     * Cria o canal e vai direto para o QR Code.
     *
     * O FORMULARIO SAIU DO CAMINHO, e essa e a mudanca.
     *
     * Pedir "nome do canal" antes do QR e pedir uma decisao que a pessoa ainda nao tem como
     * tomar: ela veio conectar um numero. O nome bom para o canal so aparece DEPOIS de
     * conectar — normalmente e o proprio numero — e o sistema passa a preenche-lo sozinho.
     *
     * Ate la o canal nasce com um nome provisorio, e a tela do QR tem um campo para trocar.
     */
    private function criarPorQrCode()
    {
        if (! $this->contaParaCanal()) return;

        $canal = Channel::create([
            'tenant_id' => $this->contaParaCanal(),
            'tipo'      => Channel::EVOLUTION,
            'nome'      => Channel::nomeProvisorio(),
        ])->refresh();

        // Falha aqui NAO impede o canal de existir: fica registrada, e a tela do QR mostra o
        // erro com o botao de recomecar. Sumir com o canal deixaria a pessoa sem nada na tela
        // e sem saber o que aconteceu.
        try {
            app(EvolutionService::class)->createInstance($canal->instance_name, $canal->webhookUrl());
        } catch (\Throwable $e) {
            $canal->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 500)]);
        }

        return redirect(static::getResource()::getUrl('conectar', ['record' => $canal]));
    }

    /**
     * Cria o canal do chat de site e vai direto para a edicao, de onde a acao
     * "Ver codigo de instalacao" (EditChannel) mostra o <script> para colar.
     *
     * Sem QR Code e sem popup do Facebook aqui: o unico passo que falta depois de
     * criar e copiar uma linha de HTML — nao ha "conectar" para esperar.
     */
    private function criarParaSite()
    {
        if (! $this->contaParaCanal()) return;

        $canal = Channel::create([
            'tenant_id' => $this->contaParaCanal(),
            'tipo'      => Channel::SITE,
            'nome'      => 'Chat do site',
            'status'    => 'open',
        ])->refresh();

        return redirect(static::getResource()::getUrl('edit', ['record' => $canal]));
    }
    private function contaParaCanal(): ?int
    {
        $id = \App\Support\TenantContext::get() ?? auth()->user()?->tenant_id;
        if ($id) return (int) $id;
        \Filament\Notifications\Notification::make()->warning()
            ->title(__('ui_c23fcc2c4c57'))
            ->body(__('ui_e39d9b87e3a1'))
            ->actions([\Filament\Actions\Action::make('conta')->label(__('ui_99c03b14b8e7'))->url('/admin/suporte/contas')])
            ->persistent()->send();
        return null;
    }

    private function selecaoDaConta(): array
    {
        if (\App\Support\TenantContext::get() || auth()->user()?->tenant_id) return [];
        abort_unless(auth()->user()?->operador && auth()->user()?->super_admin, 403);
        return [
            \Filament\Forms\Components\Select::make('conta_id')->label(__('ui_733d3f3ce2f4'))
                ->options(fn () => \App\Models\Tenant::orderBy('nome')->pluck('nome','id')->all())
                ->searchable()->required()
                ->createOptionForm([\Filament\Forms\Components\TextInput::make('nome')->label(__('ui_310d474acce2'))->required()->maxLength(150)])
                ->createOptionUsing(function (array $data) {
                    abort_unless(auth()->user()?->operador && auth()->user()?->super_admin,403);
                    return \App\Models\Tenant::create(['nome'=>$data['nome'],'slug'=>\Illuminate\Support\Str::slug($data['nome']).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8))])->id;
                }),
        ];
    }

    private function selecionarConta(array $data): void
    {
        if (\App\Support\TenantContext::get() || auth()->user()?->tenant_id) return;
        abort_unless(auth()->user()?->operador && auth()->user()?->super_admin,403);
        $conta=\App\Models\Tenant::findOrFail($data['conta_id'] ?? null);
        session()->put(\App\Support\TenantContext::CHAVE_SESSAO_SUPORTE,$conta->id);
        \App\Support\TenantContext::set($conta->id);
    }

}
