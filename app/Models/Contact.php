<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Jid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToTenant;

    public const PESSOA = 'pessoa';

    public const GRUPO = 'grupo';

    protected $fillable = ['tenant_id', 'jid', 'tipo', 'telefone_e164', 'nome'];

    protected $attributes = ['tipo' => self::PESSOA];

    protected static function booted(): void
    {
        // O jid e NOT NULL e e a identidade real no WhatsApp. Derivar aqui cobre
        // todo caminho de criacao — inclusive o formulario do painel, que so
        // pede telefone.
        static::creating(function (Contact $contato) {
            if (! $contato->jid && $contato->telefone_e164) {
                $contato->jid = Jid::dePessoa($contato->telefone_e164);
            }
        });
    }


    public function tags(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withPivot(['origem', 'aplicado_por'])
            ->orderBy('tags.nome');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function eGrupo(): bool
    {
        return $this->tipo === self::GRUPO;
    }

    public function nomeExibicao(): string
    {
        if ($this->nome) {
            return $this->nome;
        }

        if ($this->eGrupo()) {
            // sem o assunto do grupo, ao menos identifica que e grupo
            return 'Grupo '.substr((string) $this->jid, 0, 8);
        }

        return (string) ($this->telefone_e164 ?: $this->jid);
    }

    // Para onde a Evolution deve enviar. Grupo exige o JID completo; pessoa
    // aceita o telefone.
    public function destinoWhatsApp(): string
    {
        return $this->eGrupo()
            ? (string) $this->jid
            : (string) ($this->telefone_e164 ?: $this->jid);
    }

    public static function jidDoTelefone(string $e164): string
    {
        return Jid::dePessoa($e164);
    }
}
