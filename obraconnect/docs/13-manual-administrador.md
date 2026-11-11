# Manual do administrador (versão 0.19.0)

Dois tipos de administrador: **da empresa** (cliente) e **da plataforma** (equipa ObraConnect).

## Administrador da empresa

### Primeiros passos

1. Registe a empresa em `/registar` e confirme o email.
2. **Empresa**: preencha denominação social, NIF, morada e logótipo.
3. **A minha conta**: ative a autenticação de dois fatores.
4. **Empresa → Segurança**: se quiser, exija 2FA a todos os utilizadores (só pode ativar depois de ter a sua própria 2FA ativa).
5. **Utilizadores → Convidar utilizador**: escolha o email e o perfil. O convite expira em 7 dias.

### Perfis e permissões

- A empresa começa com 14 perfis (Administrador, Diretor técnico, Diretor de obra, Encarregado, Preparador, Medidor-orçamentista, Fiscal, Coordenador de segurança, Técnico de qualidade, Administrativo, Trabalhador, Subempreiteiro, Fornecedor, Dono de obra).
- Pode editar qualquer perfil exceto o de Administrador, criar perfis novos e repor um perfil ao modelo original.
- Ninguém consegue dar a outra pessoa mais permissões do que as que tem.
- **Permissões de obra** (Obras e Diário de obra) funcionam obra a obra. Um perfil com **Acesso a todas as obras** aplica-as em todas; sem essa permissão, a pessoa só as tem nas obras de cuja equipa faz parte, com o **perfil que lhe for dado nessa obra** (separador Equipa da obra). Assim um encarregado pode ser diretor de obra numa obra concreta sem ganhar acesso às outras.
- Os perfis externos (subempreiteiro, fornecedor, dono de obra) têm "Ver obras" mas, sem acesso a todas as obras, só veem aquelas para onde forem chamados.

### Utilizadores

- Mudar perfil, suspender ou remover: **Utilizadores**. Remover tira o acesso à empresa; a conta pessoal da pessoa mantém-se (pode pertencer a outras empresas).
- A empresa tem de ter sempre pelo menos um administrador ativo.
- Convites pendentes contam para o limite de utilizadores do plano.

### Suporte técnico

A equipa ObraConnect **não vê os dados da empresa** sem a sua autorização. Para pedir ajuda:
**Suporte técnico → Autorizar acesso**: motivo (ex.: número do pedido) e duração (1 a 72 h). O suporte entra só em modo de leitura. Pode revogar a qualquer momento. Cada consulta fica em **Auditoria**.

### Auditoria

Regista entradas, falhas de login, alterações à empresa, utilizadores, perfis, convites, acessos do suporte e exportações. Os registos não podem ser alterados nem apagados.

### Subscrição

**Subscrição** mostra o plano, o fim do período experimental, o período pago e a utilização. Desde a 0.22.0 quem tem `subscription.manage` (o administrador) muda de plano aqui, sem pagamento online:

- **No período experimental**, «Mudar para este plano» muda logo (o período experimental continua). «Pedir a ativação» faz um pedido a pagar: a equipa ObraConnect envia a fatura e, quando o pagamento é confirmado, o período pago começa no fim do período experimental (não se perdem dias).
- **Com a subscrição paga**, um plano mais caro aplica-se logo e a diferença até ao fim do período é faturada à parte; um plano mais barato (ou passar de mensal a anual) fica marcado para o fim do período e pode ser desmarcado. Descer de plano só é possível se a empresa já couber no plano novo (utilizadores, obras ativas e armazenamento).
- **Renovar** fica disponível 30 dias antes do fim do período; se for paga antes do fim, o período novo começa onde o atual acaba.
- **Cupões**: os de desconto usam-se no pedido; os de dias extra de período experimental em «Tem um código?». Cada empresa usa cada cupão uma vez.
- Subscrições com condições combinadas com a equipa ObraConnect (limites à medida, plano não público, sem fim de período) só são mudadas pela equipa.
- Uma empresa em só leitura por a subscrição ter terminado pode pedir a ativação (ou usar um cupão de dias): volta ao normal quando o pedido é confirmado.

Se a subscrição expirar, a empresa passa a **só leitura** (consulta e exportação continuam disponíveis). Ao fim de 30 dias (configurável) fica **suspensa**. Os dados não são apagados automaticamente.

### Integrações (API e webhooks) — 0.21.0

Em **Integrações (API)** (planos Business e Enterprise, permissão `integrations.manage`):

- **Nova chave**: nome do programa que a vai usar, o que pode fazer (âmbitos) e, se quiser, até quando vale. A chave aparece **uma só vez**: copie-a para o outro programa. Se a perder, revogue-a e crie outra.
- **Novo webhook**: endereço `https://` do outro programa e os eventos (auto aprovado, auto proposto no portal, pré-fatura pronta, fatura lida, fatura lançada, alerta aberto). O **segredo** aparece uma só vez e serve para o outro programa confirmar que o aviso veio do ObraConnect.
- **Enviar teste** experimenta logo; **Envios** mostra cada aviso (entregue, a tentar, falhou), com a resposta e o conteúdo, e deixa reenviar. Se o outro programa falhar dez avisos seguidos, o webhook desliga-se sozinho: corrija o endereço e volte a ligá-lo em **Editar**.
- Tudo fica na auditoria. Pormenores técnicos para quem faz a ligação: `docs/16-api-publica.md`.

### Exportação de dados

**Exportação de dados → Pedir exportação**: gera um ZIP com JSON de todos os dados da empresa. Recebe um email quando estiver pronto. O ficheiro fica disponível 48 horas.

## Administrador da plataforma

### Apresentação pública e preços (0.24.0)

Quem abre o endereço da plataforma sem sessão vê a apresentação do ObraConnect (o que faz, para quem, segurança, planos e preços, perguntas frequentes) e o botão para criar a empresa; quem já tem sessão vai direto para a aplicação. A mesma página está sempre em `/apresentacao` e em PDF em `/apresentacao.pdf` (para enviar a clientes).

- Os preços, limites e funcionalidades de cada plano vêm de **Planos e preços**: mudar lá muda a página e o PDF. Só aparecem os planos públicos e ativos. Das funcionalidades de cada plano só se anunciam as que já existem.
- O email para pedidos de proposta e de demonstração é `SALES_EMAIL` no `.env`; vazio, esses botões não aparecem.
- O texto está em `app/Marketing/Catalog.php` (o mesmo para a página e o PDF).

### Pedidos de subscrição e cupões (0.22.0)

Para a equipa de **faturação** (e super administradores):

- **Pedidos de subscrição**: cada pedido de ativação ou renovação chega por email. Emita a fatura no programa certificado e, quando o pagamento entrar, **Confirmar pagamento** (com o n.º da fatura): o período começa ou é prolongado e a empresa recebe um email. As **subidas de plano** já estão aplicadas: quando estiverem faturadas, marque-as como faturadas. **Cancelar** pede um motivo, que vai no email à empresa. Pedidos por pagar caducam ao fim de 30 dias (`BILLING_PENDING_ORDER_DAYS`).
- **Cupões**: percentagem ou valor de desconto (num pedido) ou dias extra de período experimental; opcionalmente só para alguns planos, só mensal ou anual, com início, fim e número máximo de usos. Depois de usado, o código e o desconto não mudam: desative-o e crie outro.
- **Dados de pagamento** (0.25.0): titular, IBAN (pode escrever o NIB), banco, BIC/SWIFT, email para os comprovativos e o texto das indicações. É o que as empresas veem no pedido por confirmar e no email do pedido, com o descritivo a usar na transferência (`ObraConnect <n.º do pedido>`, também visível em Pedidos de subscrição). A faturação vê; só o super administrador altera, e uma troca de IBAN ou de titular é avisada por email a toda a equipa de faturação — se não foi alguém da equipa, reponha os dados e mude as palavras-passe. Sem nada guardado, as empresas só veem o texto de `BILLING_PAYMENT_INSTRUCTIONS` no `.env`.

### Cópias de segurança (0.23.0)

A aplicação faz todos os dias às 03:30 uma cópia da base de dados e dos ficheiros das empresas em `storage/app/backups` (base de dados 14 dias; ficheiros, os 3 últimos). Na **Saúde do sistema** vê-se a última; se passar um dia sem cópia, há aviso por email. Copie estes ficheiros para fora do servidor com regularidade (ver `docs/11-backup-restauro.md`).

### Saúde do sistema (0.19.0)

Em **Plataforma → Saúde do sistema**: o cron (sinal a cada minuto), cada tarefa agendada (quando correu e se correu bem), a fila de trabalhos, os trabalhos falhados, o DocIntel, os erros registados hoje e a configuração (envio de emails, modo de depuração, disco). Verde está bem, amarelo merece atenção, vermelho está a falhar.

- A cada 10 minutos as verificações correm sozinhas. Quando uma passa a vermelho e continua assim na verificação seguinte (para não avisar de falhas passageiras), os **super administradores da plataforma** recebem um email (e os endereços em `MONITORING_EMAILS` no `.env`, separados por vírgulas); outro quando volta ao normal; enquanto durar, um por dia.
- **Trabalhos falhados** (emails ou leituras de faturas que falharam três vezes): o super administrador pode **Repetir** (volta à fila) ou **Apagar**.
- **Se o cron parar**, também não saem estes avisos. Ponha o endereço `https://…/api/v1/health` num serviço de monitorização externo (o UptimeRobot gratuito chega, a cada 5 minutos): responde **503** quando o cron não corre há mais de 5 minutos ou quando a fila está parada, e o serviço avisa por email.


Criado com `php artisan obraconnect:create-platform-admin` ou na página `/instalar`. Papéis:

| Papel | Pode |
| --- | --- |
| Super administrador | Tudo, incluindo suspender e reativar empresas |
| Faturação | Painel, empresas, planos e subscrições |
| Suporte técnico | Painel, empresas, pedidos de suporte (entrar só com autorização), logs de segurança |

2FA é obrigatória para todos.

### Tarefas

- **Planos e preços**: editar nome, preços (sem IVA), limites (utilizadores, obras, armazenamento), dias de trial e funcionalidades. Planos não se apagam: desativam-se.
- **Empresas → empresa → Alterar subscrição**: plano, estado, fim do trial ou do período, limite personalizado de utilizadores. Mudar de plano mantém o histórico. Uma subscrição válida reativa empresas bloqueadas por expiração (não as suspensas manualmente).
- **Suspender/Reativar**: com motivo, fica registado.
- **Pedidos de suporte**: lista das autorizações ativas. "Entrar" abre a empresa em só leitura até sair ou a autorização expirar.
- **Logs de segurança**: autenticação, ações da plataforma e do suporte, em todas as empresas.

O backoffice mostra só dados comerciais (identificação, plano, estado, contagens, contacto dos administradores). Por desenho, não mostra conteúdo operacional das empresas.

### Comandos úteis (SSH)

```bash
php artisan obraconnect:create-platform-admin email@exemplo.pt --role=support
php artisan obraconnect:sync-permissions       # depois de atualizar
php artisan obraconnect:subscriptions-sweep    # corre todos os dias às 02:15
php artisan obraconnect:prune-exports          # corre de hora a hora
php artisan obraconnect:install-sql            # regenera o SQL de instalação
php artisan obraconnect:demo --admin=email@exemplo.pt          # cria a empresa de demonstração
php artisan obraconnect:demo --fresh --admin=email@exemplo.pt  # apaga-a e volta a criar (datas de hoje)
```

### Empresa de demonstração

`obraconnect:demo` cria a **Atlântico Construções (demonstração)** com dados fictícios em todas as áreas (equipa de 15 pessoas, quatro obras, orçamentos, autos, compras, horas, custos, planeamento, diário com fotografias, documentos, inspeções e ocorrências). Demora cerca de 30 segundos e ocupa uns 3 MB.

- A equipa fictícia usa o domínio reservado `atlantico-demo.test`: ninguém entra com essas contas (as passwords são aleatórias e não ficam escritas em lado nenhum) e nenhum email lhes chega — o envio de emails retira sempre os endereços de domínios reservados.
- Para ver a demonstração, indique em `--admin` o email de um utilizador **já existente**: fica administrador da empresa de demonstração e muda de empresa no menu. A sua empresa atual não muda.
- Sem `--fresh`, se a demonstração já existir, só junta os administradores indicados. Com `--fresh` apaga tudo da demonstração (dados, ficheiros e equipa fictícia) e cria de novo; as outras empresas não são tocadas.
- A empresa de demonstração tem o plano Business ativo e conta como qualquer outra empresa no backoffice.
