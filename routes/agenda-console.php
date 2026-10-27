<?php

// The Chat campaign, sequence, Evolution and trial schedulers are not part of this edition.
\Illuminate\Support\Facades\Artisan::command('agenda:communications',function(){
 app(\App\Services\AgendaOutreach::class)->tick();$this->info('Rotina de comunicações concluída.');
})->purpose('Processar somente campanhas e lembretes do Agenda');
