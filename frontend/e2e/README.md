# Testes ponta-a-ponta (Playwright)

Testes no navegador (Chromium) dos fluxos principais do ERP, contra um ambiente **isolado** com dados **fictícios**.
Nunca tocam na base de desenvolvimento `erp_consulvolt` (dados reais migrados).

## Isolamento

| Peça | Desenvolvimento | E2E |
|---|---|---|
| Backend (php-fpm) | `app` | `app_e2e` (mesma imagem e código) |
| Base PostgreSQL | `erp_consulvolt` | `erp_consulvolt_e2e` |
| Redis | bases lógicas 0/1, prefixo `erp:` | bases lógicas 2/3, prefixos `erp_e2e:` / `erp_e2e_cache:` |
| Filas | worker de desenvolvimento | síncronas (`QUEUE_CONNECTION=sync`) |
| AGT | conforme `.env` | sempre `desligado` |
| Nginx | `web` em 127.0.0.1:8080 | `web_e2e` em 127.0.0.1:8081 (`docker/nginx/e2e.conf`) |

O comando `php artisan erp:e2e:preparar` **recusa** correr se a base configurada não terminar em `_e2e`
(e o seeder `Database\Seeders\E2E\E2ESeeder` tem a mesma protecção). Testado em `tests/Feature/PrepararE2ETest.php`.

## Preparar (uma vez)

Na raiz do projecto (`erp_laravel/`), com o Docker Desktop a correr e o ambiente base levantado (`docker compose up -d`):

```powershell
# 1. contentores E2E (reutilizam o postgres e o redis do docker-compose.yml)
docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d app_e2e web_e2e

# 2. frontend (o web_e2e serve frontend/dist) e Playwright
cd frontend
npm install
npx playwright install chromium
npm run build
```

`npm run e2e:preparar` (ou o `globalSetup` do Playwright) cria a base `erp_consulvolt_e2e` se não existir, faz
`migrate:fresh` e carrega os dados fictícios.

## Correr

```powershell
cd frontend
npm run e2e                                   # recria a base E2E e corre todos os testes (~6 min)
npx playwright test vendas                    # só um ficheiro (também recria a base)
$env:E2E_SEM_PREPARAR='1'; npx playwright test navegacao   # sem recriar a base (repetições rápidas)
npm run e2e:relatorio                         # abre o relatório HTML (frontend/playwright-report)
npm run e2e:tipos                             # verificação de tipos dos testes
```

Depois de alterar o frontend é preciso `npm run build` antes de correr os testes (o nginx E2E serve `dist`).
Em falha ficam a captura de ecrã e o *trace* em `frontend/test-results/` (`npx playwright show-trace <trace.zip>`).
`playwright-report/` e `test-results/` estão fora do Git (`frontend/.gitignore`).

Variáveis: `E2E_URL` (por omissão `http://127.0.0.1:8081`), `E2E_SEM_PREPARAR=1`, `WEB_E2E_PORTA` (porta do `web_e2e`).

## Dados fictícios (seeder `backend/database/seeders/E2E`)

Palavra-passe de todos os utilizadores: `E2e#Teste2026`.

| Utilizador | Perfil |
|---|---|
| `e2e.admin` | super-administrador, todas as empresas; ligado ao colaborador fictício Bruno (portal do colaborador) |
| `e2e.vendas` | só Vendas e Facturação |
| `e2e.pos` | operador de POS (frente de caixa, fecho) |
| `e2e.rh` | Recursos Humanos e Salários (calcular; sem validar nem contabilizar) |
| `e2e.aprovador` | aprova pedidos de compra (nível 1) |

- Empresas: **Demo E2E Comércio, Lda** (NIF 5999000001, todos os dados) e **Demo E2E Serviços, Lda** (vazia).
- Plano de contas mínimo coerente (classes 1 a 8, grupos totalizadores e contas de movimento), diários FC, RC, CP,
  GEPOS, SAL, TS, SQ, OD, AM, AC; configurações contabilísticas de Vendas, Compras, Logística, Tesouraria e POS.
- Produtos: E2E-A01 (1 000 Kz, 100 em stock a 600), E2E-A02 (2 500 Kz, 50 a 1 500), E2E-A03 (sem existências),
  E2E-S01 e E2E-S02 (serviços); IVA 14 %; Armazém Central Demo.
- Clientes Demo Alfa/Beta, fornecedores Demo Ómega/Sigma; meios de pagamento Caixa principal e Banco Fictício E2E.
- Escalões de deliberação de compras: até 1 000 000 Kz um nível; terminal POS T01 (numerário, TPA, transferência).
- RH: 3 colaboradores activos com contrato (base + alimentação + transporte) e mapeamento contabilístico completo.

## Cenários

| Ficheiro | Cobre |
|---|---|
| `autenticacao.e2e.ts` | login inválido/válido, validação do formulário, escolher e trocar de empresa, menu por permissões, «Sem acesso» e 403, terminar sessão |
| `vendas.e2e.ts` | FT com 2 linhas → detalhe → contabilizar; FR paga; NC parcial sobre a FT |
| `pos.e2e.ts` | abrir sessão, venda com pagamento misto e troco, relatório X, fecho Z sem desvio, integração |
| `compras.e2e.ts` | pedido → aprovação (outro utilizador) → proposta → adjudicação → recepção → validação em stock → factura → contabilização |
| `contabilidade.e2e.ts` | lançamento manual equilibrado (bloqueio se desequilibrado) e balancete; mapas abrem e calculam |
| `rh.e2e.ts` | abrir período, importar dos contratos, resultados e totais; mapas de IRT, remunerações e INSS |
| `configuracoes.e2e.ts` | perfil com conflito de segregação (aviso + confirmação), utilizador novo com esse perfil |
| `navegacao.e2e.ts` | abre todos os ecrãs do menu do administrador e falha se houver «Sem acesso», notificação de erro, erro na consola, excepção ou resposta 4xx/5xx |

Os ficheiros chamam-se `*.e2e.ts` para o Vitest (`npm run testes`) não os apanhar.
