<x-filament-panels::page>
 <div class="va-catalog-intro"><span class="va-eyebrow">{{ __('Seu estabelecimento') }}</span><p>{{ __('Você define as regras. A agenda respeita cada uma delas.') }}</p></div>
 @if($errors->any())<div class="va-cal-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
 <form wire:submit="saveSettings" class="va-cal-panel">
  <h2>{{ __('Regras de agendamento') }}</h2><p class="va-cal-muted">{{ __('Horários são exibidos no fuso escolhido. Valores abaixo podem ser ajustados pelo administrador.') }}</p>
  <div class="va-cal-fields">
   <label>{{ __('Fuso horário') }}<select wire:model="settings.timezone">@foreach(\DateTimeZone::listIdentifiers() as $tz)<option value="{{ $tz }}">{{ $tz }}</option>@endforeach</select></label>
   <label>{{ __('Antecedência mínima (minutos)') }}<input type="number" min="0" max="43200" wire:model="settings.min_notice_minutes" required></label>
   <label>{{ __('Limite para agendar (dias)') }}<input type="number" min="1" max="365" wire:model="settings.horizon_days" required></label>
   <label>{{ __('Prazo para cancelar ou remarcar (minutos)') }}<input type="number" min="0" max="43200" wire:model="settings.cancel_notice_minutes" required></label>
   <label>{{ __('Intervalo entre sugestões (minutos)') }}<input type="number" min="5" max="120" wire:model="settings.slot_minutes" required></label>
  </div>
  <label class="va-cal-check"><input type="checkbox" wire:model="settings.professional_manage">{{ __('Permitir que profissionais operem a própria agenda') }}</label>
  <p class="va-cal-muted">{{ __('Desligado: somente consulta. Ligado: criar, cancelar, remarcar e registrar atendimento na própria agenda. Não concede acesso a colegas ou às configurações.') }}</p>
  <button class="va-cal-primary" type="submit" wire:loading.attr="disabled">{{ __('Salvar regras') }}</button>
 </form>
 <section class="va-cal-panel">
  <h2>{{ __('Jornada por profissional') }}</h2><p class="va-cal-muted">{{ __('Adicione faixas por dia. Para almoço, separe manhã e tarde. Dias sem faixa são folgas semanais.') }}</p>
  <label class="va-cal-label">{{ __('Profissional') }}<select wire:model="professional" wire:change="loadHours"><option value="">{{ __('Selecione um profissional.') }}</option>@foreach($this->professionals() as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
  @if($professional)
   <form wire:submit="saveHours">
    <div class="va-work-week">
     @foreach([1=>'Segunda-feira',2=>'Terça-feira',3=>'Quarta-feira',4=>'Quinta-feira',5=>'Sexta-feira',6=>'Sábado',0=>'Domingo'] as $day=>$label)
      <section class="va-work-day"><header><h3>{{ __($label) }}</h3><button type="button" wire:click="addHours({{ $day }})" class="va-cal-link">{{ __('Adicionar faixa') }}</button></header>
       @php($hasHours=false)
       @foreach($hours as $i=>$row)@if((int)$row['weekday']===$day)@php($hasHours=true)
        <div class="va-work-row" wire:key="hours-{{ $professional }}-{{ $i }}"><label>{{ __('De') }}<input type="time" wire:model="hours.{{ $i }}.start" required></label><label>{{ __('Até') }}<input type="text" placeholder="18:00" maxlength="5" wire:model="hours.{{ $i }}.end" required aria-label="{{ __('Até') }} {{ __($label) }}"></label><button type="button" wire:click="removeHours({{ $i }})" class="va-cal-link" aria-label="{{ __('Remover faixa') }} {{ __($label) }}">{{ __('Remover') }}</button></div>
       @endif@endforeach
       @if(!$hasHours)<p class="va-cal-muted">{{ __('Folga semanal') }}</p>@endif
      </section>
     @endforeach
    </div>
    <p class="va-cal-muted">{{ __('Fim de turno aceita 24:00. Folgas por data e bloqueios são cadastrados diretamente na agenda. Reservas existentes precisam continuar dentro da jornada.') }}</p>
    <button class="va-cal-primary" type="submit" wire:loading.attr="disabled">{{ __('Salvar jornada') }}</button>
   </form>
  @else<p class="va-cal-muted">{{ __('Escolha um profissional para configurar seus horários.') }}</p>@endif
 </section>
</x-filament-panels::page>
