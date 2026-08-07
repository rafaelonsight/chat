<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Quem uma campanha alcanca, e quando cada um recebe.
 *
 * Este arquivo e o freio. Disparo em massa no canal por QR e o gatilho mais rapido de
 * banimento que existe, e um numero banido leva junto o atendimento inteiro do cliente — nao
 * so a campanha. Entao aqui tudo e conservador por padrao, e o que afrouxa exige gesto.
 */
class Disparador
{
    /** Palavras que fazem o cliente sair da lista. */
    public const SAIDA = ['parar', 'pare', 'sair', 'cancelar', 'descadastrar', 'remover', 'stop'];

    /**
     * O publico da campanha, antes de qualquer exclusao.
     *
     * Separado do publicoFinal de proposito: a tela mostra os DOIS numeros, e a diferenca entre
     * eles e a informacao que importa. "482 contatos, 41 fora" faz perguntar por que; "441"
     * sozinho nao faz perguntar nada.
     */
    public function publicoBruto(Campaign $campanha): Builder
    {
        $q = Contact::query()->where('tenant_id', $campanha->tenant_id);

        if ($campanha->publico === 'etiqueta' && $campanha->tag_id) {
            $q->whereHas('tags', fn ($t) => $t->whereKey($campanha->tag_id));
        }

        return $q;
    }

    /**
     * Quem realmente recebe.
     *
     * QUATRO EXCLUSOES, cada uma por um motivo diferente:
     *
     * - opt-out: pediu para sair. Mandar de novo e o caminho curto para denuncia.
     * - bloqueado: alguem daqui decidiu nao falar com essa pessoa.
     * - arquivado: saiu da base ativa.
     * - grupo: campanha em grupo e spam para dezenas de pessoas que nao pediram nada, e o
     *   jeito mais rapido de o numero ser denunciado por gente que nem e cliente.
     */
    public function publicoFinal(Campaign $campanha): Builder
    {
        return $this->publicoBruto($campanha)
            ->whereNull('opt_out_em')
            ->whereNull('bloqueado_em')
            ->whereNull('arquivado_em')
            ->where('tipo', '!=', 'grupo');
    }

    /** @return array{bruto: int, final: int, fora: int} */
    public function contagem(Campaign $campanha): array
    {
        $bruto = $this->publicoBruto($campanha)->count();
        $final = $this->publicoFinal($campanha)->count();

        return ['bruto' => $bruto, 'final' => $final, 'fora' => $bruto - $final];
    }

    /**
     * Congela a lista de quem vai receber.
     *
     * Feito UMA VEZ, ao iniciar. Se a lista fosse consultada a cada envio, quem ganhasse a
     * etiqueta no meio do disparo entraria na campanha sem ninguem ter decidido isso — e quem
     * perdesse sairia, deixando um envio pela metade que ninguem consegue explicar depois.
     *
     * @return int quantos entraram
     */
    public function montarFila(Campaign $campanha): int
    {
        $entraram = 0;

        $this->publicoFinal($campanha)->orderBy('id')->chunkById(500, function ($contatos) use ($campanha, &$entraram) {
            foreach ($contatos as $contato) {
                // firstOrCreate e nao create: a chave unica ja impede repetido, e montar a
                // fila duas vezes por engano nao pode explodir nem duplicar.
                CampaignRecipient::firstOrCreate(
                    ['campaign_id' => $campanha->id, 'contact_id' => $contato->id],
                    ['status' => CampaignRecipient::PENDENTE],
                );

                $entraram++;
            }
        });

        return $entraram;
    }

    /**
     * Quando o enesimo envio deve sair, respeitando ritmo e janela de horario.
     *
     * O ritmo espalha; a janela impede que o resto caia de madrugada. Disparo as 23h nao e so
     * falta de educacao — no CDC e assedio de consumo, e no WhatsApp e denuncia.
     */
    public function quandoEnviar(Campaign $campanha, int $indice, ?Carbon $partida = null): Carbon
    {
        $momento = ($partida ?? now())->copy()
            ->addSeconds((int) floor($indice * 60 / max(1, $campanha->por_minuto)));

        return $this->dentroDaJanela($campanha, $momento);
    }

    /** Empurra para a proxima janela permitida, se cair fora dela. */
    public function dentroDaJanela(Campaign $campanha, Carbon $momento): Carbon
    {
        $m = $momento->copy();

        if ($m->hour < $campanha->hora_inicio) {
            return $m->setTime($campanha->hora_inicio, 0);
        }

        if ($m->hour >= $campanha->hora_fim) {
            return $m->addDay()->setTime($campanha->hora_inicio, 0);
        }

        return $m;
    }

    /**
     * O cliente pediu para sair?
     *
     * Compara a mensagem inteira, e nao procura a palavra dentro dela. "Nao quero parar de
     * receber" contem "parar" — tirar essa pessoa da lista por causa disso seria errar contra
     * quem justamente disse que queria continuar.
     */
    public function pedidoDeSaida(?string $texto): bool
    {
        $limpo = mb_strtolower(trim((string) $texto));
        $limpo = preg_replace('/[^\p{L}\s]/u', '', $limpo);
        $limpo = trim(preg_replace('/\s+/', ' ', (string) $limpo));

        return in_array($limpo, self::SAIDA, true);
    }

    public function marcarSaida(Contact $contato, string $motivo = 'pediu no WhatsApp'): void
    {
        if ($contato->opt_out_em) {
            return;
        }

        $contato->forceFill(['opt_out_em' => now(), 'opt_out_motivo' => $motivo])->save();
    }
}
