# Planos, subscrições e limites

> Fases 1, 3 e 8 da extensão SaaS (`segurança.txt`, secções 4.2, 9, 17).

## 1. Catálogo de planos (versionado e imutável)

- `PlanoLicenca` — o plano em vigor (código, nome, preço, `max_empresas`, `max_utilizadores`, `dias_teste`,
  `ativo`). É o que `ServicoLimitesPlano`/`ModulosPlano` leem para aplicar limites e módulos.
- `PlanoLicencaVersao` — histórico de versões (`RASCUNHO` → `PUBLICADA` → `DESCONTINUADA`). **Uma versão publicada
  nunca se altera**; para mudar algo, cria-se um rascunho novo (cópia da versão em vigor ou de outra indicada) e
  publica-se de novo (`ServicoVersoesPlano`). Publicar:
  1. Descontinua a versão publicada anterior.
  2. Copia `preco_mensal`, `moeda`, `max_empresas`, `max_utilizadores`, `dias_teste` e `modulos` para o
     `PlanoLicenca`.
  3. Invalida a cache de módulos por tenant (`ModulosPlano::invalidar()`).
- Módulos incluídos: lista plana de códigos (`vendas`, `stock`, …) guardada na versão — ver
  `config('erp.planos_catalogo.modulos')` para o catálogo completo. Aplicados no backend
  (`ServicoPermissoes`/`ModulosPlano`), nunca só escondidos no frontend.

Gerido em `/api/plataforma/planos/*` (`PlanosPlataformaController`), acesso `plataforma_planos_gerir`.

## 2. Preço por ciclo de facturação

`App\Services\Sistema\CalculadoraPrecoPlano` é o **único** sítio onde as fórmulas de desconto por ciclo existem —
nem `ServicoLicenca` nem o frontend as recalculam por conta própria (o backend é sempre autoritário sobre o valor
final). Ciclos conhecidos (Mensal/Trimestral/Semestral/Anual) e os seus descontos vêm de
`erp.planos_catalogo.ciclos`; um número de meses fora desses quatro valores fica `PERSONALIZADO` (0% de desconto).
Toda a aritmética usa `bcmath` (nunca float) para não acumular erros de arredondamento em dinheiro.

Cupões de desconto (`CupomDesconto`) aplicam-se por cima: `ServicoLicenca::validarCupom()` nunca bloqueia o
checkout (devolve `valido: false` em vez de erro), e a aplicação usa lock de concorrência (`lockForUpdate`) para
nunca deixar dois pedidos simultâneos esgotarem um cupão com limite de usos para além do permitido
(`CupomDescontoTest`).

## 3. Pedido de subscrição (`SubscricaoEmpresa`)

Estados: `PENDENTE` → `PAGO` (ou cancelado antes de pago). Um pedido pendente:
- Cria-se com `POST /api/licenca/renovacao` (`plano_id`, `meses`, `cupom_codigo?`), por qualquer utilizador com
  `config_licenca_gerir` — calcula o preço pelo ciclo, nunca cobra mais do que a matriz pré-calculada.
- Confirma-se com `POST /api/licenca/subscricoes/{id}/confirmar-pagamento`, **só por um super-administrador**
  (`exigirSuperAdministrador`) — nunca pelo próprio gestor que pediu (separação de funções: quem pede não
  confirma a si próprio). Ver [BILLING_AND_PAYMENTS.md](BILLING_AND_PAYMENTS.md) para como o pagamento chega a
  ser confirmado (comprovativo manual ou webhook de gateway).
- Confirmar estende `licenca_fim` a partir de hoje, ou a partir do fim actual se a licença ainda estiver em vigor
  (renovação antecipada nunca perde dias já pagos) — `ServicoLicenca::confirmarPagamento()`.
- Chama `MaquinaEstadosLicenca::sincronizar()` no mesmo pedido: uma empresa suspensa que acabou de regularizar o
  pagamento fica `ACTIVA` de imediato, sem esperar pelo comando diário.

## 4. Limites de utilizadores e empresas

`App\Services\Plataforma\ServicoLimitesPlano::uso(Tenant $cliente)`:
- Durante o período de demonstração (nenhuma empresa do tenant com licença paga), o limite é sempre **1 empresa e
  1 utilizador**, independentemente do plano escolhido no registo.
- Depois da primeira licença paga, os limites passam a ser os do `PlanoLicenca` atribuído
  (`max_empresas`/`max_utilizadores`; `null` = sem limite).
- Sem plano nenhum atribuído (ex.: a conta interna da própria plataforma), não há limite nenhum.
- `exigirLimiteEmpresas()`/`exigirLimiteUtilizadores()` lançam `ErroNegocio` 422 ao tentar criar acima do limite.

## 5. Troca de plano e aviso de downgrade (Fase 8)

`PUT /api/plataforma/clientes/{cliente}/plano` (`PlanosPlataformaController::atribuirPlano`) muda só a etiqueta
do plano — **sem efeito financeiro** (sem prorata, sem crédito). Desde a Fase 8, antes de aplicar, verifica se o
uso actual de empresas/utilizadores já excede os limites do plano novo
(`ServicoLimitesPlano::excedeNoNovoPlano`):
- Se exceder, devolve `422 PLANO_ABAIXO_DO_USO` com o detalhe do excesso — **nunca bloqueia silenciosamente**.
- Repetir o pedido com `confirmar: true` aplica a troca mesmo assim (decisão explícita do operador).
- Sem excesso, a troca aplica-se directamente, sem pedir confirmação.

## 6. Período de demonstração por plano (Fase 8)

`PlanoLicenca`/`PlanoLicencaVersao.dias_teste` (nullable): um plano pode ter o seu próprio período de
demonstração. `null` = usa a definição global (`DefinicoesPlataforma::diasTestePadrao()`). Resolvido em
`Empresa::booted()` (hook `creating`) via `PlanoLicenca::diasTestePara(int $tenantId)`, antes do global.

## 7. Fora de âmbito (decisão explícita do utilizador, Fase 8)

Avaliados e **não implementados**, por não terem nenhuma base real no sistema actual:
- **Armazenamento, operações e integrações como dimensões de limite**: não existe, em lado nenhum do sistema,
  nenhuma medição de bytes armazenados por tenant, chamadas/operações contabilizadas, ou integrações activas por
  plano — implementá-los exigiria inventar a própria medição, não só o limite.
- **"Funcionalidades premium" distintas de módulos**: hoje um módulo está dentro ou fora do plano como um todo;
  não há granularidade interna.
- **Prorata/crédito numa troca de plano a meio do ciclo**: tocaria directamente em facturação — exige aprovação
  prévia (secção 25, ponto 958 do documento-fonte) antes de desenhar as regras, que não têm nenhum precedente no
  código actual.

## 8. Testes

`PlataformaClienteTest`, `LicencaCobrancaTest`, `CupomDescontoTest`, `PlanoAvancadoTest` (dias de teste por plano
e aviso de downgrade), e `CicloCompletoSaasTest` para o fluxo ponta-a-ponta.
