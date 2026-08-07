<?php

namespace App\Services;

use App\Jobs\SendTextMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;

/**
 * Pesquisa de satisfacao no fim do atendimento.
 *
 * O PROBLEMA QUE DOMINA ESTE ARQUIVO: a nota chega DEPOIS do encerramento, e no WhatsApp isso
 * quer dizer que ela chega numa CONVERSA NOVA. O cliente responde "5", o sistema abre um
 * atendimento novo, e o atendente ve aparecer em Novos uma conversa cujo unico conteudo e o
 * numero 5. Se ninguem tratar isso, a pesquisa cria mais trabalho do que informacao — e a
 * primeira coisa que o dono faz e desligar.
 *
 * Entao, quando a resposta e uma nota valida: ela e gravada na conversa QUE FOI ENCERRADA, e a
 * conversa recem-aberta e fechada na hora. O cliente nao percebe nada; o atendente tambem nao,
 * que e o ponto.
 */
class PesquisaDeSatisfacao
{
    /** Quanto tempo depois do envio uma resposta ainda conta como nota. */
    public const HORAS = 24;

    public const TEXTO_PADRAO = 'Obrigado pelo contato! De 1 a 5, como você avalia o nosso atendimento? '
        .'Responda só com o número.';

    /** Manda a pergunta, se a conta pediu. Chamado ao encerrar o atendimento. */
    public function perguntar(Conversation $conversa): void
    {
        $conta = Tenant::find($conversa->tenant_id);

        if (! $conta?->pesquisa_ativa) {
            return;
        }

        // Fora da janela de 24h a API oficial recusa texto livre, e o job marcaria a mensagem
        // como falha. Perguntar so quando da para perguntar evita encher a conversa de bolha
        // vermelha por causa de uma pesquisa que ninguem pediu.
        if (! $conversa->podeEnviarLivre()) {
            return;
        }

        $texto = trim((string) $conta->pesquisa_texto) ?: self::TEXTO_PADRAO;

        $mensagem = Message::create([
            'tenant_id'       => $conversa->tenant_id,
            'conversation_id' => $conversa->id,
            'channel_id'      => $conversa->channel_id,
            'direcao'         => 'out',
            'tipo'            => 'text',
            'corpo'           => $texto,
            // automatica: nao e o atendente falando, e nao deve contar como resposta dele em
            // nenhum relatorio de tempo.
            'automatica'      => true,
            'status'          => Message::STATUS_QUEUED,
        ]);

        $conversa->forceFill(['pesquisa_enviada_em' => now()])->save();

        SendTextMessage::dispatch($mensagem->id);
    }

    /**
     * Esta mensagem que acabou de chegar e a nota da pesquisa?
     *
     * Devolve true quando consumiu a mensagem como nota — quem chama usa isso para nao tratar
     * como conversa de verdade.
     */
    public function talvezRegistrar(Message $mensagem): bool
    {
        $nota = $this->lerNota((string) $mensagem->corpo);

        if ($nota === null) {
            return false;
        }

        $conversa = $mensagem->conversation;

        // A pesquisa foi mandada na conversa ANTERIOR do mesmo contato — a que foi encerrada.
        $encerrada = Conversation::withoutGlobalScope('tenant')
            ->where('tenant_id', $mensagem->tenant_id)
            ->where('contact_id', $conversa->contact_id)
            ->whereNotNull('pesquisa_enviada_em')
            ->whereNull('satisfacao')
            ->where('pesquisa_enviada_em', '>=', now()->subHours(self::HORAS))
            ->latest('pesquisa_enviada_em')
            ->first();

        if (! $encerrada) {
            // Numero solto sem pesquisa aberta e so um numero. "3" pode ser a quantidade que o
            // cliente quer comprar, e virar nota de satisfacao seria inventar dado.
            return false;
        }

        $encerrada->forceFill([
            'satisfacao'    => $nota,
            'satisfacao_em' => now(),
        ])->save();

        // A conversa que a resposta abriu nao e atendimento: fecha na hora, sem contar como
        // nao lida. Deixar aberta faria a pesquisa gerar fila.
        if ($conversa->id !== $encerrada->id) {
            $conversa->forceFill([
                'status'    => Conversation::ARQUIVADA,
                'nao_lidas' => 0,
            ])->save();
        }

        return true;
    }

    /**
     * Le a nota de um texto, e so aceita o que e claramente uma nota.
     *
     * "5" conta. "5!" e "nota 5" tambem, porque e assim que gente escreve. Um texto com mais
     * de uma dezena de caracteres nao conta, mesmo tendo um numero dentro: "vou levar 5 caixas"
     * nao e avaliacao, e transformar isso em nota poluiria o unico numero que o dono vai olhar.
     */
    private function lerNota(string $texto): ?int
    {
        $limpo = trim($texto);

        if ($limpo === '' || mb_strlen($limpo) > 10) {
            return null;
        }

        if (! preg_match('/(?<!\d)([1-5])(?!\d)/u', $limpo, $m)) {
            return null;
        }

        // Se sobrar letra demais depois de tirar o numero, nao era uma nota.
        $resto = preg_replace('/[^\p{L}]/u', '', $limpo);

        if (mb_strlen((string) $resto) > 5) {
            return null;
        }

        return (int) $m[1];
    }
}
