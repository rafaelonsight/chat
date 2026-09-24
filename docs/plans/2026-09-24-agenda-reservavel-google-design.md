# Agenda reservável no padrão Google Agenda

## Objetivo

Evoluir a Agenda do Virtus Chat para que cada conta possa criar, administrar e compartilhar páginas públicas de reserva, seguindo o fluxo de agendamentos do Google Agenda e integrando os diferenciais do produto: WhatsApp, CRM, equipes e vídeo.

O administrador controla todas as páginas da conta. Usuários comuns podem criar e gerir somente as próprias páginas. Páginas de equipe podem ser administradas pelo administrador e pelos coorganizadores definidos.

## Experiência de gestão

A Agenda terá uma lateral fixa em desktop com mini calendário e a seção **Páginas de reserva**. Cada página exibirá título, estado, responsável/equipe e duração, com ações de copiar link, abrir página pública, editar, pausar, mostrar ou ocultar disponibilidade na grade e excluir.

O construtor terá duas etapas, como no Google Agenda.

### 1. Agendamento

- Título e duração personalizada.
- Responsável principal, equipe e coorganizadores.
- Disponibilidade recorrente por dia da semana, incluindo múltiplos turnos, dia indisponível e copiar horário para todos.
- Fuso horário, antecedência mínima e horizonte máximo de reserva.
- Exceções por data: bloquear uma data, criar horário extra ou substituir os turnos recorrentes.
- Intervalo entre reservas, teto diário e permissão para convidados adicionais.
- Calendários/compromissos usados para detectar conflito, cor e visibilidade da disponibilidade na grade.

### 2. Página pública

- Identidade visual: marca, foto e nome da equipe ou responsável.
- Tipo de atendimento: WhatsApp, vídeo do Virtus, telefone, presencial ou a definir.
- Descrição pública.
- Formulário configurável com campos nativos (nome, telefone, e-mail, assunto) e campos extras.
- Confirmação e lembretes por WhatsApp e/ou e-mail.
- Política de cancelamento, ações de copiar link, compartilhar e código de incorporação no site.

## Fluxo do cliente

1. O cliente abre o link público e vê as informações do serviço.
2. Seleciona uma data e vê somente horários livres.
3. Escolhe um horário e preenche o formulário.
4. O servidor revalida disponibilidade e cria a reserva de forma atômica.
5. A reserva entra na Agenda, no responsável/equipe e no CRM quando houver contato identificável.
6. O cliente recebe confirmação e lembretes pelos canais configurados.

Os estados de uma página são **ativa**, **pausada** e **oculta da grade**. Ocultar disponibilidade não remove reservas reais nem desativa o link público.

## Dados e regras

Manter a proteção atual de unicidade de vaga e ampliar a validação para responsáveis e coorganizadores. Adicionar dados para estado, cor, visibilidade, identidade, campos de formulário, coorganizadores, agendas verificadas, exceções de disponibilidade, tipo de reunião, política de cancelamento e regras de lembrete.

Exceções e campos extras devem ter registros próprios, evitando concentrar todas as regras em JSON. A disponibilidade recorrente atual pode continuar em formato compacto enquanto for eficiente para o cálculo de vagas.

Toda tentativa de reservar verifica novamente: página ativa, janela de reserva, exceção da data, limite diário, conflito, disponibilidade e vaga já ocupada. Em disputa de concorrência, o cliente recebe alternativa de horário; nunca há dupla reserva.

## Integrações e segurança

WhatsApp só é enviado quando houver canal configurado e autorizado. Vídeo cria a sala somente quando o tipo escolhido exigir. Cancelamento e reagendamento usam links assinados e expiráveis. Toda criação, alteração, cancelamento, reserva e notificação é auditada.

O contexto de tenant selecionado no modo de suporte deve ser respeitado tanto na Agenda quanto nas páginas de reserva, sem gravar dados na conta de origem do operador.

## Testes

- Permissões de administrador, usuário e coorganizador.
- Disponibilidade recorrente, múltiplos turnos e exceções.
- Antecedência, horizonte, intervalo e limite diário.
- Conflitos individuais e de equipe.
- Dupla reserva concorrente.
- Estados ativa, pausada e oculta da grade.
- Campos personalizados, confirmação, cancelamento e reagendamento.
- Isolamento entre tenants e modo de suporte.
