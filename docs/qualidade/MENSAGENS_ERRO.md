# Mensagens de erro e alertas: contrato e regras

Objectivo: o utilizador recebe sempre uma mensagem **verdadeira**, **clara** e **sem detalhes do servidor**. A causa técnica
fica só no log, ligada à resposta por uma **referência**.

## 1. Contrato de erro da API

```json
{ "sucesso": false, "mensagem": "…", "codigo": "VALIDACAO", "erros": { "campo": ["…"] }, "referencia": "E-7K3QX9PD", "dados": null, "metadados": {} }
```

| Campo | Regra |
|---|---|
| `mensagem` | **Sempre segura.** Passa por `App\Support\Api\MensagemSegura::sanitizar()`. Texto técnico (SQLSTATE, caminhos, classes, SQL, IPs, Redis…) é trocado pela frase genérica da categoria. |
| `codigo` | Identificador estável (o frontend decide por ele, nunca pelo texto). |
| `erros` | Só nos 422/`ErroNegocio` com detalhes. Cada texto também é sanitizado. 429 traz `espera_segundos`. |
| `referencia` | **Só nos 5xx** (`E-` + 8 caracteres sem ambíguos). Vai também no cabeçalho `X-Referencia-Erro`. O log do servidor tem a entrada `[E-…] ClasseDaExcepção` com a excepção completa e o `referencia` no contexto: o suporte procura por ela. |

`APP_DEBUG` **não** altera a resposta (já não há bloco `depuracao`): a causa vê-se no log.

## 2. Categorias (tabela de referência)

| Categoria | Estado / código | Mensagem ao utilizador | Alerta |
|---|---|---|---|
| Validação | 422 `VALIDACAO` | «Os dados enviados são inválidos. Reveja os campos assinalados.» + lista por campo | aviso |
| Regra de negócio | 4xx `ErroNegocio` (código próprio) | A mensagem escrita pelo programador para o utilizador | aviso |
| Duplicado/conflito | 409 ou 422 (`NIF_DUPLICADO`…) | «Já existe …» sem dados de terceiros / «entra em conflito com outro registo já existente» | aviso |
| Sessão terminada | 401 `NAO_AUTENTICADO` | «A sua sessão terminou. Entre novamente.» | aviso (e a sessão é terminada) |
| Sem permissão | 403 `SEM_PERMISSAO` | «Não tem permissão para executar esta operação.» | aviso |
| Não encontrado | 404 `NAO_ENCONTRADO` | «O registo pedido não foi encontrado.» | aviso |
| Limite de pedidos | 429 `LIMITE_PEDIDOS` | «Demasiados pedidos em pouco tempo. Aguarde N segundos e tente novamente.» | aviso |
| Pedido/ficheiro grande | 413 `PEDIDO_DEMASIADO_GRANDE` / 422 `FICHEIRO_*` | «O pedido ou ficheiro enviado é demasiado grande.» / mensagem do ficheiro | aviso |
| Sessão de segurança (CSRF) | 419 `SESSAO_CSRF_EXPIRADA` | «A sua sessão de segurança expirou. Recarregue a página e tente novamente.» | aviso |
| Licença/plano | 403 `LICENCA_EXPIRADA`… | Mensagem própria + modal com atalho para os planos | aviso |
| Confirmação de identidade | 403 `MFA_REQUERIDO` | Abre o pedido de confirmação e repete o pedido (sem alerta) | n/a |
| Indisponibilidade | 502/503/504 `INDISPONIVEL` | «Serviço temporariamente indisponível. Tente novamente dentro de instantes.» + referência | erro |
| Erro interno | 500 `ERRO_INTERNO` | «Ocorreu um erro interno. Tente novamente; se persistir, contacte o suporte indicando a referência.» + referência (copiável) | erro |
| Sem resposta (rede) | estado 0 `SEM_LIGACAO` | «Não foi possível contactar o servidor. Verifique a sua ligação…» (ou «Está sem ligação à internet…») | erro |
| Sem resposta a tempo | estado 0 `SEM_RESPOSTA_A_TEMPO` | «O servidor não respondeu a tempo. Confirme se a operação ficou registada antes de a repetir.» | erro |
| Pedido cancelado | estado 0 `PEDIDO_CANCELADO` | (nada: não é uma falha) | nenhum |
| Erro inesperado no navegador | `FronteiraDeErros` | «Ocorreu um erro inesperado…» + referência `UI-…` | ecrã de erro |

Falsidades evitadas: «sem ligação» já só aparece sem resposta do servidor (um 500 nunca vira «sem ligação»); um timeout não
diz «falhou» (a operação pode ter ficado registada); «sem permissão» só nos 403; o 401 de sessão expirada não se confunde com
credenciais inválidas (esse tem código próprio e mensagem própria).

## 3. Backend: como programar

1. Mensagem para o utilizador → `throw new ErroNegocio('Texto claro, sem nomes de tabelas.', 'CODIGO', 422)`. Nunca concatenar
   `$e->getMessage()` de uma excepção que não seja `ErroNegocio` (nem SQL, caminhos, ids internos, valores de configuração,
   nomes de serviços ou chaves).
2. Precisa do texto de uma excepção qualquer num resultado (listas de erros de importação, etc.)? →
   `MensagemSegura::de($e, 'Texto genérico da situação')`: só devolve a mensagem de `ErroNegocio` (e segura); para o resto devolve o genérico.
3. Falha de serviço externo (AGT, IA, pagamentos): apanhar `ConnectionException`/`RequestException`, fazer `report($e)` e lançar
   `ErroNegocio('Não foi possível contactar X. …', 'X_INDISPONIVEL', 502)`; o 5xx ganha `referencia` automaticamente.
4. Nunca `abort(500, $e->getMessage())`. `abort(4xx, 'texto próprio em português')` é aceite; os textos em inglês do framework
   são descartados.
5. Respostas JSON feitas à mão (`response()->json`) só para protocolos de terceiros (OData, webhooks); em todo o resto usar `RespostaApi`.
6. Quem regista no log: `Log::…('contexto', ['erro' => $e->getMessage()])` é permitido (não vai para o cliente).

Guardas automáticas: `backend/tests/Unit/GuardaMensagensErroTest.php` (falha se `getMessage()` de uma excepção genérica
aparecer fora de logs, ou se um `ErroNegocio` tiver SQL/caminho literal) e
`backend/tests/Feature/Sistema/MensagensErroSegurasTest.php` (excepções reais de BD/PHP/Redis/HTTP em rotas reais: nenhuma
resposta contém padrões proibidos; 5xx com `referencia` e estado correcto; a referência está no log).

Infra: o Nginx devolve JSON no contrato (`INDISPONIVEL` / `PEDIDO_DEMASIADO_GRANDE`) para 502/503/504/413 em `/api/`, sem a página
HTML por omissão; `server_tokens off`, `expose_php = Off` e `fastcgi_hide_header X-Powered-By` já estão activos.

## 4. Frontend: como programar

* **Nunca** mostrar `e.message` de uma excepção. Usar:
  * `notificarErro(e, 'Não foi possível gravar a factura')`: apresentação completa (tipo por gravidade, referência com «copiar»,
    sem empilhar duplicados, `role="alert"`).
  * `mensagemParaUtilizador(e, 'gravar a factura')` para texto dentro do ecrã (`Alert`, `Result`, `description`): devolve
    «Não foi possível gravar a factura. <categoria>». Sem contexto devolve só a mensagem da categoria.
  * `categoriaDoErro(e)` / `gravidadeDoErro(e)` / `referenciaDoErro(e)` quando o ecrã precisa de reagir.
* `ErroApi.message` já é seguro (construído em `api/cliente.ts` por `normalizarRespostaDeErro` / `normalizarFalhaSemResposta`);
  texto do servidor só passa para códigos de negócio e só se não parecer técnico (`parecePorTecnico`).
* Mensagens próprias e verdadeiras do ecrã (validação local, «Indique o motivo…») continuam a usar `message.warning/error`
  com texto fixo; **sem** concatenar `e.message`.
* Estados `isError` de consultas: `<Alert type="error" title="Não foi possível carregar X." description={mensagemParaUtilizador(consulta.error)} />`.
* Erros de renderização: `FronteiraDeErros` (em `main.tsx`) mostra texto genérico e a referência `UI-…`; a causa só vai para a consola.
* Guarda: `frontend/src/utilitarios/guardaMensagensErro.test.ts` falha se aparecer `message.error(e.message)`,
  `notification.*({ description: e.message })`, `Modal.error({ content: e.message })`, `instanceof Error ? e.message`, `{q.error.message}`…

## 5. Regra para os módulos novos (Imobiliário, Planeamento Estratégico, Procedimentos…)

1. Backend: só `RespostaApi`, `ErroNegocio` com mensagem para utilizador e códigos estáveis; nenhuma concatenação de `getMessage()` genérico.
2. Frontend: `useAccao`/`notificarErro` nas mutações; `mensagemParaUtilizador` nas consultas; nunca `e.message`.
3. Acrescentar o código novo à tabela da secção 2 só se tiver comportamento especial; os códigos de negócio não precisam de registo no frontend.
4. As guardas de §3 e §4 correm na suite: se falharem, o erro indica o ficheiro e a linha.
