<?php

namespace App\Livewire\Publico;

use App\Models\Proposal;
use App\Support\TenantContext;
use Livewire\Component;

/**
 * A proposta que o cliente abre pelo link.
 *
 * PUBLICA E SEM LOGIN: quem abre e alguem que recebeu um link no WhatsApp. O que protege e o
 * token aleatorio de 40 caracteres — numero sequencial na URL deixaria trocar o 14 pelo 13 e ler
 * a proposta do concorrente.
 *
 * RASCUNHO NAO ABRE. Proposta em rascunho e texto pela metade com preco chutado; um link vazado
 * antes da hora custa a negociacao.
 */
class Proposta extends Component
{
    public string $token = '';

    public ?int $propostaId = null;

    public string $nomeDeQuemAceita = '';

    public string $motivoDaRecusa = '';

    public bool $recusando = false;

    public function mount(string $token): void
    {
        $proposta = Proposal::withoutGlobalScope('tenant')->where('token', $token)->first();

        if (! $proposta || $proposta->status === Proposal::RASCUNHO) {
            abort(404);
        }

        $this->token = $token;
        $this->propostaId = $proposta->id;

        TenantContext::set($proposta->tenant_id);

        /*
         * A PREVIA DE QUEM VENDEU NAO CONTA COMO ABERTURA.
         *
         * Sem isto, cada conferida do Rafael entraria na contagem e o status pularia para
         * "vista" antes de o cliente ver qualquer coisa. O rastreamento passaria a mentir — e
         * rastreamento que mente e pior que nao ter, porque leva a ligar na hora errada.
         */
        if (auth()->check() && (int) auth()->user()->tenant_id === (int) $proposta->tenant_id) {
            return;
        }

        $proposta->registrarVisualizacao(request()->ip(), request()->userAgent());
    }

    public function aceitar(): void
    {
        $this->validate(
            ['nomeDeQuemAceita' => 'required|string|min:3|max:155'],
            ['nomeDeQuemAceita.required' => 'Escreva seu nome para confirmar.',
             'nomeDeQuemAceita.min'      => 'Escreva seu nome completo.'],
        );

        $proposta = $this->proposta();

        // Confere de novo aqui, e nao so na tela: entre carregar a pagina e clicar podem passar
        // dias, e a validade pode ter virado nesse meio.
        if (! $proposta->podeSerAceita()) {
            return;
        }

        $proposta->aceitar($this->nomeDeQuemAceita, request()->ip(), request()->userAgent());

        \App\Support\AvisoDeProposta::aceita($proposta);
    }

    public function recusar(): void
    {
        $proposta = $this->proposta();

        if (! $proposta->podeSerAceita()) {
            return;
        }

        $proposta->recusar($this->motivoDaRecusa, request()->ip());

        \App\Support\AvisoDeProposta::recusada($proposta);

        $this->recusando = false;
    }

    private function proposta(): Proposal
    {
        return Proposal::withoutGlobalScope('tenant')
            ->with('itens')
            ->findOrFail($this->propostaId);
    }

    public function render()
    {
        $proposta = $this->proposta();

        return view('livewire.publico.proposta', [
            'p'          => $proposta,
            'itens'      => $proposta->itens,
            'recorrente' => $proposta->itens->where('recorrente', true),
            'unicos'     => $proposta->itens->where('recorrente', false),
        ])->layout('components.layouts.publico', [
            'titulo' => $proposta->numero.' — '.$proposta->titulo,
        ]);
    }
}
