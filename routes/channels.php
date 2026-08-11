<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Sem estas checagens o tempo real vira o vazamento entre tenants que o escopo
// global evitou no banco: bastaria assinar o canal de outro tenant.
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    // DEVOLVE ARRAY, E NAO BOOLEANO. O mesmo canal serve de duas coisas: privado, para receber
    // a mensagem que chega, e de PRESENCA, para saber quem mais esta com esta conversa aberta.
    // Para o privado qualquer valor verdadeiro basta; para a presenca tem de ser um array, e e
    // ele que vira a lista de quem esta la. Devolver false continua barrando os dois.
    //
    // So o primeiro nome vai. Nome inteiro nao acrescenta nada num aviso de uma linha, e cada
    // campo a mais aqui e um campo que vaza para todo mundo que abrir a mesma conversa.
    return Conversation::visivelPara($user, $conversationId)
        ? ['id' => $user->id, 'nome' => $user->primeiroNome()]
        : false;
});

Broadcast::channel('tenant.{tenantId}.conversations', function (User $user, int $tenantId) {
    return (int) $user->tenant_id === $tenantId;
});

/*
 * QUEM ESTA ONLINE AGORA, por conta.
 *
 * Canal de PRESENCA: o array devolvido vira a lista de quem esta la dentro. Vai o nome inteiro
 * porque esta lista e de colegas, nao de clientes — a pessoa precisa distinguir dois Celsos.
 */
Broadcast::channel('equipe.{tenantId}', function (User $user, int $tenantId) {
    return (int) $user->tenant_id === $tenantId
        ? ['id' => $user->id, 'nome' => $user->name, 'primeiro' => $user->primeiroNome()]
        : false;
});

// Recado direto: so o dono do canal. Cada um escuta o proprio, como no canal do Filament.
Broadcast::channel('recados.{userId}', fn (User $user, int $userId) => $user->id === $userId);

// Canal proprio de cada usuario. O Filament assina este canal para as notificacoes
// do painel; sem a definicao, o /broadcasting/auth recusava com 403 e o navegador
// ficava tentando de novo, enchendo o console de erro. A regra e a obvia: cada um
// escuta o proprio canal.
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});
