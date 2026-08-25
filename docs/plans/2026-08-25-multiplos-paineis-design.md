---
title: Múltiplos painéis no CRM
date: 2026-08-25
status: implemented
---

# Múltiplos painéis no CRM

## Objetivo

Permitir que uma conta mantenha vários painéis independentes — por exemplo, Comercial,
Suporte e Cobrança — sem que criar um painel pareça renomear ou substituir o atual.

## Interface

- Os painéis aparecem como abas persistentes no topo do Kanban.
- `Novo painel` abre um formulário dedicado, separado do gerenciamento.
- Ao criar, o painel anterior permanece e o novo se torna a aba ativa.
- `Gerenciar` concentra somente renomear e excluir.
- Uma confirmação informa que o painel foi criado e que os anteriores continuam disponíveis.

## Dados e segurança

Cada painel continua sendo um `Funnel`, com suas próprias `FunnelStage`. Uma conversa pertence
a uma etapa de apenas um painel por vez. Todas as consultas permanecem sob o escopo do tenant.

## Verificação

O teste de Livewire cria um segundo painel, confirma que o primeiro permanece, valida as cinco
etapas do novo e verifica que ele foi aberto automaticamente.
