<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um convidado de um compromisso.
 *
 * Pode ser contato do CRM ou so um nome com e-mail: o socio que entra numa reuniao, o
 * fornecedor que aparece uma vez. Exigir cadastro faria a pessoa inventar contato para
 * conseguir convidar.
 */
class AppointmentGuest extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'appointment_id', 'contact_id', 'nome', 'email',
        'email_em', 'whatsapp_em',
    ];

    protected $casts = [
        'email_em'    => 'datetime',
        'whatsapp_em' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function temEmail(): bool
    {
        return filled($this->email);
    }

    /** Ja foi avisado por algum caminho? */
    public function avisado(): bool
    {
        return $this->email_em !== null || $this->whatsapp_em !== null;
    }

    /** "e-mail e WhatsApp", "só e-mail", ou nada. */
    public function comoFoiAvisado(): string
    {
        return match (true) {
            $this->email_em && $this->whatsapp_em => 'avisado por e-mail e WhatsApp',
            (bool) $this->email_em => 'avisado por e-mail',
            (bool) $this->whatsapp_em => 'avisado no WhatsApp',
            default => 'ainda não avisado',
        };
    }
}
