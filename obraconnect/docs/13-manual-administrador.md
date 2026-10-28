# Manual do administrador (versão 0.1.0)

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
- Na versão atual as permissões aplicam-se à empresa inteira. As permissões por obra chegam com o módulo de obras.

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

**Subscrição** mostra o plano, o fim do período experimental e a utilização. A mudança de plano é feita pela equipa ObraConnect nesta versão.

Se a subscrição expirar, a empresa passa a **só leitura** (consulta e exportação continuam disponíveis). Ao fim de 30 dias (configurável) fica **suspensa**. Os dados não são apagados automaticamente.

### Exportação de dados

**Exportação de dados → Pedir exportação**: gera um ZIP com JSON de todos os dados da empresa. Recebe um email quando estiver pronto. O ficheiro fica disponível 48 horas.

## Administrador da plataforma

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
```
