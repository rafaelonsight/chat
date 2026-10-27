<x-filament-panels::page>
 <div class="va-biz-intro"><div><span class="va-eyebrow">{{ __('Financeiro') }}</span><h2>{{ $this->getTitle() }}</h2><p>{{ __('Organize os cadastros usados nos lançamentos e pagamentos.') }}</p></div></div>
 @if($errors->any())<div class="va-cal-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
 <form wire:submit="save" class="va-cal-panel va-biz-filters"><label>{{ __('Nome') }}<input type="text" wire:model="name" maxlength="80" minlength="2" required></label><button type="submit" class="va-cal-primary" wire:loading.attr="disabled">{{ __('Adicionar') }}</button></form>
 <div class="va-cal-panel va-biz-history">@forelse($this->rows() as $option)<div wire:key="option-{{ $option->id }}"><strong>{{ $option->name }}</strong></div>@empty<p class="va-cal-muted">{{ __('Nenhum cadastro ainda') }}</p>@endforelse</div>
</x-filament-panels::page>
