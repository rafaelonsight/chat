@if(auth()->check())
<nav class="vc-mobile-nav" aria-label="{{ __('Navegação principal') }}" x-data="{ instalada: window.matchMedia('(display-mode: standalone)').matches, instalar: null, ajuda: false }" x-on:beforeinstallprompt.window.prevent="instalar = $event" x-on:appinstalled.window="instalada = true; instalar = null">
 <a href="{{ \App\Filament\Pages\Atendimento::getUrl() }}" @class(['is-active'=>request()->routeIs('filament.admin.pages.atendimento')])><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-6 4V6a2 2 0 0 1 2-2Z"/><path d="M7 9h10M7 13h6"/></svg><span>{{ __('Conversas') }}</span></a>
 @if(\App\Filament\Resources\Contacts\ContactResource::canViewAny())
 <a href="{{ \App\Filament\Resources\Contacts\ContactResource::getUrl() }}" @class(['is-active'=>request()->routeIs('filament.admin.resources.contacts.*')])><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/></svg><span>{{ __('Contatos') }}</span></a>
 @endif
 <button type="button" x-on:click="$store.sidebar.isOpen ? $store.sidebar.close() : $store.sidebar.open()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg><span>{{ __('Mais') }}</span></button>
 <button type="button" x-show="!instalada" x-on:click="instalar ? instalar.prompt() : ajuda = true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/></svg><span>{{ __('Instalar') }}</span></button>
 <div x-show="ajuda" x-cloak class="vc-mobile-install" x-on:click.self="ajuda=false">
  <section role="dialog" aria-modal="true" aria-labelledby="vc-install-title">
   <h2 id="vc-install-title">{{ __('Instalar Virtus Chat') }}</h2>
   <p>{{ __('No iPhone, abra no Safari, toque em Compartilhar e depois em Adicionar à Tela de Início. No Android, use Instalar aplicativo no menu do Chrome.') }}</p>
   <button type="button" x-on:click="ajuda=false">{{ __('Entendi') }}</button>
  </section>
 </div>
</nav>
@endif
