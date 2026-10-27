@php($professional = $getRecord())
<article class="va-catalog-card">
    <div class="va-card-top">
        <span class="va-professional-mark" style="--professional-color: {{ $professional->cor }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($professional->nome, 0, 1)) }}</span>
        <span class="va-state {{ $professional->ativo ? '' : 'va-state-muted' }}">{{ $professional->ativo ? __('Ativo') : __('Inativo') }}</span>
    </div>
    <h2>{{ $professional->nome }}</h2>
    <p class="va-card-footnote">{{ $professional->accessUser ? __(\App\Services\AgendaAccess::LABELS[$professional->accessUser->agenda_access_status] ?? 'Acesso ainda não configurado') : __('Acesso ainda não configurado') }}</p>
    <p class="va-card-description">{{ $professional->descricao ?: __('Profissional do estabelecimento.') }}</p>
    <div class="va-service-tags" aria-label="{{ __('Serviços que executa') }}">
        @forelse($professional->services as $service)
            <span>{{ $service->nome }}{{ $service->ativo ? '' : ' · '.__('inativo') }}</span>
        @empty
            <p class="va-card-footnote">{{ __('Nenhum serviço vinculado. Edite o cadastro para escolher os serviços.') }}</p>
        @endforelse
    </div>
    @if($professional->telefone || $professional->email)
        <div class="va-professional-contact">
            @if($professional->telefone)<p>{{ $professional->telefone }}</p>@endif
            @if($professional->email)<p>{{ $professional->email }}</p>@endif
        </div>
    @endif
</article>
