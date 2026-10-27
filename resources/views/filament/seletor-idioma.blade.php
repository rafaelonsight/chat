@if(auth()->check())
<form method="POST" action="{{ route('idioma.atualizar') }}" class="vc-language-footer">
 @csrf
 <div class="vc-language-control" title="{{ __('ui_fb71a0a03e1b') }}">
  <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z"/></svg>
  <span aria-hidden="true" class="vc-language-name">{{ \App\Support\Idiomas::OPCOES[app()->getLocale()] ?? 'Português' }}</span>
  <label class="sr-only" for="idioma-interface">{{ __('ui_fb71a0a03e1b') }}</label>
  <select id="idioma-interface" name="idioma" onchange="this.form.requestSubmit()" aria-label="{{ __('ui_fb71a0a03e1b') }}">
   @foreach(\App\Support\Idiomas::OPCOES as $codigo=>$nome)<option value="{{ $codigo }}" @selected(app()->getLocale()===$codigo)>{{ $nome }}</option>@endforeach
  </select>
 </div>
</form>
@endif
