<x-filament-panels::page>
    <section class="va-catalog-intro" aria-label="{{ __('Seu estabelecimento') }}">
        <span class="va-eyebrow">{{ $this->establishmentName() }}</span>
        <p>{{ $this->catalogDescription() }}</p>
    </section>
    {{ $this->table }}
</x-filament-panels::page>
