<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Sem estas checagens o tempo real vira o vazamento entre tenants que o escopo
// global evitou no banco: bastaria assinar o canal de outro tenant.
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    return Conversation::withoutGlobalScope('tenant')
        ->whereKey($conversationId)
        ->where('tenant_id', $user->tenant_id)
        ->exists();
});

Broadcast::channel('tenant.{tenantId}.conversations', function (User $user, int $tenantId) {
    return (int) $user->tenant_id === $tenantId;
});

// Canal proprio de cada usuario. O Filament assina este canal para as notificacoes
// do painel; sem a definicao, o /broadcasting/auth recusava com 403 e o navegador
// ficava tentando de novo, enchendo o console de erro. A regra e a obvia: cada um
// escuta o proprio canal.
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});
