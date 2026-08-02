# OnChat — Fase 2: Inbox (fatia vertical)

**Data:** 2026-08-02
**Status:** aprovado
**Escopo:** primeira fatia vertical utilizável do OnChat

## Objetivo

Provar o pipeline completo de mensagens de ponta a ponta: conectar um número de
WhatsApp por QR Code, receber mensagem aparecendo na tela em tempo real, e
responder por ali.

Só texto. Só Evolution API. Um número por vez. Tudo depois é incremento sobre
trilho já testado.

## Contexto

OnChat é um **app irmão do OnSight**: mesma marca, domínio próprio
(`chat.onsight.com.br`), banco, login e tabela de tenants próprios. Compartilha
com o OnSight apenas a marca e, no futuro, um SSO. É **multi-tenant desde a
primeira migration**.

Infraestrutura já entregue (fase 1): Laravel 13.23, Livewire 4, Filament 5.7,
Horizon, Reverb, PostgreSQL, Redis, e Evolution API 2.3.7 em Docker acessível
em `http://127.0.0.1:8081`.

## Decisões de arquitetura

### Pipeline completo, sem interface de driver

Fila e log bruto de webhook entram desde já — não são otimização prematura, são
o que dá confiabilidade no primeiro dia. Webhook é entrega não-confiável: os
gateways reentregam em timeout, e sem log bruto mais chave única a mensagem
duplica na tela do cliente. Sem fila, um pico trava o endpoint.

A `interface WhatsAppDriver` prevista no estudo **fica de fora**. Interface com
uma implementação só é abstração especulativa. Quando a Cloud API entrar (fase
5), extrair a interface de um `EvolutionService` que já funciona é refatoração
mecânica — e sai melhor, porque aí os dois lados são conhecidos.

### Interface: Filament para gestão, Livewire próprio para o inbox

Canais, contatos, tenants e usuários são CRUD e vão para o Filament. O inbox é
uma tela Livewire própria: chat em tempo real é exatamente o caso que o Filament
não foi feito para resolver.

## Modelo de dados

Tenancy por banco único com `tenant_id` em toda tabela de domínio e escopo
global via trait `BelongsToTenant`, que filtra automaticamente e preenche o
`tenant_id` na criação. O tenant vem do usuário autenticado.

Escopo global em vez de filtro manual porque vazamento entre tenants é a falha
mais grave possível aqui, e confiar em lembrar do `where` em toda query garante
que um dia alguém esquece.

| Tabela | Campos |
|---|---|
| `tenants` | id, nome, slug |
| `users` | + tenant_id |
| `channels` | id, tenant_id, tipo(`evolution`), nome, instance_name, webhook_secret, telefone_e164, status, conectado_em, ultimo_erro |
| `contacts` | id, tenant_id, telefone_e164, nome — único(tenant_id, telefone_e164) |
| `conversations` | id, tenant_id, channel_id, contact_id, ultima_msg_em, nao_lidas — único(channel_id, contact_id) |
| `messages` | id, tenant_id, conversation_id, channel_id, direcao, tipo(`text`), corpo, external_id, status, erro, enviada_em — único(channel_id, external_id) |
| `webhook_events` | id, channel_id, evento, payload jsonb, recebido_em, processado_em, erro |

Dois detalhes que não são cosméticos:

- `messages.channel_id` é **denormalizado** (alcançável via `conversation`).
  Existe para sustentar o índice único com `external_id`, que é a defesa contra
  duplicação por reentrega.
- `conversations(tenant_id, ultima_msg_em desc)` indexado: é literalmente a
  consulta que a tela faz a cada carregamento e a cada mensagem nova.

Status de mensagem: `queued` → `sent` → `delivered` → `read`, ou `failed`.

### Fora de escopo nesta fatia

Etiquetas, notas internas, campos personalizados, mídia, áudio, templates,
campanhas, cobranças, opt-out, pausa de automação, Cloud API. Todos estão no
estudo e vão entrar; nenhum é necessário para provar o pipeline.

## Fluxo de mensagens

### Recebimento

1. `POST /webhooks/evolution/{channel}/{secret}` — a Evolution não assina
   payload, então a URL carrega um segredo aleatório por canal.
2. Grava em `webhook_events` e responde `200` em milissegundos.
3. Job `ProcessEvolutionWebhook`: normaliza telefone para E.164, resolve
   contato e conversa com `firstOrCreate`, grava a mensagem com
   `updateOrCreate` na chave `(channel_id, external_id)`, dispara o broadcast.

Responder rápido e processar depois impede o gateway de considerar o endpoint
morto e reentregar em cascata.

Eventos assinados: `MESSAGES_UPSERT`, `MESSAGES_UPDATE`, `CONNECTION_UPDATE`,
`SEND_MESSAGE`.

### Envio

1. A tela cria a mensagem como `queued` e já a exibe (feedback imediato).
2. Job `SendTextMessage` chama a Evolution, guarda o `external_id`, move para
   `sent`.
3. `MESSAGES_UPDATE` move para `delivered` e `read`.
4. Falha vira `failed` com o erro visível na bolha e botão de reenviar.

**Ordem por conversa:** jobs de envio da mesma conversa não podem correr em
paralelo, senão duas mensagens seguidas chegam trocadas. `WithoutOverlapping`
com chave por conversa.

## Conexão do canal

CRUD de canal no Filament. Ao criar: `POST /instance/create` na Evolution,
registro do webhook da instância apontando para a URL secreta, e exibição do QR
Code (`GET /instance/connect/{instancia}`, base64) numa tela que faz polling até
o status virar `open`. `CONNECTION_UPDATE` mantém `channels.status` em dia.

`instance_name` derivado de tenant e canal (`t{tenant_id}-c{channel_id}`), para
que instâncias de tenants diferentes nunca colidam.

## A tela

Rota autenticada `/inbox`, layout próprio, dois painéis. À esquerda a lista de
conversas por `ultima_msg_em`, com nome, prévia e não lidas. À direita a janela:
histórico paginado (30 por vez, scroll para cima carrega mais), campo de envio,
status por bolha.

Três componentes Livewire com responsabilidade separada:

- `ConversationList` — lista e seleção
- `ConversationWindow` — histórico da conversa aberta
- `MessageComposer` — envio

Separados por causa do tempo real: mensagem em conversa fechada só atualiza a
lista; na aberta, insere a bolha. Componente único re-renderizaria a tela
inteira a cada mensagem.

**Canais do Reverb:** privados por tenant — `tenant.{id}.conversations` para a
lista e `conversation.{id}` para a janela, com autorização em
`routes/channels.php` conferindo que o usuário pertence ao tenant. Sem isso o
tempo real vira o vazamento que o escopo global evitou no banco.

## Erros e bordas

| Situação | Comportamento |
|---|---|
| Webhook reentregue | `updateOrCreate` na chave única — atualiza, não duplica |
| Job falha | 3 tentativas com backoff; depois `failed_jobs`, visível no Horizon |
| Evolution fora do ar no envio | Mensagem `failed`, erro na bolha, botão de reenviar |
| Sessão do WhatsApp cai | `CONNECTION_UPDATE` marca o canal; envios ficam na fila |
| Telefone em formato inesperado | Normalização E.164 centralizada; o que não normaliza fica em `webhook_events` com erro e não cria contato sujo |
| Payload inesperado | Job registra e encerra sem estourar; evento fica no log para reprocessar |

## Testes

Feature tests com HTTP fake, sem tocar a Evolution real:

- webhook cria contato, conversa e mensagem
- webhook duplicado não duplica
- envio enfileira e atualiza status
- **um tenant não enxerga dado do outro** (inegociável)
- autorização dos canais do Reverb nega usuário de outro tenant
- normalização de telefone para E.164

## Riscos aceitos

- **Desenvolvimento direto no servidor de produção.** A VPS hospeda OnSight,
  FiberCare e DRE Pro. O git local dá rollback do código, mas não protege de
  `migrate` errado nem de worker consumindo memória. Mitigações no plano de
  implementação: limite de memória nos serviços, backup antes de migration,
  nunca rodar `migrate:fresh`.
- **Sem ambiente de staging.** Decisão consciente; revisar se o OnChat crescer.

## Critério de pronto

Conectar um chip pelo QR Code, mandar mensagem do celular e vê-la aparecer na
tela sem recarregar, responder pela tela e a resposta chegar no celular, com os
status mudando de `queued` até `read`.
