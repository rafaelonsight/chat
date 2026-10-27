@php
    $service = $getRecord();
    $total = $service->duracao_minutos + $service->intervalo_minutos;
    $width = round($service->duracao_minutos / max(1, $total) * 100, 2);
@endphp
<article class="va-catalog-card">
    <div class="va-card-top">
        <span class="va-eyebrow">{{ __('Serviço') }}</span>
        <span class="va-state {{ $service->ativo ? '' : 'va-state-muted' }}">{{ $service->ativo ? __('Ativo') : __('Inativo') }}</span>
    </div>
    @if($service->photo_path)<img class="va-product-photo" src="{{ route('agenda.services.photo',['service'=>$service->id]) }}" alt="{{ $service->nome }}" loading="lazy">@endif
    <h2>{{ $service->nome }}</h2>
    <p class="va-card-description">{{ $service->descricao ?: __('Sem descrição adicional.') }}</p>
    <div class="va-service-price">{{ \Illuminate\Support\Number::currency((float) $service->preco, in: config('agenda.currency'), locale: app()->getLocale()) }}</div>
    <div class="va-duration-track" role="img" aria-label="{{ __('Atendimento') }}: {{ $service->duracao_minutos }} {{ __('min') }}; {{ __('Intervalo') }}: {{ $service->intervalo_minutos }} {{ __('min') }}">
        <span style="width: {{ $width }}%"></span>
    </div>
    <div class="va-duration-labels">
        <span><strong>{{ $service->duracao_minutos }} {{ __('min') }}</strong>{{ __('Atendimento') }}</span>
        <span><strong>{{ $service->intervalo_minutos }} {{ __('min') }}</strong>{{ __('Intervalo') }}</span>
    </div>
    <p class="va-card-footnote">{{ $total }} {{ __('min de ocupação por atendimento') }}</p>
</article>
