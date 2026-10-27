<?php
namespace App\Services;

use App\Events\MessageStored;
use App\Jobs\{SendTextMessage,SendMediaMessage};
use App\Models\Message;
use App\Services\Canais\{Enviadores,MetaCloudEnviador,ConfiguracaoInvalida};
use App\Support\TenantContext;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\{DB,Storage};

/** Persist the claim before HTTP. An ambiguous external result must never be retried blindly. */
class AgendaMessageDelivery
{
    public function handle(SendTextMessage|SendMediaMessage $job): void
    {
        $message = Message::withoutGlobalScopes()->find($job->messageId);
        if (!$message) return;
        TenantContext::runAs($message->tenant_id, function () use ($job) {
            $text = $job instanceof SendTextMessage;
            $message = DB::transaction(function () use ($job, $text) {
                $m = Message::whereKey($job->messageId)->lockForUpdate()->first();
                if (!$m || $m->direcao !== 'out' || !in_array($m->tipo, $text ? ['text'] : ['image','audio','video','document','sticker'], true) || $m->status !== Message::STATUS_QUEUED) return null;
                if (!app(RelatorioCampanha::class)->permitirEnvio($m)) return null;
                if (!app(AgendaOutreach::class)->permit($m)) return null;
                if ($m->external_id) { $this->finish($m, 'sent', null); return null; }
                if ($m->agenda_delivery_state === 'sending') {
                    if ($m->agenda_delivery_started_at && \Carbon\CarbonImmutable::parse($m->agenda_delivery_started_at)->lte(now()->subSeconds(90))) {
                        $this->finish($m, 'uncertain', 'Não foi possível confirmar o envio. Confira a conversa no WhatsApp antes de enviar outra mensagem.');
                    }
                    return null;
                }
                if (in_array($m->agenda_delivery_state, ['sent','failed','uncertain'], true)) return null;
                $conversation = $m->conversation;
                $channel = $conversation?->channel;
                $contact = $conversation?->contact;
                if (!$channel || !$contact || $m->channel_id !== $channel->id || $channel->tenant_id !== $m->tenant_id || $contact->tenant_id !== $m->tenant_id || $conversation->tenant_id !== $m->tenant_id) {
                    $this->finish($m, 'failed', 'Não foi possível validar a conversa e o canal de envio.'); return null;
                }
                if ($channel->status !== 'open' || $contact->bloqueado() || $contact->anonimizado_em || $conversation->status === 'arquivada') {
                    $this->finish($m, 'failed', 'Envio suspenso: confira o canal, o contato e a situação do atendimento.'); return null;
                }
                if (DB::table('agenda_flow_inputs')->where('tenant_id', $m->tenant_id)->where('output_id', $m->id)->exists()) {
                    $cfg = app(AgendaGuidedFlow::class)->settings($channel->id);
                    if (!$cfg?->enabled || $conversation->atendente_id) {
                        $this->finish($m, 'failed', 'Resposta automática suspensa: fluxo pausado ou atendimento humano.'); return null;
                    }
                }
                if ($m->respondeA?->tipo !== 'comentario_ig' && !$conversation->podeEnviarLivre()) {
                    $this->finish($m, 'failed', 'Janela de atendimento fechada. Confira as opções permitidas para este canal.'); return null;
                }
                if ($m->agenda_delivery_attempts >= 3) {
                    $this->finish($m, 'failed', 'Não foi possível enviar após três tentativas. Verifique a conexão antes de tentar novamente.'); return null;
                }
                if (!$text && (!$m->media_path || !Storage::disk('local')->exists($m->media_path))) {
                    $this->finish($m, 'failed', 'O arquivo desta mensagem não está disponível. Anexe novamente.'); return null;
                }
                $m->forceFill(['agenda_delivery_state'=>'sending','agenda_delivery_attempts'=>$m->agenda_delivery_attempts + 1,'agenda_delivery_started_at'=>now(),'erro'=>null])->save();
                return $m;
            });
            if (!$message) return;
            try {
                $channel = $message->conversation->channel;
                $driver = app(Enviadores::class)->para($channel);
                $destination = $message->conversation->contact->destinoWhatsApp();
                if (!$destination) throw new ConfiguracaoInvalida('Missing destination');
                if ($text) {
                    $result = $job->interactive && $driver instanceof MetaCloudEnviador
                        ? $driver->interativo($channel, $destination, $job->interactive)
                        : $driver->texto($channel, $destination, (string)$message->corpo, $message->respondeA?->paraCitacao(), $message->mencionados ?? []);
                } else {
                    $result = $driver->midia($channel, $destination, [
                        'tipo'=>$message->tipo,'bytes'=>(string)Storage::disk('local')->get($message->media_path),
                        'mime'=>$message->media_mime,'nome'=>$message->media_nome,'legenda'=>$message->legenda,
                    ], $message->respondeA?->paraCitacao());
                }
                $external = $result['external_id'] ?? null;
                DB::transaction(function () use ($message, $external) {
                    $m = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
                    if ($external) {
                        $m->forceFill(['external_id'=>$external,'enviada_em'=>$m->enviada_em ?? now()])->save();
                        $this->finish($m, 'sent', null);
                    } else $this->finish($m, 'uncertain', 'O provedor não confirmou o identificador do envio. Confira o WhatsApp antes de enviar novamente.');
                });
            } catch (\Throwable $e) {
                $retry = DB::transaction(function () use ($message, $e) {
                    $m = Message::whereKey($message->id)->lockForUpdate()->firstOrFail();
                    if ($m->external_id || in_array($m->status, ['sent','delivered','read'], true)) { $this->finish($m, 'sent', null); return false; }
                    // 408/504 and transport errors may occur after acceptance. Preserve uncertainty.
                    $status = $e instanceof RequestException ? $e->response->status() : null;
                    $transient = $status === 429 || ($status >= 500 && $status !== 504);
                    if ($transient && $m->agenda_delivery_attempts < 3) {
                        $this->finish($m, 'retry', 'Falha temporária. Uma nova tentativa será feita automaticamente.'); return true;
                    }
                    $knownRejection = $e instanceof ConfiguracaoInvalida || ($status && !in_array($status,[408,504],true));
                    $this->finish($m, $knownRejection ? 'failed' : 'uncertain', $knownRejection
                        ? 'Não foi possível enviar. Verifique a conexão e os dados do contato antes de tentar novamente.'
                        : 'Não foi possível confirmar o envio. Confira a conversa no WhatsApp antes de enviar outra mensagem.');
                    return false;
                });
                if ($retry) throw new \RuntimeException('AGENDA_DELIVERY_RETRY');
            } finally {
                broadcast(new MessageStored($message->refresh()));
            }
        });
    }

    private function finish(Message $m, string $state, ?string $error): void
    {
        $status = match ($state) {
            'sent' => in_array($m->status, ['delivered','read'], true) ? $m->status : Message::STATUS_SENT,
            'retry' => Message::STATUS_QUEUED,
            default => Message::STATUS_FAILED,
        };
        $m->forceFill(['agenda_delivery_state'=>$state,'status'=>$status,'erro'=>$error])->save();
    }

    public function exhausted(int $id): void
    {
        $m = Message::withoutGlobalScopes()->find($id);
        if (!$m) return;
        TenantContext::runAs($m->tenant_id, function () use ($id) {
            DB::transaction(function () use ($id) {
                $m = Message::whereKey($id)->lockForUpdate()->first();
                if (!$m || $m->status !== Message::STATUS_QUEUED) return;
                if ($m->agenda_delivery_state === 'sending') {
                    if (!$m->agenda_delivery_started_at || \Carbon\CarbonImmutable::parse($m->agenda_delivery_started_at)->gt(now()->subSeconds(90))) return;
                    $this->finish($m, 'uncertain', 'Não foi possível confirmar o envio interrompido. Confira o WhatsApp antes de enviar novamente.');
                } else $this->finish($m, 'failed', 'As tentativas de envio foram interrompidas. Verifique a conexão antes de tentar novamente.');
            });
        });
    }
}
