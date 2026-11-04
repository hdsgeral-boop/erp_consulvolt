# Resultados dos testes PHPUnit (Docker local, projeto `erp_wt`)

Grupos 1-6 e 1.ª suite completa: base a1b9b36. 2.ª suite completa: após `git merge main` (01ba231).

| Grupo | Resultado | Testes |
|---|---|---|
| LicencaCobrancaTest | passou | 7 (59 asserções) |
| WebhookPagamentoSeguroTest | passou (após corrigir o teste) | 11 (92) |
| NotificacoesEGatewaysTest | passou | 3 (45) |
| PlataformaSaasRlsRealTest | passou | 4 (39) |
| RecuperacaoSenhaTest | passou | 5 (32) |
| OwaspControloAcessoTest | passou | 1 (3) |
| Suite completa em a1b9b36 | 1 falha | 473 (472 ok) |
| Suite completa após merge de main | 3 falhas (todas de teste) | 482 (479 ok) |
| LoginPorEnderecoEPrimeiroAcessoTest + SistemaMenuTest após correções | passou | 10 (60) |

Pasta tests/Feature/Tenancy: corrida dentro da suite completa; todas as classes passam (após as correções abaixo).

## Falhas (todas erro de teste, nenhum erro de código da aplicação)
1. WebhookPagamentoSeguroTest, 10 de 11 (a): `cenario()` ligava `erp.saas.ativo=true` antes de criar o super admin;
   com SaaS ativo, criar utilizador sem tenant lança `ErroContextoEmpresa`. Corrigido: criar/entrar com SaaS desligado e ligar depois.
2. SistemaMenuTest (c, já existia): o teste exigia que o módulo `config` não aparecesse, mas `ServicoPermissoes::ecraVisivel`
   mostra sempre o ecrã `notificacoes_centro` (que está no módulo `config`). Corrigido o teste: o módulo pode aparecer, só com esse ecrã.
3. LoginPorEnderecoEPrimeiroAcessoTest, 2 de 8 (a, teste novo do merge): `LogAuditoria::query()` fora de contexto de empresa
   lança `ErroContextoEmpresa` (EscopoEmpresa). Corrigido com `ContextoEmpresa::semIsolamento(...)`.

## Notas de ambiente
- Não correr testes em paralelo (mesma base `erp_consulvolt_testes`); a 1.ª execução migra a base (~5 min), depois ~1 s por teste.
- O init do Postgres não criou `erp_consulvolt_testes` (arranque do Docker interrompido); criada à mão (ver comandos no relatório).
- Já havia uma stack `erp_*` do checkout principal a correr; usou-se projeto separado `erp_wt` (ficheiro docker-compose.teste.yml) para não tocar nos seus volumes.

## Repetir
```
docker compose -p erp_wt -f docker-compose.yml -f docker-compose.teste.yml up -d --build postgres redis app
docker exec erp_wt-postgres-1 psql -U erp -d postgres -c "CREATE DATABASE erp_consulvolt_testes OWNER erp"   # se não existir
docker exec erp_wt-postgres-1 psql -U erp -d erp_consulvolt_testes -c "CREATE EXTENSION IF NOT EXISTS unaccent; CREATE EXTENSION IF NOT EXISTS pg_trgm;"
docker exec erp_wt-app-1 composer install --no-interaction
docker exec erp_wt-app-1 php artisan test --filter=LicencaCobrancaTest
docker compose -p erp_wt -f docker-compose.yml -f docker-compose.teste.yml down
```
