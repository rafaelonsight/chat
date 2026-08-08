<?php

namespace App\Livewire\Video;

use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Services\Video\Livekit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * A sala de video, para os dois lados.
 *
 * UM ENDERECO SO, e nao um para a equipe e outro para o cliente. O link e a credencial: quem o
 * tem entra, e o atendente que abriu a chamada tem o mesmo link que mandou. Duas telas
 * separadas seriam duas chances de o mesmo erro morar num lugar so.
 *
 * O QUE MUDA E QUEM ESTA LOGADO. Sessao da mesma conta vira anfitriao — pode encerrar para
 * todo mundo — e essa diferenca mora no TOKEN, nao na tela: botao escondido qualquer um faz
 * aparecer, e quem obedece e o servidor de midia.
 *
 * A CONTA VEM DO TOKEN DA REUNIAO. A URL do convidado nao carrega tenant nenhum, e o escopo
 * global nao tem de onde adivinhar; entao ela e fixada a cada requisicao, inclusive nas que o
 * Livewire faz depois — nelas o mount() ja nao roda.
 */
class Sala extends Component
{
    public string $token = '';

    public string $nome = '';

    public bool $entrou = false;

    public ?string $tokenDeVideo = null;

    public ?string $urlDeVideo = null;

    public ?string $recado = null;

    public function mount(string $token): void
    {
        $this->token = $token;

        $reuniao = $this->reuniao();

        if ($this->souDaEquipe()) {
            $this->nome = (string) auth()->user()->name;
        } elseif ($reuniao->contact?->nome) {
            // O nome que a equipe ja tem no cadastro entra sozinho: pedir de novo a quem a
            // gente ja conhece e fazer a pessoa digitar o que esta na nossa frente.
            $this->nome = $reuniao->contact->nome;
        }
    }

    public function booted(): void
    {
        TenantContext::set($this->reuniao()->tenant_id);
    }

    public function reuniao(): Meeting
    {
        return once(fn () => Meeting::withoutGlobalScope('tenant')
            ->with('contact')
            ->where('token_convidado', $this->token)
            ->firstOr(fn () => abort(404)));
    }

    /** Sessao aberta na mesma conta da reuniao. */
    public function souDaEquipe(): bool
    {
        return auth()->check() && auth()->user()->tenant_id === $this->reuniao()->tenant_id;
    }

    public function entrar(Livekit $livekit): void
    {
        $reuniao = $this->reuniao();

        if (! $livekit->configurado()) {
            $this->recado = 'A chamada de vídeo não está disponível neste servidor.';

            return;
        }

        if (! $reuniao->aberta()) {
            $this->recado = 'Esta reunião já foi encerrada.';

            return;
        }

        // Separado de encerrada de proposito: para quem recebeu o link, "expirou" e "quem te
        // convidou encerrou" pedem providencias diferentes.
        if ($reuniao->expirada()) {
            $this->recado = 'O link desta reunião expirou. Peça um link novo a quem te convidou.';

            return;
        }

        $this->validate(
            ['nome' => 'required|string|min:2|max:80'],
            ['nome.required' => 'Diga como quer aparecer na chamada.'],
        );

        if (! $this->souDaEquipe() && ! $this->dentroDoLimiteDeTentativas()) {
            return;
        }

        try {
            // A sala e criada com o teto, e o teto so vale porque quem o cumpre e o servidor
            // que aceita a conexao. Contar aqui antes nao fecharia corrida nenhuma: dois
            // convidados no mesmo instante leem o mesmo "antes" e entram os dois.
            $livekit->criarSala($reuniao->sala, $reuniao->max_participantes);

            $identidade = ($this->souDaEquipe() ? 'equipe_'.auth()->id().'_' : 'convidado_').Str::random(12);

            $this->tokenDeVideo = $livekit->tokenDeSala(
                $reuniao->sala,
                $identidade,
                trim($this->nome),
                anfitriao: $this->souDaEquipe(),
            );
        } catch (\Throwable $e) {
            report($e);

            $this->recado = 'Não conseguimos abrir a sala agora. Tente de novo em instantes.';

            return;
        }

        MeetingParticipant::create([
            'tenant_id'  => $reuniao->tenant_id,
            'meeting_id' => $reuniao->id,
            'user_id'    => $this->souDaEquipe() ? auth()->id() : null,
            'nome'       => trim($this->nome),
            'entrou_em'  => now(),
        ]);

        $this->urlDeVideo = $livekit->url();
        $this->entrou = true;
    }

    /** Encerra para todo mundo. So quem e da conta. */
    public function encerrar(Livekit $livekit): void
    {
        if (! $this->souDaEquipe()) {
            return;
        }

        $reuniao = $this->reuniao();

        try {
            $livekit->encerrarSala($reuniao->sala);
        } catch (\Throwable $e) {
            // A sala pode nem ter chegado a existir no servidor de midia. Marcar como
            // encerrada aqui vale de qualquer jeito: e o que fecha o link.
            report($e);
        }

        $reuniao->encerrar();

        $this->entrou = false;
        $this->tokenDeVideo = null;
    }

    /**
     * Tela publica que grava uma linha por chamada e a que enche a tabela de graca.
     *
     * O teto e por IP e nao por reuniao: quem esta batendo na porta nao e um convidado, e
     * limitar a reuniao puniria a sala legitima com dez pessoas entrando ao mesmo tempo.
     */
    private function dentroDoLimiteDeTentativas(): bool
    {
        $chave = 'sala:'.$this->token.':'.request()->ip();

        if (RateLimiter::tooManyAttempts($chave, 10)) {
            $this->recado = 'Muitas tentativas de entrada. Aguarde um pouco e tente de novo.';

            return false;
        }

        RateLimiter::hit($chave, 600);

        return true;
    }

    /**
     * Grava um recado do bate-papo.
     *
     * QUEM ENTREGA AO VIVO E O CANAL DE DADOS DA PROPRIA SALA, e nao esta chamada: o recado
     * aparece na tela dos outros no mesmo instante, sem passar por aqui. O servidor e o
     * REGISTRO — o que fica para quem chegar depois, e para quem for ler o atendimento amanha.
     *
     * Falhar aqui nao apaga o que ja apareceu na tela de todo mundo. Perder o registro de um
     * recado e ruim; perder o recado no meio da conversa e pior.
     */
    public function gravarMensagem(string $texto): void
    {
        $texto = trim($texto);

        if ($texto === '' || ! $this->entrou) {
            return;
        }

        $reuniao = $this->reuniao();

        if (! $reuniao->aberta()) {
            return;
        }

        MeetingMessage::create([
            'tenant_id'  => $reuniao->tenant_id,
            'meeting_id' => $reuniao->id,
            'user_id'    => $this->souDaEquipe() ? auth()->id() : null,
            'nome'       => trim($this->nome) ?: 'Convidado',
            'corpo'      => mb_substr($texto, 0, MeetingMessage::LIMITE),
        ]);
    }

    /**
     * O que ja foi dito, para quem chega depois.
     *
     * Vai para a tela UMA vez, no momento de entrar. Redesenhar isto a cada recado faria o
     * Livewire mexer no HTML da sala em plena chamada — e mexer no HTML de uma chamada e como
     * a chamada cai.
     *
     * @return list<array{nome: string, corpo: string, hora: string, daEquipe: bool}>
     */
    public function historico(): array
    {
        return $this->reuniao()->messages()->with('user')->get()
            ->map(fn (MeetingMessage $m) => [
                'nome'     => $m->nome,
                'corpo'    => $m->corpo,
                'hora'     => $m->created_at->format('H:i'),
                'daEquipe' => $m->daEquipe(),
            ])
            ->all();
    }

    public function render()
    {
        $reuniao = $this->reuniao();

        return view('livewire.video.sala', [
            'reuniao' => $reuniao,
        ])->layout('components.layouts.sala', [
            'titulo' => $reuniao->titulo ?: 'Reunião',
        ]);
    }
}
