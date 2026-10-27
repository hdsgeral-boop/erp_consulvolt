<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Support\Seguranca\ValorSemFormulas;
use App\Support\Tenancy\ContextoEmpresa;

/**
 * Ficheiro de Inventário/Existências exigido pela AGT (Regime Jurídico das Faturas, Decreto Presidencial n.º 71/25;
 * estrutura pela Circular n.º 05/GACA/GJ/DSF/AGT/2026), referido ao stock a 31 de Dezembro do exercício escolhido.
 *
 * AVISO IMPORTANTE (diferente dos outros exportadores SAF-T deste sistema): ao contrário do SAF-T de Facturação e de
 * Contabilidade — que seguem a XSD SAF-T(AO) 1.01_01, oficial e publicada — a AGT não publicou um schema técnico
 * (XSD/JSON) para este ficheiro de inventário; a Circular 05/GACA/GJ/DSF/AGT/2026 só descreve os campos a constar
 * (NIF, período, data de geração, identificação/certificação do software; por produto: identificador, descrição,
 * tipo, quantidade, unidade de medida, valor, método de custeio) e aceita XML, JSON **ou Excel/CSV** através do
 * Portal do Contribuinte. Por isso este serviço gera **CSV** (formato explicitamente aceite, sem risco de estrutura
 * XML mal-formada por falta de XSD oficial) — confirmar o leiaute exacto com o contabilista/a AGT antes de submeter.
 *
 * Quantidade e valor à data de referência são reconstruídos do histórico de `MovimentoInventario` (não há tabela de
 * saldos por data): soma das quantidades (E soma, S subtrai) até à data, e o custo médio ponderado do último
 * movimento até lá (`custo_medio_apos`, já calculado pelo sistema a cada movimento — método de custeio único do
 * sistema: custo médio ponderado, sem FIFO/LIFO).
 */
final class ServicoExportacaoInventarioAgt
{
    private const AVISO_SOFTWARE = 'Dados do software (productId, n.º de certificação) por configurar no servidor: o ficheiro não é aceite para efeitos fiscais.';

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @return array{nome: string, produtos: int, avisos: list<string>} */
    public function validar(int $ano): array
    {
        [, $nif] = $this->empresaValida($ano);
        $empresa = $this->contexto->obrigatorio();
        $produtos = $this->produtosInventariaveis($empresa)->count();
        $avisos = $this->softwarePorConfigurar() ? [self::AVISO_SOFTWARE] : [];

        return ['nome' => "INVENTARIO_AO_{$nif}_{$ano}.csv", 'produtos' => $produtos, 'avisos' => $avisos];
    }

    /** @return array{csv: string, nome: string, avisos: list<string>, produtos: int} */
    public function gerar(int $ano): array
    {
        $avisos = $this->softwarePorConfigurar() ? [self::AVISO_SOFTWARE] : [];
        [$empresa, $nif] = $this->empresaValida($ano);
        $empresaId = $empresa->id;
        $corte = "{$ano}-12-31 23:59:59";

        // mesmo sem produtos de stock, a Circular exige submeter um ficheiro «sem operações» — nunca se recusa aqui
        $produtos = $this->produtosInventariaveis($empresaId)->orderBy('codigo')->get();
        if ($produtos->isEmpty()) {
            $avisos[] = 'Sem produtos de stock na empresa: ficheiro gerado como «sem operações».';
        }

        $movimentos = MovimentoInventario::query()->where('empresa_id', $empresaId)->whereIn('produto_id', $produtos->pluck('id'))
            ->where('data', '<=', $corte)->orderBy('produto_id')->orderBy('data')->orderBy('id')
            ->get(['produto_id', 'quantidade', 'sentido', 'custo_medio_apos'])->groupBy('produto_id');

        $s = config('erp.agt.software');
        $linhas = [];
        foreach ($produtos as $p) {
            $movs = $movimentos[$p->id] ?? collect();
            $quantidade = $movs->reduce(fn ($t, $m) => bcadd($t, $m->sentido === 'E' ? (string) $m->quantidade : bcmul('-1', (string) $m->quantidade, 3), 3), '0.000');
            $custoUnitario = (string) ($movs->last()?->custo_medio_apos ?? $p->custo_medio ?? '0');
            $linhas[] = [
                'codigo_produto' => $p->codigo, 'descricao' => $p->nome, 'tipo_produto' => 'MERCADORIA',
                'quantidade' => rtrim(rtrim(number_format((float) $quantidade, 3, '.', ''), '0'), '.') ?: '0',
                'unidade_medida' => $p->unidade_fe ?: 'UN', 'valor' => number_format((float) bcmul($quantidade, $custoUnitario, 2), 2, '.', ''),
                'metodo_custeio' => 'CUSTO MEDIO PONDERADO',
            ];
        }

        $csv = $this->paraCsv($nif, $ano, $s, $linhas);

        return ['csv' => $csv, 'nome' => "INVENTARIO_AO_{$nif}_{$ano}.csv", 'avisos' => $avisos, 'produtos' => $produtos->count()];
    }

    /** @param  list<array<string, string>>  $linhas */
    private function paraCsv(string $nif, int $ano, array $s, array $linhas): string
    {
        $f = fopen('php://temp', 'w+');
        fputcsv($f, ['nif', 'periodo_fiscal', 'data_geracao', 'software_id', 'software_certificacao'], ';');
        fputcsv($f, [$nif, (string) $ano, now()->toDateString(), ValorSemFormulas::paraCsv($s['productId'] ?: 'ERP Consulvolt'), $s['softwareValidationNumber'] ?: '0'], ';');
        fputcsv($f, [], ';');
        fputcsv($f, ['codigo_produto', 'descricao', 'tipo_produto', 'quantidade', 'unidade_medida', 'valor', 'metodo_custeio'], ';');
        if (! $linhas) {
            fputcsv($f, ['SEM OPERACOES'], ';');
        }
        foreach ($linhas as $l) {
            fputcsv($f, [$l['codigo_produto'], ValorSemFormulas::paraCsv($l['descricao']), $l['tipo_produto'], $l['quantidade'], $l['unidade_medida'], $l['valor'], $l['metodo_custeio']], ';');
        }
        rewind($f);
        $conteudo = stream_get_contents($f);
        fclose($f);

        // BOM UTF-8: o Excel em Angola abre CSV com acentos correctos só com o BOM
        return "\xEF\xBB\xBF".$conteudo;
    }

    /** Produtos de stock (não serviços); `e_servico` é opcional na ficha (NULL = não é serviço, não só `false`). */
    private function produtosInventariaveis(int $empresa)
    {
        return Produto::query()->where('empresa_id', $empresa)->where('movimenta_stock', true)
            ->where(fn ($q) => $q->where('e_servico', false)->orWhereNull('e_servico'))->whereNull('eliminado_em');
    }

    /** @return array{0: Empresa, 1: string} */
    private function empresaValida(int $ano): array
    {
        if ($ano < 2000 || $ano > (int) now()->addYear()->format('Y')) {
            throw new ErroNegocio('Exercício inválido.', 'EXERCICIO_INVALIDO', 422);
        }
        $empresa = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $nif = preg_replace('/\s+/', '', (string) $empresa->nif);
        if (! preg_match('/^[0-9A-Za-z]{9,15}$/', $nif)) {
            throw new ErroNegocio('A empresa não tem um NIF válido.', 'NIF_INVALIDO', 422);
        }

        return [$empresa, $nif];
    }

    private function softwarePorConfigurar(): bool
    {
        $s = config('erp.agt.software');

        return ! ($s['softwareValidationNumber'] ?? null) || ! ($s['productId'] ?? null);
    }
}
