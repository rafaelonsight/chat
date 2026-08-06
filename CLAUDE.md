# OnChat

Chat e CRM de WhatsApp **para qualquer negócio**. Não é específico de provedor de internet:
se você encontrar vocabulário de ISP (assinante, plano, OS, contrato) no código ou na tela,
é resíduo de um projeto anterior e deve ser corrigido, não imitado.

Produção: `/opt/onchat/app` na VPS, servido por Caddy em `chat.onsight.com.br`. Roda como
usuário `onchat`. Deploy é `git pull` + migrate + build.

## Como rodar

```bash
php artisan test                      # suíte inteira, ~2 min
php artisan test --filter=NomeDoTeste
npm run build                         # OBRIGATÓRIO depois de mexer em classe do Tailwind
```

**Tailwind 4 varre os arquivos em tempo de BUILD** (`@source`). Classe nova em Blade que não
passou por `npm run build` simplesmente não existe no CSS — e o sintoma é "o estilo não
aplicou", não um erro.

## Arquitetura, em uma passada

- **Multi-tenant** por `tenant_id`, com escopo global via `BelongsToTenant`.
- **Canais** (`channels`) são as portas de entrada e saída: `evolution` (Baileys, não
  oficial) e `meta_cloud` (API oficial da Meta).
- **Drivers de canal** em `app/Services/Canais/`: a interface `Enviador` define
  `texto`, `midia`, `marcarLida`, `verificarNumero`. `Enviadores::para($canal)` escolhe.
- **Jobs** fazem o trabalho de rede; a interface nunca espera provedor responder.
- **Chatbot** é um grafo (`ChatbotFluxo`, `ChatbotMotor`), publicado por canal ou global.

### Regra que não se negocia: job não conhece provedor

Se você está escrevendo `if ($canal->tipo === ...)` dentro de um job, pare. Isso já foi
consertado três vezes (envio de texto, marcar como lida, envio de mídia) e sempre pelo mesmo
caminho: o comportamento vai para o driver, o job pede ao canal. Um `if` de driver dentro de
job se multiplica em seis meses até ninguém entender mais o envio.

## Convenções

**Comentário explica POR QUE, nunca o quê.** O código já diz o quê. O comentário existe para
a pessoa que vai querer "simplificar" aquilo em seis meses e não sabe qual bug aquilo evita.

**Português no código de domínio.** Métodos, variáveis e comentários em português; o inglês
fica nas APIs de framework. Chave de API estrangeira é traduzida na borda — o resto do
sistema não deveria aprender inglês de API.

**Nome de teste é frase de comportamento**, não nome de método. `it('o status NAO retrocede
quando o recibo chega fora de ordem')` diz o que se perde se aquilo quebrar.

**Erro de configuração estoura; recusa do provedor não.** Ver `ConfiguracaoInvalida` e
`FalhaDoProvedor`: canal sem Phone Number ID tem de aparecer alto no Horizon, porque alguém
precisa consertar. "A Meta recusou este pedido" fica em silêncio, porque o motivo já está na
bolha da conversa e retentar não muda nada.

## Armadilhas que já custaram tempo

Cada uma destas custou pelo menos uma sessão de investigação. Estão em ordem de quantas vezes
me pegaram.

### `Http::fake()` chamado duas vezes não substitui o primeiro

A definição original vence e a nova é **ignorada em silêncio**. Sem erro, sem aviso. O
sintoma nunca é "o fake não funcionou": é um teste que passa pelo motivo errado, ou que falha
dizendo que o valor esperado está nulo. Me pegou **quatro vezes**.

Remédio: o stub lê de uma propriedade do teste, e quem precisa de outra resposta troca a
propriedade.

```php
// beforeEach
$this->resposta = ['ok' => true];
$this->status   = 200;
Http::fake(['*' => fn () => Http::response($this->resposta, $this->status)]);

// no teste que precisa de outra coisa
$this->status = 503;
```

Está escrito também em `tests/Pest.php`, onde todo autor de teste passa.

### `asJson()` + `attach()` produzem um corpo que mente

`asJson()` faz duas coisas: escolhe o formato do corpo **e fixa o cabeçalho** `Content-Type:
application/json`. `attach()` muda apenas o formato do corpo. Juntos: corpo multipart
anunciado como JSON, sem boundary. Com `Http::fake` isso "funciona"; contra a API real é
recusa sem explicação.

Para subir arquivo, use um cliente sem `asJson()` e deixe o cliente HTTP montar o
`Content-Type` (ver `MetaCloudEnviador::clienteDeArquivo`).

### `TenantContext` é nulo dentro de job

`TenantContext::get()` cai para `auth()->user()?->tenant_id`, e job de fila não tem usuário
logado. Sem `TenantContext::runAs($tenantId, ...)` o escopo global não acha nada e não grava
nada — e não dá erro, só não faz.

### O nono dígito do celular brasileiro

O WhatsApp identifica contas antigas **sem** o nono dígito; a pessoa escreve **com**. O mesmo
cliente é `554184919939` para a Meta e `5541984919939` no cartão de visita. Use
`PhoneNumber::variantes()` e `Contact::acharPorTelefone()` antes de criar contato, sempre.

E o **sinal de mais decide o DDI**: `15556725603` (EUA) tem os mesmos 11 dígitos de
`41984919939` (celular brasileiro sem DDI). Descartar o `+` junto com a pontuação fazia
`toE164` colar 55 em número estrangeiro.

### `php artisan view:cache` compila mas não confere o PHP gerado

Blade inválido compila e explode em tempo de execução. Depois de mexer em Blade, rode
`php -l` nos arquivos de `storage/framework/views/`.

E marcador literal em Blade é `@{{apelido}}` — `{{ '{{apelido}}' }}` gera PHP quebrado.

### Nos testes, `QUEUE_CONNECTION=sync` ignora `->delay()`

Job atrasado dispara na hora. Para testar tolerância e temporizador, use
`Queue::fake([JobEspecifico::class])` — **parcial**. `Queue::fake()` sem argumento também
falsifica o job do webhook, e aí o motor nunca roda.

### `assertDontSee` com texto curto é gerador de falha intermitente

Cada resposta do Livewire carrega ids e snapshots aleatórios. `assertDontSee('oi')` passa
quase sempre e falha no dia em que sai um `oi` dentro de um hash — e o sintoma é uma
acusação de vazamento que não houve, num teste que passa quando rodado sozinho.

Asserção de AUSÊNCIA precisa de texto que só possa vir de um lugar. Se o cenário usa um
corpo curto, passe um corpo longo só para esse teste.

### Postgres: `CHECK` em vez de enum, e sequência não volta atrás

Enum precisa de `ALTER TYPE` para crescer. E `id` não é 1 no teste: a transação desfaz as
linhas, não a sequência. Nunca escreva `campo_1` num teste.

### Filament

`resolveRecord()` devolvendo `null` significa **pular a linha**, não "criar novo". Isso fazia
toda importação relatar sucesso sem importar ninguém.

Ação de menu que muda estado precisa de `MenuItem::postAction()`.

## WhatsApp oficial (Meta Cloud API)

- **Janela de 24 horas**: fora dela só sai **template aprovado**. A trava vive no job, não só
  na tela — a fila não anda instantânea, e a janela pode fechar entre enfileirar e enviar.
- **Destino sem o `+`** e `preview_url: false`.
- **Mídia são duas chamadas**: sobe o arquivo, a Meta devolve um id, a mensagem referencia o
  id. Para receber, também duas: pergunta onde está, depois busca os bytes. A URL devolvida
  **vive poucos minutos** e **exige o mesmo `Authorization`**, apesar de ser de outro domínio.
- **Assinatura do webhook** é HMAC sobre o corpo **cru**. Reserializar o JSON muda a ordem das
  chaves e a assinatura nunca casa.
- **Verificação do webhook** responde o `hub_challenge` em **texto puro**. A Meta manda
  `hub.mode` com ponto; funciona porque o PHP troca ponto por sublinhado.
- **Inscrição do app na WABA** (`POST /{waba-id}/subscribed_apps`) é separada da inscrição do
  webhook e **não tem botão no painel**. Sem ela a Meta tem o endereço e não entrega nada.
- **`referral`** (qual anúncio trouxe a conversa) vem só junto da **primeira** mensagem. Não
  existe consulta depois.
- **Não há grupo** na API oficial. É por isso que o híbrido com a Evolution continua
  necessário.
- **Credencial é por canal** (`channels.meta_token`, cifrado), não global: cada cliente traz o
  próprio número.

## Segurança

- **Segredo não passa por chat.** Entrega por arquivo, com script que valida formato antes de
  gravar (`/usr/local/bin/gravar-token.sh`, `gravar-segredo.sh`).
- **Backup de `.env` fica fora do repositório**, em `/var/backups/onchat/env` (root, 700). Um
  `git add -A` já tentou commitar um backup com chave da Meta dentro.
- **Token no cabeçalho, nunca na URL.** URL aparece em log de servidor, em proxy e em mensagem
  de exceção.

## Antes de dizer que terminou

1. `php artisan test` inteiro, não só o filtro.
2. `npm run build` se tocou em classe de CSS.
3. `php -l` nas views compiladas se tocou em Blade.
4. Provou contra a coisa real quando dava para provar — fake prova fiação, não comportamento.
   Quando não dava, diga isso sem disfarce.
