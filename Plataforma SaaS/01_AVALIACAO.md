# Avaliação da plataforma SaaS

## Notas gerais

| Critério | Nota |
| --- | --- |
| Arquitetura SaaS | 7/10 |
| Isolamento implementado no código | 8/10 |
| Isolamento efetivo em produção | 2/10 |
| Segurança da cobrança | 1/10 |
| Prontidão comercial | 3/10 |
| Prontidão geral para produção SaaS | **Não aprovada** |

## Análise de Configurações → Plataforma SaaS

A área é extensa e integrada ao ERP, com 12 ecrãs, APIs e permissões próprias. Ainda há falhas que impedem tratá-la como painel SaaS seguro para produção.

### Problemas principais

1. **Os interruptores principais não controlam o sistema.**
   O ecrã de gestão permite ativar SaaS, licenciamento e dias de teste, mas o backend só grava a escolha na base de dados. Em execução, o ERP lê `SAAS_ATIVO` e `LICENCAS_ATIVAS` do `.env` (`ResolverTenantAtivo`, `ResolverEmpresaAtiva`). A interface pode mostrar "Ativo" enquanto o recurso fica desligado.

2. **O indicador "RLS 100%" pode dar falsa garantia.**
   O painel considera o RLS completo quando encontra políticas nas tabelas. Não confirma que o sistema liga como `erp_app`, que `SAAS_ATIVO=true`, que o utilizador do PostgreSQL não é dono nem `BYPASSRLS`, nem que o isolamento foi testado entre dois tenants.

3. **Ativar o RLS corretamente pode quebrar a Gestão SaaS.**
   O `PlataformaSaasController` consulta `empresas` diretamente. Sob RLS real, só enxergaria a empresa da plataforma. As funções `SECURITY DEFINER` já existem, mas este controller não as usa.

4. **Um super-administrador de cliente recebia funcionalidades de plataforma.** *(corrigido, ver 03_ESTADO_ATUAL.md)*
   As APIs de licenciamento verificavam só o papel, sem confirmar que o utilizador era do tenant da plataforma. Um super-administrador de cliente podia criar e alterar planos globais, confirmar pagamentos e aprovar a própria subscrição.

5. **Dois sistemas concorrentes administram planos.**
   A gestão direta em "Licença" e o fluxo versionado em "Planos SaaS" editam o mesmo catálogo. Os módulos incluídos no plano ainda não controlam o acesso: só os limites de empresas e utilizadores funcionam.

6. **A configuração de pagamentos não corresponde ao que o cliente vê.**
   O formulário mostra bancos, titular e um IBAN de exemplo fixos no código (`Licenca.tsx`). Também há: pagamento Express sem atualização após o webhook, campos descartados pelo backend, segredos de gateway em texto normal, webhooks sem assinatura criptográfica obrigatória e confirmação de pagamento sem validação rigorosa do valor.

### O que está bem construído

- Os 12 ecrãs estão registados e acessíveis pelo menu.
- Clientes, suporte, administradores, auditoria, sessões e definições têm APIs reais.
- As rotas `/plataforma/*` têm middleware específico.
- A revogação de sessões evita revogar o token atual.
- Existem trilhas de auditoria e estrutura para isolamento por tenant.
- O build completo do frontend passou (3.818 módulos).

### Estado por área

| Área | Estado |
| --- | --- |
| Estrutura visual e navegação | Boa |
| Funcionalidades administrativas | Parcialmente completas |
| Isolamento multiempresa | Estruturado, mas ainda não operacional |
| Licenciamento e planos | Funcional, com autorização crítica incorreta (corrigida) |
| Pagamentos | Protótipo avançado, inseguro para dinheiro real |
| Confiabilidade dos indicadores | Baixa |
| Pronto para produção SaaS | Não |

## Verificação feita em 2026-10-19

Com `SAAS_ATIVO=false` no servidor, o `ResolverTenantAtivo` não aplica nenhuma restrição. Um utilizador da `empresa1` conseguiu entrar no endereço da `empresa2`, e um administrador de empresa enxerga dados das outras. As duas empresas de teste **não estão isoladas** até o SaaS ser ativado.
