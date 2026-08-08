<?php

namespace App\Http\Controllers;

use App\Events\MessageStored;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * O chat que mora no site do cliente.
 *
 * SEM SESSAO E SEM LOGIN: quem fala aqui e um visitante que acabou de chegar num site. A conta
 * sai da CHAVE do canal, que viaja no HTML de quem instalou o widget — e nunca do corpo da
 * requisicao. Se um dia alguem aceitar tenant vindo do corpo aqui, e exatamente aqui que
 * comeca o vazamento entre clientes.
 *
 * A IDENTIDADE DO VISITANTE E UMA CHAVE NO NAVEGADOR DELE. Ela vira o jid do contato, e e o que
 * faz quem volta amanha cair na mesma conversa em vez de virar um contato novo por visita. Quem
 * troca de aparelho vira outra pessoa — e isso e honesto: nao temos como saber que e a mesma.
 *
 * O TETO E POR IP E POR CHAVE. Rota publica que grava linha e a que enche a tabela de graca, e
 * o widget fica na internet aberta, em pagina que qualquer robo visita.
 */
class ChatDoSiteController extends Controller
{
    /** Quantas mensagens um visitante manda por minuto antes de a porta fechar. */
    private const POR_MINUTO = 12;

    /** O comeco da conversa: acha ou cria o visitante, e devolve o que ja foi dito. */
    public function abrir(Request $pedido, string $chave)
    {
        $canal = $this->canal($chave);

        if (! $canal) {
            return response()->json(['erro' => 'Canal não encontrado.'], 404);
        }

        $token = $this->tokenValido($pedido->input('token'));

        return TenantContext::runAs($canal->tenant_id, function () use ($canal, $pedido, $token) {
            $nome = Str::limit(trim((string) $pedido->input('nome')), 60, '');

            [$contato, $conversa] = $this->visitante($canal, $token, $nome);

            return response()->json([
                'token'     => $token,
                'saudacao'  => $canal->site_saudacao,
                'mensagens' => $this->mensagensDe($conversa, 0),
            ]);
        });
    }

    /** O visitante escreveu. */
    public function mandar(Request $pedido, string $chave)
    {
        $canal = $this->canal($chave);

        if (! $canal) {
            return response()->json(['erro' => 'Canal não encontrado.'], 404);
        }

        $corpo = trim((string) $pedido->input('corpo'));

        if ($corpo === '') {
            return response()->json(['erro' => 'Escreva alguma coisa.'], 422);
        }

        $trava = 'site:'.$chave.':'.$pedido->ip();

        if (RateLimiter::tooManyAttempts($trava, self::POR_MINUTO)) {
            return response()->json(['erro' => 'Muitas mensagens seguidas. Espere um instante.'], 429);
        }

        RateLimiter::hit($trava, 60);

        $token = $this->tokenValido($pedido->input('token'));

        return TenantContext::runAs($canal->tenant_id, function () use ($canal, $pedido, $token, $corpo) {
            $nome = Str::limit(trim((string) $pedido->input('nome')), 60, '');

            [$contato, $conversa] = $this->visitante($canal, $token, $nome);

            $mensagem = Message::create([
                'tenant_id'       => $canal->tenant_id,
                'conversation_id' => $conversa->id,
                'channel_id'      => $canal->id,
                'direcao'         => 'in',
                'tipo'            => 'text',
                'corpo'           => Str::limit($corpo, 4000, ''),
                'external_id'     => 'site_'.Str::lower(Str::random(20)),
                'status'          => Message::STATUS_DELIVERED,
                'enviada_em'      => now(),
            ]);

            $conversa->increment('nao_lidas');

            /*
             * ultima_entrada_em SIM: aqui quem procurou foi o visitante, e a conversa acabou de
             * receber palavra de cliente. E o mesmo criterio do WhatsApp — a diferenca e que
             * neste canal nao existe janela de 24h para respeitar.
             */
            $conversa->update([
                'ultima_msg_em'     => now(),
                'ultima_entrada_em' => now(),
            ]);

            broadcast(new MessageStored($mensagem->refresh()));

            return response()->json([
                'token'     => $token,
                'mensagens' => $this->mensagensDe($conversa, (int) $pedido->input('desde', 0)),
            ]);
        });
    }

    /**
     * O que apareceu desde a ultima olhada.
     *
     * Sondagem e nao aviso empurrado: o widget roda no site de terceiro, e abrir canal de tempo
     * real para visitante anonimo seria montar infraestrutura de conexao viva para quem, na
     * maioria das vezes, vai fechar a aba em trinta segundos.
     */
    public function mensagens(Request $pedido, string $chave)
    {
        $canal = $this->canal($chave);

        if (! $canal) {
            return response()->json(['erro' => 'Canal não encontrado.'], 404);
        }

        $token = $this->tokenValido($pedido->query('token'));

        return TenantContext::runAs($canal->tenant_id, function () use ($canal, $pedido, $token) {
            $contato = Contact::where('jid', $this->jidDo($token))->first();

            if (! $contato) {
                return response()->json(['mensagens' => []]);
            }

            $conversa = Conversation::where('channel_id', $canal->id)
                ->where('contact_id', $contato->id)
                ->latest('id')
                ->first();

            return response()->json([
                'mensagens' => $conversa ? $this->mensagensDe($conversa, (int) $pedido->query('desde', 0)) : [],
            ]);
        });
    }

    // ------------------------------------------------------------------ tripas

    private function canal(string $chave): ?Channel
    {
        if (mb_strlen($chave) < 10) {
            return null;
        }

        return Channel::withoutGlobalScope('tenant')
            ->where('tipo', Channel::SITE)
            ->where('site_key', $chave)
            ->first();
    }

    /**
     * O token do visitante, ou um novo.
     *
     * NUNCA se confia no formato do que veio: o token e usado para montar um jid, e jid
     * montado com texto de fora e como se planta lixo — ou pior — no lugar da identidade de
     * outra pessoa.
     */
    private function tokenValido(mixed $bruto): string
    {
        $token = is_string($bruto) ? trim($bruto) : '';

        return preg_match('/^[A-Za-z0-9]{24,48}$/', $token) === 1
            ? $token
            : Str::random(32);
    }

    private function jidDo(string $token): string
    {
        return 'site_'.$token;
    }

    /** @return array{0: Contact, 1: Conversation} */
    private function visitante(Channel $canal, string $token, string $nome): array
    {
        $contato = Contact::firstOrCreate(
            ['jid' => $this->jidDo($token)],
            [
                'tenant_id' => $canal->tenant_id,
                'tipo'      => Contact::PESSOA,
                'nome'      => $nome ?: 'Visitante do site',
            ],
        );

        // O nome digitado depois vale mais que o provisorio, mas nunca apaga um nome que a
        // equipe ja corrigiu no cadastro.
        if ($nome && $contato->nome === 'Visitante do site') {
            $contato->update(['nome' => $nome]);
        }

        $conversa = Conversation::abertaOuNova($canal->id, $contato->id, $canal->tenant_id);

        return [$contato, $conversa];
    }

    /** @return list<array{id: int, de: string, corpo: string, hora: string}> */
    private function mensagensDe(Conversation $conversa, int $desde): array
    {
        return $conversa->messages()
            ->where('id', '>', $desde)
            // Apagada nao volta para a tela de quem ja viu sumir.
            ->whereNull('apagada_em')
            ->orderBy('id')
            ->limit(80)
            ->get()
            ->map(fn (Message $m) => [
                'id'    => $m->id,
                'de'    => $m->direcao === 'in' ? 'visitante' : 'atendimento',
                'corpo' => (string) ($m->corpo ?: $m->legenda),
                'hora'  => $m->created_at->format('H:i'),
            ])
            ->values()
            ->all();
    }
}
