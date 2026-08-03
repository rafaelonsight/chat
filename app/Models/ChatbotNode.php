<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatbotNode extends Model
{
    use BelongsToTenant;

    public const MENU = 'menu';

    public const MENSAGEM = 'mensagem';

    public const EQUIPE = 'equipe';

    public const TIPOS = [
        self::MENU     => 'Abre outro menu',
        self::MENSAGEM => 'Responde um texto',
        self::EQUIPE   => 'Entrega para uma equipe',
    ];

    protected $fillable = [
        'tenant_id', 'chatbot_id', 'parent_id', 'ordem',
        'gatilho', 'rotulo', 'tipo', 'mensagem', 'team_id',
    ];

    protected $casts = ['ordem' => 'integer'];

    protected $attributes = ['tipo' => self::MENSAGEM, 'ordem' => 0];

    public function chatbot(): BelongsTo
    {
        return $this->belongsTo(Chatbot::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('ordem');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** "Suporte > Sem internet", para a tela mostrar hierarquia numa lista plana. */
    public function caminho(): string
    {
        $nomes = [$this->rotulo];
        $pai = $this->parent;

        for ($i = 0; $pai && $i < 10; $i++) {
            $nomes[] = $pai->rotulo;
            $pai = $pai->parent;
        }

        return implode(' > ', array_reverse($nomes));
    }

    public function profundidade(): int
    {
        $n = 0;
        $pai = $this->parent;

        for ($i = 0; $pai && $i < 10; $i++) {
            $n++;
            $pai = $pai->parent;
        }

        return $n;
    }

    /** Nós que nao podem ser pai deste: ele mesmo e seus descendentes (ciclo). */
    public function idsProibidosComoPai(): array
    {
        $ids = [$this->id];

        foreach ($this->children as $filho) {
            $ids = array_merge($ids, $filho->idsProibidosComoPai());
        }

        return $ids;
    }

    public function ehMenu(): bool
    {
        return $this->tipo === self::MENU;
    }

    public function entregaParaHumano(): bool
    {
        return $this->tipo === self::EQUIPE;
    }
}
