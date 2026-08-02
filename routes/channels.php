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
