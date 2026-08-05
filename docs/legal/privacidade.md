# Política de Privacidade — OnChat

> **RASCUNHO PARA REVISÃO.** Escrito por quem conhece o código: descreve o que o
> sistema realmente faz, e não o que seria confortável dizer. Não é parecer jurídico.
> Onde há `[[decidir]]`, é escolha de negócio que só o Rafael toma.

**Última atualização:** [[decidir: data da publicação]]

## 1. Quem somos

O OnChat é operado por **R. PAULINO DA SILVA LTDA**, CNPJ 65.502.134/0001-56, com sede
na Av. Cristovão Colombo, 2144, Sala 408, Andar 3, Floresta, Porto Alegre/RS,
CEP 90560-001.

Contato para assuntos de privacidade: **[[decidir: e-mail do encarregado]]**

## 2. O nosso papel muda conforme o dado

Esta é a parte que mais importa e a que quase nenhuma política explica com clareza.

**Somos OPERADOR** dos dados de atendimento. A empresa que contrata o OnChat (aqui
chamada de "cliente") é a **CONTROLADORA**: é ela que decide quem atender, o que
perguntar, quanto tempo guardar e para que usar. Nós tratamos esses dados sob instrução
dela. Se você é consumidor final e conversou pelo WhatsApp com uma empresa que usa o
OnChat, **é a essa empresa que você deve dirigir seus pedidos** — nós a apoiamos a
responder, mas não decidimos por ela.

**Somos CONTROLADORES** apenas dos dados da relação comercial com o cliente: cadastro da
conta, usuários do painel, faturamento e registros de acesso.

## 3. Que dados são tratados

**Do consumidor final que conversa com o cliente:**

- Número de telefone e identificador do WhatsApp
- Nome de exibição informado pelo próprio WhatsApp
- Conteúdo das mensagens: texto, imagens, áudios, vídeos e documentos enviados
- **Transcrição automática de áudios** — os áudios recebidos são convertidos em texto
  para que o atendente possa ler. A transcrição é feita em servidor próprio, sem enviar
  o áudio a terceiros.
- Data e hora de cada mensagem, e o estado de entrega
- Dados de cadastro que o cliente preenche ou importa: nome completo, e-mail,
  Instagram, endereço, e campos personalizados criados por ele (que podem incluir
  CPF/CNPJ, número de contrato e outros)
- Etiquetas e notas internas escritas pela equipe do cliente, que **não** são enviadas
  ao consumidor

**Do usuário do painel (equipe do cliente):** nome, e-mail, senha (armazenada com hash),
equipe, e registros de acesso.

## 4. De onde os dados vêm

- Das mensagens que o próprio consumidor envia ao número do cliente
- Da importação de planilhas feita pelo cliente
- Do preenchimento manual pela equipe do cliente
- De respostas dadas ao atendimento automático (chatbot), quando o cliente configura
  que a resposta preencha um campo do cadastro

## 5. Para que são usados

Exclusivamente para operar o atendimento: receber e responder mensagens, organizar a
fila, encaminhar para a equipe certa, manter histórico da conversa, e gerar relatórios
de desempenho para o cliente. Não usamos o conteúdo das conversas para publicidade, não
vendemos dados e não os usamos para treinar modelos de terceiros.

## 6. Com quem compartilhamos

- **Meta Platforms** — quando o atendimento usa a API oficial do WhatsApp, as mensagens
  necessariamente transitam pela infraestrutura da Meta, sujeitas às políticas dela.
  Isso implica **transferência internacional** de dados para os Estados Unidos.
- **Provedor de infraestrutura** — os servidores que hospedam o sistema.
  [[decidir: nomear o provedor da VPS]]
- **Autoridades**, quando houver obrigação legal ou ordem judicial.

Não há outro compartilhamento. A transcrição de áudio e a busca de CEP/CNPJ são feitas,
respectivamente, em servidor próprio e contra serviços públicos, sem envio de conteúdo
de conversa.

## 7. Por quanto tempo guardamos

Enquanto o cliente mantiver o contrato e conforme a política de retenção que ele
definir. Encerrado o contrato: [[decidir: prazo de exclusão após o encerramento —
sugestão de 90 dias, tempo de o cliente extrair o que precisa]].

Backups são mantidos por [[decidir: prazo dos backups]] e depois descartados.

## 8. Direitos do titular

Nos termos do art. 18 da LGPD, você pode pedir confirmação de tratamento, acesso,
correção, anonimização, portabilidade, eliminação e informação sobre compartilhamento.

**Como pedir:** se você conversou com uma empresa que usa o OnChat, procure essa
empresa — ela é a controladora e tem o dever de responder. Se você não conseguir
identificá-la, escreva para **[[decidir: e-mail do encarregado]]** e nós indicamos o
caminho.

## 9. Segurança

Os dados de cada conta ficam isolados dos das demais no banco. O acesso ao painel exige
autenticação, o tráfego é cifrado em trânsito (HTTPS), e as senhas são guardadas apenas
como hash — não temos como ler a sua senha. Fazemos cópia de segurança diária,
verificada.

Nenhuma medida elimina risco. Em caso de incidente com risco relevante, notificaremos o
cliente e a ANPD conforme o art. 48 da LGPD.

## 10. Menores

O OnChat é ferramenta de trabalho e não se destina a menores de 18 anos.

## 11. Alterações

Mudanças materiais serão comunicadas ao cliente antes de entrarem em vigor.
