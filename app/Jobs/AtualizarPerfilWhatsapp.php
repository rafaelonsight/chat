<?php

namespace App\Jobs;

use App\Models\{Channel, Contact};
use App\Services\EvolutionService;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{Http, Storage};

class AtualizarPerfilWhatsapp implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $uniqueFor = 300;

    public function __construct(public int $channelId, public int $contactId) {}

    public function uniqueId(): string
    {
        return $this->channelId.':'.$this->contactId;
    }

    public function handle(EvolutionService $evolution): void
    {
        $canal = Channel::withoutGlobalScope('tenant')->find($this->channelId);

        if (! $canal || ! $canal->instance_name) {
            return;
        }

        TenantContext::runAs($canal->tenant_id, function () use ($canal, $evolution) {
            $contato = Contact::find($this->contactId);

            if (! $contato) {
                return;
            }

            try {
                $dados = $evolution->profilePicture($canal->instance_name, $contato->jid);
                $url = Arr::get($dados, 'profilePictureUrl');

                if (! filled($url)) {
                    $this->removerFoto($contato);
                    return;
                }

                $resposta = Http::timeout(15)->withOptions(['allow_redirects' => true])->get($url);
                $mime = strtolower((string) $resposta->header('Content-Type'));

                if (! $resposta->successful() || ! str_starts_with($mime, 'image/')
                    || strlen($resposta->body()) > 5 * 1024 * 1024) {
                    $contato->update(['foto_whatsapp_consultada_em' => now()]);
                    return;
                }

                $extensao = match (true) {
                    str_contains($mime, 'png') => 'png',
                    str_contains($mime, 'webp') => 'webp',
                    default => 'jpg',
                };
                $caminho = "contatos/whatsapp/{$contato->tenant_id}/{$contato->id}.{$extensao}";

                if ($contato->foto_whatsapp && $contato->foto_whatsapp !== $caminho) {
                    Storage::disk('public')->delete($contato->foto_whatsapp);
                }

                Storage::disk('public')->put($caminho, $resposta->body());
                $contato->update([
                    'foto_whatsapp' => $caminho,
                    'foto_whatsapp_consultada_em' => now(),
                ]);
            } catch (\Throwable) {
                // Foto é enriquecimento: falha externa nunca interfere no atendimento.
                $contato->update(['foto_whatsapp_consultada_em' => now()]);
            }
        });
    }

    private function removerFoto(Contact $contato): void
    {
        if ($contato->foto_whatsapp) {
            Storage::disk('public')->delete($contato->foto_whatsapp);
        }

        $contato->update([
            'foto_whatsapp' => null,
            'foto_whatsapp_consultada_em' => now(),
        ]);
    }
}
