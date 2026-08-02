<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'telefone_e164', 'nome'];

    public function nomeExibicao(): string
    {
        return $this->nome ?: $this->telefone_e164;
    }
}
