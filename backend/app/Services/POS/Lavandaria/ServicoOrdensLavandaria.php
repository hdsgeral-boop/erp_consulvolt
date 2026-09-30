<?php

namespace App\Services\POS\Lavandaria;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\PagamentoLavandaria;
use App\Models\PecaLavandaria;
use App\Models\PedidoLavandaria;
use App\Models\Produto;
use App\Models\ReclamacaoLavandaria;
use App\Models\Terceiro;
use App\Models\TerminalPOS;
use App\Services\Logistica\ServicoArmazens;
use App\Services\Logistica\ServicoGuiasSaida;
use App\Services\Sistema\ServicoNumeracao;
use App\Services\Vendas\CalculadoraDocumento;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ordens de serviço da lavandaria e alfaiataria (js/lavandaria.js:551-692, 927-1072, 1591-1876), com as correcções:
 *   - número OS/<terminal>/<ano>/<n> por ServicoNumeracao, a continuar o contador do legado (lavandaria.js:645 podia recuar/duplicar);
 *   - registo da ordem, documento e recibo numa só transacção (o legado gravava em passos soltos);
 *   - regras de validação no servidor: estado de entrada obrigatório e descrição do dano, preço da tabela (só a estimativa dos
 *     orçamentos é indicada), morada da recolha/entrega, adiantamento mínimo do Consumidor Final;
 *   - anular por «alterar estado» exige lav_anular (no legado bastava lav_ordens — contornava a permissão de anular);
 *   - materiais de alfaiataria por guia de consumo, com saída ao custo médio e CMV lançado (ADR-043); sem stock, recusa
 *     (o legado perguntava e cortava o stock a zero, perdendo a diferença);
 *   - orçamento aprovado com preço unitário a 2 casas (o legado dividia o valor pela quantidade em vírgula flutuante).
 */
final class ServicoOrdensLavandaria
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
        private readonly ServicoConfigLavandaria $config,
        private readonly ServicoTabelasLavandaria $tabelas,
        private readonly ServicoCaixaLavandaria $caixa,
        private readonly ServicoGuiasSaida $guias,
        private readonly ServicoArmazens $armazens,
    ) {}

    /**
     * Registo da ordem na recepção (lavConfirmarOrdem / lavGravarOrdem, lavandaria.js:592-692).
     *
     * @param  array<string, mixed>  $d  cliente_id, modo_faturacao, urgente, observacoes, itens[], recolha?, entrega?, valor?, pagamentos?
     */
    public function registar(int $sessaoId, array $d): array
    {
        $cfg = $this->config->obter();
        $cliente = Terceiro::query()->findOrFail($d['cliente_id']);
        if (! $cliente->eCliente()) {
            throw new ErroNegocio('O terceiro indicado não é um cliente.', 'CLIENTE_INVALIDO', 422);
        }
        $modo = $d['modo_faturacao'] ?? 'ENTREGA';
        $itens = $this->prepararItens($d['itens'] ?? []);
        $domicilio = [];
        foreach (['recolha' => 'valor_taxa_recolha', 'entrega' => 'valor_taxa_entrega'] as $k => $taxaPadrao) {
            $x = $d[$k] ?? [];
            $ativa = ! empty($x['ativa']);
            if ($ativa && trim((string) ($x['morada'] ?? '')) === '') {
                throw new ErroNegocio("Indique a morada da {$k} ao domicílio.", 'MORADA_EM_FALTA', 422, ['campo' => $k]);
            }
            $taxa = $ativa ? RegrasLavandaria::dinheiro($x['taxa'] ?? $cfg[$taxaPadrao]) : null;
            $domicilio[$k] = ['ativa' => $ativa, 'morada' => $ativa ? trim((string) $x['morada']) : '', 'data' => $x['data'] ?? null, 'taxa' => $taxa];
        }

        // calcularNova (lavandaria.js:551-562)
        $servicos = RegrasLavandaria::soma(array_map(fn ($i) => ['total' => $i['valor']], $itens));
        $urgencia = ! empty($d['urgente']) ? CalculadoraDocumento::arredondar(bcdiv(bcmul($servicos, $cfg['percentagem_urgencia'], 8), '100', 8)) : '0.00';
        $prazo = max(array_map(fn ($i) => (int) $i['dias_entrega'], $itens));
        $dias = ! empty($d['urgente']) ? max(1, (int) ceil($prazo * $cfg['fator_prazo_urgencia'])) : max(1, $prazo);

        return DB::transaction(function () use ($sessaoId, $d, $cfg, $cliente, $modo, $itens, $domicilio, $urgencia, $dias) {
            [$s, $t] = $this->caixa->sessaoValida($sessaoId);
            $ano = (int) now()->format('Y');
            $prefixo = "OS/{$t->codigo}/{$ano}/";
            $n = $this->numeracao->proximo($this->contexto->obrigatorio(), "lav_os:{$t->id}:{$ano}", fn () => max((int) ($t->lavandaria_contadores_os[(string) $ano] ?? 0),
                ServicoCaixaLavandaria::maiorSufixo(PedidoLavandaria::query()->where('numero_encomenda', 'like', $prefixo.'%')->pluck('numero_encomenda')->all(), $prefixo)));
            $etiqueta = sprintf('%s%05d', $t->codigo, $n);
            $seq = 0;
            foreach ($itens as $k => $i) {
                $itens[$k]['linha_id'] = $k + 1;
                $etiquetas = [];
                for ($e = RegrasLavandaria::etiquetas($i); $e > 0; $e--) {
                    $etiquetas[] = sprintf('%s-%02d', $etiqueta, ++$seq);
                }
                $itens[$k]['etiquetas'] = $etiquetas;
                $itens[$k]['numero_pecas'] = count($etiquetas);
            }
            $extras = [];
            $extra = function (string $chave, string $nome, string $valor) use (&$extras) {
                $p = $this->tabelas->produtoExtra($chave);
                $extras[] = ['id' => $chave.'-'.(count($extras) + 1), 'chave' => $chave, 'produto_id' => $p->id, 'nome' => $nome,
                    'valor' => RegrasLavandaria::valorComIva(1, $valor, $p->taxa_imposto), 'taxa_imposto' => (string) $p->taxa_imposto];
            };
            if (bccomp($urgencia, '0', 2) > 0) {
                $extra('URGENCIA', 'Taxa de urgência ('.(float) $cfg['percentagem_urgencia'].'%)', $urgencia);
            }
            foreach (['recolha' => ['RECOLHA', 'Recolha ao domicílio'], 'entrega' => ['ENTREGA', 'Entrega ao domicílio']] as $k => [$chave, $nome]) {
                if ($domicilio[$k]['ativa'] && bccomp((string) $domicilio[$k]['taxa'], '0', 2) > 0) {
                    $extra($chave, "{$nome} · {$domicilio[$k]['morada']}", $domicilio[$k]['taxa']);
                }
            }
            $total = bcadd(RegrasLavandaria::soma(array_map(fn ($i) => ['total' => $i['valor']], $itens)), RegrasLavandaria::soma($extras, 'valor'), 2);
            $valor = RegrasLavandaria::dinheiro($d['valor'] ?? 0);
            $minimo = RegrasLavandaria::consumidorFinal($cliente)
                ? CalculadoraDocumento::arredondar(bcdiv(bcmul($total, $cfg['percentagem_adiantamento'], 8), '100', 8)) : '0.00';
            if (bccomp($valor, $minimo, 2) < 0) {   // lavandaria.js:635
                throw new ErroNegocio("O Consumidor Final tem de pagar um adiantamento mínimo de {$minimo}.", 'ADIANTAMENTO_MINIMO', 422, ['minimo' => $minimo, 'total' => $total]);
            }
            if (bccomp($valor, $total, 2) > 0) {
                throw new ErroNegocio('O valor a receber não pode exceder o total da ordem.', 'VALOR_EXCEDE_TOTAL', 422, ['total' => $total]);
            }

            $o = new PedidoLavandaria([
                'numero_encomenda' => sprintf('%s%05d', $prefixo, $n), 'terminal_pos_id' => $t->id, 'codigo_terminal' => $t->codigo, 'cliente_id' => $cliente->id,
                'recebido_em' => now(), 'recebido_por' => Auth::user()?->nome_utilizador, 'sessao_rececao_id' => $s->id, 'modo_faturacao' => $modo,
                'urgente' => ! empty($d['urgente']), 'data_prometida' => now()->addDays($dias), 'observacoes' => $d['observacoes'] ?? null,
                'recolha' => $domicilio['recolha'], 'entrega' => $domicilio['entrega'], 'extras' => $extras, 'itens' => $itens, 'historico_alteracoes' => [], 'atribuicoes' => [],
            ]);
            $danos = count(array_filter($itens, fn ($i) => $i['estado_entrada'] !== RegrasLavandaria::BOM_ESTADO));
            ServicoCaixaLavandaria::historico($o, 'Ordem recebida ('.count($itens)." linha(s), {$seq} etiqueta(s))".($danos ? "; {$danos} peça(s) com danos à entrada" : '').'.');
            $o->estado = RegrasLavandaria::estadoOrdem($itens);
            $o->save();

            $facturar = $modo === 'RECEPCAO' || (bccomp($valor, '0', 2) > 0 && $cfg['faturar_no_adiantamento']);
            $r = $this->caixa->receberComFaturacao($o, $facturar ? RegrasLavandaria::linhasPorFacturar($o->itens, $o->extras) : [], $valor, $d['pagamentos'] ?? [], $s, $t);
            $o->estado = RegrasLavandaria::estadoOrdem($o->itens);
            $o->save();
            $this->caixa->actualizarFaturas($o);

            return $r + ['pedido' => $o->refresh()];
        });
    }

    /** Acção numa linha: INICIAR (RECEBIDA → EM_EXECUCAO) ou PRONTA (EM_EXECUCAO → PRONTA) — aplicarAccaoItem, lavandaria.js:927-935. */
    public function accaoLinhas(int $pedidoId, ?int $linhaId, string $accao, ?string $executadoPor): PedidoLavandaria
    {
        if (! in_array($accao, ['INICIAR', 'PRONTA'], true)) {
            throw new ErroNegocio('Acção inválida (INICIAR ou PRONTA).', 'ACCAO_INVALIDA', 422);
        }

        return DB::transaction(function () use ($pedidoId, $linhaId, $accao, $executadoPor) {
            $o = $this->emCurso($pedidoId);
            $itens = $o->itens;
            $agora = now()->toIso8601String();
            $quem = trim((string) $executadoPor) ?: ($o->nome_atribuido ?: Auth::user()?->nome_utilizador);
            $n = 0;
            foreach ($itens as $k => $i) {
                if ($linhaId !== null && (int) $i['linha_id'] !== $linhaId) {
                    continue;
                }
                if ($accao === 'INICIAR' && $i['estado'] === 'RECEBIDA') {
                    $itens[$k] = array_merge($i, ['estado' => 'EM_EXECUCAO', 'iniciado_em' => $agora, 'executado_por' => $quem]);
                    ServicoCaixaLavandaria::historico($o, trim(($i['nome_peca'] ?? '')." {$i['nome']}").": execução iniciada por {$quem}.");
                    $n++;
                } elseif ($accao === 'PRONTA' && $i['estado'] === 'EM_EXECUCAO') {
                    $itens[$k] = array_merge($i, ['estado' => 'PRONTA', 'pronta_em' => $agora]);
                    ServicoCaixaLavandaria::historico($o, trim(($i['nome_peca'] ?? '')." {$i['nome']}").': pronta.');
                    $n++;
                }
            }
            if (! $n) {
                throw new ErroNegocio($accao === 'INICIAR' ? 'Não há peças recebidas para iniciar.' : 'Não há peças em execução para marcar prontas.', 'SEM_LINHAS_EM_CONDICOES', 422);
            }
            $o->itens = $itens;
            $o->estado = RegrasLavandaria::estadoOrdem($itens);
            $o->save();

            return $o;
        });
    }

    /**
     * Aprovação/recusa de orçamentos, individual ou em massa (lavConfirmarOrcamento / lavConfirmarOrcMassa, lavandaria.js:985-999, 1725-1785).
     *
     * @param  list<array{pedido_id: int, linha_id: int, valor?: mixed}>  $linhas
     * @return array{tratados: int, ignorados: list<string>}
     */
    public function decidirOrcamentos(string $decisao, array $linhas, ?string $canal, ?string $nota): array
    {
        $aprovar = $decisao === 'APROVAR';
        if (! $aprovar && $decisao !== 'RECUSAR') {
            throw new ErroNegocio('Decisão inválida (APROVAR ou RECUSAR).', 'DECISAO_INVALIDA', 422);
        }
        $nota = trim((string) $nota);
        if (! $aprovar && mb_strlen($nota) < 3) {
            throw new ErroNegocio('Indique o motivo da recusa.', 'MOTIVO_OBRIGATORIO', 422);
        }
        if ($aprovar && ($sem = count(array_filter($linhas, fn ($l) => (float) ($l['valor'] ?? 0) <= 0)))) {
            throw new ErroNegocio("Indique o valor aprovado em todas as linhas ({$sem} sem valor).", 'VALOR_INVALIDO', 422);
        }

        return DB::transaction(function () use ($aprovar, $linhas, $canal, $nota) {
            $tratados = 0;
            $ignorados = [];
            $agora = now()->toIso8601String();
            $quem = Auth::user()?->nome_utilizador;
            foreach (collect($linhas)->groupBy('pedido_id') as $pid => $lista) {
                $o = PedidoLavandaria::query()->lockForUpdate()->find($pid);
                if (! $o || in_array($o->estado, ['ENTREGUE', 'ANULADA'], true)) {
                    $ignorados[] = ($o?->numero_encomenda ?? $pid).': ordem já não está em curso';

                    continue;
                }
                $itens = $o->itens;
                $n = 0;
                foreach ($lista as $x) {
                    $k = collect($itens)->search(fn ($i) => (int) $i['linha_id'] === (int) $x['linha_id']);
                    if ($k === false || $itens[$k]['estado'] !== 'ORCAMENTO') {
                        $ignorados[] = "{$o->numero_encomenda} · ".($k === false ? "linha {$x['linha_id']}" : $itens[$k]['nome']).': orçamento já tratado';

                        continue;
                    }
                    $i = $itens[$k];
                    $rotulo = trim(($i['nome_peca'] ?? '')." {$i['nome']}");
                    if ($aprovar) {
                        $preco = CalculadoraDocumento::arredondar(bcdiv(RegrasLavandaria::dinheiro($x['valor']), (string) $i['quantidade'], 8));
                        $valor = RegrasLavandaria::valorComIva($i['quantidade'], $preco, $i['taxa_imposto']);
                        $itens[$k] = array_merge($i, ['estado_orcamento' => 'APROVADO', 'valor_orcamento' => RegrasLavandaria::dinheiro($x['valor']), 'preco' => $preco, 'valor' => $valor,
                            'canal_orcamento' => $canal, 'nota_orcamento' => $nota, 'orcamento_aprovado_em' => $agora, 'orcamento_aprovado_por' => $quem, 'estado' => 'RECEBIDA']);
                        ServicoCaixaLavandaria::historico($o, "{$rotulo}: orçamento de ".ServicoCaixaLavandaria::kz($valor)." aprovado ({$canal})".($nota ? " — {$nota}" : '').'.');
                    } else {
                        $itens[$k] = array_merge($i, ['estado' => 'ANULADA', 'estado_orcamento' => 'RECUSADO', 'motivo_cancelamento' => $nota,
                            'orcamento_recusado_em' => $agora, 'orcamento_recusado_por' => $quem]);
                        ServicoCaixaLavandaria::historico($o, "{$rotulo}: orçamento recusado — {$nota}.");
                    }
                    $n++;
                }
                if ($n) {
                    $o->itens = $itens;
                    $o->estado = RegrasLavandaria::estadoOrdem($itens);
                    $o->save();
                    $tratados += $n;
                }
            }

            return ['tratados' => $tratados, 'ignorados' => $ignorados];
        });
    }

    /**
     * Alteração de estado, individual ou em massa (lavConfirmarEstado, lavandaria.js:1818-1876). A entrega faz-se sempre pela entrega.
     *
     * @param  list<int>  $ids
     * @return array{alteradas: int, ignoradas: list<string>}
     */
    public function alterarEstado(array $ids, string $novo, ?int $colaboradorId, ?string $motivo): array
    {
        if (! in_array($novo, ['EM_EXECUCAO', 'PRONTA', 'RECEBIDA', 'ANULADA'], true)) {
            throw new ErroNegocio('Novo estado inválido (EM_EXECUCAO, PRONTA, RECEBIDA ou ANULADA).', 'ESTADO_INVALIDO', 422);
        }
        $motivo = trim((string) $motivo);
        if (in_array($novo, ['RECEBIDA', 'ANULADA'], true) && mb_strlen($motivo) < 3) {
            throw new ErroNegocio('Indique o motivo.', 'MOTIVO_OBRIGATORIO', 422);
        }
        $exec = $colaboradorId ? Colaborador::query()->findOrFail($colaboradorId)->nome_completo : null;

        return DB::transaction(function () use ($ids, $novo, $exec, $motivo) {
            $alteradas = 0;
            $ignoradas = [];
            $agora = now()->toIso8601String();
            $rotulos = ['EM_EXECUCAO' => 'Em execução', 'PRONTA' => 'Pronta', 'RECEBIDA' => 'Recebida (reverter execução)', 'ANULADA' => 'Anulada'];
            foreach (array_unique($ids) as $id) {
                $o = PedidoLavandaria::query()->lockForUpdate()->find($id);
                if (! $o) {
                    continue;
                }
                if (in_array($o->estado, ['ENTREGUE', 'ANULADA'], true)) {
                    $ignoradas[] = "{$o->numero_encomenda}: ".mb_strtolower($o->estado);

                    continue;
                }
                $itens = $o->itens;
                $n = 0;
                if ($novo === 'ANULADA') {
                    if ($this->caixa->pagamentos($o)->isNotEmpty() || $this->caixa->faturas($o)->isNotEmpty()) {
                        $ignoradas[] = "{$o->numero_encomenda}: tem documentos de venda ou recebimentos";

                        continue;
                    }
                    if (collect($itens)->contains(fn ($i) => $i['estado'] === 'ENTREGUE')) {
                        $ignoradas[] = "{$o->numero_encomenda}: tem peças entregues";

                        continue;
                    }
                    $itens = array_map(fn ($i) => array_merge($i, ['estado' => 'ANULADA']), $itens);
                    $o->motivo_cancelamento = $motivo;
                    $n = count($itens);
                } else {
                    foreach ($itens as $k => $i) {
                        if ($novo === 'EM_EXECUCAO' && $i['estado'] === 'RECEBIDA') {
                            $itens[$k] = array_merge($i, ['estado' => 'EM_EXECUCAO', 'iniciado_em' => $agora,
                                'executado_por' => $exec ?? ($o->nome_atribuido ?: ($i['executado_por'] ?? Auth::user()?->nome_utilizador))]);
                            $n++;
                        } elseif ($novo === 'PRONTA' && in_array($i['estado'], ['RECEBIDA', 'EM_EXECUCAO'], true)) {
                            $itens[$k] = array_merge($i, ['estado' => 'PRONTA', 'pronta_em' => $agora, 'iniciado_em' => $i['iniciado_em'] ?? $agora,
                                'executado_por' => $exec ?? ($i['executado_por'] ?? ($o->nome_atribuido ?: Auth::user()?->nome_utilizador))]);
                            $n++;
                        } elseif ($novo === 'RECEBIDA' && in_array($i['estado'], ['EM_EXECUCAO', 'PRONTA'], true)) {
                            $itens[$k] = array_merge($i, ['estado' => 'RECEBIDA', 'iniciado_em' => null, 'pronta_em' => null]);
                            $n++;
                        }
                    }
                    if (! $n) {
                        $ignoradas[] = "{$o->numero_encomenda}: sem peças em condições (".mb_strtolower($o->estado).')';

                        continue;
                    }
                }
                ServicoCaixaLavandaria::historico($o, "Estado alterado para {$rotulos[$novo]}".(count($ids) > 1 ? ' (alteração em massa)' : '')." — {$n} peça(s)".($motivo ? " — {$motivo}" : '').'.');
                $o->itens = $itens;
                $o->estado = $novo === 'ANULADA' ? 'ANULADA' : RegrasLavandaria::estadoOrdem($itens);
                $o->save();
                $alteradas++;
            }

            return ['alteradas' => $alteradas, 'ignoradas' => $ignoradas];
        });
    }

    /**
     * Atribuição (ou retirada) do responsável, individual ou em massa (lavConfirmarAtribuicao, lavandaria.js:1622-1664).
     *
     * @param  list<int>  $ids
     */
    public function atribuir(array $ids, ?int $colaboradorId, bool $retirar, ?string $data, string $aplicar, ?string $nota): array
    {
        $colab = null;
        if (! $retirar) {
            $colab = Colaborador::query()->find($colaboradorId ?? 0);
            if (! $colab || $colab->estado === 'INACTIVO') {
                throw new ErroNegocio('Seleccione um colaborador activo.', 'COLABORADOR_INVALIDO', 422);
            }
        }
        $data = $data ?: now()->toDateString();
        if ($data > now()->toDateString()) {
            throw new ErroNegocio('A data de atribuição não pode ser futura.', 'DATA_FUTURA', 422);
        }
        $quando = $data === now()->toDateString() ? now()->toIso8601String() : "{$data}T12:00:00+01:00";
        $nota = trim((string) $nota);

        return DB::transaction(function () use ($ids, $colab, $retirar, $data, $quando, $aplicar, $nota) {
            $ordens = PedidoLavandaria::query()->whereIn('id', $ids)->whereNotIn('estado', ['ENTREGUE', 'ANULADA'])->lockForUpdate()->get();
            if ($ordens->isEmpty()) {
                throw new ErroNegocio('As ordens seleccionadas já estão entregues ou anuladas.', 'SEM_ORDENS', 422);
            }
            $antes = $ordens->filter(fn ($o) => $data < $o->recebido_em?->toDateString());
            if (! $retirar && $antes->isNotEmpty()) {
                throw new ErroNegocio('A data de atribuição é anterior à recepção de: '.$antes->pluck('numero_encomenda')->implode(', ').'.', 'DATA_ANTERIOR_RECECAO', 422);
            }
            $quem = Auth::user()?->nome_utilizador;
            $n = 0;
            foreach ($ordens as $o) {
                $anterior = $o->nome_atribuido;
                if ($retirar) {
                    if (! $o->colaborador_atribuido_id) {
                        continue;
                    }
                    $o->fill(['colaborador_atribuido_id' => null, 'nome_atribuido' => null, 'atribuido_em' => null, 'atribuido_por' => $quem]);
                    ServicoCaixaLavandaria::historico($o, "Responsável retirado ({$anterior})".($nota ? " — {$nota}" : '').'.');
                } else {
                    $o->fill(['colaborador_atribuido_id' => $colab->id, 'nome_atribuido' => $colab->nome_completo, 'atribuido_em' => $quando, 'atribuido_por' => $quem,
                        'nota_atribuicao' => $nota ?: null]);
                    if ($aplicar === 'PENDENTES') {
                        $o->itens = array_map(fn ($i) => in_array($i['estado'], ['ORCAMENTO', 'RECEBIDA', 'EM_EXECUCAO'], true)
                            ? array_merge($i, ['executado_por' => $colab->nome_completo]) : $i, $o->itens);
                    }
                    ServicoCaixaLavandaria::historico($o, ($anterior && $anterior !== $colab->nome_completo ? "Reatribuída de {$anterior} a" : 'Atribuída a')
                        ." {$colab->nome_completo} em ".date('d/m/Y', strtotime($quando)).($nota ? " — {$nota}" : '').'.');
                }
                $o->atribuicoes = [...($o->atribuicoes ?? []), ['colaborador_id' => $colab?->id, 'nome' => $colab?->nome_completo, 'atribuido_em' => $retirar ? null : $quando,
                    'em' => now()->toIso8601String(), 'por' => $quem, 'nota' => $nota]];
                $o->save();
                $n++;
            }

            return ['alteradas' => $n, 'ignoradas' => count(array_unique($ids)) - $ordens->count()];
        });
    }

    /** Anula a ordem sem documentos nem recebimentos (lavAnularOrdem, lavandaria.js:1062-1072). */
    public function anular(int $pedidoId, string $motivo): PedidoLavandaria
    {
        $r = $this->alterarEstado([$pedidoId], 'ANULADA', null, $motivo);
        if (! $r['alteradas']) {
            throw new ErroNegocio('A ordem não pode ser anulada: '.implode('; ', $r['ignoradas'] ?: ['já está anulada']).'.', 'ORDEM_NAO_ANULAVEL', 422);
        }

        return PedidoLavandaria::query()->findOrFail($pedidoId);
    }

    /**
     * Consumo de materiais de alfaiataria (lavConfirmarMaterial, lavandaria.js:1016-1035): guia de consumo no armazém do terminal
     * (saída ao custo médio) e CMV lançado de imediato (D custo / C inventário).
     */
    public function consumirMaterial(int $pedidoId, int $linhaId, int $produtoId, string $quantidade): PedidoLavandaria
    {
        if ((float) $quantidade <= 0) {
            throw new ErroNegocio('Indique a quantidade.', 'QUANTIDADE_INVALIDA', 422);
        }

        return DB::transaction(function () use ($pedidoId, $linhaId, $produtoId, $quantidade) {
            $o = $this->emCurso($pedidoId);
            $itens = $o->itens;
            $k = collect($itens)->search(fn ($i) => (int) $i['linha_id'] === $linhaId);
            if ($k === false || ($itens[$k]['grupo'] ?? null) !== 'ALFAIATARIA' || ! in_array($itens[$k]['estado'], ['RECEBIDA', 'EM_EXECUCAO'], true)) {
                throw new ErroNegocio('Os materiais registam-se em linhas de alfaiataria recebidas ou em execução.', 'LINHA_INVALIDA', 422);
            }
            $p = Produto::query()->findOrFail($produtoId);
            if (! $p->movimenta_stock || $p->lavandaria_grupo) {
                throw new ErroNegocio("O produto {$p->codigo} não é um material em stock.", 'PRODUTO_SEM_STOCK', 422);
            }
            $armazem = TerminalPOS::query()->withTrashed()->find($o->terminal_pos_id)?->armazem_id ?: $this->armazens->garantirPredefinido()->id;
            $g = $this->guias->emitirConsumo(['armazem_id' => $armazem, 'data' => now()->toDateString(), 'area_rececao' => "Lavandaria {$o->numero_encomenda}",
                'observacoes' => "Consumo {$o->numero_encomenda} · {$itens[$k]['nome']}", 'linhas' => [['produto_id' => $p->id, 'quantidade' => $quantidade]]]);
            $g = $this->guias->contabilizar($g);
            $linhaGuia = $g->itensGuiaSaida()->first();
            $itens[$k]['materiais'] = [...($itens[$k]['materiais'] ?? []), ['produto_id' => $p->id, 'nome' => $p->nome, 'quantidade' => $quantidade,
                'custo_unitario' => (string) $linhaGuia?->custo_unitario_kz, 'valor' => (string) $linhaGuia?->valor_kz, 'guia_saida_id' => $g->id,
                'numero_guia' => $g->numero_documento, 'em' => now()->toIso8601String(), 'por' => Auth::user()?->nome_utilizador]];
            $o->itens = $itens;
            ServicoCaixaLavandaria::historico($o, "{$itens[$k]['nome']}: consumo de {$quantidade} × {$p->nome} ({$g->numero_documento}).");
            $o->save();

            return $o;
        });
    }

    /** Detalhe da ordem (carregarOrdem + indicadores, lavandaria.js:184-191). */
    public function detalhe(int $pedidoId): array
    {
        $o = PedidoLavandaria::query()->findOrFail($pedidoId);

        return ['pedido' => $o, 'cliente' => Terceiro::query()->withTrashed()->find($o->cliente_id)?->only(['id', 'nome', 'nif', 'telefone']),
            'totais' => $this->caixa->situacao($o), 'indicadores' => RegrasLavandaria::indicadores($o->toArray(), now()),
            'faturas' => $this->caixa->faturas($o)->map->only(['id', 'tipo_documento', 'numero_documento', 'data_emissao', 'total_bruto', 'valor_pago', 'valor_pendente', 'estado'])->values(),
            'pagamentos' => PagamentoLavandaria::query()->where('pedido_lavandaria_id', $o->id)->orderBy('id')->get(),
            'reclamacoes' => ReclamacaoLavandaria::query()->where('pedido_lavandaria_id', $o->id)->orderBy('id')->get(),
            'linhas_por_facturar' => RegrasLavandaria::linhasPorFacturar($o->itens ?? [], $o->extras ?? [])];
    }

    /**
     * Lista de ordens com filtros, contadores e carga por responsável (renderListaOrdens, lavandaria.js:697-771).
     *
     * @param  array<string, mixed>  $f  estado (ACTIVAS por omissão, TODAS, ATRASADAS, NAO_LEVANTADAS, ORCAMENTO, SEM_RESPONSAVEL ou um estado), texto, terminal_pos_id, responsavel (id ou SEM)
     */
    public function listar(array $f): array
    {
        $cfg = $this->config->obter();
        $agora = now();
        $ordens = PedidoLavandaria::query()->when($f['terminal_pos_id'] ?? null, fn ($q, $t) => $q->where('terminal_pos_id', $t))->get();
        $ind = $ordens->mapWithKeys(fn ($o) => [$o->id => RegrasLavandaria::indicadores($o->toArray(), $agora)]);
        $emCurso = fn ($o) => ! in_array($o->estado, ['ENTREGUE', 'ANULADA'], true);
        $atrasada = fn ($o) => $emCurso($o) && ! $ind[$o->id]['pronta_em'] && $ind[$o->id]['atraso'] > 0;
        $naoLevantada = fn ($o) => collect($o->itens ?? [])->contains(fn ($i) => $i['estado'] === 'PRONTA'
            && RegrasLavandaria::diasDesde($i['pronta_em'] ?? null, $agora) > $cfg['dias_armazenagem_gratis']);
        $comOrcamento = fn ($o) => collect($o->itens ?? [])->contains(fn ($i) => $i['estado'] === 'ORCAMENTO');
        $pagos = PagamentoLavandaria::query()->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))
            ->selectRaw('pedido_lavandaria_id, sum(montante) as pago')->groupBy('pedido_lavandaria_id')->pluck('pago', 'pedido_lavandaria_id');
        $clientes = Terceiro::query()->withTrashed()->whereIn('id', $ordens->pluck('cliente_id')->filter()->unique())->get(['id', 'nome', 'nif', 'telefone'])->keyBy('id');

        $contadores = [
            'recebidas_hoje' => $ordens->filter(fn ($o) => $o->recebido_em?->toDateString() === $agora->toDateString())->count(),
            'sem_responsavel' => $ordens->filter(fn ($o) => $emCurso($o) && ! $o->colaborador_atribuido_id)->count(),
            'orcamentos' => $ordens->filter($comOrcamento)->count(),
            'em_execucao' => $ordens->whereIn('estado', ['EM_EXECUCAO', 'RECEBIDA'])->count(),
            'prontas' => $ordens->whereIn('estado', ['PRONTA', 'ENTREGA_PARCIAL'])->count(),
            'atrasadas' => $ordens->filter($atrasada)->count(), 'nao_levantadas' => $ordens->filter($naoLevantada)->count(),
        ];
        $estado = $f['estado'] ?? 'ACTIVAS';
        $texto = mb_strtolower(trim((string) ($f['texto'] ?? '')));
        $resp = $f['responsavel'] ?? null;
        $lista = $ordens->filter(function ($o) use ($estado, $texto, $resp, $emCurso, $atrasada, $naoLevantada, $comOrcamento, $clientes) {
            $ok = match ($estado) {
                'TODAS' => true, 'ACTIVAS' => $emCurso($o), 'ATRASADAS' => $atrasada($o), 'NAO_LEVANTADAS' => $naoLevantada($o), 'ORCAMENTO' => $comOrcamento($o),
                'SEM_RESPONSAVEL' => $emCurso($o) && ! $o->colaborador_atribuido_id, default => $o->estado === $estado,
            };
            if (! $ok || ($resp === 'SEM' && $o->colaborador_atribuido_id) || ($resp && $resp !== 'SEM' && (string) $o->colaborador_atribuido_id !== (string) $resp)) {
                return false;
            }
            if ($texto === '') {
                return true;
            }
            $c = $clientes[$o->cliente_id] ?? null;
            $campos = [$o->numero_encomenda, $c?->nome, $c?->nif, $c?->telefone, $o->nome_atribuido,
                ...collect($o->itens ?? [])->flatMap(fn ($i) => [...($i['etiquetas'] ?? []), $i['nome_peca'] ?? null])->all()];

            return (bool) array_filter($campos, fn ($x) => $x !== null && str_contains(mb_strtolower((string) $x), $texto));
        })->sortByDesc(fn ($o) => $o->recebido_em?->getTimestamp())->take(300)->values();

        // carga e desempenho por responsável (concluídas nos últimos 30 dias)
        $limite = $agora->copy()->subDays(30)->getTimestamp();
        $porResp = [];
        foreach ($ordens->where('estado', '<>', 'ANULADA') as $o) {
            $k = $o->colaborador_atribuido_id ? (string) $o->colaborador_atribuido_id : 'SEM';
            $g = $porResp[$k] ?? ['responsavel' => $o->colaborador_atribuido_id ? $o->nome_atribuido : 'Sem responsável', 'em_execucao' => 0, 'atrasadas' => 0,
                'por_levantar' => 0, 'concluidas' => 0, 'no_prazo' => 0, 'exec' => [], 'ciclo' => []];
            $x = $ind[$o->id];
            $emCurso($o) && ! $x['pronta_em'] && $g['em_execucao']++;
            $emCurso($o) && $x['pronta_em'] && $g['por_levantar']++;
            $atrasada($o) && $g['atrasadas']++;
            if ($x['pronta_em'] && strtotime($x['pronta_em']) >= $limite) {
                $g['concluidas']++;
                $x['no_prazo'] && $g['no_prazo']++;
                $x['execucao'] !== null && $g['exec'][] = $x['execucao'];
                $x['entregue_em'] && $g['ciclo'][] = $x['dias_aberta'];
            }
            $porResp[$k] = $g;
        }
        $media = fn (array $l) => $l ? round(array_sum($l) / count($l), 1) : null;

        return [
            'contadores' => $contadores,
            'ordens' => $lista->map(fn ($o) => $o->only(['id', 'numero_encomenda', 'codigo_terminal', 'terminal_pos_id', 'cliente_id', 'recebido_em', 'data_prometida', 'urgente',
                'estado', 'nome_atribuido', 'atribuido_em', 'modo_faturacao']) + [
                    'cliente' => ($c = $clientes[$o->cliente_id] ?? null) ? $c->only(['id', 'nome', 'nif', 'telefone']) : null,
                    'total' => ($t = RegrasLavandaria::totais($o->itens ?? [], $o->extras ?? [], '0.00', RegrasLavandaria::dinheiro($pagos[$o->id] ?? 0)))['total'],
                    'saldo' => $t['saldo'], 'indicadores' => $ind[$o->id], 'nao_levantada' => $naoLevantada($o)])->all(),
            'por_responsavel' => array_values(array_map(fn ($g) => array_diff_key($g, ['exec' => 1, 'ciclo' => 1]) + [
                'percentagem_no_prazo' => $g['concluidas'] ? (int) round($g['no_prazo'] / $g['concluidas'] * 100) : null,
                'execucao_media' => $media($g['exec']), 'ciclo_medio' => $media($g['ciclo'])], array_filter($porResp, fn ($g) => $g['em_execucao'] || $g['por_levantar'] || $g['concluidas']))),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function prepararItens(array $entrada): array
    {
        if (! $entrada) {
            throw new ErroNegocio('A ordem tem de ter pelo menos uma peça.', 'SEM_LINHAS', 422);
        }
        $itens = [];
        foreach (array_values($entrada) as $k => $x) {
            $n = $k + 1;
            $p = PecaLavandaria::query()->find($x['peca_id'] ?? 0);
            if (! $p || $p->ativo === false) {
                throw new ErroNegocio("Linha {$n}: peça inexistente ou inactiva.", 'PECA_INVALIDA', 422, ['linha' => $n]);
            }
            $s = $this->tabelas->servico((int) ($x['produto_id'] ?? 0));
            $orcamento = (bool) $s->lavandaria_requer_orcamento;
            $estadoEntrada = trim((string) ($x['estado_entrada'] ?? ''));
            $notas = trim((string) ($x['notas_entrada'] ?? ''));
            if (! in_array($estadoEntrada, RegrasLavandaria::ESTADOS_ENTRADA, true)) {   // lavandaria.js:596-599
                throw new ErroNegocio("Indique o estado à entrada da peça «{$p->nome}» (linha {$n}).", 'ESTADO_ENTRADA_EM_FALTA', 422, ['linha' => $n]);
            }
            if ($estadoEntrada !== RegrasLavandaria::BOM_ESTADO && $notas === '') {
                throw new ErroNegocio("Descreva o dano registado na peça «{$p->nome}» (linha {$n}).", 'DANO_SEM_DESCRICAO', 422, ['linha' => $n]);
            }
            $qtd = number_format((float) ($x['quantidade'] ?? 0), 3, '.', '');
            if (bccomp($qtd, '0', 3) <= 0) {
                throw new ErroNegocio("Linha {$n}: indique a quantidade (peças ou kg).", 'QUANTIDADE_INVALIDA', 422, ['linha' => $n]);
            }
            if ($p->unidade !== 'KG' && floor((float) $qtd) != (float) $qtd) {
                throw new ErroNegocio("Linha {$n}: a peça vende-se à unidade (quantidade inteira).", 'QUANTIDADE_INVALIDA', 422, ['linha' => $n]);
            }
            // o preço vem da tabela; só a estimativa dos serviços com orçamento é indicada (lavandaria.js:483)
            $preco = $orcamento && isset($x['preco']) ? RegrasLavandaria::dinheiro($x['preco']) : ServicoTabelasLavandaria::precoPecaServico($p, $s);
            if (! $orcamento && bccomp($preco, '0', 2) <= 0) {
                throw new ErroNegocio("A peça «{$p->nome}» não tem preço para o serviço «{$s->nome}» (nem preço base).", 'PRECO_EM_FALTA', 422, ['linha' => $n]);
            }
            $valor = RegrasLavandaria::valorComIva($qtd, $preco, $s->taxa_imposto);
            $itens[] = ['peca_id' => $p->id, 'codigo_peca' => $p->codigo, 'nome_peca' => $p->nome, 'cor' => trim((string) ($x['cor'] ?? $p->cor ?? '')),
                'tecido' => trim((string) ($x['tecido'] ?? $p->tecido ?? '')), 'descricao_peca' => trim((string) ($x['descricao_peca'] ?? '')) ?: null, 'unidade' => $p->unidade ?: 'PECA',
                'produto_id' => $s->id, 'codigo_servico' => $s->codigo, 'nome' => $s->nome, 'grupo' => $s->lavandaria_grupo, 'quantidade' => $qtd,
                'numero_pecas' => max(1, (int) round((float) ($x['numero_pecas'] ?? 1))), 'preco' => $preco, 'valor' => $valor, 'taxa_imposto' => (string) $s->taxa_imposto,
                'dias_entrega' => (int) $s->lavandaria_dias_entrega ?: ($s->lavandaria_grupo === 'ALFAIATARIA' ? 5 : 2),
                'estado_entrada' => $estadoEntrada, 'notas_entrada' => $notas, 'valor_declarado' => RegrasLavandaria::dinheiro($x['valor_declarado'] ?? 0),
                'requer_orcamento' => $orcamento, 'estado_orcamento' => $orcamento ? 'PENDENTE' : null, 'valor_orcamento' => $orcamento ? $valor : null,
                'estado' => $orcamento ? 'ORCAMENTO' : 'RECEBIDA', 'materiais' => []];
        }

        return $itens;
    }

    private function emCurso(int $id): PedidoLavandaria
    {
        $o = PedidoLavandaria::query()->lockForUpdate()->findOrFail($id);
        if (in_array($o->estado, ['ENTREGUE', 'ANULADA'], true)) {
            throw new ErroNegocio('A ordem já está '.mb_strtolower($o->estado).'.', 'ORDEM_NAO_EM_CURSO', 422);
        }

        return $o;
    }
}
