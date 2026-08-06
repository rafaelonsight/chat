<?php

namespace App\Support;

class PhoneNumber
{
    // A Evolution entrega o remetente como JID (5511999998888@s.whatsapp.net).
    // Guardamos sempre E.164 com "+" para nunca depender do formato do gateway.
    public static function toE164(?string $bruto, string $ddi = '55'): ?string
    {
        if ($bruto === null || $bruto === '') {
            return null;
        }

        // Corta no '@' (JID) e depois no ':' (sufixo de dispositivo do
        // multi-dispositivo). Sem o corte no ':' o numero ganha digitos a mais e
        // a mensagem era descartada como payload invalido.
        $local = explode(':', explode('@', $bruto)[0])[0];
        $digitos = preg_replace('/\D+/', '', $local) ?? '';

        if ($digitos === '') {
            return null;
        }

        // O SINAL DE MAIS decide se o DDI ja esta ali, e essa distincao nao e cosmetica:
        // "15556725603" (EUA, com o 1 na frente) e "41984919939" (celular brasileiro sem
        // DDI) tem os mesmos 11 digitos. Sem olhar o mais, o numero de teste da propria
        // Meta virava +5515556725603 — um numero brasileiro que nao existe.
        $jaTemDdi = str_starts_with(ltrim($bruto), '+');

        if (! $jaTemDdi && (strlen($digitos) === 10 || strlen($digitos) === 11)) {
            // Sem DDI: 10 digitos (fixo com DDD) ou 11 (movel com o nono).
            $digitos = $ddi.$digitos;
        }

        if ($jaTemDdi) {
            // Ja veio com DDI, de qualquer pais: vale a faixa do E.164, que e de 8 a 15
            // digitos. Aplicar a regra brasileira aqui recusaria numero estrangeiro
            // valido — e o canal oficial de teste e americano.
            return (strlen($digitos) >= 8 && strlen($digitos) <= 15) ? '+'.$digitos : null;
        }

        // Sem o mais, assumimos Brasil: 55 + DDD(2) + 8 ou 9 digitos.
        if (strlen($digitos) < 12 || strlen($digitos) > 13) {
            return null;
        }

        return '+'.$digitos;
    }

    /**
     * As duas formas que o MESMO celular brasileiro tem.
     *
     * O nono digito foi acrescentado aos celulares do Brasil em 2016, mas o WhatsApp
     * continua identificando contas antigas SEM ele. Na pratica o mesmo cliente e
     * 554184919939 para a Meta e 5541984919939 no cartao de visita dele.
     *
     * Sem tratar isso: o contato que chega pelo canal oficial nasce com 12 digitos, uma
     * planilha importada com 13 cria um SEGUNDO contato da mesma pessoa, e o atendente que
     * procura "98491-9939" nao acha nenhum dos dois.
     *
     * Vale so para 55: a regra e brasileira, e inventar variacao em numero estrangeiro
     * casaria contatos diferentes como se fossem a mesma pessoa.
     *
     * @return array<int, string> em E.164, comecando pela forma recebida
     */
    public static function variantes(?string $bruto): array
    {
        $e164 = self::toE164($bruto);

        if ($e164 === null) {
            return [];
        }

        $digitos = ltrim($e164, '+');

        if (! str_starts_with($digitos, '55') || strlen($digitos) < 12) {
            return [$e164];
        }

        $ddd = substr($digitos, 2, 2);
        $local = substr($digitos, 4);

        $formas = [$digitos];

        if (strlen($local) === 9 && $local[0] === '9') {
            // Tem o nono: a outra forma e sem ele.
            $formas[] = '55'.$ddd.substr($local, 1);
        } elseif (strlen($local) === 8 && in_array($local[0], ['6', '7', '8', '9'], true)) {
            // Oito digitos comecando em 6-9 e celular antigo: a outra forma tem o nono.
            // Fixo comeca em 2-5 e NAO ganha variacao — acrescentar o 9 ali criaria um
            // numero que nao existe.
            $formas[] = '55'.$ddd.'9'.$local;
        }

        return array_map(fn (string $d) => '+'.$d, array_values(array_unique($formas)));
    }

    /**
     * A forma que um brasileiro disca: com o nono digito.
     *
     * Para MOSTRAR e para COPIAR. O que esta guardado continua sendo o que o provedor
     * conhece — trocar o valor gravado mexeria no envio, e isso e outra decisao.
     */
    public static function discavel(?string $bruto): ?string
    {
        $formas = self::variantes($bruto);

        if ($formas === []) {
            return null;
        }

        // A mais longa e a que tem o nono.
        usort($formas, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $formas[0];
    }

    /**
     * Formas de um pedaco de numero digitado na busca.
     *
     * O atendente digita so o numero local ("98491-9939"), sem DDI e as vezes sem DDD —
     * entao aqui nao da para usar toE164, que exige numero completo. A busca e por
     * "contem", logo basta oferecer as duas grafias do trecho.
     *
     * @return array<int, string> so digitos
     */
    public static function variantesDeBusca(string $digitos): array
    {
        $digitos = preg_replace('/\D+/', '', $digitos) ?? '';

        if ($digitos === '') {
            return [];
        }

        $formas = [$digitos];

        if (strlen($digitos) >= 9 && $digitos[strlen($digitos) - 9] === '9') {
            // Remove o 9 na posicao do nono digito contando do fim.
            $corte = strlen($digitos) - 9;
            $formas[] = substr($digitos, 0, $corte).substr($digitos, $corte + 1);
        } elseif (strlen($digitos) >= 8) {
            $corte = strlen($digitos) - 8;
            $formas[] = substr($digitos, 0, $corte).'9'.substr($digitos, $corte);
        }

        return array_values(array_unique($formas));
    }
}
