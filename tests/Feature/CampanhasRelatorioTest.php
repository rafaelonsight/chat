<?php
use App\Models\{Tenant,User,Channel,Campaign,CampaignRecipient,Contact,Conversation,Message};
use App\Services\{RelatorioCampanha,Disparador};
use App\Filament\Pages\Campanhas;
use App\Support\TenantContext;
use App\Jobs\DispararCampanha;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
beforeEach(function(){
 $this->travelTo(\Carbon\Carbon::parse('2026-10-09 10:00:00'));
 $this->tenant=Tenant::create(['nome'=>'Campanhas QA','slug'=>'campanhas-qa']);TenantContext::set($this->tenant->id);
 $this->user=User::create(['tenant_id'=>$this->tenant->id,'name'=>'Gestor','email'=>'campaigns@test.test','password'=>'testing123','admin'=>true]);$this->actingAs($this->user);
 $this->channel=Channel::create(['tenant_id'=>$this->tenant->id,'nome'=>'Teste','tipo'=>'evolution','instance_name'=>'campaign-qa','status'=>'open']);
 $this->contact=Contact::create(['tenant_id'=>$this->tenant->id,'nome'=>'Cliente QA','telefone_e164'=>'+5541990001111','jid'=>'5541990001111@s.whatsapp.net']);
 $this->campaign=Campaign::create(['tenant_id'=>$this->tenant->id,'channel_id'=>$this->channel->id,'nome'=>'Teste de relatório','publico'=>'todos','corpo'=>'Mensagem de teste','status'=>'enviando','hora_inicio'=>0,'hora_fim'=>23]);
 $this->conversation=Conversation::abertaOuNova($this->channel->id,$this->contact->id,$this->tenant->id);
 $this->message=Message::create(['tenant_id'=>$this->tenant->id,'channel_id'=>$this->channel->id,'conversation_id'=>$this->conversation->id,'direcao'=>'out','automatica'=>true,'tipo'=>'text','corpo'=>'Teste','status'=>'queued']);
 $this->recipient=CampaignRecipient::create(['campaign_id'=>$this->campaign->id,'contact_id'=>$this->contact->id,'message_id'=>$this->message->id,'status'=>'pendente']);
 $this->service=app(RelatorioCampanha::class);
});
it('conta apenas recibos confirmados e acompanha leitura apos conclusao',function(){
 expect($this->service->resumo($this->campaign))->toMatchArray(['fila'=>1,'sent'=>0,'delivered'=>0,'read'=>0]);
 $this->message->update(['status'=>'sent','enviada_em'=>now()]);expect($this->service->resumo($this->campaign)['sent'])->toBe(1);
 $this->campaign->update(['status'=>'concluida']);$this->message->update(['status'=>'read','lida_em'=>now()]);
 expect($this->service->resumo($this->campaign))->toMatchArray(['sent'=>1,'delivered'=>1,'read'=>1]);
});
it('mostra falha real em vez de sucesso da fila',function(){
 $this->message->update(['status'=>'failed','erro'=>'Número inválido']);$r=$this->service->destinatarios($this->campaign,'','failed')->first();
 expect($r->situacao)->toBe('failed')->and($r->erro)->toBe('Número inválido')->and($this->service->resumo($this->campaign)['sent'])->toBe(0);
});
it('atribui resposta somente a campanha mais recente no mesmo canal',function(){
 $this->message->update(['status'=>'sent','enviada_em'=>now()->subMinutes(10)]);
 $next=$this->campaign->replicate();$next->nome='Seguinte';$next->save();
 $m=$this->message->replicate();$m->enviada_em=now()->subMinute();$m->save();CampaignRecipient::create(['campaign_id'=>$next->id,'contact_id'=>$this->contact->id,'message_id'=>$m->id]);
 Message::create(['tenant_id'=>$this->tenant->id,'channel_id'=>$this->channel->id,'conversation_id'=>$this->conversation->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Resposta','status'=>'delivered']);
 expect($this->service->resumo($this->campaign)['responderam'])->toBe(0)->and($this->service->resumo($next)['responderam'])->toBe(1);
});
it('cancelamento preserva enviados e encerra pendentes',function(){
 Livewire::test(Campanhas::class)->call('cancelar',$this->campaign->id)->assertHasNoErrors();
 expect($this->message->fresh()->status)->toBe('failed')->and($this->service->resumo($this->campaign->fresh())['cancelada'])->toBe(1)->and($this->service->permitirEnvio($this->message->fresh()))->toBeFalse();
});
it('nao duplica mensagem vinculada ao retomar processamento',function(){
 Queue::fake();(new DispararCampanha($this->campaign->id))->handle(app(Disparador::class));
 expect(Message::where('direcao','out')->count())->toBe(1)->and($this->campaign->fresh()->status)->toBe('enviando');
});
it('agendamento futuro nao dispara antecipadamente',function(){
 Queue::fake();$this->campaign->update(['status'=>'agendada','agendada_para'=>now()->addDay()]);
 (new DispararCampanha($this->campaign->id))->handle(app(Disparador::class));
 expect($this->campaign->fresh()->status)->toBe('agendada')->and(Message::where('direcao','out')->count())->toBe(1);Queue::assertPushed(DispararCampanha::class);
});
it('renderiza relatorio e abre conversa vinculada',function(){
 Livewire::test(Campanhas::class)->call('abrir',$this->campaign->id)->assertSee('Resultados')->call('filtrar','fila')->assertSee('Cliente QA')->assertSee('Abrir conversa')->assertSee('/admin?conversa='.$this->conversation->id,false);
});
it('impede acessar campanha de outra conta por id',function(){
 $t=Tenant::create(['nome'=>'Outra','slug'=>'outra-campaign']);$ch=Channel::withoutGlobalScopes()->create(['tenant_id'=>$t->id,'nome'=>'Outro','tipo'=>'evolution','instance_name'=>'outro-campaign']);
 $c=Campaign::withoutGlobalScopes()->create(['tenant_id'=>$t->id,'channel_id'=>$ch->id,'nome'=>'Privado','publico'=>'todos']);
 expect(fn()=>Livewire::test(Campanhas::class)->call('abrir',$c->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
it('guia criacao valida e salva rascunho sem disparar',function(){
 Queue::fake();Livewire::test(Campanhas::class)->call('novaCampanha')->set('nome','Nova QA')->set('publico','todos')->call('avancar')->assertSet('etapa',2)->set('corpo','Teste')->call('avancar')->assertSet('etapa',3)->call('avancar')->assertSet('etapa',4)->call('salvar')->assertHasNoErrors();
 expect(Campaign::where('nome','Nova QA')->first()->status)->toBe('rascunho');Queue::assertNothingPushed();
});
it('preserva horario de Londres ao salvar na zona do banco',function(){
 Queue::fake();Livewire::test(Campanhas::class)->call('novaCampanha')->set('nome','Londres')->set('publico','todos')->set('corpo','Teste')->set('inicio','agendar')->set('timezone','Europe/London')->set('agendamento','2026-10-10T10:00')->call('salvar')->assertHasNoErrors();
 $c=Campaign::where('nome','Londres')->first();expect($c->agendada_para->timezone('Europe/London')->format('Y-m-d H:i'))->toBe('2026-10-10 10:00');Queue::assertNothingPushed();
});
it('pausa tambem impede job ja colocado na fila',function(){
 Queue::fake();$this->campaign->update(['status'=>'pausada']);expect($this->service->permitirEnvio($this->message))->toBeFalse()->and($this->message->fresh()->status)->toBe('queued');Queue::assertNothingPushed();
});
it('lista vazia nao se transforma em todos os contatos',function(){
 $this->campaign->update(['configuracao'=>['lista'=>true,'contact_ids'=>[]]]);expect(app(Disparador::class)->contagem($this->campaign)['final'])->toBe(0);
});
it('teste explicito enfileira somente o contato escolhido sem somar campanha',function(){
 Queue::fake();Livewire::test(Campanhas::class)->call('novaCampanha')->set('nome','Teste explícito')->set('publico','todos')->set('corpo','Teste')->set('numeroTeste',$this->contact->telefone_e164)->call('enviarTeste')->assertHasNoErrors();
 Queue::assertPushed(\App\Jobs\SendTextMessage::class,1);expect($this->campaign->recipients()->count())->toBe(1);
});
it('dias e janela usam o fuso escolhido incluindo fim de semana',function(){
 $this->campaign->update(['hora_inicio'=>9,'hora_fim'=>17,'configuracao'=>['timezone'=>'Europe/London','dias'=>[1,2,3,4,5]]]);
 $next=app(Disparador::class)->dentroDaJanela($this->campaign,\Illuminate\Support\Carbon::parse('2026-10-09 19:00','Europe/London'));
 expect($next->format('Y-m-d H:i'))->toBe('2026-10-12 09:00');
});
it('resposta pode ir para equipe sem bot preservando atendente atribuido',function(){
 $this->campaign->update(['configuracao'=>['resposta_modo'=>'equipe']]);$this->message->update(['status'=>'sent','enviada_em'=>now()->subMinute()]);
 $m=Message::create(['tenant_id'=>$this->tenant->id,'channel_id'=>$this->channel->id,'conversation_id'=>$this->conversation->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Oi']);
 expect($this->service->prepararResposta($m))->toBe('equipe');$this->conversation->update(['atendente_id'=>$this->user->id]);
 expect($this->service->prepararResposta($m->fresh()))->toBeNull();
});
it('primeira resposta escolhe o chatbot publicado da campanha',function(){
 $bot=\App\Models\Chatbot::create(\App\Filament\Resources\Chatbots\Pages\CreateChatbot::defaults(['tenant_id'=>$this->tenant->id,'nome'=>'Bot da campanha']));$bot->forceFill(['status'=>'publicado','ativo'=>true])->save();
 $this->campaign->update(['configuracao'=>['resposta_modo'=>'chatbot','chatbot_id'=>$bot->id]]);$this->message->update(['status'=>'sent','enviada_em'=>now()->subMinute()]);
 $m=Message::create(['tenant_id'=>$this->tenant->id,'channel_id'=>$this->channel->id,'conversation_id'=>$this->conversation->id,'direcao'=>'in','tipo'=>'text','corpo'=>'Oi']);
 expect($this->service->prepararResposta($m))->toBe('chatbot')->and($this->conversation->fresh()->chatbot_id)->toBe($bot->id);
});
it('gera previa visual apenas com dados ficticios no banco de teste',function(){
 $this->message->update(['status'=>'read','enviada_em'=>now(),'lida_em'=>now()]);
 $component=Livewire::test(Campanhas::class)->call('abrir',$this->campaign->id);
 $html=$component->html();file_put_contents('/tmp/virtus-campaign-qa.html','<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;padding:24px;background:#f6f7fa;font-family:Arial,sans-serif}button{cursor:pointer}a{color:#7047eb}svg{width:18px;height:18px}button,a.fi-btn{border:1px solid #ddd;border-radius:8px;padding:9px 14px;background:#fff}</style><body>'.$html.'</body></html>');
 $component->assertSee('Resultados');
});
