# Mapeamento do construtor de chatbot do ISP Chat

**Data:** 2026-08-03
**Fontes:** prints do ISP Chat enviados pelo Rafael + documentação pública da HelenaCRM
**Status:** estudo, para dirigir a implementação do construtor do OnChat

> **Inferência com evidência:** o construtor do ISP Chat aparenta ser o da
> **HelenaCRM em marca branca**, ou os dois compartilham o mesmo motor. O
> vocabulário é idêntico e específico demais para coincidência: *ponto de retorno*,
> *tempo de tolerância*, *transbordo*, *direcionar para outro chatbot*, *mover
> card*, *pergunta dinâmica*, *Supervisor IA*. Não é certeza, mas é forte o
> suficiente para tratar a documentação da Helena como a especificação da tela que
> só vemos por print — e foi o que fiz aqui.

---

## 1. A estrutura do produto

Três telas, nesta ordem:

**Lista de chatbots.** No topo, cartões dos canais conectados (número, instância,
qual bot está em uso). Abaixo, tabela com **Nome · Tipo do canal · Equipe padrão ·
Ações**. Abas **Operacionais | Arquivados**, busca e **+ Novo**. O bot em produção
leva o selo **Em uso**. Ações por linha: arquivar, **duplicar**, editar, ajustes.

**Criar (gaveta lateral).** Só três campos: **Nome**, **Tipo de canal** e **Equipe
padrão do chatbot**. Os tipos de canal vistos: *WhatsApp (Oficial)*, *WhatsApp
(Z-API)*, *WhatsApp (EvolutionAPI)*, *Instagram/Messenger*.

**Construtor.** Canvas com grade de pontos, cartões ligados por linhas tracejadas.
No cabeçalho: nome + selo **Rascunho**, ajuda, **testar** (ícone de frasco),
**histórico de versões**, ajustes, **Salvar alterações** e **Publicar**. Rodapé com
ferramentas de canvas (seleção, moldura, adicionar, IA, nota, grade).

Detalhe de acabamento que vale copiar: **cartão com problema recebe um X vermelho
no próprio cartão**. Muito melhor que uma lista de erros no topo, porque aponta
onde.

---

## 2. Anatomia do fluxo

Quatro peças:

1. **Nó de início** — "INICIAR ATENDIMENTO", mostra o canal. Fica solto à esquerda.
2. **Bloco de Gatilhos** — numerado, com **Configuração geral** dentro (§3).
3. **Grupos** — os cartões de trabalho ("Novo Grupo 22"). Cada um tem uma lista
   ordenada de **ações** e um **+** para "Próximos passos".
4. **Saídas** — cada ação que ramifica cria linhas de saída no rodapé do cartão,
   com rótulo e uma bolinha de conexão.

Grupos podem ser **duplicados** (aparecem como "(Cópia)" no nome).

---

## 3. Gatilhos e Configuração geral

Esta é a parte que não se vê no print e que muda o desenho.

### 3.1 Tipos de gatilho

| Gatilho | Quando dispara |
|---|---|
| **Iniciar atendimento pelo canal** | qualquer mensagem, independente do horário |
| **Dentro ou fora do horário** | só no período configurado |
| **Palavra-chave** | quando a mensagem contém um termo específico |
| **Múltiplos gatilhos** | vários no mesmo bot, gerenciados juntos |

Palavra-chave existe para **campanha de anúncio**: o cliente chega por um link com
termo específico e cai num fluxo próprio.

### 3.2 Tempo de espera — dois relógios diferentes

**Tempo limite de espera.** Tempo máximo aguardando a resposta do cliente. Se ele
não responder, o sistema executa o **subfluxo de tempo limite de espera** — um
caminho próprio no grafo, não um erro.

**Tempo de tolerância.** Tempo entre uma mensagem e outra **do cliente**, para
**agrupar mensagens seguidas e tratá-las como uma só**. No print aparece como
"Tolerância: Imediatamente" e "Limite de espera: 20 minutos".

> **É o achado mais importante deste mapeamento.** Cliente no WhatsApp escreve em
> várias linhas: "oi" / "bom dia" / "preciso de ajuda". Sem tolerância, cada linha
> é um passo do fluxo — o bot responde o menu na primeira e "não entendi" nas
> outras duas, até estourar as tentativas. O bot parece quebrado no primeiro
> contato real.

---

## 4. Catálogo de ações

Agrupado como eles agrupam.

### IA — blocos independentes
- **Adicionar Supervisor IA**
- **Adicionar Agente IA**

### Mensagem
- **Enviar mensagem** — texto e segue.
- **Enviar modelo de mensagem** — reaproveita os templates já cadastrados (lista só
  os ativos compatíveis com o canal).
- **Enviar pergunta** — em três formatos: **texto livre**, **menu de botões** ou
  **lista de opções**. Espera a resposta e segue. Tem tratamento de **resposta
  inválida** (ex.: a pergunta é de botões e o cliente manda áudio) com mensagem de
  alerta configurável.
- **Enviar pergunta dinâmica** — marcada como NOVO na paleta deles.
- **Enviar menu**.

### Contato
- **Adicionar/remover etiquetas do contato**
- **Adicionar/remover da sequência**

### Atendimento
- **Transferir atendimento** (para equipe)
- **Concluir atendimento**
- **Modificar metadados internos** do contato ou do atendimento

### CRM
- **Criar card**, **Mover card**, **Alterar campos do card**

### Tempo
- **Aguardar mensagens do contato** — pausa até o cliente escrever, com teto de
  tempo. Se ele escreve, segue; se estoura, cai no **fluxo de exceção por limite de
  tempo**.
- **Esperar alguns segundos** — pausa cega, para dar respiro entre mensagens.

### Fluxo
- **Enviar condicional** — cada condicional tem **vários casos**, e cada caso tem
  **condições**. Não é sim/não: é um "escolha entre N".
- **Direcionar para outro chatbot**
- **Direcionar para um ponto de retorno**
- **Retornar para o menu anterior**
- **Ponto de retorno** — marca um lugar do fluxo para o qual qualquer ponto pode
  desviar. Existe para **não precisar duplicar sub-fluxos**.

### Integração
- **Enviar webhook** — manda um JSON com tudo do atendimento e do contato para uma
  URL externa (n8n, Make, o ERP do provedor). Ramifica por:
  - **200 ou 400** → fluxo de sucesso ou de falha;
  - **valor retornado**, não só código: `OK`, `CPF INVÁLIDO`, `NENHUM PAGAMENTO
    ABERTO` — cada retorno pode ter seu próprio caminho;
  - **20 segundos sem resposta** → fluxo alternativo de "não obtive retorno".

No fluxo de exemplo que o Rafael mandou, o webhook aparece com as saídas **"Sucesso
no envio"** e **"Falha no envio"**, e uma delas retorna `IDENTIFICADO` /
`NAO_IDENTIFICADO` — exatamente o desvio por valor.

---

## 5. Como se ramifica

Toda ramificação é uma **saída nomeada** no rodapé do cartão:

| Origem | Saídas |
|---|---|
| Menu / pergunta com opções | uma por opção |
| Menu / pergunta | + **"Tempo limite de espera da resposta atingido"** |
| Condicional | uma por caso |
| Webhook | sucesso, falha, valor retornado, sem retorno |
| Aguardar mensagem | recebeu, tempo excedido |
| Grupo comum | "Próximos passos" |

---

## 6. Testar antes de publicar

Mecanismo bom e barato de copiar:

- **Testar** gera um **código com hashtag** exclusivo. Enviar esse código no canal
  ativa o bot **em modo de teste** — só para quem testa, sem expor o fluxo aos
  clientes do canal.
- **Publicar versão** libera oficialmente; a partir daí ele responde as conversas
  daquele canal.
- Há **histórico de versões** no cabeçalho.

Isso resolve o problema que hoje nós temos: para testar o bot é preciso publicar e
ativar, e aí ele atende cliente de verdade.

---

## 7. Distribuição e transbordo (fora do construtor, mas o bot depende)

A documentação especifica por completo:

- o transbordo passa o atendimento de um atendente para outro **até alguém aceitar**;
- se ninguém aceita até o tempo limite, vai para **toda a equipe**;
- tempo máximo configurável entre **2 minutos e 2 horas**;
- **fora do horário**: com resposta automática ligada, o cliente recebe a mensagem;
  desligada, o atendimento espera e **não** aciona transbordo;
- a **ordem** de distribuição segue a ordem de criação dos usuários e **não é
  configurável**;
- se todos estiverem indisponíveis, pula a distribuição e manda para a equipe toda.

---

## 8. Lado a lado com o OnChat

| Peça | OnChat hoje | Severidade do que falta |
|---|---|---|
| Canvas, blocos, ligações, arrastar | ✅ | — |
| Rascunho / publicado / versão | ✅ | — |
| Validação antes de publicar | ✅ (melhor: nomeia o problema) | — |
| Enviar mensagem / menu / pergunta | ✅ | — |
| Esperar segundos | ✅ | — |
| Transferir / Concluir | ✅ | — |
| **Tempo de tolerância** (agrupar mensagens) | ❌ | 🔴 **quebra no primeiro contato real** |
| **Tempo limite de espera + subfluxo** | ❌ | 🔴 conversa fica parada para sempre |
| **Modo de teste sem publicar** | ❌ | 🔴 hoje testar = atender cliente de verdade |
| Condicional com N casos | ⚠️ só sim/não | 🟡 |
| **Enviar webhook** com saídas por retorno | ❌ | 🟡 é o que integra com o ERP do provedor |
| Etiquetas | ❌ | 🟡 destrava CRM, campanha, sequência |
| Aguardar mensagem do contato | ❌ | 🟡 |
| Ponto de retorno / voltar ao menu anterior | ⚠️ tenho "0 - Voltar" | 🟡 evita duplicar fluxo |
| Enviar modelo de mensagem | ❌ (templates existem) | 🟢 barato |
| Direcionar para outro chatbot | ❌ | 🟢 |
| Duplicar grupo / duplicar bot | ❌ | 🟢 |
| X vermelho no cartão com problema | ❌ | 🟢 acabamento que vale muito |
| Gatilho por palavra-chave | ❌ | 🟢 serve campanha de anúncio |
| Card CRM / Sequência / Agente IA | ❌ | depende de módulos que não existem |

---

## 9. Ordem sugerida

**Primeiro os três vermelhos**, porque são os que fazem o bot parecer quebrado:

1. **Tolerância** — agrupar mensagens seguidas do cliente antes de avançar o fluxo.
2. **Tempo limite de espera** com saída própria no menu e na pergunta.
3. **Modo de teste com código hashtag** — poder testar sem expor ao cliente.

**Depois, o que dá alcance:**

4. **Condicional com N casos** (substitui o sim/não).
5. **Enviar webhook** com saídas por código e por valor retornado, teto de 20s.
6. **Etiquetas** — e com elas, condicional sobre etiqueta.
7. **Aguardar mensagem do contato**.

**Acabamento e conveniência:**

8. X vermelho no cartão, duplicar grupo, duplicar bot, enviar modelo de mensagem,
   ponto de retorno, direcionar para outro chatbot, gatilho por palavra-chave.

**Fica para quando os módulos existirem:** card de CRM, sequência, Agente e
Supervisor de IA.

---

## Fontes

- Prints do construtor do ISP Chat (lista, criação, canvas vazio, gaveta de gatilho,
  fluxo montado, paleta de ações) — enviados em 03/08/2026
- HelenaCRM — Criando um chatbot: `docs.helena.app/configurando-sua-plataforma/apps/chatbot/criando-um-chatbot`
- HelenaCRM — Tipos de chatbot: `.../apps/chatbot/tipos-de-chatbot`
- HelenaCRM — Gatilhos no Chatbot (22/05/2026): `.../novidades-de-produto/maio-de-2026/22-05-2026-gatilhos-no-chatbot`
- HelenaCRM — Distribuição e transbordo: `.../ajustes/equipes/distribuicao-e-transbordo-de-atendimento`
- HelenaCRM — Atualizações no Chatbot Avançado (04/12/2025)
