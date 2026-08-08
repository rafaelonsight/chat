<?php

namespace App\Livewire\Video;

use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Models\MeetingRequest;
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

    // ------------------------------------------------------- a sala de espera
    /** Bateu na porta e esta do lado de fora. */
    public bool $aguardando = false;

    public ?int $pedidoId = null;

    /**
     * O historico do bate-papo, congelado no momento de entrar.
     *
     * Guardado numa propriedade em vez de consultado a cada desenho: com a espera ligada a
     * tela se redesenha de tres em tres segundos, e uma consulta por desenho seria uma
     * consulta a cada tres segundos por pessoa na sala, para reentregar o que ja esta na tela.
     */
    public array $historicoInicial = [];

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

        /*
         * A PORTARIA.
         *
         * Quem e da conta entra direto: o atendente que abriu a sala nao vai pedir licenca
         * para entrar nela. A espera existe contra quem esta fora — link de reuniao circula em
         * grupo de WhatsApp, e sem ela basta um encaminhamento para alguem entrar sem que
         * ninguem perceba.
         */
        if (! $this->souDaEquipe() && $reuniao->sala_de_espera) {
            $pedido = MeetingRequest::create([
                'tenant_id'  => $reuniao->tenant_id,
                'meeting_id' => $reuniao->id,
                'nome'       => trim($this->nome),
            ]);

            $this->pedidoId = $pedido->id;
            $this->aguardando = true;

            return;
        }

        $this->abrirPorta($livekit);
    }

    /**
     * De tres em tres segundos, enquanto espera.
     *
     * Sondagem e nao aviso empurrado de proposito: quem espera nao tem conta, nao tem sessao e
     * nao pode assinar canal privado nenhum. Uma pergunta a cada tres segundos, por uma pessoa
     * que esta parada olhando para a tela, e barata; a alternativa seria abrir um canal de
     * tempo real para desconhecido, que e superficie que ninguem precisa.
     */
    public function verificarPedido(Livekit $livekit): void
    {
        if (! $this->aguardando || ! $this->pedidoId) {
            return;
        }

        $pedido = MeetingRequest::withoutGlobalScope('tenant')->find($this->pedidoId);

        if (! $pedido) {
            $this->aguardando = false;

            return;
        }

        if ($pedido->recusado()) {
            $this->aguardando = false;
            $this->recado = 'Quem organiza a reunião não liberou sua entrada.';

            return;
        }

        if ($pedido->vencido()) {
            $this->aguardando = false;
            $this->recado = 'Ninguém respondeu a tempo. Tente entrar de novo.';

            return;
        }

        if ($pedido->aceito()) {
            $this->aguardando = false;
            $this->abrirPorta($livekit);
        }
    }

    /** Emite o token e coloca a pessoa dentro. */
    private function abrirPorta(Livekit $livekit): void
    {
        $reuniao = $this->reuniao();

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
        $this->historicoInicial = $this->historico();
        $this->entrou = true;
    }

    // ----------------------------------------------------------- do lado de dentro

    /**
     * Quem esta batendo na porta agora.
     *
     * So para quem e da conta: a lista tem o nome de quem esta esperando, e nome de terceiro
     * nao se mostra para outro terceiro.
     */
    public function pedidos()
    {
        if (! $this->souDaEquipe() || ! $this->entrou) {
            return collect();
        }

        return $this->reuniao()->requests()->pendentes()->orderBy('id')->get();
    }

    /**
     * Cala o microfone de um participante.
     *
     * So quem e da conta. E a defesa mora AQUI e nao no botao: a identidade chega do navegador,
     * e navegador e do outro lado.
     */
    public function silenciar(string $identidade, Livekit $livekit): void
    {
        if (! $this->souDaEquipe() || ! $this->entrou) {
            return;
        }

        try {
            $achou = $livekit->silenciarParticipante($this->reuniao()->sala, $identidade);

            $this->recado = $achou
                ? null
                : 'Essa pessoa já está sem microfone ligado.';
        } catch (\Throwable $e) {
            report($e);

            $this->recado = 'Não consegui silenciar agora. Tente de novo.';
        }
    }

    /**
     * Tira alguem da sala.
     *
     * E DERRUBA O LINK JUNTO quando quem sai e convidado de fora. Sem isso, a pessoa que
     * acabou de ser removida abre o mesmo endereco e volta — e quem removeu descobre que a
     * acao nao valia nada. Com a portaria ligada, ela ao menos volta para a fila; sem
     * portaria, voltaria direto.
     */
    public function remover(string $identidade, Livekit $livekit): void
    {
        if (! $this->souDaEquipe() || ! $this->entrou) {
            return;
        }

        try {
            $livekit->removerParticipante($this->reuniao()->sala, $identidade);
        } catch (\Throwable $e) {
            report($e);

            $this->recado = 'Não consegui tirar a pessoa da sala agora.';

            return;
        }

        // Convidado removido volta a precisar de licenca. Quem e da equipe nao: tirar um colega
        // da sala nao pode trancar o sistema para ele.
        if (! str_starts_with($identidade, 'equipe_')) {
            $this->reuniao()->update(['sala_de_espera' => true]);
        }
    }

    public function aceitar(int $id): void
    {
        $this->decidirPedido($id, MeetingRequest::ACEITO);
    }

    public function recusar(int $id): void
    {
        $this->decidirPedido($id, MeetingRequest::RECUSADO);
    }

    private function decidirPedido(int $id, string $status): void
    {
        if (! $this->souDaEquipe()) {
            return;
        }

        $pedido = $this->reuniao()->requests()->whereKey($id)->first();

        $pedido?->decidir($status, auth()->id());
    }

    /**
     * Liga e desliga a portaria no meio da reuniao.
     *
     * Serve para o caso real de uma reuniao aberta — treinamento, apresentacao — em que
     * liberar um por um vira trabalho de porteiro e ninguem consegue prestar atencao no que
     * esta sendo dito.
     */
    public function alternarSalaDeEspera(): void
    {
        if (! $this->souDaEquipe()) {
            return;
        }

        $reuniao = $this->reuniao();

        $reuniao->update(['sala_de_espera' => ! $reuniao->sala_de_espera]);

        // Desligar a portaria libera quem ja estava na fila: deixar gente esperando por uma
        // porta que acabou de ser destrancada seria esquecimento, nao decisao.
        if (! $reuniao->sala_de_espera) {
            $reuniao->requests()->pendentes()->get()
                ->each(fn (MeetingRequest $p) => $p->decidir(MeetingRequest::ACEITO, auth()->id()));
        }
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
