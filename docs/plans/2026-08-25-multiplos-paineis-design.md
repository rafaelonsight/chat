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

Cada painel continua sendo um `Funnel`, com suas próprias `FunnelStage`. O `BoardItem` é o
cartão genérico: não depende de canal ou conversa e pode opcionalmente apontar para um contato
e um responsável. `BoardField` define campos extras por painel (texto, número, dinheiro, data,
lista ou checkbox), e os valores ficam no JSON `dados` do cartão. Conversas continuam podendo
aparecer no quadro, mas deixam de ser a única forma de criar cartões. Todas as consultas
permanecem sob o escopo do tenant.

## Verificação

Os testes Livewire cobrem criação de múltiplos painéis, abertura por URL, cartão sem conversa,
seleção de contato existente, campo personalizado obrigatório e bloqueio de movimentação para
uma coluna de outro painel.
