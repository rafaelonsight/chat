<x-filament-panels::page>
    <x-filament::button wire:click="exportarCsv" wire:loading.attr="disabled" wire:target="exportarCsv" color="gray" icon="heroicon-o-arrow-down-tray">{{ __('Exportar CSV') }}</x-filament::button>
    <form wire:submit="aplicar" class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-900">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
            <label class="text-sm">{{ __('ui_6b98c9b52824') }}<input type="date" wire:model="inicio" class="mt-1 w-full rounded-lg border p-2"></label>
            <label class="text-sm">{{ __('ui_fc4548003743') }}<input type="date" wire:model="fim" class="mt-1 w-full rounded-lg border p-2"></label>
            <label class="text-sm">{{ __('ui_61f21e6ff9d6') }}<select wire:model="canal" class="mt-1 w-full rounded-lg border p-2"><option value="">{{ __('ui_3af1d5fc44a4') }}</option>@foreach($canais as $c)<option value="{{ $c->id }}">{{ $c->nome }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('ui_e03e3996dc23') }}<select wire:model="equipe" class="mt-1 w-full rounded-lg border p-2"><option value="">{{ __('ui_23ca4b434fa7') }}</option>@foreach($equipes as $e)<option value="{{ $e->id }}">{{ $e->nome }}</option>@endforeach</select></label>
            <label class="text-sm">{{ __('ui_1642e770673d') }}<select wire:model="atendente" class="mt-1 w-full rounded-lg border p-2"><option value="">{{ __('ui_a4114a83f4c6') }}</option>@foreach($pessoas as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
            <button type="submit" wire:loading.attr="disabled" class="self-end rounded-lg bg-violet-600 px-4 py-2 font-medium text-white">{{ __('ui_ef722a671a09') }}</button>
        </div>
        @foreach(['inicio','fim','canal','equipe','atendente'] as $campo)@error($campo)<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
    </form>
 <x-filament::section>
 <x-slot name="heading">{{ __('Comparação com o período anterior') }}</x-slot>
 @php $de=\Carbon\Carbon::parse($inicio);$ate=\Carbon\Carbon::parse($fim);$dias=(int)$de->diffInDays($ate)+1; @endphp
 <p class="mb-4 text-sm text-gray-500">{{ $inicio }} — {{ $fim }} · {{ __('Período anterior') }}: {{ $de->copy()->subDays($dias)->format('Y-m-d') }} — {{ $de->copy()->subDay()->format('Y-m-d') }}. {{ __('Os valores mostram atual / anterior. O tipo de mensagem filtra apenas a tabela de mensagens.') }}</p>
 <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b">@foreach(['Atendente','Finalizados','Avaliações recebidas','Média','Espera média','Atendimento médio'] as $titulo)<th class="p-3">{{ __($titulo) }}</th>@endforeach</tr></thead><tbody>
 @forelse($comparativos as $c)@php $a=$c['atual'];$b=$c['anterior']; @endphp<tr class="border-b border-gray-100"><td class="p-3 font-medium">{{ $c['nome'] }}</td><td class="p-3">{{ $a?->finalizados??0 }} / {{ $b?->finalizados??0 }}</td><td class="p-3">{{ $a?->recebidas??0 }} / {{ $b?->recebidas??0 }}</td><td class="p-3">{{ $a?->media!==null?number_format($a->media,2):'—' }} / {{ $b?->media!==null?number_format($b->media,2):'—' }}</td><td class="p-3">{{ $this::tempo($a?->espera) }} / {{ $this::tempo($b?->espera) }}</td><td class="p-3">{{ $this::tempo($a?->duracao) }} / {{ $this::tempo($b?->duracao) }}</td></tr>@empty<tr><td colspan="6" class="p-4">{{ __('Sem atendimentos nos períodos selecionados.') }}</td></tr>@endforelse
 </tbody></table></div>
 </x-filament::section>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach(['Finalizados'=>$finalizados,'Pesquisas enviadas'=>$enviadas,'Avaliações recebidas'=>$recebidas,'Taxa de resposta'=>$enviadas ? number_format(100*$recebidas/$enviadas,1,',','.').'%' : '—'] as $rotulo=>$valor)
            <div class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-900"><p class="text-sm text-gray-500">{{ $rotulo }}</p><p class="mt-2 text-3xl font-semibold">{{ $valor }}</p></div>
        @endforeach
    </div>
    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-900">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">{{ __('ui_82411b027b28') }}</h2><p class="text-sm text-gray-500">{{ __('ui_48a44f882ec5') }} <strong>{{ $media!==null ? number_format($media,2,',','.') . ' / 5' : 'Sem avaliações' }}</strong></p></div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm" style="min-width:950px">
                <thead><tr class="border-b text-xs text-gray-500"><th class="p-3">{{ __('ui_1642e770673d') }}</th><th class="p-3">{{ __('ui_7399a37e06b1') }}</th><th class="p-3">{{ __('ui_422b6c14f240') }}</th><th class="p-3">{{ __('ui_88adb0e2fc58') }}</th><th class="p-3">{{ __('ui_40f5bf5407ba') }}</th><th class="p-3">{{ __('ui_d8a313d337a8') }}</th><th class="p-3">{{ __('ui_8b8b5f66f9a6') }}</th><th class="p-3">{{ __('ui_e345cb6bcf2f') }}</th><th class="p-3">{{ __('ui_e429e56c0cf8') }}</th></tr></thead>
                <tbody>@forelse($linhas as $l)<tr class="border-b border-gray-100"><td class="p-3"><button type="button" wire:click="selecionarAtendente({{ (int)$l->responsavel_id }})" class="font-medium text-violet-600">{{ $l->nome ?: 'Responsável indisponível' }}</button></td><td class="p-3">{{ $l->finalizados }}</td><td class="p-3">{{ $l->enviadas }}</td><td class="p-3 font-semibold">{{ $l->recebidas }}</td><td class="p-3">{{ $l->enviadas ? number_format(100*$l->recebidas/$l->enviadas,1,',','.') . '%' : '—' }}</td><td class="p-3">{{ $l->media!==null ? number_format($l->media,2,',','.') : '—' }}</td><td class="p-3"><div class="flex gap-2">@foreach(range(1,5) as $n)<span class="rounded bg-gray-50 px-2 py-1 text-xs dark:bg-gray-800" title="{{ $n }} estrelas: {{ $l->{'nota'.$n} }} avaliações">{{ $n }}★ <strong>{{ $l->{'nota'.$n} }}</strong></span>@endforeach</div></td><td class="p-3">{{ \App\Filament\Pages\RelatorioAtendentes::tempo($l->espera!==null?(float)$l->espera:null) }}</td><td class="p-3">{{ \App\Filament\Pages\RelatorioAtendentes::tempo($l->duracao!==null?(float)$l->duracao:null) }}</td></tr>@empty<tr><td colspan="9" class="p-8 text-center text-gray-500">{{ __('ui_058c33d21b57') }}</td></tr>@endforelse</tbody>
            </table>
        </div>
        <p class="mt-4 text-xs text-gray-500">{{ __('ui_629d25d1f5ce') }}</p>
        <p class="mt-2 text-xs text-gray-500">{{ __('ui_46aa8428cb03') }}</p>
    </section>
    <section class="rounded-2xl bg-white p-5 shadow-sm dark:bg-gray-900">
        <h2 class="mb-4 text-lg font-semibold">{{ __('ui_85697f205dbd') }}</h2>
        <p class="mb-4 text-sm text-gray-500">{{ __('ui_d7e57c8d91a8') }}</p>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm" style="min-width:760px"><thead><tr class="border-b text-xs text-gray-500"><th class="p-3">{{ __('ui_dd77aceafb55') }}</th><th class="p-3">{{ __('ui_8b21198f9bb6') }}</th><th class="p-3">{{ __('ui_0895a36d55d2') }}</th><th class="p-3">{{ __('ui_804429e0a065') }}</th><th class="p-3">{{ __('ui_8aea45136e9e') }}</th><th class="p-3">{{ __('ui_0dd1e29f74d3') }}</th></tr></thead><tbody>
        @forelse($detalhes as $d)<tr class="border-b border-gray-100"><td class="p-3"><a href="/admin?conversa={{ $d->conversation_id }}" class="font-medium text-violet-600">{{ $d->protocolo }}</a>@if($d->versao>1)<span class="block text-xs text-gray-500">Encerramento {{ $d->versao }}</span>@endif</td><td class="p-3">{{ $d->responsavel_nome }}</td><td class="p-3">{{ \Illuminate\Support\Carbon::parse($d->created_at)->timezone($tz)->format('d/m/Y H:i') }}</td><td class="p-3">{{ $d->pesquisa_status }}</td><td class="p-3">{{ $d->satisfacao!==null ? $d->satisfacao . ' / 5 ★' : 'Sem resposta' }}</td><td class="p-3">{{ $d->satisfacao_em ? \Illuminate\Support\Carbon::parse($d->satisfacao_em)->timezone($tz)->format('d/m/Y H:i') : '—' }}</td></tr>@empty<tr><td colspan="6" class="p-8 text-center text-gray-500">{{ __('ui_edebe19b8859') }}</td></tr>@endforelse
        </tbody></table></div><div class="mt-4">{{ $detalhes->links() }}</div>
        <p class="mt-4 text-xs text-gray-500">{{ __('ui_6f4541b2f6f5') }}</p>
    </section>

 <x-filament::section>
  <x-slot name="heading">{{ __('Mensagens por atendente e tipo') }}</x-slot>
 <label class="mb-4 block text-sm">{{ __('Tipo de mensagem') }}<select wire:model.live="tipoMensagem" class="ml-2 rounded-lg border-gray-300"><option value="">{{ __('Todos os tipos') }}</option>@foreach(['text'=>'Texto','audio'=>'Áudio','image'=>'Imagem','video'=>'Vídeo','document'=>'Documento','template'=>'Template','sticker'=>'Figurinha'] as $valor=>$rotulo)<option value="{{ $valor }}">{{ __($rotulo) }}</option>@endforeach</select></label>
  <p class="mb-4 text-sm text-gray-500">{{ __('A autoria é da mensagem. Respostas automáticas não contam como mensagens do atendente. Ao filtrar um atendente, esta tabela mostra apenas os envios dele pelo painel.') }}</p>
  <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-3">{{ __('Origem') }}</th><th class="p-3">{{ __('Tipo de mensagem') }}</th><th class="p-3 text-right">{{ __('Mensagens') }}</th><th class="p-3 text-right">{{ __('Falhas') }}</th></tr></thead><tbody>
  @forelse($mensagensPorTipo as $m)<tr class="border-b border-gray-100"><td class="p-3 font-medium">{{ __($m->origem) }}</td><td class="p-3">{{ __(['text'=>'Texto','audio'=>'Áudio','image'=>'Imagem','video'=>'Vídeo','document'=>'Documento','template'=>'Template','sticker'=>'Figurinha'][$m->tipo] ?? $m->tipo) }}</td><td class="p-3 text-right tabular-nums">{{ $m->total }}</td><td class="p-3 text-right tabular-nums">{{ $m->falhas }}</td></tr>@empty<tr><td colspan="4" class="p-5 text-gray-500">{{ __('Nenhuma mensagem no período selecionado.') }}</td></tr>@endforelse
  </tbody></table></div>
 </x-filament::section>
</x-filament-panels::page>
