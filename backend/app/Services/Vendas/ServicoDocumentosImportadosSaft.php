<?php

namespace App\Services\Vendas;

use App\Models\Venda;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Documentos integrados de outro sistema (migrados do ERP legado) no SAF-T(AO) — r4_agt.
 *
 * Norma: XSD SAF-T(AO) 1.01_01, tipo SAFTAOSourceBilling — «P para documento produzido na aplicacao, I para documento
 * integrado e produzido noutra aplicacao, M para documento proveniente de recuperacao ou de emissao manual»; HashControl
 * — «deve ser preenchido com "0" (zero), caso o documento seja gerado por um programa não validado». Pelo Decreto
 * Presidencial n.º 71/25 as facturas omitidas do SAF-T consideram-se não emitidas — por isso NÃO se excluem.
 *
 * Melhor prática aplicada: o documento fiscal já emitido nunca é renumerado nem alterado; fica marcado como integrado
 * (saft_source_billing «I», sistema_origem), sai no SAF-T com SourceBilling «I», Hash «0» e HashControl «0»; se o n.º
 * original não cumprir o padrão do XSD, sai com a representação SAF-T guardada em numero_documento_saft (derivada de
 * forma determinística, RegrasNumeracaoAgt::numeroSaftImportado) e o relatório mostra o original ao lado. Nunca são
 * enviados à facturação electrónica (ServicoEnvioAgt::pendentes).
 *
 * Critério: documento fiscal (FT, FR, NC) que este sistema nunca selou (fe_selado_em vazio — todos os emitidos aqui são
 * selados na emissão), guia (GR, GD) com n.º fora do padrão (as guias não são seladas) ou documento já marcado I/M.
 */
final class ServicoDocumentosImportadosSaft
{
    public const TIPOS = ['FT', 'FR', 'NC', 'GR', 'GD'];

    /** Padrão SAF-T do n.º (RegrasNumeracaoAgt::PADRAO_NUMERO) em expressão regular do PostgreSQL. */
    private const PADRAO_SQL = '^[^ ]+ [^/ ]+/[0-9]+$';

    /** @return Builder<Venda> */
    public function consulta(?string $inicio = null, ?string $fim = null): Builder
    {
        return Venda::query()->whereIn('tipo_documento', self::TIPOS)
            ->where(fn ($q) => $q->whereIn('saft_source_billing', ['I', 'M'])->orWhere(fn ($w) => $w->whereNull('saft_source_billing')->where(fn ($x) => $x
                ->where(fn ($f) => $f->whereIn('tipo_documento', Venda::FISCAIS)->whereNull('fe_selado_em'))
                ->orWhere(fn ($g) => $g->whereIn('tipo_documento', ['GR', 'GD'])->whereRaw('numero_documento !~ ?', [self::PADRAO_SQL])))))
            ->when($inicio, fn ($q) => $q->where('data_emissao', '>=', $inicio))
            ->when($fim, fn ($q) => $q->where('data_emissao', '<', date('Y-m-d', strtotime("{$fim} +1 day"))));
    }

    public static function eImportado(Venda $v): bool
    {
        $sb = $v->getAttribute('saft_source_billing');

        if ($sb !== null) {
            return in_array($sb, ['I', 'M'], true);
        }

        return in_array($v->tipo_documento, Venda::FISCAIS, true) ? $v->fe_selado_em === null
            : in_array($v->tipo_documento, ['GR', 'GD'], true) && ! RegrasNumeracaoAgt::numeroValido((string) $v->numero_documento);
    }

    /** N.º a usar no SAF-T: o gravado, ou o original se cumpre o padrão, ou o derivado (sem gravar). */
    public static function numeroSaft(Venda $v): string
    {
        $gravado = $v->getAttribute('numero_documento_saft');
        if ($gravado) {
            return (string) $gravado;
        }
        if (! self::eImportado($v) || RegrasNumeracaoAgt::numeroValido((string) $v->numero_documento)) {
            return (string) $v->numero_documento;
        }

        return RegrasNumeracaoAgt::numeroSaftImportado((string) $v->tipo_documento, (string) $v->numero_documento) ?? (string) $v->numero_documento;
    }

    /**
     * Relatório de validação: um registo por documento integrado, com o n.º original e o do SAF-T.
     *
     * @return array{documentos: list<array<string, mixed>>, totais: array<string, int>}
     */
    public function relatorio(?string $inicio = null, ?string $fim = null): array
    {
        $linhas = $this->consulta($inicio, $fim)->orderBy('data_emissao')->orderBy('id')
            ->get(['id', 'tipo_documento', 'numero_documento', 'numero_documento_saft', 'saft_source_billing', 'sistema_origem', 'data_emissao', 'total_bruto', 'estado', 'fe_selado_em', 'saft_hash'])
            ->map(function (Venda $v) {
                $saft = self::numeroSaft($v);
                $formatoOk = RegrasNumeracaoAgt::numeroValido((string) $v->numero_documento);

                return [
                    'id' => $v->id, 'tipo_documento' => $v->tipo_documento, 'numero_original' => $v->numero_documento, 'numero_saft' => $saft,
                    'data_emissao' => $v->data_emissao?->toDateString(), 'total_bruto' => $v->total_bruto, 'estado' => $v->estado,
                    'source_billing' => $v->getAttribute('saft_source_billing') ?: 'I', 'marcado' => (bool) $v->getAttribute('saft_source_billing'),
                    'sistema_origem' => $v->getAttribute('sistema_origem'), 'formato_original_valido' => $formatoOk,
                    'no_saft' => in_array($v->tipo_documento, ['FT', 'FR', 'NC'], true) ? 'SalesInvoices' : 'MovementOfGoods (não exportado neste ficheiro)',
                    'motivo' => $formatoOk ? 'Integrado de outro sistema (Hash «0»).' : 'Integrado de outro sistema; n.º original fora do padrão SAF-T — exportado com o n.º SAF-T indicado.',
                ];
            })->values();

        return ['documentos' => $linhas->all(), 'totais' => [
            'documentos' => $linhas->count(), 'fora_do_padrao' => $linhas->where('formato_original_valido', false)->count(),
            'por_marcar' => $linhas->where('marcado', false)->count(),
        ]];
    }

    /**
     * Marca os documentos integrados (SourceBilling «I») e grava o n.º SAF-T dos que não cumprem o padrão. Não altera o
     * n.º original nem nenhum campo fiscal. Idempotente.
     *
     * @return array{marcados: int, numeros_saft: int, conflitos: list<string>, documentos: list<array<string, mixed>>}
     */
    public function marcar(bool $simular, ?string $sistemaOrigem = null): array
    {
        $sistemaOrigem ??= (string) config('erp.agt.sistema_origem_legado', 'ERP Consulvolt v4 (sistema anterior)');
        $saida = ['marcados' => 0, 'numeros_saft' => 0, 'conflitos' => [], 'documentos' => []];
        DB::transaction(function () use ($simular, $sistemaOrigem, &$saida) {
            $usados = Venda::query()->pluck('numero_documento')->merge(Venda::query()->whereNotNull('numero_documento_saft')->pluck('numero_documento_saft'))
                ->map(fn ($n) => strtoupper((string) $n))->flip();
            foreach ($this->consulta()->whereNull('saft_source_billing')->orderBy('id')->lockForUpdate()->get() as $v) {
                $numero = RegrasNumeracaoAgt::numeroValido((string) $v->numero_documento) ? null
                    : RegrasNumeracaoAgt::numeroSaftImportado((string) $v->tipo_documento, (string) $v->numero_documento);
                if ($numero !== null && isset($usados[strtoupper($numero)])) {
                    $alternativo = preg_replace('#/(\d+)$#', '-I/$1', $numero);
                    if (isset($usados[strtoupper($alternativo)])) {
                        $saida['conflitos'][] = "{$v->numero_documento}: n.º SAF-T {$numero} já usado.";
                        $numero = null;
                    } else {
                        $numero = $alternativo;
                    }
                }
                if ($numero !== null) {
                    $usados[strtoupper($numero)] = true;
                    $saida['numeros_saft']++;
                }
                $saida['marcados']++;
                $saida['documentos'][] = ['id' => $v->id, 'tipo' => $v->tipo_documento, 'original' => $v->numero_documento, 'saft' => $numero ?? $v->numero_documento];
                if (! $simular) {
                    // query builder: só as colunas de integração (os campos fiscais selados não são tocados)
                    Venda::query()->whereKey($v->id)->toBase()->update(['saft_source_billing' => 'I', 'sistema_origem' => mb_substr($sistemaOrigem, 0, 120),
                        'numero_documento_saft' => $numero, 'atualizado_em' => now()]);
                }
            }
        });

        return $saida;
    }
}
