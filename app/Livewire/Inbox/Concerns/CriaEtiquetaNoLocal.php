<?php
namespace App\Livewire\Inbox\Concerns;
trait CriaEtiquetaNoLocal {
 public bool $criandoEtiqueta=false;
 public string $nomeNovaEtiqueta='';
 public string $corNovaEtiqueta='cinza';
 public function abrirCriacaoEtiqueta(): void {abort_unless(auth()->user()?->admin && !auth()->user()?->ehAuditor(),403);$this->criandoEtiqueta=true;}
 public function criarEtiquetaNoLocal(): void {
  abort_unless(auth()->user()?->admin && !auth()->user()?->ehAuditor(),403);
  $this->validate(['nomeNovaEtiqueta'=>'required|string|max:40','corNovaEtiqueta'=>['required',\Illuminate\Validation\Rule::in(array_keys(\App\Models\Tag::PALETA))]]);
  $conversa=\App\Models\Conversation::findOrFail($this->conversationId);
  $alvo=$this instanceof \App\Livewire\Inbox\ContactDetails ? $conversa->contact : $conversa;
  $contexto=$alvo instanceof \App\Models\Conversation?'conversa':'contato';
  $tag=\App\Models\Tag::where('tenant_id',$alvo->tenant_id)->where('contexto',$contexto)->whereRaw('lower(nome)=lower(?)',[trim($this->nomeNovaEtiqueta)])->first();
  if(!$tag) $tag=\App\Models\Tag::create(['tenant_id'=>$alvo->tenant_id,'nome'=>trim($this->nomeNovaEtiqueta),'cor'=>$this->corNovaEtiqueta,'contexto'=>$contexto]);
  app(\App\Services\Etiquetador::class)->aplicar($alvo,[$tag->id],\App\Services\Etiquetador::MANUAL,auth()->id());
  $this->reset('criandoEtiqueta','nomeNovaEtiqueta','corNovaEtiqueta');$this->dispatch('conversa-atualizada');$this->dispatch('contato-atualizado');
 }
}
