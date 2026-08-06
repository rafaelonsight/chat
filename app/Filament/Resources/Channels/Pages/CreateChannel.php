<?php

namespace App\Filament\Resources\Channels\Pages;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaCloudEnviador;
use App\Services\EvolutionService;
use Filament\Resources\Pages\CreateRecord;

class CreateChannel extends CreateRecord
{
    protected static string $resource = ChannelResource::class;

    /**
     * O que acontece depois de salvar depende do TIPO.
     *
     * Antes esta pagina criava instancia na Evolution para qualquer canal, sem perguntar.
     * Era invisivel enquanto so existia um tipo; com o canal oficial passou a criar
     * instancia inutil na Evolution e a deixar o canal parecendo desconectado.
     */
    protected function afterCreate(): void
    {
        $canal = $this->record->refresh();

        if ($canal->tipo === Channel::META_CLOUD) {
            $this->conferirNaMeta($canal);

            return;
        }

        // Falha aqui nao impede o canal de existir: fica registrada e o botao
        // Conectar tenta de novo.
        try {
            app(EvolutionService::class)->createInstance($canal->instance_name, $canal->webhookUrl());
        } catch (\Throwable $e) {
            $canal->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /**
     * No oficial nao existe QR Code para provar que o canal esta de pe — existe pergunta
     * a API. Conferir na hora do cadastro troca "salvei e espero que funcione" por uma
     * resposta: se a credencial ou o Phone Number ID estiverem errados, aparece agora, e
     * nao no primeiro cliente esperando resposta.
     */
    private function conferirNaMeta(Channel $canal): void
    {
        $r = app(MetaCloudEnviador::class)->conferir($canal);

        if (! $r['ok']) {
            $canal->update(['ultimo_erro' => $r['erro']]);

            return;
        }

        $canal->update([
            // 'open' porque no caminho oficial nao ha sessao para cair: se a credencial e
            // o numero respondem, o canal opera. Sem isto o diagnostico contaria o canal
            // como desconectado e gritaria CRITICO para sempre.
            'status'        => 'open',
            'conectado_em'  => now(),
            'ultimo_erro'   => null,
            'telefone_e164' => $canal->telefone_e164
                ?: '+'.preg_replace('/\D+/', '', (string) $r['numero']),
        ]);
    }
}
