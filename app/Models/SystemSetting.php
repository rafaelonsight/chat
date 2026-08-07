<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Chave e valor do sistema inteiro. NAO usa BelongsToTenant de proposito: o que mora aqui e
 * do servidor, nao de um cliente.
 */
class SystemSetting extends Model
{
    protected $fillable = ['chave', 'valor'];

    public static function ler(string $chave, ?string $padrao = null): ?string
    {
        return static::query()->where('chave', $chave)->value('valor') ?? $padrao;
    }

    public static function gravar(string $chave, ?string $valor): void
    {
        static::query()->updateOrCreate(['chave' => $chave], ['valor' => $valor]);
    }
}
