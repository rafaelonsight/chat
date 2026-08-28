<?php

namespace App\Livewire;

use App\Events\RecadoDireto;
use App\Models\DirectMessage;
use App\Models\Team;
use App\Models\User;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * A bolha do chat da equipe, no canto da tela.
 *
 * QUEM ESTA ONLINE E RESPONDIDO PELO NAVEGADOR, e nao por este componente: a presenca vem do
 * canal de presenca do Reverb, ao vivo. Guardar "ultimo visto" numa coluna daria uma lista que
 * envelhece — a pessoa fecha o navegador e continua verde ate alguem lembrar de limpar.
 *
 * A LISTA TRAZ TODO MUNDO, online ou nao, e todo mundo e clicavel. Pedido do Rafael, e e o
 * comportamento certo: recado para quem esta fora fica gravado e aparece quando a pessoa entra.
 * Esconder ou travar quem esta offline transformaria uma caixa de recados numa sala de espera.
 *
 * ELE NAO SEGUE O ACESSO POR CANAL E TIME. Aquilo diz o que a pessoa pode ver do CLIENTE; aqui
 * nao ha cliente nenhum, e um atendente restrito a um canal continua precisando falar com o
 * chefe. O que limita e o tenant, como em tudo.
 */
class ChatInterno extends Component
{
    use \Livewire\WithFileUploads;
    public $arquivo;
    public bool $aberto = false;

    /** Com quem estou falando agora. */
    public ?int $comQuem = null;

    public string $texto = '';

    /** Recorte da lista por equipe: null = todas. */
    public ?int $equipe = null;

    public function abrir(int $usuarioId): void
    {
        User::where('tenant_id',auth()->user()->tenant_id)->findOrFail($usuarioId);
        $this->comQuem = $usuarioId;
        $this->texto = '';
        $this->arquivo = null;
        $this->marcarLidas();
    }

    public function voltar(): void
    {
        $this->comQuem = null;
    }

    public function enviar(): void
    {
        $this->texto=trim($this->texto);
        $this->validate([
            'texto'   => 'required_without:arquivo|nullable|string|max:2000',
            'arquivo' => 'nullable|file|max:25600|mimes:pdf,txt,csv,png,jpg,jpeg,webp,doc,docx,xls,xlsx,zip,mp3,wav,ogg,webm,m4a,mp4',
            'comQuem' => 'required|integer',
        ]);

        $destino = User::where('tenant_id', auth()->user()->tenant_id)
            ->whereKey($this->comQuem)
            ->first();

        // Mesmo tenant ou nada: o id vem da tela, e id que vem da tela e sempre um palpite ate
        // ser conferido aqui.
        if (! $destino || $destino->id === auth()->id()) {
            $this->texto = '';

            return;
        }

        $persist=function(array $media=[])use($destino){return DirectMessage::create([
            'tenant_id'=>auth()->user()->tenant_id,'de_user_id'=>auth()->id(),
            'para_user_id'=>$destino->id,'corpo'=>trim($this->texto),
        ]+$media);};
        if($this->arquivo){
            \App\Support\InternalMediaValidation::check($this->arquivo);
            $recado=app(\App\Support\StoreTaskMedia::class)->store(auth()->user()->tenant_id,$this->arquivo,'internal-media',fn($path)=>$persist(['file_path'=>$path,
                'file_name'=>mb_substr(basename($this->arquivo->getClientOriginalName()),0,200),'file_mime'=>$this->arquivo->getMimeType(),'file_size'=>$this->arquivo->getSize()]));
        }else{$recado=$persist();}

        try{broadcast(new RecadoDireto($recado))->toOthers();}
        catch(\Throwable $e){\Illuminate\Support\Facades\Log::warning('Internal message stored; broadcast failed',['message_id'=>$recado->id,'exception'=>$e::class]);}

        $this->texto = '';
        $this->arquivo = null;
        $this->dispatch('internal-media-sent');
    }

    /**
     * Chega recado enquanto a tela esta aberta.
     *
     * Se a conversa com essa pessoa estiver aberta na minha frente, ja marca como lido: exigir
     * um clique para "ler" o que esta na tela seria pedir cerimonia para o obvio.
     */
    #[On('recado-recebido')]
    public function chegouRecado(array $carga = []): void
    {
        if ($this->aberto && $this->comQuem !== null && ($carga['de'] ?? null) === $this->comQuem) {
            $this->marcarLidas();
        }
    }

    #[On('abrir-chat-interno')]
    public function abrirAviso(int $usuarioId): void
    {
        if (! User::where('tenant_id', auth()->user()->tenant_id)->whereKey($usuarioId)->exists()) return;
        $this->aberto = true;
        $this->abrir($usuarioId);
    }

    public function marcarLidas(): void
    {
        if ($this->comQuem === null) {
            return;
        }

        DirectMessage::where('para_user_id', auth()->id())
            ->where('de_user_id', $this->comQuem)
            ->whereNull('lida_em')
            ->update(['lida_em' => now()]);
    }

    /** Para o nome do canal de escuta sair certo no atributo #[On]. */
    public function getIdDoUsuarioProperty(): int
    {
        return (int) auth()->id();
    }

    public function render()
    {
        $eu = auth()->user();

        $pessoas = User::where('tenant_id', $eu->tenant_id)
            ->where('id', '!=', $eu->id)
            ->with('teams:id')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id'      => $u->id,
                'nome'    => $u->name,
                'inicial' => mb_strtoupper(mb_substr($u->primeiroNome(), 0, 1)),
                'equipes' => $u->teams->pluck('id')->all(),
            ]);

        if ($this->equipe) {
            $pessoas = $pessoas->filter(fn (array $p) => in_array($this->equipe, $p['equipes'], true))->values();
        }

        // Quantos recados nao lidos de cada um, para a bolinha na linha da pessoa.
        $naoLidas = DirectMessage::where('para_user_id', $eu->id)
            ->whereNull('lida_em')
            ->selectRaw('de_user_id, count(*) as total')
            ->groupBy('de_user_id')
            ->pluck('total', 'de_user_id');

        return view('livewire.chat-interno', [
            'pessoas'   => $pessoas,
            'equipes'   => Team::ativas()->orderBy('nome')->get(),
            'naoLidas'  => $naoLidas,
            'total'     => (int) $naoLidas->sum(),
            'conversa'  => $this->comQuem
                ? DirectMessage::entre((int) auth()->id(), $this->comQuem)
                    ->orderByDesc('id')
                    ->limit(100)
                    ->get()
                    ->reverse()
                : collect(),
            'falandoCom' => $this->comQuem
                ? User::where('tenant_id', $eu->tenant_id)->find($this->comQuem)
                : null,
        ]);
    }
}
