<?php

namespace App\Services\Agendamento;

use App\Jobs\SendTextMessage;
use App\Models\Appointment;
use App\Models\AppointmentGuest;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Notifications\ConviteDeCompromisso;
use App\Support\DataPtBr;
use Illuminate\Support\Facades\Notification;

/**
 * Avisar os convidados de um compromisso.
 *
 * TRES CAMINHOS PARA A MESMA INFORMACAO, e o texto e um so: e-mail, WhatsApp, e o bloco que a
 * pessoa copia para colar onde quiser. Se cada caminho montasse o proprio texto, o dia em que
 * alguem corrigisse a hora num deles os outros dois continuariam errados.
 *
 * O COPIAR EXISTE PORQUE OS OUTROS DOIS FALHAM. Convidado sem e-mail e sem WhatsApp cadastrado,
 * grupo de familia, Telegram, o cliente que so usa Instagram: nao da para prever por onde a
 * pessoa fala. Um bloco de texto pronto resolve todos esses casos de uma vez, e nao depende de
 * integracao nenhuma.
 */
class Convite
{
    /**
     * O bloco para copiar e colar.
     *
     * Sem markdown e sem emoji de enfeite: isto vai para WhatsApp, para Telegram e para caixa
     * de texto de sistema alheio, e cada um estraga uma formatacao diferente. Linha em branco
     * separando o link e proposital — quase todo aplicativo transforma URL em link clicavel
     * quando ela esta sozinha na linha.
     */
    public function texto(Appointment $compromisso): string
    {
        $quando = $compromisso->comeca_em;

        $linhas = [
            $compromisso->titulo,
            '',
            DataPtBr::porExtenso($quando).' às '.$quando->format('H:i'),
            'Duração: '.($compromisso->duracao_min ?: 30).' minutos',
        ];

        if ($compromisso->user) {
            $linhas[] = 'Com: '.$compromisso->user->name;
        }

        if ($compromisso->descricao) {
            $linhas[] = '';
            $linhas[] = $compromisso->descricao;
        }

        if ($reuniao = $compromisso->meeting) {
            $linhas[] = '';
            $linhas[] = 'A reunião é por vídeo. No horário, toque no link:';
            $linhas[] = '';
            $linhas[] = $reuniao->url();
        }

        return implode("\n", $linhas);
    }

    /**
     * Manda por e-mail para quem tem e-mail.
     *
     * Notificacao sem destinatario cadastrado (`route`) porque convidado nao e usuario do
     * sistema: nao tem login, nem preferencia, nem nada para guardar.
     *
     * @return array{enviados: int, sem_email: int}
     */
    public function porEmail(Appointment $compromisso): array
    {
        $enviados = 0;
        $semEmail = 0;

        foreach ($compromisso->guests as $convidado) {
            if (! $convidado->temEmail()) {
                $semEmail++;

                continue;
            }

            Notification::route('mail', [$convidado->email => $convidado->nome])
                ->notify(new ConviteDeCompromisso($compromisso, $compromisso->meeting?->url()));

            $convidado->update(['email_em' => now()]);
            $enviados++;
        }

        return ['enviados' => $enviados, 'sem_email' => $semEmail];
    }

    /**
     * Manda pelo WhatsApp, por um canal escolhido, para os contatos escolhidos.
     *
     * O CANAL E ESCOLHIDO E NAO ADIVINHADO. Com mais de um numero, mandar pelo de menor id
     * sairia do numero errado sem avisar — no canal oficial isso custa dinheiro e chega ao
     * cliente com a identidade trocada.
     *
     * QUEM NAO PODE RECEBER E DEVOLVIDO PELO NOME, e nao engolido: no canal oficial, fora da
     * janela de 24 horas so sai template aprovado, e a pessoa precisa saber quais dos convites
     * ela ainda vai ter de mandar a mao.
     *
     * @param  list<int>  $contactIds
     * @return array{enviados: int, fora: list<string>}
     */
    public function porWhatsapp(Appointment $compromisso, Channel $canal, array $contactIds): array
    {
        $texto = $this->texto($compromisso);
        $enviados = 0;
        $fora = [];

        $contatos = Contact::whereKey($contactIds)->get();

        foreach ($contatos as $contato) {
            $conversa = Conversation::abertaOuNova($canal->id, $contato->id, $compromisso->tenant_id);

            if (! $conversa->podeEnviarLivre()) {
                $fora[] = $contato->nomeExibicao();

                continue;
            }

            $mensagem = Message::create([
                'tenant_id'       => $compromisso->tenant_id,
                'conversation_id' => $conversa->id,
                'channel_id'      => $canal->id,
                'direcao'         => 'out',
                'tipo'            => 'text',
                'corpo'           => $texto,
                'automatica'      => true,
                'status'          => Message::STATUS_QUEUED,
            ]);

            SendTextMessage::dispatch($mensagem->id);

            $conversa->update(['ultima_msg_em' => now()]);

            // Avisado por WhatsApp entra na lista de convidados: sem isso, "quem eu ja avisei?"
            // ficaria sem resposta na proxima vez que a tela abrisse.
            $this->registrar($compromisso, $contato)->update(['whatsapp_em' => now()]);

            $enviados++;
        }

        return ['enviados' => $enviados, 'fora' => $fora];
    }

    /** Poe (ou acha) o contato na lista de convidados. */
    public function registrar(Appointment $compromisso, Contact $contato): AppointmentGuest
    {
        return AppointmentGuest::firstOrCreate(
            ['appointment_id' => $compromisso->id, 'contact_id' => $contato->id],
            [
                'tenant_id' => $compromisso->tenant_id,
                'nome'      => $contato->nomeExibicao(),
                'email'     => $contato->email,
            ],
        );
    }
}
