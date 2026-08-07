# Módulo de Agendamento

**Data:** 2026-08-07
**Origem:** dois clientes reais pedindo a mesma coisa — Barbearia Gomes e uma manicure
**Status:** escopo fechado; duas decisões de arquitetura em aberto (§3), nada implementado

---

## 1. O que é, e por que

Um módulo de agenda dentro do OnChat: cadastro de **profissional**, **serviço** e
**jornada**; agendamento pelo **WhatsApp** conduzido pelo chatbot; **agenda na tela**
para a recepção e para o profissional; **lembretes** antes do horário e **follow-up**
depois, para trazer o cliente de volta.

**Por que é módulo e não projeto:** dois clientes diferentes — barbearia e manicure —
chegaram pedindo a mesma coisa sem combinar. O que os separa é só o tamanho: a
barbearia tem vários profissionais e serviços curtos e padronizados (30–50 min); a
manicure é uma pessoa só, com serviços longos e variáveis (40 min a 2h30). Se o
modelo aguenta os dois, aguenta salão, estética, oficina e consultório. Profissional
único é o caso N=1, não um caso especial.

**Por que vale a pena:** chat sozinho é commodity. Chat *com agenda* vira o sistema
onde o negócio opera — muda o preço que se cobra e trava o cliente. E o follow-up de
recorrência é a peça que dá retorno financeiro medível: barbearia e manicure vivem de
o cliente lembrar de voltar, e hoje quem lembra é ele, sozinho.

---

## 2. Decisões tomadas

| # | Decisão | Escolha | Por quê |
|---|---|---|---|
| 1 | Módulo genérico ou sob medida | **Genérico no modelo, específico na apresentação** | O OnChat já é multi-tenant; nascer genérico custa pouco e evita reescrever no segundo cliente |
| 2 | Como o profissional vê a agenda | **Tela + aviso no WhatsApp**, tela primeiro | A tela é a fonte da verdade; o WhatsApp é canal de saída. O contrário deixa a manicure solo sem onde consultar |
| 3 | Conversa por IA ou por fluxo | **Fluxo, sem IA**, com o encaixe da IA deixado pronto e desligado | Marcar, remarcar e cancelar são três caminhos. Fluxo se testa; IA se reza. Custo zero por conversa |
| 4 | Onde a conversa vive | **Em aberto** — ver §3 | |
| 5 | Lembretes antes | **Vários, configuráveis**, cada um com prazo e texto; confirmar ou só avisar **por lembrete** | Pedir confirmação três vezes irrita; não pedir nenhuma joga fora a chance de revender o horário |
| 6 | Quem escreve as opções ("1 sim, 2 reagendar") | **O sistema monta**, a barbearia escreve só a parte humana e pode renomear rótulos; marcador `{{opcoes}}` posiciona | Texto livre diverge da configuração e o cliente cancela achando que reagendou. Opção estruturada vira botão sozinha quando o canal for Meta |
| 7 | Follow-up depois do atendimento | **Retorno + avaliação + resgate**, os três | Retorno e resgate são a mesma regra com prazo diferente |
| 8 | Produtos | **Fora** | Catálogo e venda não têm relação com horário. Dois clientes esperam agenda, não loja |
| 9 | Opção "tanto faz / primeiro que tiver" | **Configurável, ligada por padrão** | Enche a agenda do profissional novo, que é quem tem buraco; e some sozinha quando só existe um |
| 10 | Ordem da conversa | **Profissional → serviço → dia → horário** | Cliente de barbearia é fiel ao barbeiro, não ao corte. Exige serviço×profissional muitos-para-muitos |

---

## 3. Decisões em aberto

As duas surgiram no fim, quando o repositório da VPS foi lido e se descobriu que o
OnChat avançou muito além do backup de 03/08 que serviu de base às primeiras
conversas.

**3.1 — O agendamento é uma máquina de estados própria ou ações novas no chatbot?**

Quando o desenho começou, o chatbot do OnChat era uma página vazia; a recomendação
foi máquina de estados dedicada. Hoje existe `ChatbotMotor` (905 linhas), editor
visual em grafo (`Chatbot`, `ChatbotStep`, `ChatbotAction`, `ChatbotEdge`), publicar
e validar, e oito tipos de ação — mensagem, menu, pergunta, esperar, condicional,
etiqueta, transferir, concluir. Menu e pergunta já param o fluxo esperando resposta;
já existem escape, tolerância e tempo limite.

**Recomendação: ações novas na paleta**, como o próprio comentário do `ChatbotAction`
prevê ("a paleta cresce, mas cada tipo novo precisa de motor E de tela"). Três ações:

- **Escolher na agenda** — menu cujas opções vêm do banco em vez de escritas na mão,
  parametrizado pelo que se pergunta (profissional, serviço, dia, horário)
- **Confirmar agendamento** — grava com a trava do banco; duas saídas no grafo: deu
  certo, ou o horário acabou de ser tomado
- **Meus agendamentos** — lista os futuros do contato, para cancelar ou remarcar

Duas engrenagens de conversa em paralelo significariam dois escapes, dois tempos
limite e dois lugares para consertar o mesmo bug. E como ação da paleta, a barbearia
consegue editar o próprio fluxo no editor visual.

O que **não muda** com essa decisão: o cálculo de disponibilidade e a trava de
concorrência. Sempre foram código, não fluxo.

**3.2 — Os lembretes estendem Sequências ou têm fila própria?**

Sequências já existem (`Sequence`, `SequenceEnrollment`, `SequenceStep`,
`Cadenciador`) com cadência por gatilho, `parar_ao_responder` ligado por padrão,
janela de envio 9h–20h, `podeReceber()` respeitando descadastro, e a inscrição
guardando próximo passo e hora. `ProcurarSumidos` já é o resgate;
`PesquisaDeSatisfacao` já é a avaliação.

**Recomendação: estender Sequências.** As proteções de envio já estão lá e são
exatamente as que o follow-up precisa; reimplementá-las num segundo disparador é como
se perde o número no WhatsApp.

**A lacuna real:** sequência conta **para frente**, lembrete conta **para trás**.
`sequence_steps.atraso_horas` é "tantas horas depois do passo anterior", sem sinal, e
`sequences.gatilho` está travado por CHECK em `primeira_conversa`,
`atendimento_encerrado` e `sem_resposta`. "24h antes das 15h de sábado" não se
expressa nisso. Falta:

- um gatilho de agendamento
- uma **âncora** no passo: se o prazo conta da inscrição ou do início do atendimento

Detalhe a favor: `parar_ao_responder` já é por sequência, então a de lembretes nasce
com ele **desligado** — responder "1, confirmo" não pode cancelar o lembrete de 30
minutos.

---

## 4. Modelo de dados

Tudo por tenant, como o resto do OnChat.

**`professionals`** — nome, telefone (para receber o aviso), `user_id` opcional, cor
na agenda, ativo. O login é opcional de propósito: o barbeiro que não quer entrar em
sistema nenhum continua existindo na agenda.

**`services`** — nome, duração em minutos, preço, **`retorno_dias`** (de quanto em
quanto tempo o serviço se repete: corte 25, barba 15, alongamento 21), ativo. O
`retorno_dias` é o que faz o follow-up chegar na hora certa em vez de na média.

**`professional_service`** — muitos-para-muitos, com **duração e preço opcionais por
profissional**. O Léo leva 40 min no corte que o João faz em 30, e cobra mais caro.
Vazio significa "vale o do serviço".

**`professional_hours`** e **`professional_hour_exceptions`** — grade semanal e
exceção pontual (folga, feriado, meio período). Mesmo desenho de
`BusinessHour`/`BusinessHourException`, que já existe e funciona: copiar o padrão, não
inventar outro.

**`appointments`** — `contact_id`, `professional_id`, `service_id`, `inicio_em`,
`fim_em`, status (agendado, confirmado, atendido, cancelado, não compareceu), origem
(whatsapp ou painel), valor, observação, e o rastro de quem cancelou e quando.

Duas decisões dentro dessa tabela:

- **O cliente da agenda é o `Contact` do chat.** Não existe cadastro de cliente
  separado. Quem agenda pelo WhatsApp já é contato; quem a recepção marca vira
  contato. Cadastro único significa que histórico de conversa e histórico de
  agendamento são a mesma coisa — que é o que faz o módulo valer mais que uma agenda
  avulsa.
- **`fim_em` fica gravado, não calculado na leitura.** Se a duração do corte mudar de
  30 para 40 min amanhã, os agendamentos de ontem não podem mudar de tamanho
  retroativamente.

**Configuração da conta:** janela de agendamento (padrão 3 dias), antecedência mínima
para marcar (padrão 30 min), intervalo entre atendimentos (padrão 0), prazo para o
cliente cancelar sozinho (padrão 2h), "tanto faz" ligado.

---

## 5. Disponibilidade e concorrência

**O cálculo:** pega a grade semanal do profissional no dia, tira as exceções, tira os
agendamentos existentes (mais o intervalo, se houver), corre a janela de 15 em 15
minutos guardando as posições onde a **duração inteira do serviço cabe** antes de a
jornada acabar, e corta o que estiver dentro da antecedência mínima. Simples de
descrever, cheio de canto — precisa de teste de mesa, não de conferência no olho.

O "tanto faz" é a mesma função rodada para todos os profissionais que fazem aquele
serviço, unida e ordenada por horário.

**A concorrência é o ponto onde não se improvisa.** Dois clientes confirmando 16h com
o Léo no mesmo segundo é cenário garantido num sábado. Verificar antes de gravar não
resolve: entre a verificação e a gravação cabe a outra conversa.

A solução fica no banco. Postgres, **restrição de exclusão** (`btree_gist`) proibindo
dois agendamentos não cancelados do mesmo profissional com horários que se sobrepõem.
Não é validação de aplicação — é o banco recusando a segunda gravação, sempre, com
quantos processos existirem. A aplicação captura a recusa e responde "esse horário
acabou de ser preenchido", remontando a lista na hora.

O que isso garante: **nenhum caminho** cria agenda duplicada. Nem o bot, nem a
recepção pela tela, nem um script, nem um descuido futuro.

---

## 6. A conversa

**Quando o bot fala.** Conversa nova ou sem ninguém atendendo. No instante em que um
humano assume, o bot cala e não volta a falar naquela conversa. "atendente" sai do
fluxo em qualquer ponto. Resposta não entendida: repete a pergunta **uma vez**; na
segunda transfere para humano, com o que já foi coletado escrito como nota interna,
para o atendente não começar do zero.

**O trilho:**

1. **Saudação e identificação** — contato conhecido é chamado pelo nome; desconhecido
   é perguntado
2. **Menu** — para quem tem histórico, a primeira opção é *"o de sempre: Corte com o
   Léo"*; depois agendar, meus agendamentos, falar com atendente
3. **Profissional** — "tanto faz" primeiro; passo pulado quando só existe um
4. **Serviço** — apenas os que aquele profissional faz
5. **Dia** — dentro da janela, com "ver mais dias"
6. **Horário**
7. **Resumo e confirmação** — serviço, profissional, dia, hora, valor e duração, numa
   mensagem só

**Nada é reservado durante a conversa.** O horário só é gravado na confirmação.
Reservar no meio trava horário em toda conversa abandonada — e conversa de WhatsApp é
abandonada o tempo todo. O preço é que, raramente, o horário some entre a escolha e o
"sim"; e é exatamente o caso que a restrição do banco resolve com elegância. Conversa
parada expira em 15 minutos e o estado é descartado, sem nada a liberar.

**Cancelar e remarcar** entram por "meus agendamentos": lista os futuros do contato,
cada um cancelável ou remarcável — remarcar é cancelar e voltar ao passo 5, mantendo
serviço e profissional. O cliente cancela sozinho até o prazo configurado; dentro do
prazo, o bot transfere para humano, porque cancelar em cima da hora é conversa que a
barbearia quer ter.

**Marcadores.** [`Marcadores.php`](app/Support/Marcadores.php) já centraliza as
variáveis de texto (`{{nome}}`, `{{telefone}}`, `{{atendente}}`,
`{{proxima_abertura}}`) e é usado pelo modelo de mensagem e pela resposta automática.
O módulo acrescenta `{{servico}}`, `{{profissional}}`, `{{data}}`, `{{hora}}`,
`{{valor}}` **na mesma classe**. Ressalva: hoje `aplicar()` recebe conversa, usuário e
um parâmetro solto de `proximaAbertura`; acrescentar mais quatro posicionais apodrece
a assinatura. Trocar por um contexto único na hora de mexer — meia hora de trabalho.

---

## 7. A tela

Página de agenda no grupo Aplicações: o dia em colunas, uma por profissional, com os
agendamentos posicionados na hora. Visões de dia e semana. A recepção marca, edita,
cancela e bloqueia horário; o dono vê a casa inteira; o profissional logado vê só a
coluna dele e marca folga.

Estados visíveis: agendado, confirmado (o cliente respondeu ao lembrete), atendido,
não compareceu.

**Baixa automática.** O follow-up de retorno conta a partir do **atendido**. Se
ninguém der baixa, nada fica atendido e o recurso que mais traz dinheiro nunca
dispara — e barbeiro em sábado lotado não vai clicar em "atendido" trinta vezes.
Então: passadas algumas horas do fim do horário, o agendamento vira **atendido
sozinho**, a menos que alguém tenha marcado que o cliente não veio. Quem falta é a
exceção, e é a exceção que a barbearia tem motivo para registrar — ela quer saber
quem furou. O dado nasce certo sem depender de disciplina que ninguém tem.

**Aviso ao profissional** sai por WhatsApp no momento do agendamento e, se ele tiver
login, aparece também na tela. Nota prática: isso consome o número da barbearia
falando com o número do profissional, que vira um contato no chat como outro qualquer.

---

## 8. Follow-up

Quatro tipos, uma engrenagem:

| Tipo | Quando | Padrão que já vem pronto |
|---|---|---|
| Antes | X minutos antes do início | 24h com confirmação · 6h aviso · 30min aviso |
| Pós-atendimento | X horas depois do fim | 3h depois, pede avaliação |
| Retorno | `retorno_dias` do serviço, sem horário futuro marcado | herda do serviço (corte 25, unha 15) |
| Resgate | N dias fixos, sem horário futuro marcado | 90 dias |

Retorno e resgate são **a mesma regra com prazo diferente**; o que muda é de onde vem
o número — o retorno herda do serviço, o resgate é fixo.

**A condição do meio não pode faltar: só dispara para quem não tem agendamento
futuro.** Mandar "faz tempo que você não aparece" para quem já está marcado para
quinta é o tipo de erro que faz a barbearia desligar o módulo.

**Cancelamento em cascata.** Agendamento cancelado ou remarcado ⇒ os envios pendentes
dele são cancelados e refeitos. É o bug clássico do gênero: o cliente cancela e mesmo
assim recebe "seu horário é amanhã às 10h". Queima a confiança na hora.

**Borda:** cliente marca hoje para daqui a duas horas. Os lembretes de 24h e 6h
nasceriam no passado — são descartados, nunca enviados. Só o de 30 min sai.

**Retorno e resgate não pendem de um agendamento**, então funcionam por varredura
diária: contatos com atendimento concluído, sem horário futuro, cujo último
atendimento passou do prazo. E aqui um detalhe que, se escapar, transforma o recurso
em praga: **é preciso registrar que já mandou**. Sem esse registro, a varredura de
amanhã encontra o mesmo cliente e manda de novo, todo dia, até ele bloquear o número.
Cada disparo fica gravado e o mesmo contato não recebe o mesmo tipo de mensagem outra
vez dentro de uma carência.

---

## 9. Proteções de envio

Já implementadas em Sequências, e é por isso que a recomendação de §3.2 é estender em
vez de duplicar:

- **Descadastro respeitado para sempre** (`podeReceber()`)
- **Janela de horário** de envio (9h–20h por padrão)
- **Para ao responder**, por sequência — desligado na de lembretes

A acrescentar:

- **Teto de disparos por dia**
- **Envio espalhado ao longo do dia**, em vez de 200 mensagens às 9h em ponto

O risco que essas proteções endereçam é concreto: mensagem proativa para quem não
pediu é como um número do WhatsApp é bloqueado. O que protege aqui é que só vai para
cliente já atendido — existe relação. Ainda assim, o número **é** o negócio do
cliente; perder o número é perder o negócio.

**Custo na Meta.** Um lembrete 24h antes cai, por definição, fora da janela de 24
horas: no WhatsApp oficial exige template aprovado e é **cobrado por envio**. 300
agendamentos/mês × 3 lembretes = 900 envios pagos. No Evolution, hoje, é de graça.
Não muda a decisão, mas muda quantos lembretes fazem sentido ligados por padrão — e é
argumento para o de 30 min ser o mais importante dos três: evita o furo e é o único
que costuma cair dentro da janela.

---

## 10. Ordem de construção

A ordem decide quando a Barbearia Gomes tem algo nas mãos:

1. **Cadastro e tela de agenda**, com marcação manual pela recepção — já é utilizável;
   a barbearia troca o caderno por isso mesmo sem bot nenhum
2. **Cálculo de disponibilidade** e a restrição do banco, com os testes
3. **A conversa de agendar** pelo WhatsApp — aqui o produto vendido existe
4. **Aviso ao profissional**
5. **Lembretes antes**, com confirmação
6. **Cancelar e remarcar** pelo bot
7. **Retorno, resgate e avaliação**

Do 1 ao 3 é a entrega. Do 4 ao 7 é o que faz o cliente não largar mais.

---

## 11. Fora de escopo

Registrado de propósito, para não voltar como surpresa:

- **Produtos e comanda** — catálogo e venda; nada a ver com horário
- **Pagamento e sinal antecipado**
- **IA na conversa** — o encaixe fica pronto e desligado; horário disponível **nunca**
  sai do modelo, sai sempre do banco
- **Link público de agendamento** fora do WhatsApp
- **Sincronia com Google Agenda**
- **Comissão por profissional** — vai ser pedida (barbeiro trabalha por porcentagem, e
  com os atendimentos no sistema com valor, calcular quanto cada um fez no mês é a
  próxima coisa que a barbearia quer). Não agora.

---

## 12. Testes

- **Disponibilidade** — teste de mesa: jornada, exceção, agendamento no meio, duração
  que não cabe, antecedência mínima, virada de dia, profissional que não faz o serviço
- **Concorrência** — tentar gravar dois agendamentos sobrepostos e exigir que o banco
  recuse o segundo
- **Conversa** — um teste por transição, mais escape, tolerância e expiração
- **Cancelamento de lembrete ao remarcar** — o bug mais esperado do módulo
- **Varredura de retorno** — não pode reenviar para quem já recebeu, nem enviar para
  quem tem horário futuro

---

## 13. Riscos

| Risco | Mitigação |
|---|---|
| Agenda duplicada num sábado cheio | Restrição de exclusão no Postgres, não validação de aplicação |
| Lembrete chegando para quem cancelou | Envios materializados e cancelados em cascata |
| Varredura de retorno virando spam diário | Registro de disparo + carência por tipo |
| Número da barbearia bloqueado | Descadastro, teto diário, envio espalhado, só para quem já foi atendido |
| Ninguém dar baixa nos atendimentos | Baixa automática algumas horas depois; a exceção é o não comparecimento |
| Duas engrenagens de conversa divergindo | Decisão §3.1 — ações na paleta, não motor paralelo |
| Custo por mensagem quando a Meta entrar | Menos lembretes ligados por padrão; priorizar o de 30 min |
