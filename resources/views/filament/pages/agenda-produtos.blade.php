<x-filament-panels::page>
 <div class="va-biz-intro"><div><span class="va-eyebrow">{{ __('Seu estabelecimento') }}</span><h2>{{ __('O cuidado continua em casa.') }}</h2><p>{{ __('Organize os produtos que você oferece e acompanhe cada entrada e saída.') }}</p></div><button type="button" wire:click="open" class="va-cal-primary">{{ __('Cadastrar produto') }}</button></div>
 @if($errors->any())<div role="alert" class="va-cal-error">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
 <div class="va-biz-filters"><label>{{ __('Buscar produto') }}<input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('Nome ou código') }}"></label><label>{{ __('Situação') }}<select wire:model.live="filter"><option value="active">{{ __('Ativos') }}</option><option value="inactive">{{ __('Inativos') }}</option><option value="all">{{ __('Todos') }}</option></select></label></div>
 @php($rows=$this->rows())
 <div class="va-product-grid">
 @forelse($rows as $p)
  <article class="va-product-card" wire:key="product-{{ $p->id }}"><header><span class="va-biz-code">{{ $p->code ?: __('Sem código') }}</span><span class="va-stock {{ $p->stock===0?'va-stock-empty':'' }}">{{ $p->stock }} {{ __('em estoque') }}</span></header>@if($p->photo_path)<img class="va-product-photo" src="{{ route('agenda.products.photo',['product'=>$p->id]) }}" alt="{{ $p->name }}" loading="lazy">@endif<h3>{{ $p->name }}</h3><p class="va-product-price">{{ \App\Services\AgendaBusiness::format($p->price_cents) }}</p><p class="va-cal-muted">{{ __('Custo') }} {{ \App\Services\AgendaBusiness::format($p->cost_cents) }} · {{ __($p->active?'Ativo':'Inativo') }}</p><footer><button type="button" wire:click="open({{ $p->id }})" class="va-cal-link">{{ __('Editar produto') }}</button><button type="button" wire:click="open({{ $p->id }}, true)" class="va-cal-link">{{ __('Movimentar estoque') }}</button></footer></article>
 @empty<div class="va-cal-panel va-biz-empty"><h3>{{ __('Seu catálogo de produtos começa aqui.') }}</h3><p>{{ __('Cadastre um produto e registre a primeira entrada no estoque.') }}</p></div>@endforelse
 </div>{{ $rows->links() }}
 @if($editing)
 <section x-data x-init="$nextTick(() => $el.scrollIntoView({block: 'start'}))" wire:key="product-editor-{{ $selected }}-{{ (int)$stockMode }}" class="va-cal-panel va-biz-editor" aria-label="{{ $stockMode?'Movimentar estoque':'Cadastro de produto' }}"><header class="va-biz-header"><h2>{{ $stockMode?__('Movimentar estoque').' · '.$form['name']:__($selected?'Editar produto':'Novo produto') }}</h2><button type="button" wire:click="$set('editing',false)" class="va-cal-link">{{ __('Fechar') }}</button></header>
 @if($errors->any())<div role="alert" class="va-cal-error">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif <form wire:submit="save"><div class="va-cal-fields">
 @if($stockMode)
  <label>{{ __('Movimento') }}<select wire:model="movement.direction"><option value="in">{{ __('Entrada') }}</option><option value="out">{{ __('Saída') }}</option></select></label><label>{{ __('Quantidade de unidades') }}<input type="number" min="1" max="1000000" step="1" wire:model="movement.quantity" required></label><label class="va-biz-full">{{ __('Motivo') }}<input type="text" wire:model="movement.reason" minlength="3" maxlength="240" placeholder="{{ __('Ex.: compra, venda ou ajuste por contagem') }}" required></label>
 @else
  <label>{{ __('Nome do produto') }}<input type="text" wire:model="form.name" maxlength="120" placeholder="{{ __('Ex.: pomada modeladora') }}" required></label><label>{{ __('Código (opcional)') }}<input type="text" wire:model="form.code" maxlength="60"></label><label>{{ __('Preço de venda (R$)') }}<input type="text" inputmode="decimal" wire:model="form.price" required></label><label>{{ __('Custo por unidade (R$)') }}<input type="text" inputmode="decimal" wire:model="form.cost" required></label><label>Quantidade em estoque<input type="number" min="0" max="1000000" step="1" wire:model="form.stock" required></label>@if($selected)<label>Motivo da alteração do estoque<input type="text" wire:model="form.stock_reason" maxlength="240" placeholder="Obrigatório se alterar a quantidade"></label>@endif<label>Foto do produto (até 4 MB)<input type="file" wire:key="product-photo-input-{{ $photoInputVersion }}" wire:model="photo" accept="image/jpeg,image/png,image/webp"></label>@if($photo || ($hasSavedPhoto && !$removePhoto))
<div style="position:relative">
 <img class="va-product-photo" src="{{ $photo ? $photo->temporaryUrl() : route('agenda.products.photo',['product'=>$selected]) }}" alt="{{ __('Foto do produto') }}">
 <button type="button" wire:click="clearPhoto" wire:loading.attr="disabled" aria-label="{{ __('Excluir foto') }}" title="{{ __('Excluir foto') }}" style="position:absolute;top:24px;right:8px;display:grid;place-items:center;width:40px;height:40px;border-radius:10px;background:#fff;color:#b42318;border:1px solid #f3c6c2;box-shadow:0 2px 8px #0001">
  <x-filament::icon icon="heroicon-o-trash" class="h-5 w-5" />
 </button>
</div>
@endif
@if($removePhoto && $hasSavedPhoto)<p class="va-cal-muted">{{ __('Foto removida. Salve o produto para confirmar.') }}</p>@endif <label class="va-cal-check"><input type="checkbox" wire:model="form.active">{{ __('Produto ativo') }}</label>
 @endif
 </div><button type="submit" class="va-cal-primary" wire:loading.attr="disabled">{{ __($stockMode?'Registrar movimento':'Salvar produto') }}</button></form>
 @if($selected)<h3 class="va-biz-history-title">{{ __('Últimos movimentos') }}</h3><div class="va-biz-history">@forelse($this->history() as $m)<div><strong>{{ $m->quantity>0?'+':'' }}{{ $m->quantity }} unidades</strong><span>{{ $m->reason }}</span><small>{{ \Carbon\Carbon::parse($m->created_at)->timezone(\App\Models\AgendaSettings::current()->timezone)->format('d/m/Y H:i') }} · saldo {{ $m->balance }} · {{ $m->actor_name }}</small></div>@empty<p class="va-cal-muted">{{ __('Ainda não há movimentos. O estoque inicial é zero.') }}</p>@endforelse</div>@endif
 </section>
 @endif
</x-filament-panels::page>
