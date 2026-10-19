# Estado atual

Atualizado em 2026-10-19.

## Feito

- **Domínio:** `meuerp.ao` e `www` servidos pela AWS com HTTPS. DNS no Route 53, com SPF, DKIM e DMARC.
- **Subdomínios:** `gestao-admin`, `empresa1` e `empresa2` com HTTPS.
- **Prefixo da plataforma configurável:** `DOMINIO_PLATAFORMA` (antes estava fixo no código). `DOMINIO_BASE=meuerp.ao` no servidor.
- **E-mail:** SMTP do StackMail ativo, remetente `noreply@meuerp.ao`. Teste de envio chegou à caixa de entrada.
- **Empresas de teste:** `empresa1` e `empresa2` criadas pelo serviço do SaaS (`ServicoClientes::criar`), com licença `SEM_LIMITE`. NIFs de teste: trocar pelos reais.
- **P0, autorização de licenças:** criar e alterar planos e confirmar pagamentos agora exigem o super-administrador do tenant da plataforma (`LicencaController`). Teste de regressão em `LicencaCobrancaTest`.

## Pendente

- **P0:** segurança dos webhooks e da cobrança (Fase 2).
- **P1:** ativar o SaaS (`SAAS_ATIVO=true`, papel `erp_app`, RLS) e o teste cruzado entre tenants. Ao ativar, o workflow de deploy precisa migrar com `--database=pgsql_owner`.
- **P1:** consultas da Gestão SaaS compatíveis com RLS; indicador "RLS" confiável.
- **P2/P3:** planos unificados, módulos por plano, fluxo comercial.
- Primeiro acesso do administrador da plataforma, "Esqueceu a senha?" e rodapé dos ecrãs de login.
- Site comercial em `meuerp.ao`.

## Aviso

Enquanto o SaaS não estiver ativado e testado, as empresas de teste **não estão isoladas** entre si. Não usar dados reais de clientes.
