# Plano de implementação da extensão SaaS — retrospectiva

> Resposta a `segurança.txt` ("EXTENSÃO DO PROMPT MESTRE", 26 secções, 400+ pontos). Instrução original: *"vais
> implementar tudo o que coloquei no prompt acima e fazer no máximo por fases e vais nomeá-las as fases."* Plano
> completo (com a investigação que o fundamenta) em `C:\Users\leona\.claude\plans\fizzy-crunching-reef.md`.

## Fases concluídas

| Fase | Nome | Commits | Resumo |
|---|---|---|---|
| 0 | Fecho do Webhook de Pagamentos | `43c2024` | Assinatura HMAC obrigatória + montante autoritário nos gateways de pagamento. |
| 1 | Máquina de Estados Comercial | `c91e259` | `estado_licenca` explícito, 9 estados, transições validadas e auditadas (`MaquinaEstadosLicenca`). |
| 2 | Suspensão com Regularização | `9880292`, `8a61857` | Nunca bloqueio total: leitura e rotas `licenca.*` sempre acessíveis. |
| 3 | Automação do Ciclo de Cobrança (reduzida) | `78f9aea` | Só sincronização automática de estado (`erp:licencas:sincronizar`); geração automática de cobrança recusada pelo utilizador. |
| 4 | RBAC e MFA do Back-office | `70b6402`, `7035be8` | Perfis granulares (`OPERADOR_PLATAFORMA`) + TOTP manual (sem dependência externa), MFA opcional por decisão do utilizador. |
| 5 | Dashboard Executivo | `84ccb88` | MRR/ARR/receita/churn/conversão/tickets/disponibilidade em `ServicoDashboardExecutivo`. |
| 6 | Maturação do Suporte Técnico | `625dbb7` (estados/categorias), `8e82997` (SLA/alertas/transferência/tempo) | 11 estados, 18 categorias, SLA consciente de dias úteis, alertas de violação, correcção do gap frontend dos 4 estados antigos. |
| 7 | Monitorização Operacional | `b0a6ff2` | `ServicoSaude` partilhado por `/api/saude` (público) e `/api/plataforma/monitorizacao` (back-office); corrige exposição de versões a utilizadores não-privilegiados. |
| 8 | Limites e Planos Avançados (âmbito reduzido) | `999dd16` | Período de teste por plano + aviso (não bloqueio) de downgrade abaixo do uso. Armazenamento/operações/integrações/funcionalidades premium/prorata recusados — sem base real no sistema. |
| 9 | Testes Ponta-a-Ponta e Documentação | (este commit) | `CicloCompletoSaasTest`, correcção do gap de SLA em tickets criados pelo cliente, os 10 documentos desta pasta. |

## Fase diferida

| Fase | Nome | Estado |
|---|---|---|
| 10 | Base de Conhecimento | Diferida a pedido explícito do utilizador — sem implicações de segurança, sem bloquear nenhuma outra fase. Só a construir a pedido futuro. |

## Checkpoints de aprovação explícita (documento-fonte, secção 25, ponto 958)

O documento exige aprovação prévia para qualquer funcionalidade que altere contratos, cobranças, retenção de
dados, processos financeiros ou arquitectura. Checkpoints efectivamente levantados ao utilizador nesta extensão:

1. **Após a funcionalidade de senhas obrigatórias** (antes da Fase 0): comitar e avançar para o plano de fases;
   manter o fluxo de auto-registo público com palavra-passe escolhida pelo cliente (não é uma conta criada por
   um administrador, por isso o padrão de senha gerada não se aplica aqui).
2. **`dias_tolerancia`** (Fase 1): manter bloqueio imediato (0 dias), preservando o comportamento ADR-074
   existente, em vez de introduzir um período de graça novo.
3. **Geração automática de cobrança** (Fase 3): manter fluxo manual por agora; só a sincronização de *estado* é
   automática.
4. **MFA obrigatório** (Fase 4): deixar opcional por agora, para não quebrar testes existentes com esses papéis.
5. **Âmbito da Fase 8**: reduzido ao que tem base real (trial por plano, aviso de downgrade); recusadas as
   dimensões de limite inventadas do zero e o prorata/crédito (tocaria em facturação).

## Lacunas conhecidas, não corrigidas nesta extensão

Ver [SAAS_SECURITY_AUDIT.md](SAAS_SECURITY_AUDIT.md) secção 4 para o detalhe e a justificação de cada uma:
senha de administrador escolhida pelo cliente em `ClientesController`/`ServicoClientes::criar()`; MFA não
obrigatório; ausência deliberada de impersonação.

## Como verificar o estado actual

```
docker exec erp_app_e2e php artisan test   # suite completa do backend
npx tsc -b && npx vitest run               # frontend (dentro de frontend/)
npm run build                              # obrigatório depois de qualquer mudança: localhost:8081/erp_web_e2e serve de frontend/dist
```

Ver [SAAS_TEST_REPORT.md](SAAS_TEST_REPORT.md) para o resultado da última corrida completa.
