<x-filament-panels::page>
 <x-filament::section><x-slot name="heading">{{ __('Créditos Virtus IA') }}</x-slot>
 <div class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-2xl font-semibold">{{ number_format($usadosMes,0,',','.') }} <span class="text-base font-normal text-gray-500">{{ __('de') }} {{ number_format($franquia,0,',','.') }} créditos</span></p><p class="mt-1 text-sm text-gray-500">{{ __('Usados neste mês') }}</p></div><div class="text-right"><p class="text-2xl font-semibold text-violet-600">{{ number_format($disponiveis,0,',','.') }}</p><p class="text-sm text-gray-500">{{ __('Créditos disponíveis') }}</p></div></div>
 <p class="mt-2 text-sm text-gray-500">{{ __('Saldo extra') }}: {{ number_format($saldoExtra,0,',','.') }} créditos</p>
 @if($percentual>=80)
 <p role="alert" class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ $disponiveis===0 ? __('Saldo esgotado. Somente as funções de IA estão bloqueadas.') : ($percentual>=100 ? __('Franquia mensal esgotada. A IA utiliza o saldo extra.') : ($percentual>=95 ? __('Você consumiu pelo menos 95% da franquia mensal.') : __('Você consumiu pelo menos 80% da franquia mensal.'))) }}</p>
 @endif
 <div class="mt-4 h-2 overflow-hidden rounded-full bg-gray-100" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $franquia }}" aria-valuenow="{{ min($usadosMes,$franquia) }}" aria-label="{{ __('Consumo da franquia mensal') }}"><div class="h-full rounded-full bg-violet-600" style="width:{{ $percentual }}%"></div></div>
 <p class="mt-3 text-xs text-gray-500">{{ __('Renovação em :data. Este contador usa o mês inteiro, independentemente dos filtros.', ['data'=>$renovacao]) }}</p>
 @if($desconhecidasMes)
 <p class="mt-2 text-sm text-amber-700">{{ __('Saldo provisório: há chamadas com consumo estimado porque o provedor não informou os créditos.') }}</p>
 @endif
 </x-filament::section>
 <x-filament::section><x-slot name="heading">{{ __('Comprar mais créditos') }}</x-slot>
 <p class="mb-4 text-sm text-gray-500">{{ __('Escolha um pacote extra. A compra será registrada como solicitação; pagamento e liberação automática ainda não estão disponíveis.') }}</p>
 <div class="grid gap-4 md:grid-cols-3">
 @foreach($pacotes as $créditos=>$centavos)
 <div class="rounded-xl border border-gray-200 p-4"><p class="text-lg font-semibold">{{ number_format($créditos,0,',','.') }} créditos</p><p class="mt-2 text-2xl font-semibold">R$ {{ number_format($centavos/100,2,',','.') }}</p>
 @if(auth()->user()?->admin)
 <x-filament::button type="button" class="mt-4 w-full" wire:click="solicitarCompra({{ $créditos }})" wire:loading.attr="disabled" wire:target="solicitarCompra">{{ __('Solicitar compra') }}</x-filament::button>
 @else
 <p class="mt-3 text-xs text-gray-500">{{ __('Peça ao administrador da conta para comprar.') }}</p>
 @endif
 </div>
 @endforeach
 </div>
 @if($compras->isNotEmpty())
 <h3 class="mt-5 text-sm font-semibold">{{ __('Solicitações de compra') }}</h3>
 @foreach($compras as $compra)
 <div class="mt-2 flex flex-wrap justify-between gap-2 border-t py-2 text-sm"><span>#{{ $compra->id }} · {{ number_format($compra->créditos,0,',','.') }} créditos · R$ {{ number_format($compra->valor_centavos/100,2,',','.') }}</span><span>{{ __('Aguardando pagamento') }}</span></div>
 @endforeach
 @endif
 </x-filament::section>
 <p class="text-sm text-gray-500">{{ __('Texto: 1 crédito por token informado pelo provedor. Áudio: créditos proporcionais à duração, conforme tarifa da plataforma. O histórico começa na ativação da medição.') }}</p>
 <form wire:submit="aplicar" class="flex flex-wrap items-end gap-3">
 <label class="text-sm">{{ __('De') }}<input type="date" wire:model="inicio" class="mt-1 block rounded-lg border-gray-300"></label>
 <label class="text-sm">{{ __('Até') }}<input type="date" wire:model="fim" class="mt-1 block rounded-lg border-gray-300"></label>
 <label class="text-sm">{{ __('Atendente') }}<select wire:model="atendente" class="mt-1 block rounded-lg border-gray-300"><option value="">{{ __('Todos') }}</option>
@foreach($usuarios as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>

@endforeach
</select></label>
 <label class="text-sm">{{ __('Recurso') }}<select wire:model="recurso" class="mt-1 block rounded-lg border-gray-300"><option value="">{{ __('Todos') }}</option>
@foreach($opcoes as $o)<option value="{{ $o }}">{{ __($nomesRecursos[$o] ?? $o) }}</option>

@endforeach
</select></label>
 <x-filament::button type="submit">{{ __('Filtrar') }}</x-filament::button><x-filament::button color="gray" wire:click="exportar">{{ __('Exportar CSV') }}</x-filament::button>
 </form>
 <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
@foreach(['chamadas'=>'Chamadas de IA','entrada'=>'Tokens de entrada','saida'=>'Tokens de saída','total'=>'Total de créditos'] as $k=>$label)<x-filament::section><p class="text-sm text-gray-500">{{ __($label) }}</p><p class="mt-2 text-2xl font-semibold">{{ number_format($totais->$k,0,',','.') }}</p></x-filament::section>

@endforeach
</div>
 @if($totais->desconhecidas)<p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">{{ __('Há :n chamadas sem créditos informados pelo provedor. Nesses casos, o saldo usa uma estimativa conservadora identificada como provisória.', ['n'=>$totais->desconhecidas]) }}</p>

@endif

 @if(!$primeiro)<p class="text-sm text-gray-500">{{ __('Ainda não há consumo registrado. Use uma função de IA para iniciar o histórico.') }}</p>

@endif

 <x-filament::section><x-slot name="heading">{{ __('Por recurso e modelo') }}</x-slot><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr>
@foreach(['Recurso','Modelo','Chamadas','Entrada','Saída','Total'] as $h)<th class="p-2">{{ __($h) }}</th>

@endforeach
</tr></thead><tbody>
@foreach($recursos as $l)<tr class="border-t"><td class="p-2">{{ __($nomesRecursos[$l->recurso] ?? $l->recurso) }}</td><td class="p-2">{{ $l->modelo }}</td>
@foreach(['chamadas','entrada','saida','total'] as $k)<td class="p-2">{{ $l->$k === null ? '—' : number_format($l->$k,0,',','.') }}</td>

@endforeach
</tr>

@endforeach
</tbody></table></div></x-filament::section>
 <div class="grid gap-4 lg:grid-cols-2"><x-filament::section><x-slot name="heading">{{ __('Por atendente') }}</x-slot>
@foreach($pessoas as $l)<div class="flex justify-between gap-3 border-b py-2 text-sm"><span>{{ $l->nome }}</span><span>{{ $l->total===null?'—':number_format($l->total,0,',','.') }} créditos · {{ $l->chamadas }}</span></div>

@endforeach
</x-filament::section><x-filament::section><x-slot name="heading">{{ __('Por dia') }}</x-slot>
@foreach($dias as $l)<div class="flex justify-between gap-3 border-b py-2 text-sm"><span>{{ $l->dia }}</span><span>{{ $l->total===null?'—':number_format($l->total,0,',','.') }} créditos</span></div>

@endforeach
</x-filament::section></div>
 <p class="text-xs text-gray-500">{{ __('A IA utiliza primeiro a franquia mensal e depois o saldo extra. Sem saldo suficiente, apenas a operação de IA é bloqueada. A compra automática aguarda a integração de pagamento.') }}</p>
<x-filament::section><x-slot name="heading">{{ __('Transcrição e histórico de créditos') }}</x-slot>
 <p class="text-sm">{{ __('Áudio transcrito no período') }}: {{ number_format($audio/60,2,',','.') }} min</p>
 @forelse($historicoCreditos as $h)
 <div class="flex flex-wrap justify-between gap-2 border-b py-3 text-sm"><span>{{ $h->created_at }} · {{ $h->motivo }}</span><strong>+{{ number_format($h->tokens,0,',','.') }} {{ __('créditos') }}</strong></div>
 @empty
 <p class="mt-3 text-sm text-gray-500">{{ __('Nenhum crédito extra concedido.') }}</p>
 @endforelse
 </x-filament::section>
</x-filament-panels::page>
