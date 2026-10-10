<?php

namespace App\Services\Contabilidade;

use App\Models\DiarioContabil;
use App\Models\LancamentoContabil;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Documento de origem de um lançamento (8.1.3 «Ver Documento de Origem» e regra da descontabilização, 8.1.1).
 * Legado: goToSourceDocument / checkUnpostBlockers (js/ui_lancamentos.js:2386-2457 e 2959-3078).
 *
 * Um lançamento gerado por um módulo (vendas, compras, salários, tesouraria, POS, activos…) só se descontabiliza
 * NESSE módulo — que repõe o estado do documento («por contabilizar») e estorna o lançamento com as suas regras. A
 * Contabilidade só estorna os lançamentos manuais/importados. A origem determina-se, por esta ordem:
 *   1. `tipo_origem` gravado pelo módulo (lançamentos novos);
 *   2. `tipo_documento_origem` da migração do legado;
 *   3. um documento de módulo com `numero_lan_contabilizacao` = n.º do lançamento;
 *   4. dados do legado (sem origem gravada): como o legado, um documento contabilizado com o mesmo n.º de documento
 *      (factura/recibo de venda, factura de compra) ou o período salarial «SAL<mmaaaa>».
 * Só leituras, sempre restringidas à empresa activa.
 */
final class ServicoOrigemLancamentos
{
    /** Origens que pertencem à própria Contabilidade (descontabilizam-se aqui, por estorno). */
    public const ORIGENS_CONTABILIDADE = ['', 'MANUAL', 'IMPORTACAO', 'ESTORNO', 'TRANSFERENCIA'];

    /** tipo_origem => [módulo (rota), ecrã, nome do módulo, instruções] */
    private const MODULOS = [
        'VENDAS' => ['vendas', 'vendas_faturacao', 'Vendas', 'Aceda ao módulo de Vendas, localize o documento original e use «Descontabilizar» a partir daí.'],
        'RECIBOS' => ['vendas', 'vendas_faturacao', 'Vendas (recibos)', 'Aceda ao módulo de Vendas → Recibos, localize o recibo e use «Descontabilizar» a partir daí.'],
        'COMPRAS_FATURA' => ['compras', 'compras_faturacao', 'Compras', 'Aceda ao módulo de Compras, localize o documento original (Fatura ou Guia) e use «Descontabilizar».'],
        'COMPRAS_RECECAO' => ['compras', 'compras_rececoes', 'Compras (receções)', 'Aceda ao módulo de Compras → Receções, localize a receção e use «Descontabilizar».'],
        'SALARIOS' => ['rh', 'processamento', 'Recursos Humanos / Salários', 'Aceda ao módulo de Recursos Humanos → Processamento Salarial, localize o período e use o botão «Descontabilizar» disponível nesse módulo.'],
        'PRODUTIVIDADE' => ['rh', 'rh_produtividade', 'Recursos Humanos (produtividade)', 'Aceda a Recursos Humanos → Produtividade e anule a contabilização a partir daí.'],
        'ASSIDUIDADE' => ['rh', 'rh_assiduidade', 'Recursos Humanos (assiduidade)', 'Aceda a Recursos Humanos → Assiduidade e anule a contabilização a partir daí.'],
        'TESOURARIA' => ['teso', 'teso_contab_historico', 'Tesouraria', 'Aceda a Tesouraria → Histórico / Descontabilização e anule a integração do documento.'],
        'CAIXA' => ['teso', 'teso_folha_caixa', 'Tesouraria (folha de caixa)', 'Aceda a Tesouraria → Folha de Caixa e anule a contabilização da folha.'],
        'POS' => ['pos', 'pos_integracao', 'POS', 'Aceda a POS → Integração e anule a contabilização da sessão.'],
        'POS_DESVIO' => ['pos', 'pos_desvios', 'POS (desvios)', 'Aceda a POS → Desvios e anule a contabilização do desvio.'],
        'LOGISTICA' => ['stock', 'pos_armazem', 'Logística', 'Aceda ao módulo de Logística, localize o documento (guia) e anule a contabilização a partir daí.'],
        'INVENTARIO' => ['stock', 'pos_armazem', 'Logística (inventário)', 'Aceda a Logística → Inventário e anule a contabilização do inventário.'],
        'AMORTIZACOES' => ['activos', 'activos_amortizacoes', 'Activos (amortizações)', 'Aceda a Activos → Amortizações e anule a contabilização do período.'],
        'ABATE_ATIVO' => ['activos', 'activos_abates', 'Activos (abates)', 'Aceda a Activos → Abates e anule o abate.'],
        'ACRESCIMOS' => ['acrescimos', 'ad_propostas', 'Acréscimos e Diferimentos', 'Aceda a Acréscimos e Diferimentos → Propostas e anule a contabilização.'],
        'ROTINAS' => ['contab', 'contab_rotinas', 'Rotinas contabilísticas', 'Aceda a Contabilidade → Rotinas → Histórico e reverta a execução da rotina.'],
        'ENCERRAMENTO' => ['contab', 'encerramento', 'Encerramento do exercício', 'Reabra o exercício em Contabilidade → Encerramento (os lançamentos de apuramento são revertidos aí).'],
        'CONSOLIDACAO' => ['contab', 'consolidacao', 'Consolidação', 'Os lançamentos da consolidação revertem-se em Contabilidade → Consolidação.'],
    ];

    /** tipo_documento_origem (migração do legado) => tipo_origem equivalente */
    private const TIPOS_DOCUMENTO = ['FATURA_COMPRA' => 'COMPRAS_FATURA', 'RECECAO_COMPRA' => 'COMPRAS_RECECAO', 'VENDA' => 'VENDAS', 'RECIBO' => 'RECIBOS'];

    /** Tabelas dos módulos com o n.º do lançamento da contabilização: tabela => [tipo_origem, coluna do número] */
    private const COM_NUMERO_LAN = [
        'vendas' => ['VENDAS', 'numero_documento'],
        'recibos_venda' => ['RECIBOS', 'numero_recibo'],
        'faturas_compra' => ['COMPRAS_FATURA', 'numero_fatura'],
        'rececoes_compra' => ['COMPRAS_RECECAO', 'numero_rececao'],
        'periodos_processamento_salarial' => ['SALARIOS', 'mes_ano'],
        'documentos_tesouraria' => ['TESOURARIA', 'numero_documento'],
    ];

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * Null = lançamento da própria Contabilidade (manual, importado, estorno, transferência).
     *
     * @param  Collection<int, LancamentoContabil>  $linhas  linhas de UM lançamento (documento)
     * @return array{tipo_origem: string, modulo: string, ecra: string, nome: string, instrucoes: string, documento_id: ?int, numero: ?string}|null
     */
    public function origem(Collection $linhas): ?array
    {
        $l0 = $linhas->first();
        if (! $l0) {
            return null;
        }
        $empresa = $this->contexto->obrigatorio();

        // 1) origem gravada pelo módulo
        $tipo = strtoupper(trim((string) $linhas->pluck('tipo_origem')->filter()->first()));
        if (! in_array($tipo, self::ORIGENS_CONTABILIDADE, true)) {
            return $this->descrever($tipo, null, $l0->numero_documento);
        }
        if ($tipo !== '') {
            return null;   // manual, importação, estorno ou transferência explícitos
        }

        // 2) migração do legado
        $tipoDoc = strtoupper(trim((string) $linhas->pluck('tipo_documento_origem')->filter()->first()));
        if ($tipoDoc !== '') {
            return $this->descrever(self::TIPOS_DOCUMENTO[$tipoDoc] ?? $tipoDoc, $linhas->pluck('documento_origem_id')->filter()->first(), $l0->numero_documento);
        }

        // 3) documento de um módulo que guardou o n.º deste lançamento
        $numeroLan = trim((string) $l0->numero_lan);
        if ($numeroLan !== '') {
            foreach (self::COM_NUMERO_LAN as $tabela => [$tipoModulo, $colNumero]) {
                $doc = DB::table($tabela)->where('empresa_id', $empresa)->where('numero_lan_contabilizacao', $numeroLan)->first(['id', $colNumero.' as numero']);
                if ($doc) {
                    return $this->descrever($tipoModulo, (int) $doc->id, (string) $doc->numero);
                }
            }
        }

        // 4) legado: documento contabilizado com o mesmo número (como checkUnpostBlockers)
        return $this->legado($linhas, $empresa);
    }

    /** @param  Collection<int, LancamentoContabil>  $linhas */
    private function legado(Collection $linhas, int $empresa): ?array
    {
        $numeros = $linhas->flatMap(fn ($l) => [$l->numero_documento, $l->referencia])->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all();
        if (! $numeros) {
            return null;
        }
        $venda = DB::table('vendas')->where('empresa_id', $empresa)->where('contabilizado', true)->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '!=', 'ANULADO'))
            ->where(fn ($q) => $q->whereIn('numero_documento', $numeros)->orWhereIn(DB::raw("tipo_documento || ' ' || numero_documento"), $numeros))
            ->first(['id', 'numero_documento']);
        if ($venda) {
            return $this->descrever('VENDAS', (int) $venda->id, (string) $venda->numero_documento);
        }
        $recibo = DB::table('recibos_venda')->where('empresa_id', $empresa)->where('contabilizado', true)->whereIn('numero_recibo', $numeros)->first(['id', 'numero_recibo']);
        if ($recibo) {
            return $this->descrever('RECIBOS', (int) $recibo->id, (string) $recibo->numero_recibo);
        }
        $fatura = DB::table('faturas_compra')->where('empresa_id', $empresa)->where('contabilizado', true)->whereIn('numero_fatura', $numeros)->first(['id', 'numero_fatura']);
        if ($fatura) {
            return $this->descrever('COMPRAS_FATURA', (int) $fatura->id, (string) $fatura->numero_fatura);
        }
        // salários: n.º «SAL<mmaaaa>» ou o próprio «mm/aaaa» (legado: SAL + month_year sem a barra)
        $diario = DiarioContabil::query()->whereKey($linhas->first()->diario_id)->value('codigo');
        $sal = array_values(array_filter($numeros, fn ($n) => str_starts_with(strtoupper($n), 'SAL') || preg_match('#^\d{2}/\d{4}$#', $n)));
        if ($sal || strtoupper((string) $diario) === 'SAL') {
            $periodo = DB::table('periodos_processamento_salarial')->where('empresa_id', $empresa)->where('contabilizado', true)
                ->where(fn ($q) => $q->whereIn('mes_ano', $numeros)->orWhereIn(DB::raw("'SAL' || replace(mes_ano, '/', '')"), $numeros))
                ->first(['id', 'mes_ano']);
            if ($periodo) {
                return $this->descrever('SALARIOS', (int) $periodo->id, (string) $periodo->mes_ano);
            }
        }

        return null;
    }

    private function descrever(string $tipo, ?int $documentoId, ?string $numero): array
    {
        [$modulo, $ecra, $nome, $instrucoes] = self::MODULOS[$tipo]
            ?? ['contab', 'lancamentos', "módulo de origem ({$tipo})", 'Este lançamento foi gerado automaticamente por outro módulo: anule-o no módulo de origem.'];

        return ['tipo_origem' => $tipo, 'modulo' => $modulo, 'ecra' => $ecra, 'nome' => $nome, 'instrucoes' => $instrucoes,
            'documento_id' => $documentoId, 'numero' => $numero !== null && $numero !== '' ? $numero : null];
    }
}
