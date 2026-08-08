<?php

namespace App\Services\Agendamento;

use App\Jobs\SendTextMessage;
use App\Models\Appointment;
use App\Models\BookingPage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * O cliente escolheu o horario: grava.
 *
 * O CONTATO NASCE AQUI, e nao e um cadastro paralelo. Quem reservou pelo link e um contato do
 * CRM como qualquer outro — se fosse uma tabela de "convidados" a parte, o dia em que essa
 * pessoa mandasse mensagem no WhatsApp o sistema teria dois registros dela e nenhum jeito de
 * saber que sao a mesma.
 */
class Reserva
{
    public function marcar(
        BookingPage $pagina,
        Carbon $quando,
        string $nome,
        string $telefone,
        ?string $observacao = null,
    ): Appointment {
        $e164 = PhoneNumber::toE164($telefone);

        if (! $e164) {
            throw new \InvalidArgumentException('Telefone inválido.');
        }

        return TenantContext::runAs($pagina->tenant_id, function () use ($pagina, $quando, $nome, $e164, $observacao) {
            // Conferir de novo aqui, e nao so na tela: entre ver a vaga e confirmar passam
            // minutos, e a agenda nao para nesse meio tempo.
            if (! (new Vagas($pagina))->estaLivre($quando)) {
                throw new VagaTomada;
            }

            $contato = Contact::acharOuCriarPorTelefone($e164, ['nome' => $nome]);

            // Contato que ja existia sem nome ganha o nome agora; um que ja tinha nome nao e
            // renomeado, porque o cadastro do CRM vale mais que o que o visitante digitou.
            if (blank($contato->nome)) {
                $contato->update(['nome' => $nome]);
            }

            try {
                $compromisso = Appointment::create([
                    'tenant_id'       => $pagina->tenant_id,
                    'user_id'         => $pagina->user_id,
                    'criado_por'      => null,
                    'contact_id'      => $contato->id,
                    'booking_page_id' => $pagina->id,
                    'tipo'            => Appointment::COMPROMISSO,
                    'titulo'          => $pagina->titulo.' — '.$nome,
                    'descricao'       => $observacao ?: null,
                    'comeca_em'       => $quando,
                    'duracao_min'     => $pagina->duracao_min,
                ]);
            } catch (QueryException $e) {
                // O indice unico parcial e quem decide a corrida de dois cliques no mesmo
                // segundo. Chegar aqui significa que o outro venceu.
                if (str_contains($e->getMessage(), 'appointments_vaga_unica')) {
                    throw new VagaTomada;
                }

                throw $e;
            }

            $this->confirmarNoWhatsapp($pagina, $compromisso, $contato);

            return $compromisso;
        });
    }

    /**
     * O aviso no WhatsApp, quando a pagina tem canal escolhido.
     *
     * SO COM CANAL ESCOLHIDO, e nunca por conta propria. Mandar mensagem para um numero que
     * nunca falou com a gente e exatamente o gesto que derruba um canal por QR, e quem paga
     * nao e a reserva: e o atendimento inteiro do cliente, que sai do ar junto.
     *
     * Falha aqui nao desmancha a reserva. O horario esta marcado de verdade; a mensagem e um
     * agrado, e agrado que falha nao pode levar embora o compromisso.
     */
    private function confirmarNoWhatsapp(BookingPage $pagina, Appointment $compromisso, Contact $contato): void
    {
        if (! $pagina->channel_id) {
            return;
        }

        try {
            $conversa = Conversation::abertaOuNova($pagina->channel_id, $contato->id, $pagina->tenant_id);

            // No canal oficial, fora da janela de 24h so sai template aprovado. Enfileirar
            // assim mesmo daria bolha vermelha na conversa e nenhum aviso ao cliente.
            if (! $conversa->podeEnviarLivre()) {
                return;
            }

            $mensagem = Message::create([
                'tenant_id'       => $pagina->tenant_id,
                'conversation_id' => $conversa->id,
                'channel_id'      => $pagina->channel_id,
                'direcao'         => 'out',
                'tipo'            => 'text',
                'corpo'           => $this->texto($pagina, $compromisso),
                'automatica'      => true,
                'status'          => Message::STATUS_QUEUED,
            ]);

            $conversa->update(['ultima_msg_em' => now()]);

            SendTextMessage::dispatch($mensagem->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function texto(BookingPage $pagina, Appointment $compromisso): string
    {
        $linhas = [
            'Tudo certo! Seu horário está confirmado.',
            '',
            '📅 '.$compromisso->comeca_em->format('d/m/Y').' às '.$compromisso->comeca_em->format('H:i'),
            '⏱️ '.$pagina->duracao_min.' minutos',
        ];

        if ($pagina->local) {
            $linhas[] = '📍 '.$pagina->local;
        }

        $linhas[] = '';
        $linhas[] = 'Se precisar remarcar, é só responder por aqui.';

        return implode("\n", $linhas);
    }
}
