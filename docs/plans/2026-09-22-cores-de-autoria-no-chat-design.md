# Cores de autoria no chat

## Objetivo

Permitir identificar visualmente quem da equipe enviou cada mensagem no histórico de uma conversa ou grupo.

## Decisão

Cada usuário possui uma cor de chat selecionada em seu cadastro. A paleta é fechada para preservar contraste e acessibilidade. Mensagens enviadas pelo painel guardam o usuário autor; a bolha e a etiqueta de autoria usam sua cor.

## Cores iniciais

- Atlas · Assistente IA: amarelo;
- Rafael: azul;
- Celso Lopes: cinza.

## Regras

Mensagens recebidas continuam neutras. Mensagens enviadas fora do painel ou que pertencem ao histórico anterior permanecem no estilo verde padrão, pois não têm autor interno confiável. Nenhuma mensagem é alterada nem reenviada para aplicar a cor.

## Validação

O teste cobre autoria persistida, etiqueta visual azul e preservação do fluxo de mensagens de grupo.
