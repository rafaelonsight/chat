<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactFieldValue extends Model
{
    protected $table = 'contact_field_values';

    // Chave composta: nao ha id proprio, e o Eloquent precisa saber disso para
    // nao tentar buscar por id inexistente.
    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['contact_id', 'contact_field_id', 'valor'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ContactField::class, 'contact_field_id');
    }
}
