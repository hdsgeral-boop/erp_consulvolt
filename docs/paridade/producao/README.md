# Documentação e ferramentas de deploy da PRODUÇÃO (referência, NÃO activadas)

Cópia só de leitura de ficheiros da produção (snapshot de 2026-10-26), guardada para consulta. **Nada aqui está ligado ao
servidor de teste**: o teste usa o nginx do host + certbot, `docker-compose.prod.yml`/`docker-compose.teste.yml` e
`ferramentas/operacao/actualizar-teste.sh` (ver `docs/TESTE_AWS.md`). Os ficheiros abaixo colidem com essa arquitectura
(Caddy nas portas 80/443, RDS, `/opt/erp`, bucket e parâmetros SSM da produção) e por isso não devem ser executados no teste.

| Ficheiro | O que é | Porque não se activa no teste |
|---|---|---|
| `DEPLOY_AWS.md` | EC2 t3.large + RDS PG16, SSM sem SSH, S3 de transferência, Route53, Caddy HTTPS a pedido | o teste é um t3.micro com nginx do host |
| `deploy/aws/instalar.sh`, `atualizar.sh` | instalação e actualização por pacote S3 com rollback e verificação de saúde | ligados ao bucket/SSM/compose da produção. A ideia de rollback automático e de esperar por `/api/saude` é aproveitável |
| `deploy/caddy/Caddyfile.aws`, `Caddyfile.exemplo` | TLS a pedido (`on_demand_tls` → `GET /api/sistema/dominio-permitido`, já existente no teste) | colide com o TLS do nginx/certbot do host |
| `docker-compose.aws.yml` | compose para RDS + Caddy | o teste usa Postgres no contentor |
| `docker/desempenho.ini` | `opcache.validate_timestamps=${PHP_OPCACHE_VALIDAR:-1}`; após alterar PHP: reiniciar app, worker e scheduler | referência para afinar o OPcache |
| `plataforma-saas/*` | avaliação, plano de correcções, estado, resultados de testes e **runbook de activação** do SaaS; `dns_email_meuerp.ao.zone` (MX/SPF/DKIM/DMARC) | contexto histórico; as lições do runbook foram fundidas em `docs/arquitetura/SAAS_ATIVACAO.md` |
| `API.md` | referência da API da produção | diverge da API do teste (portal, estrutura orgânica, MFA, Google…): usar só como comparação |

O que SE aproveitou para o teste (activo): `ferramentas/operacao/verificar_saas.sh` (adaptado ao cookie HttpOnly) e as lições do
runbook (permissão do `backend/.env`, cache de empresas, `DB_APP_PASSWORD` por `config()`), em `docs/arquitetura/SAAS_ATIVACAO.md`.
