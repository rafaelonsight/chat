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

        $digitos = preg_replace('/\D+/', '', explode('@', $bruto)[0]) ?? '';

        if ($digitos === '') {
            return null;
        }

        // Sem DDI: 10 digitos (fixo com DDD) ou 11 (movel com o 9)
        if (strlen($digitos) === 10 || strlen($digitos) === 11) {
            $digitos = $ddi.$digitos;
        }

        // Com DDI brasileiro: 55 + DDD(2) + 8 ou 9 digitos
        if (strlen($digitos) < 12 || strlen($digitos) > 13) {
            return null;
        }

        return '+'.$digitos;
    }
}
