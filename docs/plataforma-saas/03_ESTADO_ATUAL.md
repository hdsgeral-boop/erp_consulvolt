# Estado atual

Atualizado em 2026-10-19.

## Feito

- **Domínio:** `meuerp.ao` e `www` servidos pela AWS com HTTPS (Route 53 para o DNS, Caddy como proxy com certificados automáticos). DNS com SPF, DKIM e DMARC. Exemplo da configuração do Caddy em [`deploy/caddy/Caddyfile.exemplo`](../../deploy/caddy/Caddyfile.exemplo).
- **Subdomínios:** `gestao-admin` (plataforma), `empresa1` e `empresa2`, todos com HTTPS.
- **Prefixo da plataforma configurável:** `DOMINIO_PLATAFORMA` (antes estava fixo no código). `DOMINIO_BASE=meuerp.ao` no servidor.
- **E-mail:** SMTP do StackMail ativo, remetente `noreply@meuerp.ao`. Teste de envio chegou à caixa de entrada.
- **SaaS ativado em produção:** `SAAS_ATIVO=true` e `LICENCAS_ATIVAS=true`. A aplicação liga à base como `erp_app` (sujeita à RLS); as migrações usam a ligação `pgsql_owner`. Foi feito um backup antes da ativação, guardado em `~/erp-backups` no servidor. Procedimento e lições em [RUNBOOK_ATIVACAO.md](RUNBOOK_ATIVACAO.md).
- **Deploy automático:** cada push no `main` corre o workflow `deploy-aws.yml`, que migra com `--database=pgsql_owner` e não toca nos `.env` do servidor. Ver [DEPLOY_AWS.md](../DEPLOY_AWS.md).
- **Empresas de teste:** `empresa1` e `empresa2` criadas pelo serviço do SaaS (`ServicoClientes::criar`), com licença `SEM_LIMITE`. NIFs de teste: trocar pelos reais.
- **Já integrado no `main`:**
  - autorização de licenças: criar/alterar planos e confirmar pagamentos só pelo super-administrador da plataforma (teste de regressão em `LicencaCobrancaTest`);
  - cobrança segura, com webhooks assinados;
  - Gestão SaaS compatível com RLS;
  - recuperação de senha ("Esqueceu a senha?");
  - rodapé nos ecrãs de entrada.

## Pendente

- **Lista de empresas vazia em PHP-FPM:** `GET /api/sistema/empresas` devolve lista vazia em alguns pedidos servidos por FPM. Causa em investigação. O script `ferramentas/operacao/verificar_saas.sh` falha se a lista vier vazia.
- Primeiro acesso do administrador da plataforma.
- Login por host (cada host aceitar só utilizadores do seu tenant).
- Mover as telas da plataforma para `frontend/src/plataforma`.
- Testes automáticos a validar após a ativação.
- Criar as empresas reais pelo SaaS (as atuais são de teste).
- P2/P3: planos unificados, módulos por plano, fluxo comercial.
- Site comercial em `meuerp.ao`.

## Aviso

Não usar dados reais de clientes enquanto a lista de empresas em FPM e o login por host não estiverem resolvidos e verificados com `verificar_saas.sh`.
