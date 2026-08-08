<?php

namespace App\Services\Video;

use App\Jobs\SendTextMessage;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\Message;

/**
 * Abrir uma sala e avisar quem tem de entrar.
 *
 * EXISTE PORQUE HA DOIS LUGARES QUE FAZEM ISSO: o botao dentro da conversa e a tela de
 * Reunioes no menu. A primeira versao morava so na conversa; o dia em que a segunda apareceu,
 * copiar as regras — reaproveitar sala aberta, respeitar a janela de 24h, nao deixar o aviso
 * derrubar a sala — seria garantir que as duas divergissem na primeira correcao.
 *
 * O QUE NAO MORA AQUI e a tela: este arquivo devolve o que aconteceu, e quem chama decide como
 * contar. Servico que escreve mensagem de erro para o usuario e servico que so serve para uma
 * tela.
 */
class Chamada
{
    /** Deu certo. */
    public const AVISADO = 'avisado';

    /** Fora da janela de 24h: neste canal a API recusa texto livre. */
    public const JANELA_FECHADA = 'janela';

    /** O provedor recusou, ou algo quebrou no caminho. */
    public const FALHOU = 'falhou';

    /** Conversa nenhuma para avisar — reuniao aberta pelo menu, sem cliente do outro lado. */
    public const SEM_CONVERSA = 'sem_conversa';

    public function __construct(private readonly Livekit $livekit) {}

    public function disponivel(): bool
    {
        return $this->livekit->configurado();
    }

    /**
     * A sala daquela conversa.
     *
     * UMA POR VEZ: duas salas abertas na mesma conversa fariam o cliente entrar numa e o
     * atendente esperar na outra. Vencida, nao se reaproveita — o link dela ja nao abre.
     */
    public function paraConversa(Conversation $conversa, ?int $criadaPor = null): Meeting
    {
        $aberta = Meeting::abertas()
            ->where('conversation_id', $conversa->id)
            ->latest('id')
            ->first();

        if ($aberta && ! $aberta->expirada()) {
            return $aberta;
        }

        return Meeting::abrir([
            'tenant_id'       => $conversa->tenant_id,
            'criada_por'      => $criadaPor,
            'contact_id'      => $conversa->contact_id,
            'conversation_id' => $conversa->id,
            'titulo'          => 'Atendimento — '.($conversa->contact?->nomeExibicao() ?: 'chamada'),
        ]);
    }

    /** Uma sala sem cliente do outro lado: reuniao de equipe, ou link para mandar a mao. */
    public function avulsa(?string $titulo = null, ?int $criadaPor = null): Meeting
    {
        return Meeting::abrir([
            'criada_por' => $criadaPor,
            'titulo'     => trim((string) $titulo) ?: 'Reunião',
        ]);
    }

    /**
     * Manda o link pela propria conversa.
     *
     * FALHA AQUI NAO DESMANCHA A SALA. Ela existe, quem abriu ja esta indo para ela, e o link
     * continua na tela para mandar do jeito que der. Aviso que falha nao pode levar embora o
     * compromisso que ele so ia anunciar.
     */
    public function avisar(Meeting $reuniao): string
    {
        $conversa = $reuniao->conversation;

        if (! $conversa) {
            return self::SEM_CONVERSA;
        }

        // No canal oficial, fora da janela de 24h so sai template aprovado. Enfileirar assim
        // mesmo daria bolha vermelha na conversa e o cliente sem link nenhum.
        if (! $conversa->podeEnviarLivre()) {
            return self::JANELA_FECHADA;
        }

        try {
            $mensagem = Message::create([
                'tenant_id'       => $conversa->tenant_id,
                'conversation_id' => $conversa->id,
                'channel_id'      => $conversa->channel_id,
                'direcao'         => 'out',
                'tipo'            => 'text',
                'corpo'           => "Vamos falar por vídeo? É só tocar no link, não precisa instalar nada:\n"
                    .$reuniao->url(),
                'status'          => Message::STATUS_QUEUED,
            ]);

            SendTextMessage::dispatch($mensagem->id);

            $conversa->update(['ultima_msg_em' => now()]);
        } catch (\Throwable $e) {
            report($e);

            return self::FALHOU;
        }

        return self::AVISADO;
    }

    /** Encerra para todos: fecha o link e derruba quem estiver dentro. */
    public function encerrar(Meeting $reuniao): void
    {
        try {
            $this->livekit->encerrarSala($reuniao->sala);
        } catch (\Throwable $e) {
            // A sala pode nem ter chegado a existir no servidor de midia. Marcar encerrada
            // aqui vale de qualquer jeito: e o que fecha o link.
            report($e);
        }

        $reuniao->encerrar();
    }
}
