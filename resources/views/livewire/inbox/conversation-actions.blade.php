<div wire:poll.20s>
 @if($acoes->isNotEmpty())
 <div x-data="{ aberto:false }" class="relative" x-on:click.outside="aberto=false" x-on:keydown.escape="aberto=false">
  <button type="button" x-on:click="aberto=!aberto" :aria-expanded="aberto" class="inline-flex items-center gap-1.5 rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50 dark:border-white/20 dark:text-gray-200" aria-haspopup="true">
   <x-heroicon-o-bolt class="h-4 w-4"/>{{ __('Ações') }} @if($pendentes->isNotEmpty())<span class="rounded-full bg-amber-100 px-1.5 text-amber-800">{{ $pendentes->count() }}</span>@endif
  </button>
  <div x-show="aberto" x-cloak class="absolute right-0 z-30 mt-2 w-64 rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-white/10 dark:bg-gray-800">
   @foreach($pendentes as $pedido)<button type="button" wire:key="pedido-ia-{{ $pedido->id }}" wire:click="selecionarSolicitacao({{ $pedido->id }})" x-on:click="aberto=false" class="block w-full rounded-lg bg-amber-50 px-3 py-2 text-left"><span class="block text-xs font-semibold text-amber-800">{{ __('IA aguarda sua confirmação') }}</span><span class="block text-sm">{{ $acoes->firstWhere('id',$pedido->attendance_action_id)?->nome }}</span></button>@endforeach
   @foreach($acoes as $item)<button type="button" wire:key="acao-{{ $item->id }}" wire:click="selecionar({{ $item->id }})" x-on:click="aberto=false" class="block w-full rounded-lg px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5"><span class="block text-sm font-medium">{{ $item->nome }}</span><span class="block text-xs text-gray-500">{{ $item->tipo==='pagina'?__('Abrir página'):__('Executar integração') }}</span></button>@endforeach
  </div>
 </div>
 @endif
 @if($selecionada && $acao && !$painelAberto)
 <div class="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4" x-data x-on:keydown.escape.window="$wire.fechar()" x-trap.inert.noscroll="true" role="dialog" aria-modal="true" aria-labelledby="acao-titulo-{{ $this->getId() }}">
  <section class="w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900" style="max-height:85dvh">
   <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-medium text-violet-600">{{ __('Ação do atendimento') }}</p><h2 id="acao-titulo-{{ $this->getId() }}" class="mt-1 text-lg font-semibold">{{ $acao->nome }}</h2></div><button type="button" wire:click="fechar" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100" aria-label="{{ __('Fechar') }}"><x-heroicon-o-x-mark class="h-5 w-5"/></button></div>
   @if($solicitacaoId)<p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ __('A IA solicitou esta ação. Revise os dados antes de confirmar. Você também pode recusar.') }}</p>@endif
   @if($acao->descricao)<p class="mt-3 text-sm text-gray-500">{{ $acao->descricao }}</p>@endif
   <div class="mt-4 rounded-xl border border-gray-200 p-3 text-sm dark:border-white/10"><p class="font-medium">{{ __('Destino') }}: {{ $previa['destino'] }}</p>
   @if($previa['url'])<p class="mt-2 break-all text-xs text-gray-500">{{ $previa['url'] }}</p>@else<p class="mt-2 text-xs text-gray-500">{{ __('Dados que serão enviados') }}</p><pre class="mt-2 whitespace-pre-wrap break-all text-xs">{{ json_encode($previa['dados'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre>@endif</div>
   <p class="mt-3 text-xs text-gray-500">{{ __('Esta ação não envia uma mensagem ao cliente. A execução ficará registrada.') }}</p>
   @if($erro)<p role="alert" class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $erro }}</p>@endif
   @if($resultado)<p role="status" class="mt-4 whitespace-pre-wrap rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800">{{ $resultado }}</p>@endif
   <div class="mt-5 flex flex-wrap justify-end gap-2">
    <button type="button" wire:click="fechar" class="rounded-lg border border-gray-300 px-4 py-2 text-sm">{{ $resultado||$erro?__('Fechar'):__('Cancelar') }}</button>
    @if($solicitacaoId && !$resultado && !$erro)<button type="button" wire:click="recusarSolicitacao" class="rounded-lg border border-red-200 px-4 py-2 text-sm text-red-700">{{ __('Recusar solicitação') }}</button>@endif
    @if(!$resultado && !$erro)<button type="button" wire:click="confirmar" wire:loading.attr="disabled" wire:target="confirmar" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove wire:target="confirmar">{{ $acao->tipo==='pagina'?__('Confirmar abertura'):__('Confirmar execução') }}</span><span wire:loading wire:target="confirmar">{{ __('Processando…') }}</span></button>@endif
    @if($resultado && $previa['url'])<a href="{{ $previa['url'] }}" target="_blank" rel="noopener noreferrer" class="rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Abrir página') }} ↗</a>@endif
   </div>
  </section>
 </div>
 @endif
 @if($painelAberto && $acao && $acao->tipo==='pagina' && $acao->modo_pagina==='painel' && $resultado)
 <div class="fixed inset-0 z-[100]" x-data="{ carregando:true }" x-on:keydown.escape.window="$wire.fechar()" x-trap.inert.noscroll="true" role="dialog" aria-modal="true" aria-labelledby="pagina-titulo-{{ $this->getId() }}">
  <button type="button" wire:click="fechar" class="absolute inset-0 bg-black/25" aria-label="{{ __('Fechar painel') }}"></button>
  <aside class="absolute right-0 top-0 flex h-full flex-col bg-white shadow-2xl dark:bg-gray-900" style="width:min(100vw,760px)">
   <header class="flex items-center justify-between gap-3 border-b border-gray-200 p-4 dark:border-white/10">
    <div class="min-w-0"><p class="text-xs font-medium text-violet-600">{{ __('Página integrada') }}</p><h2 id="pagina-titulo-{{ $this->getId() }}" class="truncate text-base font-semibold">{{ $acao->nome }}</h2><p class="truncate text-xs text-gray-500">{{ $previa['destino'] }}</p></div>
    <button type="button" wire:click="fechar" class="shrink-0 rounded-lg p-2 text-gray-500 hover:bg-gray-100" aria-label="{{ __('Fechar painel') }}"><x-heroicon-o-x-mark class="h-5 w-5"/></button>
   </header>
   <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-4 py-3 text-xs dark:border-white/10 dark:bg-gray-800">
    <p class="text-gray-500">{{ __('Se a página não aparecer, o site pode impedir a exibição interna.') }}</p>
    <a href="{{ $previa['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 rounded-lg border border-gray-300 bg-white px-3 py-2 font-medium text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ __('Abrir em nova aba') }} <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4"/></a>
   </div>
   <div class="relative min-h-0 flex-1"><p x-show="carregando" class="absolute left-4 top-4 rounded-lg bg-white p-2 text-xs text-gray-500 shadow">{{ __('Carregando página…') }}</p>
    <iframe wire:ignore wire:key="pagina-integrada-{{ $chave }}" src="{{ $previa['url'] }}" title="{{ $acao->nome }}" class="h-full w-full border-0" referrerpolicy="no-referrer" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-downloads" x-on:load="carregando=false"></iframe>
   </div>
  </aside>
 </div>
 @endif

</div>
