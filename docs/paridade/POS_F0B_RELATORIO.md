# POS — Fase F0b: preservar os dados da lavandaria

Objectivo: nenhuma carga ETL voltar a perder preços, tamanhos e modos da lavandaria, e dar ao administrador o meio de medir (e reparar) o que a produção já perdeu. **Não muda comportamento visível**: o ecrã e a regra de preço em vigor ficam como estão (a regra nova é a F5).

## 1. Auditoria da migração legado → novo: o que se perdia

Fonte: `ServicoMigracaoLegado` (carga a partir do contrato `database/legado/esquema.json`) + `ServicoMigracaoLavandaria::normalizar()` (pós-carga) contra o modelo do legado (`js/lavandaria.js`, `POS_LEGADO_INVENTARIO.md` §2.D.2).

| # | Dado do legado | O que acontecia | Gravidade |
|---|---|---|---|
| 1 | `lav_pieces.lav_prices[] {mode,size,price}` (opções Peça/Kg/Tamanho; a 1.ª manda) | **Perdia-se**: a coluna não existia no contrato, logo a carga não a lia. Só ficavam `unit`+`price` (espelho da 1.ª opção). O `etl_linhas_legado` é apagado no fim, por isso **só o backup Dexie original a recupera**. | Perda real |
| 2 | `lav_pieces.service_prices[].mode/size` (matriz serviço × opção) | Ficavam no JSON com chaves em inglês (`mode`,`size`); a `normalizar` só traduzia `product_id`/`price`. O sistema novo ignora-as e **usa a primeira entrada do serviço**, seja de que opção for (risco de preço trocado em peças com várias opções: tratar na F5). | Mal traduzido |
| 3 | `lav_orders.items[].size` (nome do tamanho) e `unit` = modo (`PECA`/`KG`/`TAMANHO`) | `unit` virava `unidade` (mantendo `TAMANHO`, que o sistema novo não conhece); `size` ficava cru. Nada os lia. | Mal traduzido |
| 4 | `pos_terminals.lav_fr_counters` (contador das FR-LAV por ano) | **Perdia-se** (só `os`, `rc`, `ft` estavam no contrato). | Perda real |
| 5 | `lav_orders`, `lav_payments`, `lav_claims`, `lav_settings`, restantes campos de `lav_pieces` | Mapeados 1-para-1 (confirmado coluna a coluna no `esquema.json`); JSON `items/extras/pickup/delivery/history/assignments/pos_payments` traduzidos pela `normalizar`. `invoice_ids` → `faturas_ids_legado` + pivô `pedidos_lavandaria_faturas`. | OK |
| 6 | `lav_prices` entradas `TAMANHO` sem nome | O legado também as ignorava (`opcoesPeca`); agora são relatadas como ocorrência ao completar. | Informativo |

Também se constatou (não corrigido aqui, é da F5): `ServicoTabelasLavandaria::precosServico` regrava `precos_servico` só com `{produto_id, preco}` quando uma peça é editada no ecrã actual (apaga o modo/tamanho das entradas); o importador de preços por serviço também. As opções novas (`opcoes_preco`) **não** são tocadas por `guardarPeca`.

## 2. Migração aditiva

`backend/database/migrations/2026_12_06_090000_lavandaria_opcoes_preco.php` — idempotente (`hasColumn`), só colunas anuláveis, sem alterar tipos nem apagar; as tabelas já têm RLS/GRANT (as colunas herdam).

- `pecas_lavandaria.opcoes_preco jsonb` — `[{modo: PECA|KG|TAMANHO, tamanho, preco}]` (legado `lav_prices`; a 1.ª opção manda).
- `pecas_lavandaria.opcoes_preco_origem varchar(12)` — `LEGADO` (lida de `lav_prices`), `DERIVADA` (reconstruída de `unidade`+`preco`, como fazia `opcoesPeca` do legado; a confirmar com o backup) ou `NOVO` (gravada no sistema novo, F5).
- `terminais_pos.lavandaria_contadores_fr jsonb` — legado `lav_fr_counters`.

Sem tabela nova: os preços por serviço e opção vivem em `precos_servico[]` (agora com `modo`/`tamanho` por entrada) e as linhas das ordens guardam `modo` e `tamanho` dentro de `itens`. Contrato (`esquema.json`), `PecaLavandariaBase`/`TerminalPOSBase` (fillable + cast `array`) e `docs/dicionario/DICIONARIO_DADOS.md` actualizados. Como a lista de peças devolve o modelo, `opcoes_preco`/`opcoes_preco_origem` já aparecem na API (campos novos; o frontend ignora-os até à F5). Requests/Resources dos controladores POS ficam com a F0.

Numeração: a F0 já criou os UNIQUE (`2026_12_05_090000_pos_integridade_unicidade`, auto-protegidos pela auditoria `erp:pos:auditar-integridade`) para `numero_encomenda`, `numero_recibo`, `configuracoes_lavandaria(empresa_id)` e `pecas_lavandaria(empresa_id, codigo)`; **não acrescentei UNIQUE nenhum**. A minha auditoria também detecta duplicados (O11, G08, P08, T02) para o administrador.

## 3. ETL corrigido (`ServicoMigracaoLavandaria`)

- `normalizar()` (corre no fim de `erp:migrar-backup-legado`): traduz `size`→`tamanho` e acrescenta `modo` (= `unit` legado) às linhas; `service_prices` `mode/size`→`modo/tamanho`; traduz `lav_prices` em bruto (já no contrato como `opcoes_preco`, origem `LEGADO`) e, sem `lav_prices` no backup, deriva a opção única (`DERIVADA`). `lav_fr_counters` entra pela carga.
- **Modo completar** — `ServicoMigracaoLavandaria::completar(?array $legado, bool $simular)`, idempotente e re-executável sobre dados já migrados, numa transacção (`--simular` faz ROLLBACK), auditado (`CORRECAO_MIGRACAO`):
  - peças: `precos_servico` (só chaves por traduzir); `opcoes_preco` só se faltar, ou se for `DERIVADA` e o backup trouxer `lav_prices` (substitui a reconstrução); **nunca** toca `LEGADO`/`NOVO`;
  - ordens: só as que ainda têm chaves do legado nas linhas (ordens do sistema novo não se tocam); `valor` já existente nunca muda (os totais das ordens antigas mantêm-se);
  - terminais: `lavandaria_contadores_fr` só se estiver nulo;
  - verifica empresa e código do backup contra a base antes de aplicar `lav_prices`/`lav_fr_counters` (ids são os do backup); conflitos, `lav_prices` inválidos, opções TAMANHO sem nome e JSON ilegíveis vão para `erros[]` sem parar o resto.

## 4. Comandos

| Comando | Para quê |
|---|---|
| `php artisan erp:lavandaria:auditar-migracao [--empresa=ID] [--saida=PASTA] [--json] [--database=]` | Auditoria **só de leitura** (transacção READ ONLY, ligação do dono da base como `erp:pos:auditar-integridade`). Escreve `storage/app/private/auditorias/lavandaria_migracao_<data>.json` e `.md` com contagens e ids (≤20 por verificação) por empresa; **sem dados pessoais**. Sai com 1 se houver ERRO. |
| `php artisan erp:lavandaria:completar-migracao [<backup.json>] [--simular] [--json] [--database=]` | Modo completar. Com o backup Dexie original lê `lav_prices` e `lav_fr_counters`; sem ele só traduz e deriva. |

Verificações da auditoria (código → significado): `P01` unidade fora de PECA/KG; `P02` peça sem `opcoes_preco`; `P03` opções derivadas (a confirmar); `P04` `precos_servico` por traduzir; `P05` preço por serviço de serviço inexistente; `P06` **provável perda de opções** (service_prices com Kg/Tamanho sem `lav_prices` lido); `P07` peça sem preço; `P08` código de peça duplicado; `O01–O18` ordens (sem número, estado/modo inválido, sem cliente/terminal/datas, sem itens, extras/domicílio/histórico/linhas por traduzir, `size` por traduzir, linha sem qtd/preço, valor ≠ qtd×preço, estado ≠ estado derivado das linhas, facturas do legado sem ligação, número de ordem duplicado, etiquetas repetidas); `G01–G08` recebimentos (sem/duplicado nº de recibo, sem ordem válida, estado/natureza inválidos, meios por traduzir, montante ≠ soma dos meios); `C01–C04` totais (recebido > total da ordem, facturado > total, recebido > facturado em ordem facturada na recepção, ordem anulada com recebimentos); `R01–R03` reclamações; `T01–T04` definições e contadores. Em `estatisticas`: peças com mais de uma opção / com opção Tamanho / com opção Kg / com preço por serviço e opção — é o número que decide a urgência da F5.

## 5. Como correr em produção e no teste (o coordenador)

1. Deploy do código (migração aditiva corre no `migrate`; não bloqueia nem reescreve dados).
2. Auditoria de base: `php artisan erp:lavandaria:auditar-migracao` → guardar `.json`/`.md`; ver `conclusao`, `estatisticas` e as verificações `P02`, `P06`, `O14`, `O15`, `C01`, `C02`, `O11`, `G08`.
3. Ensaiar: `php artisan erp:lavandaria:completar-migracao /caminho/backup_dexie.json --simular` (ROLLBACK); rever `erros`.
4. Aplicar: o mesmo sem `--simular`. Se o backup já não existir, correr sem ficheiro: as peças ficam `DERIVADA` e a auditoria `P06` lista as que provavelmente tinham várias opções (reler do backup antes da F5; não reconstituir à mão).
5. Re-correr a auditoria: `O14/O15/P04` desaparecem; `P03` desaparece quando houver backup; `C0x`/`O11`/`G08` são dados do legado a decidir caso a caso (nunca apagar vendas).

## 6. Testes

`backend/tests/Feature/MigracaoLavandariaF0bTest.php` (11 testes, fixtures geradas por código): tradução de preços/tamanhos/modos (peça com 3 opções + opção sem nome descartada, matriz serviço × opção, linhas TAMANHO/KG), idempotência (incluindo totais das ordens antigas iguais), não sobrescrita (peça `NOVO`/`LEGADO`, ordem do sistema novo, terminal com contador), `DERIVADA` substituída pelo backup, `normalizar()` com `lav_prices` em bruto, relato de erros, `--simular`, leitura do backup Dexie, os dois comandos e a auditoria (perdas, duplicados, totais; sem dados pessoais no JSON).

## 7. O que fica para a F5

Regra de preço por opção (`opcoes_preco`) e matriz `precos_servico[modo,tamanho]`; ecrã de peças com «Cobrar por»; corrigir `precosServico`/importador para preservar `modo`/`tamanho`; `precoPecaServico` passar a escolher a entrada da opção (hoje usa a primeira do serviço); linhas novas com `modo`/`tamanho`; `opcoes_preco_origem = NOVO` ao editar.
