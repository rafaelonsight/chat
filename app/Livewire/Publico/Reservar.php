<?php

namespace App\Livewire\Publico;

use App\Models\BookingPage;
use App\Services\Agendamento\Reserva;
use App\Services\Agendamento\VagaTomada;
use App\Services\Agendamento\Vagas;
use App\Support\PhoneNumber;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * A tela que o cliente do cliente ve.
 *
 * SEM LOGIN, e por isso ela e a superficie mais exposta do sistema inteiro: qualquer um com o
 * link chega aqui. Entao ela so sabe fazer uma coisa — marcar um horario que ja existe — e nao
 * mostra nada da conta alem do que o dono escreveu para ser lido.
 *
 * A CONTA VEM DO SLUG. A URL nao carrega tenant nenhum, e o escopo global nao tem de onde
 * adivinhar; entao o tenant e fixado a cada requisicao, inclusive nas que o Livewire faz
 * depois, porque nelas o mount() ja nao roda mais.
 */
class Reservar extends Component
{
    public string $slug = '';

    public string $dia = '';

    public string $quando = '';

    public string $nome = '';

    public string $telefone = '';

    public string $observacao = '';

    public ?string $confirmado = null;

    /** O link da sala, quando a reserva e por video. */
    public ?string $linkDoVideo = null;

    public ?string $recado = null;

    public function mount(string $slug): void
    {
        $this->slug = $slug;

        $this->pagina();
    }

    public function booted(): void
    {
        TenantContext::set($this->pagina()->tenant_id);
    }

    public function pagina(): BookingPage
    {
        return once(fn () => BookingPage::withoutGlobalScope('tenant')
            ->with('user')
            ->where('slug', $this->slug)
            ->firstOr(fn () => abort(404)));
    }

    public function escolherDia(string $dia): void
    {
        $this->dia = $dia;
        $this->quando = '';
        $this->recado = null;
    }

    public function escolherHora(string $quando): void
    {
        $this->quando = $quando;
        $this->recado = null;
    }

    public function voltar(): void
    {
        $this->quando = '';
        $this->recado = null;
    }

    public function confirmar(Reserva $reserva): void
    {
        $this->validate([
            'nome'       => 'required|string|min:2|max:80',
            'telefone'   => 'required|string|max:25',
            'observacao' => 'nullable|string|max:500',
            'quando'     => 'required|date',
        ], [
            'nome.required'     => 'Como podemos te chamar?',
            'telefone.required' => 'Precisamos do WhatsApp para confirmar.',
        ], ['observacao' => 'observação']);

        if (! PhoneNumber::toE164($this->telefone)) {
            $this->addError('telefone', 'Esse número não parece certo. Use DDD + número.');

            return;
        }

        // Tela publica que grava sem limite e convite para encherem a agenda de alguem com
        // horario falso ate nao sobrar vaga de verdade.
        $chave = 'reserva:'.$this->slug.':'.request()->ip();

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            $this->recado = 'Muitas tentativas. Tente de novo daqui a pouco.';

            return;
        }

        RateLimiter::hit($chave, 3600);

        try {
            $marcado = $reserva->marcar(
                $this->pagina(),
                Carbon::parse($this->quando),
                trim($this->nome),
                trim($this->telefone),
                trim($this->observacao) ?: null,
            );
        } catch (VagaTomada $e) {
            $this->quando = '';
            $this->recado = $e->getMessage();

            return;
        }

        $this->confirmado = $marcado->comeca_em->toDateTimeString();

        // Mostrado na tela tambem, e nao so mandado no WhatsApp: a pagina pode nao ter canal
        // configurado, e ai a tela e o unico lugar onde o link existe.
        $this->linkDoVideo = $marcado->meeting?->url();
    }

    public function render()
    {
        $pagina = $this->pagina();
        $vagas = $pagina->ativa ? (new Vagas($pagina))->porDia() : [];

        // O dia abre escolhido no primeiro que tem vaga: uma tela que pede dois cliques para
        // mostrar qualquer coisa parece vazia no primeiro.
        if ($this->dia === '' || ! array_key_exists($this->dia, $vagas)) {
            $this->dia = (string) array_key_first($vagas);
        }

        return view('livewire.publico.reservar', [
            'pagina'  => $pagina,
            'vagas'   => $vagas,
            'doDia'   => $vagas[$this->dia] ?? [],
        ])->layout('components.layouts.publico', [
            'titulo' => $pagina->titulo,
        ]);
    }
}
