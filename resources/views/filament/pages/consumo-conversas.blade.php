<x-filament-panels::page>
 <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Área de consumo') }}">
  <x-filament::button :color="$secao === 'meta' ? 'primary' : 'gray'" wire:click="$set('secao','meta')">Meta</x-filament::button>
  <x-filament::button :color="$secao === 'infraestrutura' ? 'primary' : 'gray'" wire:click="$set('secao','infraestrutura')">{{ __('Infraestrutura') }}</x-filament::button>
 </div>
 <p class="text-sm text-gray-500">{{ __('Acompanhe o uso do Virtus e os custos de mensagens da Meta em um só lugar.') }}</p>
 @if ($secao === 'infraestrutura')
  @include('filament.pages.consumo-infraestrutura')
 @else

 <div class="space-y-5" wire:poll.15s>
  <div class="flex flex-wrap items-center justify-between gap-3">
   <div class="flex gap-2">
    <x-filament::button :color="$aba === 'precos' ? 'primary' : 'gray'" wire:click="$set('aba','precos')">{{ __('Tabela de preços') }}</x-filament::button>
    @if ($this->podeVerConsumo())<x-filament::button :color="$aba === 'consumo' ? 'primary' : 'gray'" wire:click="$set('aba','consumo')">{{ __('Consumo da conta') }}</x-filament::button>@endif
   </div>
   <a href="{{ \App\Filament\Resources\MetaTemplates\MetaTemplateResource::getUrl('index') }}" class="text-sm text-violet-600">{{ __('Voltar aos templates') }}</a>
  </div>
  @if ($aba === 'consumo' && $this->podeVerConsumo())
   <x-filament::section>
    <x-slot name="heading">{{ __('Consumo da conta') }}</x-slot>
    <p class="mb-4 text-sm text-gray-500">{{ __('Os dados são consultados na Meta por conta WhatsApp. Canais da mesma conta compartilham o consumo.') }}</p>
    <form wire:submit="consultarConsumo" class="flex flex-wrap items-end gap-4">
     <label class="text-sm">{{ __('Canal') }}<select wire:model="channelId" class="mt-1 block rounded-lg border-gray-300">@foreach ($canais as $id=>$nome)<option value="{{ $id }}">{{ $nome }}</option>@endforeach</select></label>
     <label class="text-sm">{{ __('De') }}<input type="date" wire:model="inicio" class="mt-1 block rounded-lg border-gray-300"></label>
     <label class="text-sm">{{ __('Até') }}<input type="date" wire:model="fim" class="mt-1 block rounded-lg border-gray-300"></label>
     <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="consultarConsumo">{{ __('Consultar consumo') }}</x-filament::button>
    </form>
    @foreach (['channelId','inicio','fim'] as $campo) @error($campo)<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
   </x-filament::section>
   @if ($consumo)
    @if (empty($consumo['ok']))<p role="alert" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">{{ __('Não foi possível consultar o consumo. Confira a conexão do canal e tente novamente.') }}</p>
    @else
     @php $volume=collect($pontos)->sum('volume'); $temCusto=empty($consumo['aviso_custo']) && count($pontos)>0 && collect($pontos)->every(fn($p)=>isset($p['cost']) && is_numeric($p['cost'])); @endphp
     <div class="grid gap-4 lg:grid-cols-3">
      <x-filament::section><p class="text-sm text-gray-500">{{ __('Mensagens registradas pela Meta') }}</p><strong class="text-3xl">{{ number_format($volume,0,',','.') }}</strong></x-filament::section>
      <x-filament::section><p class="text-sm text-gray-500">{{ __('Cobrança efetiva informada pela Meta') }}</p><strong class="text-2xl">{{ $temCusto ? ($consumo['moeda']??'').' '.number_format(collect($pontos)->sum('cost'),4,'.',',') : __('Indisponível') }}</strong></x-filament::section>
     </div>
     <x-filament::section>
      <x-slot name="heading">{{ __('Estimativa da tarifa Meta') }}</x-slot>
      <label class="mb-3 block text-sm">{{ __('Moeda da estimativa') }}
       <select wire:model.live="moedaConsumo" class="ml-2 rounded-lg border-gray-300">
        <option value="BRL">{{ __('Reais (BRL)') }}</option><option value="GBP">{{ __('Libras (GBP)') }}</option><option value="USD">{{ __('Dólares (USD)') }}</option>
       </select>
      </label>
      <p class="mb-3 text-xs text-gray-500">{{ __('Estimativa calculada em USD e convertida pela cotação de referência. A cobrança efetiva permanece na moeda informada pela Meta.') }}</p>
      @if (!empty($custoEstimado['cambio']))<p class="mb-3 text-xs text-gray-500">{{ __('Cotação de referência') }}: 1 USD = {{ number_format($custoEstimado['cambio']['taxa'],6,'.',',') }} {{ $moedaConsumo }} · {{ $custoEstimado['cambio']['data'] }} · ECB / Frankfurter</p>@elseif ($moedaConsumo !== 'USD')<p class="mb-3 text-sm text-amber-800">{{ __('Cotação indisponível. Tente novamente mais tarde ou selecione USD.') }}</p>@endif
      <strong class="text-2xl">{{ $custoEstimado['total'] !== null ? $custoEstimado['moeda'].' '.number_format($custoEstimado['total'],4,'.',',') : __('Estimativa incompleta') }}</strong>
      <p class="mt-2 text-sm text-gray-600">{{ __('Calculada pelas tarifas atuais, pelo país do destinatário e pelas mensagens registradas como cobradas. Não é o valor da fatura.') }}</p>
      <p class="mt-2 text-sm font-medium text-amber-800">{{ __('Não inclui taxas do parceiro, impostos ou spread cambial. O valor final pode ser diferente.') }}</p>
      @if ($custoEstimado['total'] === null)<p class="mt-2 text-xs text-gray-500">{{ __('Faltam tarifas válidas ou há categorias com faixas ou condições especiais. Valores indisponíveis não são considerados zero.') }}</p>@endif
     </x-filament::section>
     @if (!empty($consumo['aviso_custo']))<p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">{{ __('A Meta não disponibilizou o custo desta conta. Consulte a cobrança do parceiro; consumo indisponível não significa custo zero.') }}</p>@endif
     <x-filament::section>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-3">{{ __('Data') }}</th><th class="p-3">{{ __('País') }}</th><th class="p-3">{{ __('Categoria') }}</th><th class="p-3">{{ __('Tipo de cobrança') }}</th><th class="p-3">{{ __('Mensagens') }}</th><th class="p-3">{{ __('Tarifa estimada por mensagem') }}</th><th class="p-3">{{ __('Estimativa Meta') }}</th></tr></thead><tbody>
       @forelse ($pontos as $indice=>$ponto)<tr class="border-b border-gray-100"><td class="p-3">{{ date('Y-m-d',$ponto['start']) }}</td><td class="p-3">{{ $ponto['country']??'—' }}</td><td class="p-3">{{ $ponto['pricing_category']??'—' }}</td><td class="p-3">{{ $ponto['pricing_type']??'—' }}</td><td class="p-3">{{ $ponto['volume']??0 }}</td><td class="p-3">{{ $custoEstimado['linhas'][$indice]['tarifa'] !== null ? $custoEstimado['moeda'].' '.number_format($custoEstimado['linhas'][$indice]['tarifa'],4,'.',',') : '—' }}</td><td class="p-3">{{ $custoEstimado['linhas'][$indice]['estimativa'] !== null ? $custoEstimado['moeda'].' '.number_format($custoEstimado['linhas'][$indice]['estimativa'],4,'.',',') : '—' }}</td></tr>@empty<tr><td colspan="7" class="p-4 text-gray-500">{{ __('Sem consumo informado para este período.') }}</td></tr>@endforelse
      </tbody></table></div>
      <p class="mt-3 text-xs text-gray-500">{{ __('Última consulta') }}: {{ $consumo['consultado_em']??'—' }} · {{ __('Os dados podem levar tempo para aparecer na Meta. Não substituem a fatura.') }}</p>
     </x-filament::section>
    @endif
   @endif
  @else
   <x-filament::section>
    <x-slot name="heading">{{ __('Tabela de preços') }}</x-slot>
    <p class="mb-4 text-sm text-gray-500">{{ __('A tarifa depende do país do destinatário, da categoria e da moeda. São valores da Meta, sem taxas do parceiro ou impostos.') }}</p>
    <div class="flex flex-wrap items-end gap-4">
     <label class="text-sm">{{ __('País do destinatário') }}<select wire:model.live="mercado" class="mt-1 block rounded-lg border-gray-300">@foreach (\App\Services\TarifasMeta::MERCADOS as $codigo=>$nome)<option value="{{ $codigo }}">{{ $nome }}</option>@endforeach</select></label>
     <label class="text-sm">{{ __('Moeda') }}<select wire:model.live="moeda" class="mt-1 block rounded-lg border-gray-300">@foreach (\App\Services\TarifasMeta::MOEDAS as $codigo)<option>{{ $codigo }}</option>@endforeach</select></label>
     <x-filament::button wire:click="atualizarPrecos" wire:loading.attr="disabled" wire:target="atualizarPrecos">{{ __('Atualizar preços') }}</x-filament::button>
    </div>
    <div class="mt-5 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-3">{{ __('Categoria') }}</th><th class="p-3">{{ __('Preço por mensagem') }}</th><th class="p-3">{{ __('Verificado em') }}</th><th class="p-3">{{ __('Situação') }}</th></tr></thead><tbody>
     @foreach (\App\Services\TarifasMeta::CATEGORIAS as $categoriaTabela)
      @php $linha=$tarifas->get($categoriaTabela); @endphp
      <tr class="border-b border-gray-100"><td class="p-3">{{ $categoriaTabela }}</td><td class="p-3 font-medium">{{ $linha?->tarifa !== null ? $moeda.' '.number_format((float)$linha->tarifa,6,'.',',') : __('Aguardando consulta válida') }}</td><td class="p-3">{{ $linha?->verificado_em?->format('Y-m-d H:i')??'—' }}</td><td class="p-3 text-gray-500">{{ $linha?->erro ? __('Fonte indisponível; último valor preservado') : ($linha?->verificado_em ? __('Consultado na fonte oficial') : __('Ainda não consultado')) }}</td></tr>
     @endforeach
    </tbody></table></div>
    <p class="mt-4 text-sm text-gray-500">{{ __('Mensagens de serviço dentro da janela de atendimento de 24 horas são gratuitas na Meta.') }}</p>
    <p class="mt-4 text-xs text-gray-500">{{ __('A verificação é diária. Valores antigos não são usados no simulador quando a consulta falha.') }} <a class="text-violet-600 underline" href="{{ \App\Models\MetaTarifa::FONTE }}" target="_blank" rel="noopener">{{ __('Fonte oficial e regras da Meta') }}</a></p>
   </x-filament::section>
   <x-filament::section>
    <x-slot name="heading">{{ __('Simular custo') }}</x-slot>
    <div class="flex flex-wrap items-end gap-4">
     <label class="text-sm">{{ __('Categoria') }}<select wire:model.live="categoria" class="mt-1 block rounded-lg border-gray-300">@foreach (\App\Services\TarifasMeta::CATEGORIAS as $nome)<option>{{ $nome }}</option>@endforeach</select></label>
     <label class="text-sm">{{ __('Quantidade de mensagens') }}<input type="number" min="0" max="100000000" wire:model.live.debounce.400ms="quantidade" class="mt-1 block rounded-lg border-gray-300"></label>
     <strong class="py-2 text-xl">{{ $estimativa !== null ? $moeda.' '.number_format($estimativa,4,'.',',') : __('Sem tarifa atual validada') }}</strong>
    </div>
    <p class="mt-3 text-xs text-gray-500">{{ __('Estimativa para mensagens cobradas, com faixas de volume quando disponíveis. Mensagens gratuitas e condições especiais devem ser conferidas na Meta.') }}</p>
   </x-filament::section>
  @endif
 </div>


 @endif
</x-filament-panels::page>