# Auditoria das mensagens de erro e alertas (inventário)

Data: 2026-11-06. Contrato e regras actuais: `MENSAGENS_ERRO.md`.

## 1. Inventário

### Backend (`backend/app`)

| Ponto | Quantidade | Estado |
|---|---|---|
| `new ErroNegocio(` | 2236 (2156 com texto literal analisado) | Todos os literais passam pelo sanitizador sem alteração, excepto 2 que citavam `APP_KEY` (corrigidos). Os dinâmicos são «Linha N: …» com `ErroNegocio` filha |
| `getMessage()` | 101 ocorrências | 23 em logs/filas/comandos (permitidos e listados na guarda); 7 chegavam ao cliente com excepções genéricas (corrigidos); restantes só encadeiam `ErroNegocio` |
| `abort(` | 2 (`ReparticaoController`, `abort(404)`) | seguros |
| `response()->json` à mão | 4 (OData, webhooks, gateway) | protocolos de terceiros; mensagens vêm de `ErroNegocio` |
| Middleware que responde (`SanitizarEntrada`, `ValidarUploadsSeguros`, `ExigirPlataforma`, `ExigirMfaRecente`…) | 6 | mensagens próprias e seguras; passam agora também pelo sanitizador |
| Handler `ManipuladorExcecoesApi` | 1 | reescrito |
| Respostas fora do PHP (Nginx) | 3 ficheiros de configuração | 502/503/504/413 em `/api/` passam a JSON |

### Frontend (`frontend/src`, sem testes)

| Padrão | Ocorrências / ficheiros |
|---|---|
| `notificarErro` | 501 / 171 |
| `message.error` / `.warning` / `.success` | 17 / 15 · 81 / 50 · 197 / 113 (todas com texto próprio fixo, ver §3) |
| `notification.*`, `Modal.error`, `Result status="error"` | 1, 2, 2 |
| `<Alert` | 560 / 251 (57 são «Não foi possível…» de consulta com erro) |
| `onError` (React Query) | 178 / 89 |
| `.message` / `String(e)` / `JSON.stringify(e)` de erro | 66 linhas / 53 ficheiros, das quais 25 eram de excepções genéricas (§3) |
| `ErrorBoundary` | 0 (antes: ecrã em branco) |

## 2. O que era falso ou vazava (exemplos reais)

Backend
* `ManipuladorExcecoesApi::erroInterno` com `APP_DEBUG` devolvia classe, mensagem crua da excepção e `ficheiro:linha`. Os 500 não tinham referência para o suporte.
* `ManipuladorExcecoesApi` (ramo `HttpExceptionInterface`): devolvia `$e->getMessage()` cru com código `ERRO_HTTP` (ex.: «CSRF token mismatch.», o texto do 413, o texto do 503 de manutenção).
* `ServicoControloOrcamental:224`: `'… o documento não foi gravado. '.$e->getMessage()` (SQL/PHP cru para o utilizador).
* `ServicoCopiaEmpresa:315`: nome de restrição/tabela da base de dados na resposta.
* `ClienteHttpAgt:60`: mensagem cURL (com URL/host da AGT) na resposta ao utilizador.
* `GeminiIA` / `OpenAIIA` (teste de ligação): mensagem cURL crua.
* `ServicoRecebimentosVendas:198`: `mb_substr($e->getMessage(), 0, 300)` de uma `Throwable` na lista de erros.
* `ServicoSaude`: com `APP_DEBUG` mostrava a mensagem crua nas verificações.
* `ServicoConfiguracaoIA` / `ServicoLigacaoAgt`: mensagens a citar `APP_KEY` (nome de configuração).
* `ServicoCambiosBAIAutomaticos`: texto escolhido com `instanceof ErroNegocio ? getMessage()` (agora `MensagemSegura::de`).

Frontend
* `api/cliente.ts`: `Sem ligação ao servidor` para QUALQUER falha sem resposta, incluindo timeouts (falso: o servidor pode ter processado) e pedidos cancelados; `Erro inesperado (502)` e o corpo HTML do proxy usados como mensagem; o `message` do servidor era mostrado sempre, mesmo em 5xx.
* `notificarErro`: `String(e)` para qualquer coisa; mostrava `Código: ERRO_INTERNO`/códigos internos; não tinha referência; empilhava duplicados; usava sempre «erro» vermelho (também para validação).
* `conversoes.mensagemErro`, `Operacoes.tsx`, `AccaoProposta.tsx`, `NovaProposta.tsx`, `Contratos.tsx`, `ModalLiquidarFacturas.tsx`, `Integracao.tsx`: `e instanceof Error ? e.message` (texto de TypeError/AxiosError no ecrã).
* `Validacoes.tsx` (2), `ModalSaldosHistoricos.tsx`, `EcraSaft.tsx`, `Relatorios.tsx`, `PopoverPreContabilizacao.tsx`, `Portal.tsx`, `PlataformaDashboardExecutivo.tsx`: `consulta.error.message` em `Alert`/`Typography`.
* `QuadroOrganigrama.tsx`: `message.error(e.message)` de uma excepção do navegador.
* `PainelVenda.tsx`: expunha a chave interna de permissão («hotel_checkout»).
* Sem `ErrorBoundary`: um erro de renderização deixava o ecrã em branco.

## 3. Camada criada

Backend: `App\Support\Api\MensagemSegura` (lista negra, `sanitizar`, `de`, `generica` por estado, `novaReferencia`); `RespostaApi::erro` sanitiza `mensagem` e `erros` e aceita `referencia`;
`ManipuladorExcecoesApi` reescrito (sem `depuracao`, 5xx com referência + log completo correlacionado, 413/419/502–504 com texto próprio, 429 com `espera_segundos`); Nginx com JSON para falhas próprias.
Frontend: `utilitarios/mensagensErro.ts` (`normalizarRespostaDeErro`, `normalizarFalhaSemResposta`, `categoriaDoErro`, `gravidadeDoErro`, `mensagemParaUtilizador`, `detalhesDoErro`, `referenciaDoErro`, `parecePorTecnico`), `ErroApi.referencia`, `notificarErro` reescrito, `componentes/FronteiraDeErros.tsx`.

As 197 `message.success` e 17 `message.error`/81 `message.warning` restantes são textos fixos do ecrã (validação local, confirmações) e não foram alterados.

## 4. Testes

* PHPUnit `MensagensErroSegurasTest` (16 casos: QueryException, PDOException, TypeError, RuntimeException com caminhos, Redis, 502/503/504, CSRF, 413, 403, sanitizador) e `GuardaMensagensErroTest` (3).
* Vitest `mensagensErro.test.ts` (25), `erros.test.tsx` (6), `guardaMensagensErro.test.ts` (10).

## 5. O que fica

* Os 57 `Alert` «Não foi possível carregar X.» sem `description` continuam correctos (não afirmam causa); podem ganhar `description={mensagemParaUtilizador(consulta.error)}` ao serem tocados.
* Logs `Log::…['erro' => $e->getMessage()]` e colunas de estado internas (`ultimo_erro`, migração do legado) guardam texto técnico para a equipa; se algum ecrã administrativo os mostrar, passar por `MensagemSegura::sanitizar`.
* A página de erro de `/up`, `/auth/google` e do SPA (fora de `/api/`) continua a ser a do Nginx (sem versão, `server_tokens off`).
