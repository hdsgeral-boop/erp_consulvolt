# Resultados dos testes PHPUnit (ambiente Docker local, projeto `erp_wt`)

Base: a1b9b36 (antes do merge de main 01ba231).

| Grupo | Resultado | Testes |
|---|---|---|
| LicencaCobrancaTest | passou | 7 (59 asserções) |
| WebhookPagamentoSeguroTest | passou (após correção do teste) | 11 (92) |
| NotificacoesEGatewaysTest | passou | 3 (45) |
| PlataformaSaasRlsRealTest | passou | 4 (39) |
| RecuperacaoSenhaTest | passou | 5 (32) |
| OwaspControloAcessoTest | passou | 1 (3) |
| Suite completa (inclui tests/Feature/Tenancy) | 1 falha | 473 (472 ok) |

## Falhas
1. WebhookPagamentoSeguroTest (10 de 11) - (a) erro do teste: `cenario()` ligava `erp.saas.ativo=true` antes de criar o
   super admin, e com SaaS ativo criar utilizador sem tenant lança `ErroContextoEmpresa`. Corrigido: criar/entrar
   com SaaS desligado e ligar depois (padrão dos outros testes de Tenancy).
2. SistemaMenuTest > menu mostra so os ecras permitidos (tests/Feature/SistemaMenuTest.php:24) - o menu devolve o
   módulo `config` a um utilizador que não devia vê-lo. A classificar (b ou c) depois do merge de main.

## Notas de ambiente
- Os testes não podem correr em paralelo (mesma base `erp_consulvolt_testes`): o primeiro teste de cada execução migra a base (~5 min).
- A base `erp_consulvolt_testes` não foi criada pelo init (volume criado durante um arranque do Docker interrompido); criada à mão.
