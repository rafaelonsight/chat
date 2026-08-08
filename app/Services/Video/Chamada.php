<?php

namespace App\Services\Video;

use App\Jobs\SendTextMessage;
use App\Models\Appointment;
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

    /**
     * A linha sobre abrir no navegador.
     *
     * No iPhone, link tocado dentro do WhatsApp abre numa janela embutida que a Apple nao
     * autoriza a usar camera nem microfone. Nao ha o que a pagina faca a respeito — so avisar
     * antes. E como o link deste produto viaja pelo WhatsApp por projeto, quase todo convidado
     * chega exatamente por esse caminho.
     *
     * Curta de proposito: mensagem de WhatsApp longa ninguem le ate o fim, e a parte nao lida
     * seria justamente esta.
     */
    public const AVISO_DO_NAVEGADOR = 'Se a câmera não funcionar, abra o link no navegador (Safari ou Chrome).';

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

    /**
     * A sala de um compromisso marcado.
     *
     * O `comecou_em` DA SALA E O HORARIO DO COMPROMISSO, e nao agora. Sem isso, marcar uma
     * visita para semana que vem criaria um link que vence hoje a noite: as doze horas de
     * validade contam de quando a sala vale, e a sala de uma reuniao de quinta vale na quinta.
     *
     * Entrar ANTES do horario continua liberado de proposito — quem chega dez minutos mais
     * cedo esperando na sala e o comportamento normal de reuniao, e barrar isso so criaria
     * gente batendo na porta.
     */
    public function paraCompromisso(Appointment $compromisso): Meeting
    {
        $conversa = $this->conversaDoContato($compromisso->contact_id);

        return Meeting::abrir([
            'tenant_id'       => $compromisso->tenant_id,
            'criada_por'      => $compromisso->criado_por,
            'contact_id'      => $compromisso->contact_id,
            'conversation_id' => $conversa?->id,
            'appointment_id'  => $compromisso->id,
            'titulo'          => $compromisso->titulo,
            'comecou_em'      => $compromisso->comeca_em,
        ]);
    }

    /**
     * Remarcou o compromisso: a sala anda com ele.
     *
     * Sem isto, arrastar a visita de terca para quinta deixaria o link vencendo na terca — e o
     * cliente descobriria isso na quinta, na hora de entrar.
     */
    public function sincronizarHorario(Appointment $compromisso): void
    {
        $reuniao = $compromisso->meeting;

        if ($reuniao && ! $reuniao->comecou_em->equalTo($compromisso->comeca_em)) {
            $reuniao->update(['comecou_em' => $compromisso->comeca_em]);
        }
    }

    /**
     * A conversa aberta daquele contato, se houver.
     *
     * Nao abre conversa nova: se nao ha nenhuma, o link vai a mao. Abrir por conta propria
     * poria na caixa de entrada um atendimento que ninguem pediu.
     */
    public function conversaDoContato(?int $contactId): ?Conversation
    {
        if (! $contactId) {
            return null;
        }

        return Conversation::where('contact_id', $contactId)
            ->where('status', '!=', Conversation::ARQUIVADA)
            ->latest('ultima_msg_em')
            ->first();
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
    public function avisar(Meeting $reuniao, ?string $texto = null): string
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
                // O aviso da janela do WhatsApp vai junto de proposito: no iPhone, link
                // aberto por dentro do aplicativo cai numa janela que a Apple nao autoriza a
                // usar camera. Sem a linha, cada convidado descobre isso sozinho no meio da
                // chamada — e conclui que o sistema nao funciona.
                'corpo'           => $texto ?: "Vamos falar por vídeo? É só tocar no link, não precisa instalar nada:\n"
                    .$reuniao->url()
                    ."\n\n".self::AVISO_DO_NAVEGADOR,
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

    /**
     * O convite de uma reuniao MARCADA.
     *
     * Texto diferente do "vamos falar agora" de proposito: quem recebe "e so tocar no link"
     * para uma reuniao de quinta toca na quinta-feira que nao existe — toca agora, entra numa
     * sala vazia e conclui que nao funciona.
     */
    public function convite(Meeting $reuniao): string
    {
        $quando = $reuniao->comecou_em;

        $linhas = [
            'Sua reunião está marcada para '.$quando->format('d/m').' às '.$quando->format('H:i').'.',
            '',
            'No horário, é só tocar aqui — não precisa instalar nada:',
            $reuniao->url(),
            '',
            self::AVISO_DO_NAVEGADOR,
        ];

        return implode("\n", $linhas);
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
