<?php

namespace App\Services\Tesouraria;

use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Documentos em aberto de clientes e fornecedores (proceedToPendingDocsList, js/ui_tesouraria.js:1784-1906).
 * Continua a ser calculado a partir do DIÁRIO — funciona para qualquer origem (vendas, compras, legado) —, com as correcções:
 *   - só contas de terceiros da classe 3 (excepto 34, impostos) e com terceiro; o legado aceitava 1|2|3|48 sem critério;
 *   - numa só consulta agregada (o legado lia todas as linhas do diário para o browser);
 *   - desconta os documentos de tesouraria ainda POR INTEGRAR (evita pagar duas vezes) — como o legado —
 *     e liga ao documento de origem (venda / factura de fornecedor) quando o n.º e o terceiro coincidem.
 * Natureza: saldo devedor → documento a receber (liquida-se a crédito); credor → a pagar (liquida-se a débito).
 */
final class ServicoPendentes
{
    public const TOLERANCIA = '0.005';

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * @param  array{terceiro_id?: ?int, codigo_conta?: ?string, natureza?: ?string, pesquisa?: ?string, excluir_documento_id?: ?int}  $f
     * @return list<array<string, mixed>>
     */
    public function abertos(array $f = []): array
    {
        $empresa = $this->contexto->obrigatorio();
        $chave = "COALESCE(NULLIF(l.numero_documento, ''), NULLIF(l.referencia, ''), 'SEM_DOC')";
        $diario = DB::table('lancamentos_contabeis as l')
            ->where('l.empresa_id', $empresa)->whereNotNull('l.terceiro_id')
            ->where('l.codigo_conta', 'like', '3%')->where('l.codigo_conta', 'not like', '34%')
            ->when($f['terceiro_id'] ?? null, fn ($q, $v) => $q->where('l.terceiro_id', $v))
            ->when($f['codigo_conta'] ?? null, fn ($q, $v) => $q->where('l.codigo_conta', 'like', str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->groupBy('l.terceiro_id', 'l.codigo_conta', DB::raw($chave))
            ->selectRaw("l.terceiro_id, l.codigo_conta, {$chave} as numero_documento, MIN(l.data_documento) as data_documento,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE 0 END) as debito, SUM(CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE 0 END) as credito")
            ->get();

        // documentos de tesouraria por integrar (os integrados já estão no diário)
        $pendentes = DB::table('itens_documento_tesouraria as i')->join('documentos_tesouraria as d', 'd.id', '=', 'i.documento_tesouraria_id')
            ->where('d.empresa_id', $empresa)->where('d.estado', 'PENDENTE')->whereNotNull('i.terceiro_id')
            ->when($f['excluir_documento_id'] ?? null, fn ($q, $v) => $q->where('d.id', '<>', $v))
            ->groupBy('i.terceiro_id', 'i.codigo_conta', 'i.numero_documento')
            ->selectRaw("i.terceiro_id, i.codigo_conta, COALESCE(NULLIF(i.numero_documento, ''), 'SEM_DOC') as numero_documento,
                SUM(CASE WHEN i.tipo_dc = 'D' THEN i.valor ELSE 0 END) as debito, SUM(CASE WHEN i.tipo_dc = 'C' THEN i.valor ELSE 0 END) as credito")
            ->get()->keyBy(fn ($p) => "{$p->terceiro_id}|{$p->codigo_conta}|{$p->numero_documento}");

        $terceiros = DB::table('terceiros')->where('empresa_id', $empresa)->whereIn('id', $diario->pluck('terceiro_id')->unique())->pluck('nome', 'id');
        $pesquisa = mb_strtolower(trim((string) ($f['pesquisa'] ?? '')));
        $saida = [];
        foreach ($diario as $l) {
            $p = $pendentes["{$l->terceiro_id}|{$l->codigo_conta}|{$l->numero_documento}"] ?? null;
            $debito = number_format((float) $l->debito, 2, '.', '');
            $credito = number_format((float) $l->credito, 2, '.', '');
            $aReceber = bccomp($debito, $credito, 2) >= 0;
            $total = $aReceber ? $debito : $credito;
            $liquidado = $aReceber ? bcadd($credito, (string) ($p->credito ?? 0), 2) : bcadd($debito, (string) ($p->debito ?? 0), 2);
            $emCurso = $aReceber ? (string) ($p->credito ?? 0) : (string) ($p->debito ?? 0);
            $saldo = bcsub($total, $liquidado, 2);
            if (bccomp(ltrim($saldo, '-'), self::TOLERANCIA, 3) <= 0) {
                continue;
            }
            $natureza = $aReceber ? 'A_RECEBER' : 'A_PAGAR';
            if (($f['natureza'] ?? null) && $f['natureza'] !== $natureza) {
                continue;
            }
            $nome = (string) ($terceiros[$l->terceiro_id] ?? '');
            if ($pesquisa !== '' && ! str_contains(mb_strtolower($nome.' '.$l->numero_documento), $pesquisa)) {
                continue;
            }
            $saida[] = [
                'terceiro_id' => (int) $l->terceiro_id, 'terceiro' => $nome, 'codigo_conta' => $l->codigo_conta, 'numero_documento' => $l->numero_documento,
                'data_documento' => $l->data_documento, 'natureza' => $natureza, 'total' => $total, 'liquidado' => $liquidado,
                'em_liquidacao' => number_format((float) $emCurso, 2, '.', ''), 'saldo' => $saldo, 'liquidar_a' => $aReceber ? 'C' : 'D',
            ];
        }
        $this->ligarDocumentos($saida, $empresa);
        usort($saida, fn ($a, $b) => [$a['terceiro'], $a['data_documento']] <=> [$b['terceiro'], $b['data_documento']]);

        return $saida;
    }

    /** Saldo em aberto de um documento concreto (para validar o valor a liquidar). */
    public function saldo(int $terceiroId, string $conta, string $numeroDocumento, ?int $excluirDocumentoId = null): ?array
    {
        foreach ($this->abertos(['terceiro_id' => $terceiroId, 'codigo_conta' => $conta, 'excluir_documento_id' => $excluirDocumentoId]) as $a) {
            if ($a['codigo_conta'] === $conta && $a['numero_documento'] === $numeroDocumento) {
                return $a;
            }
        }

        return null;
    }

    /** Liga cada pendente à venda (cliente) ou factura de fornecedor com o mesmo n.º e terceiro. */
    private function ligarDocumentos(array &$saida, int $empresa): void
    {
        $numeros = array_unique(array_column($saida, 'numero_documento'));
        if (! $numeros) {
            return;
        }
        $vendas = DB::table('vendas')->where('empresa_id', $empresa)->whereIn('numero_documento', $numeros)->whereIn('tipo_documento', ['FT', 'FR', 'ND'])
            ->get(['id', 'cliente_id', 'numero_documento'])->groupBy(fn ($v) => "{$v->cliente_id}|{$v->numero_documento}");
        $faturas = DB::table('faturas_compra')->where('empresa_id', $empresa)->whereIn('numero_fatura', $numeros)
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))
            ->get(['id', 'fornecedor_id', 'numero_fatura'])->groupBy(fn ($f) => "{$f->fornecedor_id}|{$f->numero_fatura}");
        foreach ($saida as &$s) {
            $k = "{$s['terceiro_id']}|{$s['numero_documento']}";
            // só liga quando é inequívoco (o legado tinha n.ºs repetidos)
            $s['venda_id'] = $s['natureza'] === 'A_RECEBER' && ($vendas[$k] ?? collect())->count() === 1 ? $vendas[$k]->first()->id : null;
            $s['fatura_compra_id'] = $s['natureza'] === 'A_PAGAR' && ($faturas[$k] ?? collect())->count() === 1 ? $faturas[$k]->first()->id : null;
        }
    }
}
