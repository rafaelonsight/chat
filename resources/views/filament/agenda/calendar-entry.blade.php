<article class="va-cal-entry {{ $entry->kind==='block'?'is-block':'' }} {{ !$entry->active?'is-cancelled':'' }}" style="--professional-color:{{ $calendar['people']->firstWhere('id',$entry->professional_id)?->cor ?? '#6338F6' }}" wire:key="entry-{{ $entry->id }}-{{ $entry->version }}-{{ $mode }}-{{ $day ?? $date }}">
 <div class="va-cal-entry-time"><time>{{ $entry->start_at->setTimezone($timezone)->format('H:i') }} — {{ $entry->end_at->setTimezone($timezone)->format('H:i') }}</time><span class="va-state">{{ __(\App\Models\AgendaCalendarEntry::STATES[$entry->status]) }}</span></div>
 @if($entry->kind==='block')<h4>{{ $entry->reason }}</h4><p>{{ $entry->start_at->setTimezone($timezone)->format('d/m') }} — {{ $entry->end_at->setTimezone($timezone)->format('d/m') }}</p>
 @else
  <h4>{{ $entry->snapshot['client'] }}</h4><p>{{ $entry->snapshot['service'] }}</p><p>{{ $entry->snapshot['professional'] }} · {{ $entry->snapshot['currency'] }} {{ number_format((float)$entry->price,2,',','.') }}</p>
  @if($entry->buffer_minutes)<div class="va-cal-buffer">+ {{ $entry->buffer_minutes }} {{ __('min de intervalo') }} · {{ __('ocupado até') }} {{ $entry->occupied_until->setTimezone($timezone)->format('H:i') }}</div>@endif
  <p>{{ __('Presença') }}: {{ __(['pendente'=>'Pendente','confirmada'=>'Confirmada','recusada'=>'Recusada'][$entry->presence]) }}</p>
  <button type="button" class="va-cal-link" wire:click="showHistory({{ $entry->contact_id }})">{{ __('Histórico do cliente') }}</button>
 @endif
 @if($canOperate && $entry->active)
  <div class="va-cal-entry-actions">
   @if($entry->kind==='booking' && $entry->status==='confirmado')
    <button class="va-cal-link" wire:click="openReschedule({{ $entry->id }})">{{ __('Remarcar') }}</button>
    @if($entry->presence!=='confirmada')<button class="va-cal-link" wire:click="changeEntry({{ $entry->id }},{{ $entry->version }},'presence','confirmada')">{{ __('Confirmar presença') }}</button>@endif
    @if($entry->presence!=='recusada')<button class="va-cal-link" wire:click="changeEntry({{ $entry->id }},{{ $entry->version }},'presence','recusada')">{{ __('Presença recusada') }}</button>@endif
    @if($entry->start_at->lte(now('UTC')))<button class="va-cal-link" wire:click="changeEntry({{ $entry->id }},{{ $entry->version }},'state','em_atendimento')">{{ __('Iniciar atendimento') }}</button><button class="va-cal-link" wire:confirm="{{ __('Registrar falta deste cliente?') }}" wire:click="changeEntry({{ $entry->id }},{{ $entry->version }},'state','falta')">{{ __('Registrar falta') }}</button>@endif
   @elseif($entry->status==='em_atendimento')<button class="va-cal-link" wire:click="changeEntry({{ $entry->id }},{{ $entry->version }},'state','concluido')">{{ __('Concluir atendimento') }}</button>@endif
   @if(($entry->kind==='block' && $isAdmin) || $entry->status==='confirmado')<button class="va-cal-danger" wire:confirm="{{ __('Cancelar este item e liberar o horário?') }}" wire:click="changeEntry({{ $entry->id }},{{ $entry->version }},'cancel')">{{ $entry->kind==='block'?__('Liberar bloqueio'):__('Cancelar') }}</button>@endif
  </div>
 @endif
</article>
