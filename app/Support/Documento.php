<?php

namespace App\Support;

use App\Services\ConsultaCnpj;

/**
 * O que e um CPF e o que e um CNPJ.
 *
 * POR QUE EXISTE SEPARADO: ConsultaCnpj sabe conversar com a Receita, e ja tinha a conta do modulo
 * 11 para o CNPJ. Mas ela nao sabe nada de CPF, e a ficha de pessoa aceita os dois — sem um lugar
 * que decida "isto e um documento valido", cada tela decidiria por conta propria e o cadastro
 * aceitaria qualquer sequencia de digitos.
 *
 * DIGITO ERRADO NAO E DETALHE: e o documento que amarra a pessoa ao contrato, a nota e a cobranca.
 * Um CPF trocado num digito passa despercebido no cadastro e reaparece meses depois, quando a nota
 * e recusada.
 *
 * A conta do CNPJ fica delegada a ConsultaCnpj de proposito: duas implementacoes do mesmo modulo 11
 * e a garantia de que uma delas vai divergir da outra.
 */
class Documento
{
    public static function digitos(?string $bruto): string
    {
        return preg_replace('/\D/', '', (string) $bruto) ?? '';
    }

    public static function ehCpf(?string $bruto): bool
    {
        return strlen(self::digitos($bruto)) === 11;
    }

    public static function ehCnpj(?string $bruto): bool
    {
        return strlen(self::digitos($bruto)) === 14;
    }

    /** Vazio nao e invalido: o documento nao e obrigatorio. Quem exige e a tela. */
    public static function valido(?string $bruto): bool
    {
        $digitos = self::digitos($bruto);

        if ($digitos === '') {
            return true;
        }

        return match (strlen($digitos)) {
            11 => self::cpfValido($digitos),
            14 => ConsultaCnpj::valido($digitos),
            default => false,
        };
    }

    public static function formatar(?string $bruto): ?string
    {
        $digitos = self::digitos($bruto);

        if (self::ehCnpj($digitos)) {
            return ConsultaCnpj::formatar($digitos);
        }

        if (self::ehCpf($digitos)) {
            return vsprintf('%s.%s.%s-%s', [
                substr($digitos, 0, 3), substr($digitos, 3, 3),
                substr($digitos, 6, 3), substr($digitos, 9, 2),
            ]);
        }

        // Nem um nem outro: devolve como veio, para a pessoa ver o que digitou.
        return $bruto ?: null;
    }

    /** Como se chama o que a pessoa digitou — para a mensagem de erro dizer a coisa certa. */
    public static function rotulo(?string $bruto): string
    {
        return self::ehCnpj($bruto) ? 'CNPJ' : 'CPF';
    }

    /**
     * Modulo 11 do CPF: dois digitos verificadores, pesos decrescentes.
     *
     * Sequencia repetida (111.111.111-11) fecha a conta e nao existe — por isso a checagem antes.
     */
    private static function cpfValido(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        foreach ([9, 10] as $tamanho) {
            $soma = 0;

            for ($i = 0; $i < $tamanho; $i++) {
                $soma += (int) $cpf[$i] * ($tamanho + 1 - $i);
            }

            $resto = ($soma * 10) % 11;
            $esperado = $resto === 10 ? 0 : $resto;

            if ((int) $cpf[$tamanho] !== $esperado) {
                return false;
            }
        }

        return true;
    }
}
