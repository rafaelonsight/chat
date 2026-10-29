# Virtus Chat — Versão Final (Blueprint)

**Data:** 27/10/2026 · **Autor:** Rafael Paulino · **Status:** proposta para validação

Este documento junta as três versões que hoje rodam no servidor (produção, v2 e Agenda) em um
único produto: o Virtus Chat definitivo. Ele diz o que entra, como fica organizado, como os dados
migram e em que ordem o trabalho acontece.

---

## 1. Decisões já tomadas

| Tema | Decisão |
|---|---|
| Produto | **Um produto só, com módulos** ligados por plano. Um código, um deploy, um banco. |
| Cliente-alvo | **PMEs com equipe de atendimento** pelo WhatsApp. Agenda é módulo extra, não o centro. |
| WhatsApp | **Oficial (Meta Cloud) e Evolution em pé de igualdade.** O cliente escolhe ao criar o canal. |
| Dados que migram | **Onsight** (produção) e **LA Group** (v2). A demo da Agenda fica só em backup. |
| Ponto de partida | **Código da Agenda** (o mais completo) entra como branch no Git da produção, com o que a produção ganhou depois aplicado por cima. |

---

## 2. Ponto de partida: o que existe hoje

As três versões são a mesma base em estágios diferentes. **Produção ⊂ v2 ⊂ Agenda**: nenhum
arquivo existe só na produção; a v2 acrescenta IA e qualidade; a Agenda acrescenta agendamento.

| Versão | Onde | Testes | Situação |
|---|---|---|---|
| Produção | `/opt/onchat` · app.virtus.chat | 1.462 ✔ · 0 ✘ | Estável, no Git, dependências atualizadas em 27/10 |
| v2 | `/opt/virtus-v2` · new.virtus.chat | 1.762 ✔ · 20 ✘ | Sem Git, dependências vulneráveis |
| Agenda | `/opt/virtus-agenda` · agenda.virtus.chat | 99 ✔ · 1 ✘ (só a suíte do módulo) | Sem Git; ~1.700 testes herdados nunca rodam |

Stack comum: Laravel 13, Filament 5, Livewire 4, Horizon, Reverb, Tailwind 4, PostgreSQL 16,
Redis, Evolution API, LiveKit, Whisper.cpp.

---

## 3. Inventário de recursos da versão final

Legenda de origem: **P** = já na produção · **V2** = veio da v2 · **AG** = veio da Agenda.
Legenda de estado: ✅ funciona e tem teste · ⚠️ tem bug conhecido · 🔧 precisa de ajuste para virar módulo.

### 3.1 Núcleo (todo plano)

| Recurso | Origem | Estado |
|---|---|---|
| Multi-tenant por `tenant_id` com escopo global | P | ✅ |
| Usuários, equipes, permissões, perfil inicial | P/V2 | ✅ |
| Inbox: atribuição, transferência, notas, menções, etiquetas, campos personalizados | P | ✅ |
| Grupos de WhatsApp (integrantes, cores de autoria) | P | ✅ |
| Chat interno entre atendentes | P | ✅ |
| Horário de atendimento e resposta automática | P | ✅ |
| Contatos com dados de CNPJ/CEP | P | ✅ |
| Notificações no navegador (push) | P | ✅ |
| Acesso por código, idiomas (pt-BR / en-GB) | V2 | ⚠️ tradução quebrou nomes de propriedade (ver §9) |
| Diagnóstico e `/saude` | P | ✅ |

### 3.2 Canais

| Canal | Origem | Estado |
|---|---|---|
| WhatsApp Evolution (QR code) | P | ✅ |
| WhatsApp oficial Meta Cloud + cadastro incorporado | P | ✅ |
| Templates da Meta: sincronizar | P | ✅ |
| Templates da Meta: criar e editar | V2 | ✅ |
| Instagram e Messenger | P | ✅ |
| Chat do site (widget) | P | ✅ |
| Tarifas e estimativa de custo Meta | V2 | ✅ |

### 3.3 Módulos (ligados por plano)

| Módulo | Conteúdo | Origem | Estado |
|---|---|---|---|
| **Automação** | Chatbot em grafo, biblioteca de modelos, condições múltiplas, simulador, integrações reutilizáveis, sequências, pesquisa de satisfação | P/V2 | ✅ |
| **Campanhas** | Disparos, relatórios de campanha, follow-up | P/V2/AG | ✅ |
| **Vendas** | Funis/painéis com várias colunas, propostas com página pública, produtos/ofertas | P | ✅ |
| **IA** | Agentes com ferramentas, base de conhecimento, resumo no encerramento, assistente de escrita, tradução, créditos/saldo | V2 | ⚠️ 17 testes falhando + tela de consumo quebrada |
| **Qualidade** | Alertas de qualidade e de demora, protocolo, ações de atendimento, relatórios de atendentes e etiquetas, desempenho | V2 | ✅ (1 teste de menu falhando) |
| **Agenda** | Profissionais, serviços, calendário, fluxo guiado no WhatsApp com editor, confirmações, lembretes, link de reserva | AG + P | 🔧 hoje ligado por variável de ambiente, precisa virar por conta |
| **Financeiro simples** | Bancos, formas de pagamento, produtos com foto, relatórios | AG | 🔧 idem |
| **Vídeo** | Salas LiveKit, reuniões ligadas a tickets | P | ✅ (sem uso nos últimos dias) |
| **Transcrição** | Áudio para texto via Whisper | P | ✅ |
| **Central de demandas** | Tickets, tarefas, portal do cliente com código por e-mail | P | ✅ |

### 3.4 Plataforma (operação da Onsight)

| Recurso | Origem | Estado |
|---|---|---|
| Revenda: licenças, trial monitorado, bloqueio por licença | P | ✅ (bug do cadastro já corrigido na produção) |
| Modo suporte por conta | P | ✅ |
| Consumo mensal de conversas | P | ✅ |

### 3.5 Fica de fora

- `/opt/onchat/escape-opcional` (176 MB): experimento antigo do chatbot.
- `/opt/virtus-agenda/runtime` (914 MB): ~80 arquivos "before/candidate", scripts `publish-*.py` e cópias inteiras do app.
- Telefonia Asterisk: o tronco SIP da Athostec está com registro rejeitado e não há código no app que dependa dela. **Decisão pendente** (§12).

---

## 4. Arquitetura

### 4.1 Uma aplicação

```
/opt/virtus/app          ← código (Git: rafaelonsight/chat, branch main)
usuário Linux: virtus    ← substitui onchat, virtus-agenda
banco: virtus            ← PostgreSQL 16
Redis: 1 instância, prefixo virtus_
```

Processos (systemd):

| Serviço | Papel |
|---|---|
| `virtus-php` (pool FPM) | Web |
| `virtus-horizon` | Filas, com supervisores separados: `webhooks` (alta prioridade), `envio`, `ia`, `campanhas`, `default` |
| `virtus-reverb` | Tempo real |
| `virtus-scheduler.timer` | Agendador do Laravel; substitui os seis timers soltos de hoje (qualidade, resumos, tarifas, comunicações da Agenda, monitor) |
| `evolution` (Docker) | **Uma** Evolution API para todas as contas. Hoje são três. |
| `livekit`, `whisper` | Mantidos como estão |

Fila separada para webhooks garante que uma campanha grande não atrase a chegada de mensagem
do cliente.

### 4.2 Módulos por plano

Hoje a Agenda liga por variável de ambiente (`AGENDA_WHATSAPP_ENABLED` etc.), que vale para a
instalação inteira. Na versão final o módulo é **da conta**:

- A tabela `licenses` já tem a coluna `plano`. Acrescentar `modulos` (jsonb) para exceções por conta.
- Um catálogo em código, `config/planos.php`, define o que cada plano liga:

| Plano | Núcleo + Canais | Automação | Vendas | Campanhas | Qualidade | IA | Agenda | Financeiro | Vídeo | Demandas |
|---|---|---|---|---|---|---|---|---|---|---|
| Essencial | ✔ | ✔ | | | | | | | | |
| Equipe | ✔ | ✔ | ✔ | ✔ | ✔ | | | | ✔ | |
| Completo | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |

  (Nomes e combinação de planos são proposta; preço fica fora deste documento.)

- `Modulos::ativo($tenant, 'agenda')` é a única pergunta que o código faz. Ela é usada em três
  lugares: navegação do Filament (o item some), middleware de rota (403 se acessar direto) e
  jobs/agendador (não roda automação de módulo desligado).
- IA continua com franquia e créditos (`franquia_ia`, `extras_ia`), independentes do plano.

### 4.3 Canais: regras que já funcionam e continuam

- Interface `Enviador` (`texto`, `midia`, `marcarLida`, `verificarNumero`), escolhida por `Enviadores::para($canal)`.
  **Job não conhece provedor**: nenhum `if ($canal->tipo === ...)` fora do driver.
- `ConfiguracaoInvalida` estoura; `FalhaDoProvedor` fica em silêncio na bolha da conversa.
- Telefones sempre por `PhoneNumber::variantes()` / `Contact::acharPorTelefone()` (nono dígito).

**Novo — WhatsApp em pé de igualdade:**

| Item | Evolution | Meta Cloud |
|---|---|---|
| Conexão | QR code na tela do canal | Cadastro incorporado |
| Custo | Sem custo por mensagem | Por conversa, com estimativa na tela (já existe) |
| Risco | Aviso fixo na criação: "número pode ser desconectado ou banido" | — |
| Queda | Alerta por e-mail e push **para o administrador da conta** e banner na inbox com botão "Reconectar" | Alerta de token expirado |

Hoje a queda só aparece no log e no diagnóstico da Onsight; o cliente não fica sabendo.

### 4.4 Webhooks: guardar menos

`webhook_events` ocupa 95% do banco de produção (2 GB para 3.377 mensagens), porque guarda o
payload inteiro com mídia.

- Gravar o payload **sem** os campos de mídia em base64 (a mídia já vai para o `MediaService`).
- Retenção: 7 dias para eventos processados com sucesso, 30 dias para os com erro.
  Comando agendado `virtus:limpar-webhooks`.

---

## 5. Dados e migração

### 5.1 Base

O schema final é o da Agenda (148 migrations), que contém as 99 da produção e as 131 da v2.
As migrations da produção posteriores a 02/10 (push) são conferidas uma a uma.

### 5.2 Migração das contas

| Conta | Origem | Como |
|---|---|---|
| Onsight | banco `onchat` (tenant 1452) | Restaurar o dump da produção como base do banco `virtus` e rodar as migrations novas por cima. Mantém ids, mídia e licença. |
| LA Group | banco `onchat_v2` (tenant 2) | Script de cópia `virtus:importar-conta` que lê do banco antigo e insere com **ids novos**, remapeando todas as chaves estrangeiras (contatos, conversas, mensagens, campanhas, canais, etiquetas, usuários). |

Antes da migração: limpar `webhook_events` (§4.4), senão o banco novo herda os 2 GB.

O script de importação tem teste próprio: importa uma conta de exemplo e confere contagens e
que nenhum registro ficou apontando para id do banco antigo.

### 5.3 Canais na virada

- Evolution: as instâncias são recriadas na Evolution única; cada número lê o QR uma vez.
- Meta Cloud, Instagram, Messenger: as credenciais migram com a conta; só muda a URL de webhook
  no painel da Meta.

### 5.4 Acesso aos canais na virada (decidido em 29/10)

Na versão final, quem não é Super Admin só enxerga os canais ligados a ele (`channel_user`). A
produção atual não tem essa regra: sem um passo na virada, os usuários da Onsight entram e não
veem canal nenhum.

- Na virada, **todo usuário existente** é ligado a **todos os canais da própria conta**. Isso
  mantém o que cada um vê hoje.
- A LA Group já chega ligada pela importação, porque a v2 tinha os vínculos.
- Daí em diante vale a regra nova: canal criado depois liga só o administrador que o criou
  (commit `3b809ec`). Os demais são liberados na tela de usuários.

```sql
INSERT INTO channel_user (user_id, channel_id, created_at, updated_at)
SELECT u.id, c.id, now(), now() FROM users u JOIN channels c ON c.tenant_id = u.tenant_id
ON CONFLICT DO NOTHING;
```

### 5.5 Outros itens da virada levantados na homologação

| Item | O que fazer |
|---|---|
| Perfil inicial | Os usuários da produção vão preencher apelido, telefone e foto no primeiro acesso. Avisar a equipe antes. |
| Licença da LA Group | O teste grátis venceu em 22/10. Definir o status antes de importar. |
| Timer `virtus-v2-resumos` | O resumo agora roda no agendador do app (`a77a458`). Desligar o timer da v2 para não mandar resumo em dobro. |
| Webhooks com base64 | Reconfigurar as instâncias existentes com `base64=false`. Depois, rodar `virtus:limpar-webhooks` e `VACUUM FULL webhook_events`. |
| `MAIL_SEMPRE_PARA` e `ACESSO_POR_SENHA` | São só da homologação. Conferir que **não** estão no `.env` da produção. |

---

## 6. Domínios

| Domínio | Antes | Depois |
|---|---|---|
| app.virtus.chat | produção | **Versão final** |
| admin.virtus.chat | revenda | Revenda da versão final |
| virtus.chat / www | landing | Landing (atualizar com os módulos) |
| new.virtus.chat | v2 | **Homologação** da versão final (dados de teste) |
| agenda.virtus.chat | Agenda | Redireciona para app.virtus.chat |
| chat.onsight.com.br | webhooks antigos | Mantém o redirecionamento até a Meta e a Evolution apontarem para o novo |

---

## 7. Operação

### 7.1 Backup

Hoje são 53 GB de backup **só no próprio servidor**.

- Diário: `pg_dump` do banco `virtus` + `.env` + mídia **incremental** (restic), em vez de um
  tar de 1,2 GB por dia.
- **Cópia externa** obrigatória (Backblaze B2 ou S3), criptografada pelo restic.
- Retenção: 7 diários, 4 semanais, 6 mensais.
- Teste de restauração automático uma vez por semana num banco temporário; falha gera alerta.

### 7.2 Monitoramento

- `/saude` (já existe) consultado por um serviço externo de uptime a cada minuto.
- Alertas existentes (canal desconectado, webhook parado, jobs falhando) passam a ir para
  e-mail + push do operador, não só para o log.
- Sentry ou Flare para exceções; hoje o erro do Filament se repete ~500 vezes por dia sem ninguém ver.

### 7.3 Deploy

- GitHub como fonte: `main` = produção, `homolog` = new.virtus.chat.
- Script `deploy.sh`: `git pull` → `composer install --no-dev` → `migrate --force` → `npm run build`
  → `optimize` → reinicia Horizon e Reverb. Mantém o release anterior para voltar com um comando.
- **Proibido editar arquivo direto no servidor.** Foi assim que v2 e Agenda ficaram sem Git e
  que a produção acumulou 18 arquivos sem commit.

---

## 8. Segurança e LGPD

- SSH só por chave (feito em 27/10).
- `composer audit` e `npm audit` no CI; build falha com vulnerabilidade alta ou crítica.
- `.env` com permissão 600, sem ACL extra (hoje o usuário `celsi` lê o da produção).
- `max_input_vars` do PHP para 5000 (formulários grandes do Filament estouram 1000).
- Isolamento entre contas: os testes de "registro de outra conta dá 404" (§9) ficam como
  testes obrigatórios de todo recurso novo.
- LGPD: as páginas de privacidade e exclusão de dados já existem na landing; falta o fluxo
  de exportar e apagar os dados de um contato a pedido.

---

## 9. Qualidade: o que precisa ser corrigido

| # | Problema | Onde |
|---|---|---|
| 1 | A IA não reconhece a configuração (17 testes): `ResumoIA` lê `ativa/chave/modelo` e recebe vazio | v2 → final |
| 2 | Tela de consumo de IA usa `$créditos` (com acento), propriedade que não existe — efeito da tradução automática de 08/10 | v2 → final |
| 3 | Menu "Conta" sem agrupamento (teste de navegação) | v2 → final |
| 4 | QR da Agenda faz uma chamada a mais ao provedor | AG → final |
| 5 | Cadastro de cliente criava licença duplicada | corrigido na produção, levar para a final |
| 6 | Erros do Filament "Cannot update locked property" (~500/dia) | produção |
| 7 | Reverb da Agenda configurado na porta 8096, onde nada escuta | some com a unificação |
| 8 | `phpunit.xml` da Agenda só roda a suíte do módulo | final roda **tudo** |

**Auditoria da tradução:** a v2 trocou textos por chaves `ui_xxxxxxxx` (1.616 chaves). Antes da
virada, um script compara todas as chaves usadas no código com os dois arquivos de idioma e
falha se faltar alguma, e uma busca procura identificadores PHP com acento.

**Critério de pronto da versão final:** suíte inteira verde (estimativa de ~1.900 testes),
`composer audit` limpo, e um roteiro manual de 20 passos (§11, fase 4) executado em homologação.

---

## 10. Faxina no servidor depois da virada

- Apagar `/opt/virtus-v2`, `/opt/virtus-agenda`, os bancos `onchat_v2`, `virtus_agenda` e de teste antigos,
  as duas Evolutions extras e seus volumes, os serviços `virtus-v2-*` e `virtus-agenda-*`.
- Apagar `/opt/onchat/backups` (2,1 GB) e os backups locais antigos, **depois** que o backup externo
  estiver validado.
- Espaço estimado recuperado: ~25 GB de disco e ~1,2 GB de RAM.

---

## 11. Roteiro

| Fase | Entrega | Pronto quando |
|---|---|---|
| **0. Consolidar** | Branch `final` no repositório da produção com o código da Agenda; faxina do `runtime`; push, notificações e correções de 27/10 aplicados por cima | Branch no GitHub, `composer audit` limpo |
| **1. Verde** | Corrigir os itens 1–8 do §9; `phpunit.xml` rodando tudo; auditoria da tradução | Suíte inteira verde |
| **2. Módulos** | `Modulos::ativo`, `config/planos.php`, coluna `modulos`, navegação/middleware/jobs respeitando módulo; flags de ambiente da Agenda removidas | Teste por módulo: desligado some da tela, dá 403 na rota e não roda job |
| **3. Canais e webhooks** | Alerta de queda para o cliente, aviso de risco da Evolution, Evolution única, retenção de webhooks | Derrubar um canal em homologação e o administrador receber alerta |
| **4. Homologação** | new.virtus.chat com a versão final, dados copiados de Onsight e LA Group, roteiro manual de 20 passos (enviar/receber em cada canal, chatbot, campanha, IA, agendamento, revenda) | Roteiro sem falha |
| **5. Virada** | Backup completo, manutenção de ~30 min, migração para o banco `virtus`, vínculo dos usuários aos canais (§5.4), itens do §5.5, troca do app.virtus.chat, reconexão dos números, URLs de webhook atualizadas | Onsight e LA Group atendendo pela versão final, cada usuário vendo os mesmos canais de antes |
| **6. Operação** | Backup externo com teste de restauração, uptime, Sentry, CI no GitHub, `deploy.sh` | Primeiro teste de restauração automático passou |
| **7. Faxina** | §10 | Servidor só com a versão final |

As fases 0 a 2 não tocam na produção. Até a fase 5, app.virtus.chat continua como está.

---

## 12. Decisões em aberto

1. **Nomes, combinação e preço dos planos** (a tabela do §4.2 é só ponto de partida).
2. **Telefonia (Asterisk/Athostec):** reativar e integrar ao atendimento, ou desligar.
3. **Provedor de backup externo:** Backblaze B2 (mais barato) ou AWS S3.
4. **LA Group:** é cliente pagante? Define se a migração dele exige janela combinada.
5. **Vídeo (LiveKit):** sem uso recente; manter no plano Equipe ou só no Completo.
