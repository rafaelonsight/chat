<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Support\DataPtBr;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * O convite de um compromisso, por e-mail.
 *
 * ENFILEIRADO: servidor de SMTP demora, e cinco convidados sao cinco conexoes. Segurar a tela
 * enquanto isso acontece faria a pessoa clicar de novo achando que travou — e ai saem dez
 * convites.
 *
 * O ASSUNTO CARREGA A DATA. E-mail de convite chega no meio de vinte outros, e "Reuniao de
 * orcamento" sem data nao diz se e para hoje ou para o mes que vem — que e a unica coisa que
 * quem le precisa saber antes de abrir.
 */
class ConviteDeCompromisso extends Notification implements ShouldQueue
{
    use SerializesModels;

    public function __construct(
        private readonly Appointment $compromisso,
        private readonly ?string $linkDaSala = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $quando = $this->compromisso->comeca_em;
        $quem = $this->compromisso->user?->name;

        $mensagem = (new MailMessage)
            ->subject($this->compromisso->titulo.' — '.$quando->format('d/m \à\s H:i'))
            ->greeting(__('ui_d3a0420c5887'))
            ->line(__('ui_e925d615d253').$this->compromisso->titulo.'**.')
            ->line(__('ui_b356d64b0414').DataPtBr::porExtenso($quando).' às '.$quando->format('H:i'))
            ->line(__('ui_f0e1900ac6c8').($this->compromisso->duracao_min ?: 30).' minutos');

        if ($quem) {
            $mensagem->line(__('ui_b0a69e2ebff6').$quem);
        }

        if ($this->compromisso->descricao) {
            $mensagem->line(__('ui_3fcd7398f6ad').$this->compromisso->descricao);
        }

        // O botao so existe quando ha sala: botao "Entrar" numa reuniao presencial manda a
        // pessoa para uma tela de camera que ninguem vai atender.
        if ($this->linkDaSala) {
            $mensagem
                ->line(__('ui_9c78818d3b29'))
                ->action(__('ui_3eadfe35dedf'), $this->linkDaSala)
                // No e-mail o risco e o mesmo: quem abre pelo aplicativo do celular tambem cai
                // numa janela embutida sem camera.
                ->line(\App\Services\Video\Chamada::AVISO_DO_NAVEGADOR);
        }

        /*
         * A RESPOSTA VAI PARA QUEM MARCOU, e nao para a caixa do sistema.
         *
         * O convidado que nao pode comparecer responde ao e-mail — e o comportamento mais
         * previsivel que existe. Sem isto, a resposta cai numa caixa que ninguem le: a pessoa
         * acha que avisou, e quem marcou descobre na hora da reuniao.
         *
         * O remetente continua sendo o do produto, e nao o e-mail do usuario: o SPF do dominio
         * dele nao autoriza este servidor, e o convite iria direto para o spam.
         */
        if ($dono = $this->compromisso->user) {
            if (filled($dono->email)) {
                $mensagem->replyTo($dono->email, $dono->name);
            }
        }

        return $mensagem
            ->line(__('ui_6c075854bad4'))
            ->salutation(__('ui_2e6ba7dc8625').config('app.name'));
    }
}
