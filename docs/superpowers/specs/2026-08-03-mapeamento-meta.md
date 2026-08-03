# Mapeamento da integração Meta — WhatsApp oficial, Messenger, Instagram e comentários

**Data:** 2026-08-03
**Fontes:** documentação oficial da Meta (developers.facebook.com), consultada nesta data
**Status:** estudo. Nada implementado.

> Datas e preços da Meta mudam. Tudo aqui foi lido da documentação oficial em
> 03/08/2026 e precisa ser reconferido antes de virar contrato ou preço de venda.

---

## 1. Resumo executivo — o que decide tudo

Três fatos moldam qualquer integração com a Meta:

**A janela de 24 horas.** Fora dela você **não pode** enviar texto livre. Só
modelo (template) aprovado. Isso não é regra de preço, é regra de plataforma — e
é a diferença mais profunda em relação ao Baileys, onde escrevemos quando
queremos. Se o OnChat não modelar essa janela, o atendente vai digitar e o envio
vai falhar em silêncio.

**A cobrança é por modelo entregue, não por conversa.** Desde 01/07/2025.
Mensagem sem modelo dentro da janela aberta é **grátis**. Modelo de utilidade
respondendo o cliente dentro da janela também é grátis. Só marketing sempre
custa.

**Comentário não é mensagem.** Comentário em post do Instagram ou Facebook é
**público**, tem autor que talvez nunca tenha te mandado DM, e responder em
público é diferente de responder no privado. Tratar comentário como conversa
comum é a decisão errada mais comum nesse tipo de produto.

---

## 2. WhatsApp Business Platform (API oficial / Cloud API)

### 2.1 Modelo de cobrança

Desde **01/07/2025**: cobrança **por mensagem de modelo entregue**
(`"type":"template"`).

| Categoria | Quando custa |
|---|---|
| **Marketing** | **Sempre.** Sem desconto por volume em nenhuma faixa — deliberado pela Meta para manter disparo promocional caro. |
| **Utilidade** | Grátis se entregue **dentro** da janela de atendimento aberta. Fora dela, cobrada com faixas de volume. |
| **Autenticação** | Cobrada, com faixas de volume (OTP, verificação). |
| **Serviço** | Texto livre respondendo o cliente dentro da janela: **grátis**. |

Desde **01/11/2024**, mensagem que não é modelo (`text`, `image`, `audio`,
`document`…) **não é cobrada** — mas só pode ser enviada dentro de uma janela de
atendimento aberta.

**Faixas de volume** (utilidade e autenticação): específicas por mercado *e*
categoria, valem para todas as WABAs do portfólio, e **zeram todo mês** à
meia-noite no fuso da WABA.

**Preço máximo (a partir de 2026):** na API de Mensagens de Marketing dá para
definir um teto por entrega; a Meta cobra esse valor ou menos.

### 2.2 Janela de atendimento (CSW) e ponto de entrada gratuito (FEP)

**CSW — Customer Service Window: 24 horas**, aberta quando o cliente te manda
mensagem. Dentro dela: qualquer tipo de mensagem, de graça. Fora dela: **só
modelo**.

**FEP — Free Entry Point: 72 horas.** Se o cliente chega por **anúncio
clique-para-WhatsApp** ou pelo **botão de CTA de uma Página do Facebook**, usando
o app Android ou iOS (desktop e web **não** contam):

1. Abre a CSW de 24h normalmente.
2. Se você responder dentro de 24h com **qualquer** tipo de mensagem, essa
   resposta é grátis e abre uma janela FEP de **72 horas** contada da sua
   resposta.
3. Enquanto a FEP estiver aberta, **qualquer** mensagem é grátis.

**Pegadinha:** CSW e FEP são independentes. Depois que a CSW fecha, mesmo com FEP
aberta, você só envia modelo. FEP resolve o **custo**, não a **permissão**.

Consequência prática para provedor: **anúncio clique-para-WhatsApp é a entrada
mais barata que existe.** 72 horas de conversa livre e gratuita.

### 2.3 Brasil — cobrança em reais (prazo real, não teórico)

- Implantação começou em **01/07/2026**.
- Desde **16/07/2026**, provedores qualificados e empresas integradas direto
  podem criar WABAs em **BRL**, faturadas pelo **Facebook Brasil**.
- **Até 30/06/2027** os clientes qualificados precisam migrar **todas** as WABAs
  do portfólio para BRL.
- **A partir de 01/07/2027 a Meta deixa de entregar mensagens** de WABAs que não
  estejam em BRL de clientes qualificados.

Ou seja: se o OnChat nascer com WABA em dólar, há um prazo de migração com
desligamento no fim. Nascer direto em BRL evita isso.

### 2.4 Cadastro Incorporado (Embedded Signup) — como o cliente entra

É o fluxo em que o provedor conecta o próprio número **dentro do nosso produto**,
sem sair para o painel da Meta. É o que viabiliza o OnChat como produto
multi-tenant.

**Prazo crítico: a versão 2 do Cadastro Incorporado será descontinuada em
15/10/2026.** Qualquer implementação nova tem que ser **v3**. Isso é daqui a
cerca de dois meses.

Limitações relevantes:

- Número comercial só pode ser registrado para uso na **Cloud API**.
- Número já em uso no **app WhatsApp Business** é aceito, mas exige customizar o
  fluxo para onboarding desses usuários.
- Cliente integrado começa com **limites de mensagens** (messaging limits) que
  sobem com qualidade e volume.
- Como provedor de tecnologia, **o cliente precisa colocar a forma de pagamento
  na WABA dele** — não passa pela nossa.

Esse último ponto é decisivo para o modelo de negócio: a Meta cobra do provedor
de internet, não de nós. Nós cobramos pelo software.

### 2.5 O que muda no OnChat

| Hoje (Baileys/Evolution) | Com a API oficial |
|---|---|
| Escrevemos quando queremos | Fora da janela, **só modelo aprovado** |
| Texto livre sempre | Texto livre só dentro de 24h |
| Sem custo por mensagem | Marketing sempre custa |
| Risco de ban do número | Número oficial, sem risco de ban por uso |
| Grupos funcionam | **Grupos não existem na API oficial** |
| Conectar = QR Code | Conectar = Embedded Signup v3 + verificação |

**Grupos são a perda mais importante.** Nós acabamos de implementar grupos, e a
API oficial não os suporta. Provedor que usa grupo de bairro precisa manter um
canal Baileys em paralelo — o modelo híbrido que o estudo original previa.

---

## 3. Messenger (Página do Facebook)

### 3.1 O essencial

- Requer **Token de Acesso à Página**, gerado depois que um admin da Página
  concede as permissões ao app.
- **Login do Facebook para Empresas** é obrigatório para pedir as permissões.
- **Acesso padrão** limita os dados a quem tem função no app ou na Página.
  **Advanced Access** libera para todos os usuários e **exige análise do app**.
- Webhooks em vez de polling.
- Há **limitação de volume** (rate limiting) documentada.

### 3.2 Campos de webhook da Página que interessam

| Campo | Para quê |
|---|---|
| `messages` | mensagens recebidas no Messenger |
| `messaging_handovers` | **protocolo de transferência** — passar o controle entre nosso app e o Inbox da Página |
| `messaging_optins` | consentimento para mensagens de notificação |
| `feed` | atividade no feed da Página, **inclusive comentários** |
| `mention` | menções à Página, inclusive em comentários e posts |
| `inbox_labels` | etiquetas do Inbox da Página |

**`messaging_handovers` é mais importante do que parece.** Sem ele, nosso app e o
Inbox nativo da Página disputam a conversa: o cliente responde e a mensagem pode
cair só no app da Meta, com o atendente do OnChat achando que ninguém respondeu.
O protocolo de transferência é o que evita atendimento duplicado.

---

## 4. Instagram — três caminhos, e eles não são equivalentes

A Meta oferece **três** APIs para Instagram. Escolher errado custa refazer.

| | **Login do Instagram** | **Login do Facebook para Empresas** | **Messenger API for Instagram** |
|---|---|---|---|
| URL base | `graph.instagram.com` | `graph.facebook.com` | `graph.facebook.com` |
| Precisa de Página do Facebook | **Não** | **Sim** | **Sim** |
| Mensagens (DM) | ✅ | — | ✅ |
| Comentários | ✅ | ✅ | — |
| Menções | ✅ (vêm dentro de `comments`) | ✅ (campo próprio) | — |
| Mídia / publicar | ✅ | ✅ | — |
| Busca por hashtag | — | ✅ | — |
| Instagram + Messenger juntos | — | — | ✅ **num só lugar** |

**Recomendação:** para o OnChat, **Messenger API for Instagram** — porque ela
unifica DM do Instagram e do Messenger na mesma integração, que é exatamente o
nosso caso (um inbox para tudo). Se depois quisermos gerenciar comentários,
somamos a API com Login do Facebook, que compartilha a mesma base
(`graph.facebook.com`) e o mesmo token de Página.

O **Login do Instagram** é atraente por não exigir Página do Facebook — útil para
cliente que só tem Instagram. Mas ele não unifica com Messenger, então viraria
uma segunda integração.

### 4.1 Regra de mensagem do Instagram

Só é possível mandar DM para quem **já mandou mensagem** para a conta. Quando o
usuário escreve, abre uma janela de **24 horas** para resposta em texto livre.
Mesma lógica do WhatsApp.

### 4.2 Campos de webhook do Instagram

`messages`, `message_echoes`, `message_reactions`, `comments`, `live_comments`,
`mentions`, `story_insights`.

### 4.3 Requisitos e limitações que travam projeto

- O app precisa estar **Publicado** no Painel de Apps para receber webhook.
- **Advanced Access** obrigatório (para `comments` e `live_comments`
  explicitamente).
- **Verificação da empresa: obrigatória.**
- Certificado TLS válido — **autoassinado não funciona**. (O nosso é Let's
  Encrypt, então está resolvido.)
- **A conta profissional do Instagram tem que ser pública** para receber
  notificação de comentário ou menção. Conta privada não gera webhook.
- **Não existe personalização de webhook por conta:** se qualquer usuário assinar
  um campo, o app recebe notificação de **todos** os campos assinados. Ou seja, o
  filtro por cliente é responsabilidade nossa, no recebimento.
- Comentário em **live** só chega durante a transmissão.
- ID de álbum não vem na notificação — precisa buscar pelo ID do comentário.
- `story_insights` só traz as métricas das primeiras 24h.

---

## 5. Comentários de feed — por que merecem modelo próprio

Comentário chega por webhook (`feed` na Página, `comments` no Instagram), mas
**não é conversa**:

1. É **público**. Responder errado é errar na frente de todo mundo.
2. O autor pode nunca ter mandado DM — então **não há janela de 24h aberta** e
   não se pode mandar DM para ele por iniciativa nossa.
3. Existem duas respostas possíveis e elas são diferentes: **responder o
   comentário em público** ou **puxar para o privado**.
4. O padrão "comentário vira DM" (*comment-to-DM*) só funciona se o usuário
   iniciar o DM, ou dentro das regras específicas de resposta a comentário.

**Sugestão de modelagem:** comentário entra como um tipo de item com fila
própria, não misturado nas conversas de atendimento. Reaproveita `team_id` para
roteamento ("comentário do Instagram vai para o Marketing") e
`conversation_events` para o rastro. O que **não** deve acontecer é comentário de
post competindo com cliente sem internet na fila de Novos — é a mesma lição do
grupo de WhatsApp, que tiramos da fila por afogar o atendimento real.

---

## 6. O que o OnChat precisa ganhar para suportar a Meta

Em ordem de dependência:

### 6.1 Janela de conversa como cidadão de primeira classe

`conversations.janela_expira_em` (timestamp). Ao receber mensagem do cliente:
`now() + 24h`. A interface **precisa** mostrar quando a janela está fechada e
**bloquear** o campo de texto livre, oferecendo modelo no lugar. Sem isso, o
atendente digita e o envio falha.

Isso vale para WhatsApp oficial, Messenger e Instagram — os três têm a mesma
regra de 24h.

### 6.2 Templates aprovados são outra coisa, não o nosso "Modelo de mensagens"

Nosso `message_templates` é texto livre nosso, para agilizar digitação. Template
da Meta é **entidade remota**: tem id, categoria (marketing/utilidade/auth),
idioma, variáveis posicionais, e **status de aprovação** que a Meta concede ou
nega. Precisa de tabela própria, sincronização com a Meta, e um seletor separado
no compositor. Confundir os dois seria erro caro.

### 6.3 Identidade sem telefone

`contacts.jid` hoje guarda `telefone@s.whatsapp.net` ou `grupo@g.us`. Messenger e
Instagram usam **PSID / IGSID**, que são identificadores **escopados por
app+página** e **não são telefone**. A coluna aguenta, mas a semântica muda:
`telefone_e164` fica nulo, e o mesmo ser humano pode ser dois contatos (um no
Instagram, um no WhatsApp) sem forma confiável de unificar.

Decisão a tomar: aceitar contatos duplicados por canal, ou construir unificação
manual ("mesclar contatos").

### 6.4 Canal ganha tipo de verdade

`channels.tipo` já existe com `evolution`. Passa a ter `cloud_api`, `messenger`,
`instagram`. E o `EvolutionService` — que eu deliberadamente **não** abstraí em
interface por não haver segundo driver — agora tem motivo real para virar
interface, porque o segundo driver deixou de ser hipotético.

### 6.5 Protocolo de transferência do Messenger

`messaging_handovers`, para não disputar a conversa com o Inbox nativo da Página.

### 6.6 Cadastro Incorporado v3

Para o provedor conectar o número dele sem sair do OnChat. **Já nascer em v3** —
a v2 morre em 15/10/2026.

---

## 7. Pré-requisitos administrativos (não são código)

Nada disso se resolve programando:

1. **App na Meta** (Painel de Apps), publicado.
2. **Verificação da empresa** (Business Verification) — obrigatória, exige
   documento da pessoa jurídica.
3. **Análise do app** (App Review) para Advanced Access, com vídeo demonstrando o
   uso de cada permissão.
4. **Provedor de Tecnologia / Solution Provider**, se o OnChat for vender para
   outros provedores.
5. Para WhatsApp: **WABA em BRL** (ver §2.3) e forma de pagamento **na conta do
   cliente**.
6. Conta do Instagram do cliente precisa ser **profissional e pública** para
   comentários.

Prazo típico de verificação e análise: semanas, não dias. **É o caminho crítico
do projeto** — começar por aqui, não pelo código.

---

## 8. Ordem sugerida

| Fase | O que | Por quê primeiro |
|---|---|---|
| **0** | Abrir app, verificação da empresa, iniciar App Review | Leva semanas e bloqueia tudo |
| **1** | Janela de 24h no modelo e na interface | Sem isso, qualquer canal Meta quebra em uso |
| **2** | Interface de driver + `channels.tipo` | Agora há segundo driver de verdade |
| **3** | WhatsApp Cloud API + templates aprovados | Maior valor: campanha e cobrança legais, sem risco de ban |
| **4** | Cadastro Incorporado v3 | Multi-tenant de verdade; prazo em 15/10/2026 |
| **5** | Messenger + Instagram DM (Messenger API for Instagram) | Uma integração entrega os dois |
| **6** | Comentários de Instagram e feed da Página, com fila própria | Depende de Advanced Access aprovado |

**O híbrido continua necessário:** grupos de WhatsApp só existem no Baileys.
Provedor que usa grupo de bairro vai manter os dois canais lado a lado — que é
exatamente o desenho que o estudo original propôs.

---

## Fontes

- Preços na Plataforma do WhatsApp Business — `developers.facebook.com/docs/whatsapp/pricing` (atualizado 01/07/2026)
- Cadastro Incorporado — `developers.facebook.com/docs/whatsapp/embedded-signup` (atualizado 28/06/2026)
- Plataforma do Instagram — `developers.facebook.com/docs/instagram-platform` (atualizado 17/04/2026)
- Webhooks do Instagram — `developers.facebook.com/docs/instagram-platform/webhooks` (atualizado 03/03/2026)
- Visão geral da plataforma do Messenger — `developers.facebook.com/docs/messenger-platform/overview` (atualizado 23/03/2026)
- Webhooks Reference: Page — `developers.facebook.com/docs/graph-api/webhooks/reference/page`
