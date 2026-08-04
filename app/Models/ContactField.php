<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactField extends Model
{
    use BelongsToTenant;

    public const TEXTO_CURTO = 'texto_curto';

    public const TEXTO_LONGO = 'texto_longo';

    public const INTEIRO = 'inteiro';

    public const DECIMAL = 'decimal';

    public const LISTA = 'lista';

    public const MULTISELECAO = 'multiselecao';

    public const DATA = 'data';

    public const DATA_HORA = 'data_hora';

    public const BOOLEANO = 'booleano';

    public const LINK = 'link';

    public const CPF_CNPJ = 'cpf_cnpj';

    public const CEP = 'cep';

    public const TIPOS = [
        self::TEXTO_CURTO  => 'Texto curto',
        self::TEXTO_LONGO  => 'Texto longo',
        self::INTEIRO      => 'Número inteiro',
        self::DECIMAL      => 'Número decimal',
        self::LISTA        => 'Lista de opções',
        self::MULTISELECAO => 'Multiseleção',
        self::DATA         => 'Data',
        self::DATA_HORA    => 'Data e hora',
        self::BOOLEANO     => 'Booleano',
        self::LINK         => 'Link',
        self::CPF_CNPJ     => 'CPF/CNPJ',
        self::CEP          => 'CEP',
    ];

    /** Tipos que dependem de uma lista de opcoes para fazer sentido. */
    public const COM_OPCOES = [self::LISTA, self::MULTISELECAO];

    protected $fillable = ['tenant_id', 'nome', 'tipo', 'opcoes', 'obrigatorio', 'ordem', 'ajuda'];

    protected $casts = [
        'opcoes'      => 'array',
        'obrigatorio' => 'boolean',
        'ordem'       => 'integer',
    ];

    protected $attributes = [
        'tipo'        => self::TEXTO_CURTO,
        'obrigatorio' => false,
        'ordem'       => 0,
    ];

    public function values(): HasMany
    {
        return $this->hasMany(ContactFieldValue::class);
    }

    public function usaOpcoes(): bool
    {
        return in_array($this->tipo, self::COM_OPCOES, true);
    }

    public function rotuloTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    /** Como o valor guardado deve aparecer para uma pessoa. */
    public function exibir(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return match ($this->tipo) {
            self::BOOLEANO     => $valor === '1' ? 'Sim' : 'Não',
            self::MULTISELECAO => implode(', ', (array) json_decode($valor, true) ?: []),
            self::DATA         => $this->comoData($valor, 'd/m/Y'),
            self::DATA_HORA    => $this->comoData($valor, 'd/m/Y H:i'),
            self::CPF_CNPJ     => self::formatarCpfCnpj($valor),
            self::CEP          => self::formatarCep($valor),
            default            => $valor,
        };
    }

    private function comoData(string $valor, string $formato): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($valor)->format($formato);
        } catch (\Throwable) {
            return $valor;
        }
    }

    public static function soDigitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }

    public static function formatarCep(?string $valor): string
    {
        $d = self::soDigitos($valor);

        return strlen($d) === 8 ? substr($d, 0, 5).'-'.substr($d, 5) : (string) $valor;
    }

    public static function formatarCpfCnpj(?string $valor): string
    {
        $d = self::soDigitos($valor);

        if (strlen($d) === 11) {
            return substr($d, 0, 3).'.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-'.substr($d, 9);
        }

        if (strlen($d) === 14) {
            return substr($d, 0, 2).'.'.substr($d, 2, 3).'.'.substr($d, 5, 3).'/'.substr($d, 8, 4).'-'.substr($d, 12);
        }

        return (string) $valor;
    }

    /**
     * Digito verificador de verdade. Campo que aceita 111.111.111-11 nao valida
     * nada — e esse e justamente o lixo que entra em cadastro de provedor.
     */
    public static function cpfCnpjValido(?string $valor): bool
    {
        $d = self::soDigitos($valor);

        if (strlen($d) === 11) {
            return self::cpfValido($d);
        }

        if (strlen($d) === 14) {
            return self::cnpjValido($d);
        }

        return false;
    }

    private static function cpfValido(string $d): bool
    {
        // Todos os digitos iguais passam na conta do DV mas nao existem.
        if (preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }

        for ($posicao = 9; $posicao < 11; $posicao++) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $d[$i] * (($posicao + 1) - $i);
            }

            $dv = ((10 * $soma) % 11) % 10;

            if ((int) $d[$posicao] !== $dv) {
                return false;
            }
        }

        return true;
    }

    private static function cnpjValido(string $d): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }

        foreach ([12, 13] as $posicao) {
            $peso = $posicao === 12 ? 5 : 6;
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $d[$i] * $peso;
                $peso = $peso === 2 ? 9 : $peso - 1;
            }

            $resto = $soma % 11;
            $dv = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $d[$posicao] !== $dv) {
                return false;
            }
        }

        return true;
    }
}
