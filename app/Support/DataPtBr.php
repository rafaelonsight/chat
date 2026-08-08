<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Nomes de dia e mes em portugues.
 *
 * A mao, e nao pelo locale do PHP: translatedFormat depende de o locale do sistema estar
 * instalado na maquina, e quando nao esta ele nao falha — devolve "August" e segue. Erro que
 * nao levanta a mao e erro que chega ao cliente.
 */
class DataPtBr
{
    public const DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    public const DIAS_LONGOS = [
        'domingo', 'segunda-feira', 'terça-feira', 'quarta-feira',
        'quinta-feira', 'sexta-feira', 'sábado',
    ];

    public const MESES = [
        1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    /** "quinta-feira, 13 de agosto" */
    public static function porExtenso(Carbon $d): string
    {
        return self::DIAS_LONGOS[$d->dayOfWeek].', '.$d->day.' de '.self::MESES[$d->month];
    }

    /** "hoje", "amanhã", ou "qui 13/08" */
    public static function curto(Carbon $d): string
    {
        if ($d->isToday()) {
            return 'hoje';
        }

        if ($d->isTomorrow()) {
            return 'amanhã';
        }

        return self::DIAS[$d->dayOfWeek].' '.$d->format('d/m');
    }
}
