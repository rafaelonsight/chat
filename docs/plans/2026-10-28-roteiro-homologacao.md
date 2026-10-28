# Virtus Chat versão final: roteiro de homologação

O critério de pronto da Fase 4 é executar os 20 passos abaixo sem falha. Anote em cada passo **OK** ou o que deu errado.

## O ambiente

| | |
|---|---|
| Painel | https://homolog.169-58-34-201.sslip.io/admin |
| Revenda | https://revenda-homolog.169-58-34-201.sslip.io |
| Código | branch `homolog` (igual à `final`), em `/opt/virtus-homolog/app` |
| Banco | `virtus_homolog`: cópia da produção de 28/10 mais a LA Group importada da v2 |
| Evolution | a da v2 (porta 8084), só para números de teste |

**O que é diferente da produção, de propósito:**
- **E-mail:** todo e-mail da homologação vai para a caixa do operador (ra…@onsight.com.br), por causa do `MAIL_SEMPRE_PARA`. Inclui o código de acesso de qualquer usuário, resumos e alertas. Nenhum cliente real recebe nada.
- **Canais copiados:** os canais Meta e Evolution copiados estão sem token e fechados, para não falarem com clientes de verdade. Os testes usam canais novos, com número de teste.
- **Meta:** WhatsApp oficial, Instagram e Messenger **não recebem** mensagens na homologação, porque o webhook do app da Meta aponta para a produção. O recebimento pela Meta é testado na virada (Fase 5).
- **Endereço provisório:** o `sslip.io` é um endereço que resolve sozinho para o IP do servidor. Se preferir `homolog.virtus.chat`, basta criar um registro A apontando para 169.58.34.201.

**Para ter à mão:** um celular com um número de WhatsApp de teste (não use o +55 41 98460-6033 da produção).

## Roteiro

### Acesso
1. **Entrar como Onsight:** em /admin, entre com o e-mail de um usuário da Onsight. O código chega na caixa do operador. No primeiro acesso, o sistema pede o perfil inicial (apelido, telefone, foto). Preencha e confira que o painel abre.
2. **Histórico migrado:** na inbox estão as conversas antigas da Onsight. Abra uma conversa com imagem ou áudio e confira que a mídia carrega.
3. **Canal caído:** no topo da inbox aparece a faixa vermelha do +55 41 98460-6033, que está fechado de propósito. O botão **Reconectar** leva à tela do QR.

### WhatsApp por QR (Evolution)
4. **Criar canal:** crie um canal "WhatsApp por QR code" com o número de teste. Antes de conectar, aparece o aviso de risco de desconexão e banimento. Leia o QR; o canal fica conectado.
5. **Receber:** mande uma mensagem do celular de teste. Ela aparece na inbox sem recarregar a página, o que confirma o Reverb.
6. **Responder:** responda com texto, imagem e áudio gravado no painel. Os três chegam no celular.
7. **Transcrição:** mande um áudio do celular. A transcrição aparece na conversa.
8. **Atendimento:** transfira a conversa para outra equipe ou atendente, aplique uma etiqueta e confira o histórico da conversa.
9. **Encerrar:** encerre o atendimento com resumo. O resumo da IA é gerado, e o e-mail de resumo chega na caixa do operador em até 1 minuto. Isso confirma que o `virtus:enviar-resumos` está agendado.

### Automação, campanhas e IA
10. **Chatbot:** monte um fluxo simples (boas-vindas, uma pergunta, transferir) no canal de teste e publique. Mande "oi" do celular: o bot responde e transfere para um humano.
11. **Campanha:** crie uma campanha para uma lista com o número de teste e dispare. A mensagem chega no celular. Teste pausar e retomar.
12. **IA no atendimento:** use sugerir resposta, traduzir mensagem e o assistente de escrita. As três respondem.

### Queda do WhatsApp (Fase 3)
13. **Derrubar:** no celular de teste, vá em Aparelhos conectados e desconecte o Virtus. Em até 1 minuto:
    - chega o e-mail "WhatsApp desconectado" na caixa do operador;
    - aparece a notificação no sino do painel;
    - aparece o push no navegador, se ele estiver permitido;
    - a faixa vermelha aparece na inbox.
14. **Reconectar:** pelo link do e-mail, leia o QR de novo. A faixa some. Uma nova queda volta a avisar.

### Módulos e revenda
15. **Entrar na revenda:** entre em revenda-homolog com o usuário operador. As contas Onsight e LA Group aparecem.
16. **Ligar a Agenda:** na licença da Onsight, ligue o módulo Agenda. Volte ao painel: aparecem os grupos Agenda e Financeiro. Crie um profissional, um serviço e uma página de agendamento. Faça um agendamento pela página pública e confira que o lembrete chega por WhatsApp no número de teste.
17. **Desligar a IA:** na licença da Onsight, desligue o módulo IA. Os botões de IA somem da conversa, e o resumo automático deixa de rodar. Religue no final.

### LA Group (importada da v2)
18. **Renovar a licença:** a licença da LA Group é um teste grátis que **venceu em 22/10**, e está assim também na v2. Na revenda, renove ou estenda a licença. Sem isso, o painel da conta mostra "licença bloqueada".
19. **Entrar como LA Group:** entre com tech@lagroup.uk (o código chega na caixa do operador). Confira que estão lá:
    - as 5 conversas, com a imagem da conversa que tinha foto;
    - o chatbot "Bem-vindo";
    - a campanha concluída;
    - os 3 canais Meta, fechados.
20. **Isolamento:** logado como LA Group, cole na barra o endereço de uma conversa da Onsight (`/admin/conversations/<id>`). A resposta tem de ser 404. Faça o mesmo ao contrário.

## Depois do roteiro
- **Falhas:** cada falha vira correção na branch `final`, depois `git merge` na `homolog`, depois um novo teste do passo.
- **Roteiro sem falha:** a Fase 4 está pronta e vem a virada (Fase 5).
