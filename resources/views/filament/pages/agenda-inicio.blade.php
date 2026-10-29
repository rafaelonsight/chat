<x-filament-panels::page>
 @php($calendar=$this->calendarData())
 @php($canOperate=$this->canOperate())
 @php($isAdmin=$this->isAdministrator())
 <div class="va-calendar" wire:poll.45s>
  <header class="va-cal-heading"><div><span class="va-eyebrow">{{ $this->establishmentName() }}</span><h2>{{ __('Seu atendimento, do seu jeito.') }}</h2><p>{{ $calendar['cfg']->timezone }} · {{ __('A disponibilidade vem da jornada e da agenda.') }}</p></div><div class="va-cal-buttons">
   @if($isAdmin)<a class="va-cal-secondary" href="{{ \App\Filament\Pages\AgendaConfiguracoes::getUrl() }}" wire:navigate>{{ __('Configurar agenda') }}</a><button class="va-cal-secondary" wire:click="openNew('block')">{{ __('Folga ou bloqueio') }}</button>@endif
   @if($canOperate)<button class="va-cal-primary" wire:click="openNew">{{ __('Novo agendamento') }}</button>@endif
  </div></header>
  @if(auth()->user()->isAgendaProfessional())<div class="va-service-tags">@foreach($this->ownProfessional()?->services ?? [] as $service)<span>{{ $service->nome }}</span>@endforeach</div>@endif
  <div class="va-cal-toolbar">
   <div class="va-cal-buttons"><button class="va-cal-secondary" wire:click="move(-1)" aria-label="{{ __('Período anterior') }}">←</button><button class="va-cal-secondary" wire:click="today">{{ __('Hoje') }}</button><button class="va-cal-secondary" wire:click="move(1)" aria-label="{{ __('Próximo período') }}">→</button></div>
   <label>{{ __('Data') }}<input type="date" wire:model.live="date" required></label>
   <label>{{ __('Visualização') }}<select wire:model.live="mode"><option value="day">{{ __('Dia') }}</option><option value="week">{{ __('Semana') }}</option></select></label>
   @unless(auth()->user()->isAgendaProfessional())<label>{{ __('Profissional') }}<select wire:model.live="professional"><option value="">{{ __('Agenda geral') }}</option>@foreach($this->professionals() as $person)<option value="{{ $person->id }}">{{ $person->nome }}{{ $person->ativo?'':' · '.__('inativo') }}</option>@endforeach</select></label>@endunless
   <label class="va-cal-check"><input type="checkbox" wire:model.live="includeCancelled">{{ __('Mostrar cancelados') }}</label>
  </div>
  @if($errors->any() && !$editor)<div class="va-cal-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
  @if($calendar['people']->isEmpty())
   <div class="va-cal-empty"><h3>{{ __('Prepare quem atende') }}</h3><p>{{ __('Cadastre serviços e profissionais, depois configure a jornada para começar a agendar.') }}</p>@if($isAdmin)<div class="va-cal-buttons"><a class="va-cal-secondary" href="{{ \App\Filament\Resources\Agenda\Services\ServiceResource::getUrl() }}" wire:navigate>{{ __('Serviços') }}</a><a class="va-cal-primary" href="{{ \App\Filament\Resources\Agenda\Professionals\ProfessionalResource::getUrl() }}" wire:navigate>{{ __('Profissionais') }}</a></div>@endif</div>
  @elseif($mode==='day')
   <div class="va-cal-lanes">
    @foreach($calendar['people'] as $person)
     <section class="va-cal-lane" style="--professional-color:{{ $person->cor }}"><header><span class="va-cal-color" aria-hidden="true"></span><div><h3>{{ $person->nome }}</h3><p>{{ $calendar['start']->format('d/m/Y') }}</p></div></header>
      <div class="va-cal-hours">@php($hours=app(\App\Services\AgendaCalendar::class)->workHours($person->id))@php($hasHours=false)
       @foreach($hours as $row)@if((int)$row['weekday']===$calendar['start']->dayOfWeek)@php($hasHours=true)<span>{{ sprintf('%02d:%02d',intdiv($row['start_minute'],60),$row['start_minute']%60) }} — {{ sprintf('%02d:%02d',intdiv($row['end_minute'],60),$row['end_minute']%60) }}</span>@endif@endforeach
       @if(!$hasHours)<span>{{ __('Sem jornada neste dia') }}</span>@endif
      </div>
      @forelse($calendar['entries']->where('professional_id',$person->id) as $entry)@include('filament.agenda.calendar-entry',['entry'=>$entry,'timezone'=>$calendar['cfg']->timezone,'canOperate'=>$canOperate,'isAdmin'=>$isAdmin])
      @empty<p class="va-cal-muted va-cal-lane-empty">{{ __('Nenhum atendimento ou bloqueio neste dia.') }}</p>@endforelse
     </section>
    @endforeach
   </div>
  @else
   <div class="va-cal-week">
    @foreach($calendar['days'] as $day)
     <section class="va-cal-week-day"><header><span>{{ $day->locale(app()->getLocale())->translatedFormat('D') }}</span><h3>{{ $day->format('d/m') }}</h3></header>
      @php($items=$calendar['entries']->filter(fn($e)=>$e->start_at->lt($day->addDay()->utc()) && $e->occupied_until->gt($day->utc())))
      @forelse($items as $entry)@include('filament.agenda.calendar-entry',['entry'=>$entry,'timezone'=>$calendar['cfg']->timezone,'canOperate'=>$canOperate,'isAdmin'=>$isAdmin])
      @empty<p class="va-cal-muted">{{ __('Sem compromissos') }}</p>@endforelse
     </section>
    @endforeach
   </div>
  @endif
  <p class="va-cal-footnote">{{ __('O intervalo após cada atendimento também ocupa a agenda. Consultar um horário não reserva a vaga.') }}</p>
 </div>
 @if($editor)
 <div class="va-cal-overlay" role="dialog" aria-modal="true" aria-labelledby="va-editor-title" x-data x-init="$nextTick(() => $el.querySelector('button')?.focus())" x-trap.inert.noscroll="true" @keydown.escape.window="$wire.set('editor',false)">
  <div class="va-cal-dialog"><header><div><span class="va-eyebrow">{{ $this->establishmentName() }}</span><h2 id="va-editor-title">{{ $editing?__('Remarcar atendimento'):($form['kind']==='block'?__('Folga ou bloqueio'):__('Novo agendamento')) }}</h2></div><button type="button" class="va-cal-secondary" wire:click="$set('editor',false)">{{ __('Fechar') }}</button></header>
   <form wire:submit="saveEntry">
    @if($errors->any())<div class="va-cal-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <div class="va-cal-fields">
     <label>{{ __('Profissional') }}<select wire:model="form.professional_id" wire:change="formProfessionalChanged" @disabled($editing) required><option value="">{{ __('Selecione um profissional.') }}</option>@foreach($this->professionals() as $person)<option value="{{ $person->id }}">{{ $person->nome }}</option>@endforeach</select></label>
     @if($form['kind']==='booking')
      <label>{{ __('Serviço') }}<select wire:model="form.service_id" wire:change="$set('suggestions',[])" @disabled($editing) required><option value="">{{ __('Escolha o serviço') }}</option>@foreach($this->servicesForForm() as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
      @if($editing)<div class="va-cal-summary"><strong>{{ $form['client_name'] }}</strong><p>{{ __('Preço e duração originais são preservados na remarcação.') }}</p></div>
      @else
       <label>{{ __('Cliente cadastrado') }}<select wire:model.live="form.contact_id"><option value="">{{ __('Cadastrar cliente neste agendamento') }}</option>@foreach($this->contactsForForm() as $id=>$label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select></label>
       @if(empty($form['contact_id']))<label>{{ __('Nome do cliente') }}<input type="text" wire:model="form.client_name" maxlength="120" required></label><label>{{ __('Telefone do cliente') }}<input type="tel" wire:model="form.client_phone" placeholder="+55 11 99999-0000" maxlength="30" required></label>@endif
      @endif
     @else<label>{{ __('Motivo do bloqueio') }}<input type="text" wire:model="form.reason" placeholder="{{ __('Folga, férias, manutenção…') }}" maxlength="200" required></label>@endif
     <label>{{ __('Data de início') }}<input type="date" wire:model="form.date" wire:change="$set('suggestions',[])" required></label><label>{{ __('Horário de início') }}<input type="time" wire:model="form.time" required></label>
     @if($form['kind']==='block')<label>{{ __('Data de fim') }}<input type="date" wire:model="form.end_date" required></label><label>{{ __('Horário de fim') }}<input type="time" wire:model="form.end_time" required></label>@endif
    </div>
    <p class="va-cal-muted">{{ __('Fuso horário') }}: {{ $calendar['cfg']->timezone }}</p>
    @if($form['kind']==='booking')<button type="button" class="va-cal-secondary" wire:click="suggest" wire:loading.attr="disabled">{{ __('Consultar horários disponíveis') }}</button>
     <div class="va-cal-slots">@foreach($suggestions as $time)<button type="button" wire:click="selectTime('{{ $time }}')" class="va-cal-slot {{ $form['time']===$time?'is-selected':'' }}">{{ $time }}</button>@endforeach</div>
     @if(!$suggestions)<p class="va-cal-muted">{{ $searchedSlots?__('Nenhum horário disponível nesta data. Escolha outro dia ou revise a jornada e os bloqueios.'):__('Escolha serviço, profissional e data para consultar vagas. A confirmação verifica a disponibilidade novamente.') }}</p>@endif
    @endif
    <div class="va-cal-buttons va-cal-submit"><button type="submit" class="va-cal-primary" wire:loading.attr="disabled">{{ $editing?__('Confirmar remarcação'):($form['kind']==='block'?__('Salvar bloqueio'):__('Confirmar agendamento')) }}</button><button type="button" class="va-cal-secondary" wire:click="$set('editor',false)">{{ __('Voltar') }}</button></div>
   </form>
  </div>
 </div>
 @endif
 @if($history)<section class="va-cal-panel"><header class="va-cal-heading"><h2>{{ __('Histórico do cliente') }}</h2><button class="va-cal-secondary" wire:click="closeHistory">{{ __('Fechar') }}</button></header>@foreach($history as $item)<div class="va-history-row"><time>{{ $item['start'] }}</time><strong>{{ $item['service'] }}</strong><span>{{ $item['professional'] }}</span><span>{{ $item['status'] }}</span></div>@endforeach</section>@endif
</x-filament-panels::page>
