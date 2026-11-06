# POS — Fase F0 (alicerces e dívida técnica): relatório

Âmbito: integridade da base, FormRequests/Resources, mesas em Eloquent, auditoria e documentação, **sem mudar comportamento
visível**. Ramo `paridade-bd-front`. Backend em `backend/`.

## 1. Integridade na base de dados

Tudo vem de um único catálogo declarativo, `App\Support\POS\IntegridadePOS` (`definicoes()`, `indices()`), usado pelas migrações
e pelo comando de auditoria — a auditoria que decide é a mesma que o comando mostra.

**Regra de segurança (produção tem dados reais que não vimos):** cada restrição só é criada se a auditoria não encontrar
duplicados / valores fora da lista / órfãos. Se encontrar: **não cria, não falha o deploy**, regista `Log::warning` («restrição X NÃO
criada — N linha(s) impedem…») e deixa-a para depois. Nunca altera nem apaga dados. CHECK: criado `NOT VALID` e depois
`VALIDATE CONSTRAINT` (não bloqueia escritas); um CHECK que ficou `NOT VALID` é validado numa corrida seguinte se os dados o permitirem.
Uma linha inserida entre a auditoria e a criação não rebenta a migração (savepoint + aviso).

### Migrações novas (para ensaiar; todas idempotentes, ≥ 2026_12_05_090000)

| Migração | O que faz |
|---|---|
| `2026_12_05_090000_pos_integridade_unicidade` | 8 UNIQUE (índices únicos): `sessoes_pos (empresa_id, codigo_sessao)` e `(empresa_id, numero_z)`; `pedidos_lavandaria (empresa_id, numero_encomenda)`; `pagamentos_lavandaria (empresa_id, numero_recibo)`; `configuracoes_lavandaria (empresa_id)`; `pecas_lavandaria (empresa_id, codigo)`; `mesas_pos (terminal_pos_id, lower(nome))` (o serviço já ignorava maiúsculas); `contas_mesa_pos (venda_id) WHERE venda_id IS NOT NULL` |
| `2026_12_05_090100_pos_integridade_estados_check` | 15 CHECK (NULL continua válido): `sessoes_pos.estado/estado_contabilizacao/estado_liquidacao`; `liquidacoes_pos.estado/natureza_registo/alvo`; `senhas_pos_armazem.estado`; `estadias_hotel.estado/modo`; `pedidos_lavandaria.estado/modo_faturacao`; `pagamentos_lavandaria.estado/natureza_registo`; `reclamacoes_lavandaria.estado`. Valores = os que o código usa (constantes `RegrasLavandaria::ESTADOS`, `EstadiaHotel::*`, `ServicoPrestacaoContasPOS::NATUREZAS`, …) |
| `2026_12_05_090200_pos_integridade_chaves_estrangeiras` | FK `sessoes_pos.operador_id → utilizadores(id) ON DELETE SET NULL` (o nome fica em `nome_operador`; sem índice: só serve a eliminação de utilizadores) |
| `2026_12_05_090300_pos_indices_desempenho` | `CREATE INDEX CONCURRENTLY IF NOT EXISTS` (fora de transacção): `sessoes_pos (empresa_id, aberto_em DESC, id DESC)` (listagem de sessões), `estadias_hotel (empresa_id, terminal_pos_id) WHERE estado='ABERTA'` (mapa de quartos), `pedidos_lavandaria (empresa_id, estado, recebido_em DESC)` (listas/indicadores) |

Verificado na base viva: as restantes colunas `*_id` das tabelas POS **já têm FK e índice**; os pivôs `vendas_estadias_hotel` e
`pedidos_lavandaria_faturas` **já têm PK composta** (o inventário D-01 estava desactualizado nisso); `terminais_pos.tipo` e
`contas_mesa_pos.estado` já tinham CHECK; `configuracoes_pos (empresa_id)` já era único. Não há colunas de «tipo de movimento» nas
tabelas POS (os movimentos de caixa/tesouraria são de outros módulos).

### Comando de auditoria (só leitura)

`php artisan erp:pos:auditar-integridade [--json] [--database=pgsql_owner]` — lista cada restrição (existe / em falta / **bloqueada** /
não validada), quantas linhas a impedem e **ids** (até 20 por grupo), valores de estado fora da lista e códigos de numeração
repetidos; sem dados pessoais. Usa por omissão a ligação do dono (`SAAS_LIGACAO_DONO`) para ver todas as empresas apesar da RLS.
Sai com 1 se alguma restrição estiver bloqueada. **Antes do deploy: correr na cópia da produção** e corrigir os ids indicados
(renumerar/ajustar estado; nunca apagar vendas); depois `php artisan migrate`.

### Restrições deixadas ao F0b (lavandaria)

O F0b não criou nenhuma restrição de unicidade/CHECK (só colunas aditivas e ETL), por isso as da lavandaria **ficaram no meu lote**:
`pedidos_lavandaria.numero_encomenda`, `pagamentos_lavandaria.numero_recibo`, `configuracoes_lavandaria.empresa_id`,
`pecas_lavandaria.codigo` e os CHECK de estado/modo da lavandaria. Se o F0b/F5 acrescentar valores a estas enumerações
(p.ex. novos `modo`), tem de os somar em `IntegridadePOS::definicoes()` **antes** de os gravar.

## 2. Validação e respostas

- **FormRequests** (`App\Http\Requests\POS\{Pos,Prestacao,Hotelaria,Lavandaria,Armazem,Catalogo,Mesas}`, ~65 classes) em todos os
  12 controladores `Api\POS\*`. Base `PedidoPOS`: a permissão do catálogo do legado é verificada em `authorize()` **antes** da
  validação (403 continua a ganhar a 422, como no `exigir()` do controlador); as regras, mensagens e atributos são as mesmas, copiadas
  mecanicamente do controlador. Casos com permissão dependente do conteúdo (desconto/preço → `pos_desconto`, anular por estado)
  mantêm-se no controlador.
- **API Resources** (`App\Http\Resources\POS`): `TerminalPOSResource`, `SessaoPOSResource`, `VendaPOSResource`, `LiquidacaoPOSResource`,
  `EstadiaHotelResource`, `PecaLavandariaResource`, `ReclamacaoLavandariaResource` (base `RecursoPOS`, `preserveKeys = true`).
  O teste de contrato apanhou um desvio real: sem `preserveKeys` o Laravel reindexava mapas de chaves numéricas
  (`contagens_numerario: {"5000": 2}` passava a lista).
- **Teste de contrato** (`tests/Support/ContratoPOS.php`, fixture `tests/Fixtures/contrato_pos.json`, gancho de 3 linhas em
  `tests/TestCase.php`, inactivo por omissão): `POS_CONTRATO=verificar php artisan test --filter=…` exige que cada resposta 2xx dos controladores
  POS caiba na forma (chaves e tipos) gravada **antes** da refactorização; `POS_CONTRATO=gravar POS_CONTRATO_FICHEIRO=…` regrava. Os testes
  existentes fazem de cenários. Comparação final antes/depois: **82 rotas/estados 2xx, 0 diferenças** (nem chaves a mais nem a menos).
  Nenhuma asserção dos testes existentes foi alterada.

## 3. Mesas
`ServicoMesasPOS` passou de `DB::table` para `MesaPOS`/`ContaMesaPOS` (isolamento por empresa/RLS pelo trait, auditoria automática).
Datas e desconto saem em bruto (`getRawOriginal`) para o JSON não mudar. Nota: cada «Suspender» é agora uma linha de auditoria
(`Atualizou` em `contas_mesa_pos` com o diff de `linhas`/`versao`); se o volume incomodar, tirar o trait de `ContaMesaPOS` e
manter só a auditoria explícita de «Libertou a mesa».

## 4. Auditoria
O inventário estava desactualizado: **todos** os modelos POS (`TerminalPOS`, `SessaoPOS`, `ConfiguracaoPOS`, `LiquidacaoPOS`, `MesaPOS`,
`ContaMesaPOS`, `EstadiaHotel`, `PedidoLavandaria`, `PagamentoLavandaria`, `PecaLavandaria`, `ReclamacaoLavandaria`,
`ConfiguracaoLavandaria`) já herdam `Auditavel` das classes `…Base` geradas. Os meios de pagamento são JSON do terminal (auditados
com ele). `SenhaPOSArmazem` fica **sem** auditoria de propósito (linha de alta frequência), tal como os itens de venda (pertencem a Vendas).
`tests/Feature/POSAuditoriaTest` prova-o; o `ServicoMesasPOS` passou a gerar auditoria que antes não tinha.

## 5. Comentários e documentação
- `ServicoCaixaLavandaria`: removida a nota falsa «a Factura sai sem `pos`» (D-09) e o docblock duplicado de `emitirFatura`.
- `ServicoMesasPOS`: removida a referência à migração inexistente `2026_10_06_100100` e a «até os models serem gerados» (D-04).
- `ServicoConfigLavandaria` (D-05): a coluna `fator_prazo_urgencia` **já é `numeric(6,4)`** (o comentário dizia «inteiro»); o serviço guarda
  percentagem (50) e lê percentagem ou fracção. Documentado; **não** convertido para fracção (migrar valores já gravados é risco sem ganho).
- `PosCargaMigracao` (D-15): documentado que «pos-carga» = pós-carga, não o módulo POS; **nome mantido** (scripts de deploy usam-no).
- `DICIONARIO_DADOS.md`: URLs de API do POS corrigidos (`/api/pos/definicoes`, `/lavandaria/ordens`, `/lavandaria/definicoes`).

## 6. Testes novos (todos verdes)
`POSIntegridadeTest` (11: base limpa com tudo criado; UNIQUE por empresa; duplicados → não cria/não falha/log/ids; só cria depois de corrigir;
idempotência; CHECK com valor inválido; NOT VALID → VALIDATE; FK com órfãos; migrações idempotentes e não falham com dados maus; comando
de auditoria só de leitura, exit code e JSON), `POSFormRequestsTest` (403 antes de 422; mensagens em português), `POSAuditoriaTest`,
e mais um caso em `POSMesasTest` (auditoria das mesas). Suite filtrada `POS|Pos|Lavandaria|Hotelaria|Mesa|R3VendasTest|CorrecoesPosMigracaoTest`:
**158 verdes**, e também com `POS_CONTRATO=verificar`. Pint: 1707 ficheiros OK.

## 7. Riscos e o que fica para o coordenador
1. **Ensaiar na cópia da produção**: `erp:pos:auditar-integridade` primeiro; as migrações não falham mas podem deixar restrições por criar
   (ver o log). Candidatos prováveis a dados sujos: `numero_recibo` repetido (FR copiada para `pagamentos_lavandaria` + recibos migrados),
   `pecas_lavandaria.codigo` repetido ou nulo (nulos passam), estados/modos em inglês vindos do ETL, `operador_id` de utilizadores do legado sem
   correspondência (FK).
2. `CREATE UNIQUE INDEX` (não concorrente) bloqueia escritas da tabela durante a criação — tabelas pequenas, instantâneo; fazer fora de hora de ponta.
3. As migrações dependem de `App\Support\POS\IntegridadePOS`: se o catálogo mudar no futuro, migrações antigas mudam de comportamento em bases novas
   (aceitável: são idempotentes e aditivas).
4. Ficou por fazer (não pedido ou fora de F0): `LavandariaController` e demais mantêm lógica de listagem/filtros no controlador (só validação e
   respostas principais foram extraídas); Resources não cobrem respostas que o serviço já devolve como arrays construídos (relatórios, X/Z, pré-visualizações).
