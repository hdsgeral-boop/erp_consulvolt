<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use Throwable;

/**
 * Reconciliação do IVA dedutível com o mapa da AGT (processReconciliacaoAGT, js/ui_reports.js:6802-7173).
 * Sistema: linhas a débito das contas 345 do mês, agrupadas por NIF + n.º de documento, com a base calculada como no
 * Mapa de IVA. AGT: ficheiro Excel (layout do modelo da AGT; NIF, documento, valor tributável, IVA dedutível).
 * Cruzamento (paridade): por NIF e valores, com tolerância de 1 Kz na base e no IVA; o que sobra fica "só na AGT" ou
 * "só no sistema".
 * Correcções: o estado DIVERGENTE existia mas nunca era atribuído — agora, quando o NIF e o n.º de documento coincidem e
 * os valores não, as duas pontas juntam-se numa linha DIVERGENTE (em vez de duas linhas soltas); os valores são somados
 * em decimal exacto. O histórico gravado no navegador (localStorage) não é servidor: a reconciliação é calculada a
 * pedido (gravar exige tabela própria — ver ADR-055).
 */
final class ServicoReconciliacaoIvaAgt
{
    private const COLUNAS = [
        'nif' => ['nif'],
        'nome' => ['nome / denominação', 'nome / denominacao', 'nome', 'entidade'],
        'documento' => ['número do documento', 'numero do documento', 'nº documento', 'n.º documento', 'doc'],
        'base' => ['valor tributável', 'valor tributavel', 'base'],
        'iva' => ['iva dedutível valor', 'iva dedutivel valor', 'iva suportado/ conf. na fe', 'iva suportado / conf. na fe', 'iva suportado/ confir. na fe', 'iva'],
        'data' => ['data do documento', 'data'],
    ];

    private const ORDEM = ['DIVERGENTE' => 1, 'FALTA_NO_SISTEMA' => 2, 'FALTA_NA_AGT' => 3, 'CONCILIADO' => 4];

    public function __construct(private readonly ServicoRelatoriosContabeis $relatorios) {}

    /** @return array<string, mixed> */
    public function reconciliar(string $mes, string $ficheiro): array
    {
        $ini = "{$mes}-01";
        $fim = date('Y-m-t', strtotime($ini));
        $sistema = [];
        foreach ($this->relatorios->mapaIva(['data_inicio' => $ini, 'data_fim' => $fim])['linhas'] as $l) {
            if ($l->tipo_dc !== 'D') {
                continue;
            }
            $nif = preg_replace('/\s+/', '', (string) $l->nif);
            $doc = trim((string) $l->numero_documento);
            $k = mb_strtoupper("{$nif}_{$doc}");
            $sistema[$k] ??= ['nif' => $nif, 'nome' => $l->nome, 'documento' => $doc, 'data' => $l->data_documento,
                'base_sistema' => '0.00', 'iva_sistema' => '0.00', 'base_agt' => '0.00', 'iva_agt' => '0.00', 'estado' => 'FALTA_NA_AGT'];
            $sistema[$k]['iva_sistema'] = bcadd($sistema[$k]['iva_sistema'], $l->valor, 2);
            $sistema[$k]['base_sistema'] = bcadd($sistema[$k]['base_sistema'], $l->base, 2);
        }
        $resumoSistema = $this->resumo($sistema, 'base_sistema', 'iva_sistema');

        $agt = $this->lerFicheiro($ficheiro);
        $resumoAgt = $this->resumo($agt, 'base', 'iva');

        $usados = [];
        $resultado = $sistema;
        $soAgt = [];
        foreach ($agt as $row) {
            $alvo = null;
            foreach ($sistema as $k => $doc) {
                if (! isset($usados[$k]) && $doc['nif'] === $row['nif'] && $this->perto($doc['iva_sistema'], $row['iva']) && $this->perto($doc['base_sistema'], $row['base'])) {
                    $alvo = $k;
                    break;
                }
            }
            $estado = 'CONCILIADO';
            if ($alvo === null) {
                foreach ($sistema as $k => $doc) {
                    if (! isset($usados[$k]) && $doc['nif'] === $row['nif'] && $row['documento'] !== '' && mb_strtoupper($doc['documento']) === mb_strtoupper($row['documento'])) {
                        $alvo = $k;
                        $estado = 'DIVERGENTE';
                        break;
                    }
                }
            }
            if ($alvo === null) {
                $soAgt[] = ['nif' => $row['nif'], 'nome' => $row['nome'], 'documento' => $row['documento'], 'data' => $row['data'],
                    'base_sistema' => '0.00', 'iva_sistema' => '0.00', 'base_agt' => $row['base'], 'iva_agt' => $row['iva'], 'estado' => 'FALTA_NO_SISTEMA'];

                continue;
            }
            $usados[$alvo] = true;
            $resultado[$alvo]['base_agt'] = bcadd($resultado[$alvo]['base_agt'], $row['base'], 2);
            $resultado[$alvo]['iva_agt'] = bcadd($resultado[$alvo]['iva_agt'], $row['iva'], 2);
            $resultado[$alvo]['estado'] = $estado;
        }

        $linhas = array_merge(array_values($resultado), $soAgt);
        foreach ($linhas as &$l) {
            $l['diferenca_iva'] = bcsub($l['iva_sistema'], $l['iva_agt'], 2);
        }
        unset($l);
        usort($linhas, fn ($a, $b) => (self::ORDEM[$a['estado']] <=> self::ORDEM[$b['estado']]) ?: strcmp($a['nif'], $b['nif']));
        $contagem = array_fill_keys(array_keys(self::ORDEM), 0);
        foreach ($linhas as $l) {
            $contagem[$l['estado']]++;
        }

        return ['mes' => $mes, 'sistema' => $resumoSistema, 'agt' => $resumoAgt, 'contagem' => $contagem, 'linhas' => $linhas];
    }

    /** @return list<array{nif: string, nome: string, documento: string, base: string, iva: string, data: ?string}> */
    private function lerFicheiro(string $ficheiro): array
    {
        try {
            $folha = IOFactory::load($ficheiro)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new ErroNegocio('Não foi possível ler o ficheiro da AGT (use XLSX, XLS ou CSV).', 'FICHEIRO_INVALIDO', 422);
        }
        $cabecalho = array_map(fn ($c) => mb_strtolower(trim((string) $c)), array_shift($folha) ?? []);
        $indice = [];
        foreach (self::COLUNAS as $campo => $nomes) {
            foreach ($nomes as $n) {
                $i = array_search($n, $cabecalho, true);
                if ($i !== false) {
                    $indice[$campo] = $i;
                    break;
                }
            }
        }
        if (! isset($indice['nif'])) {
            throw new ErroNegocio('O ficheiro da AGT não tem a coluna NIF.', 'FICHEIRO_INVALIDO', 422);
        }
        $linhas = [];
        foreach ($folha as $row) {
            $nif = preg_replace('/\s+/', '', (string) ($row[$indice['nif']] ?? ''));
            if ($nif === '') {
                continue;
            }
            $data = isset($indice['data']) ? ($row[$indice['data']] ?? null) : null;
            if (is_numeric($data)) {
                $data = DataExcel::excelToDateTimeObject((float) $data)->format('Y-m-d');
            }
            $linhas[] = ['nif' => $nif, 'nome' => trim((string) (isset($indice['nome']) ? ($row[$indice['nome']] ?? '') : '')),
                'documento' => trim((string) (isset($indice['documento']) ? ($row[$indice['documento']] ?? '') : '')),
                'base' => $this->numero(isset($indice['base']) ? ($row[$indice['base']] ?? null) : null),
                'iva' => $this->numero(isset($indice['iva']) ? ($row[$indice['iva']] ?? null) : null),
                'data' => $data !== null && $data !== '' ? (string) $data : null];
        }

        return $linhas;
    }

    /** parseAgtNumber (js/ui_reports.js:6896-6905): "1.500,50" → 1500.50. */
    private function numero(mixed $v): string
    {
        if (is_int($v) || is_float($v)) {
            return number_format(round((float) $v, 2), 2, '.', '');
        }
        $s = preg_replace('/\s+/', '', (string) $v);
        if ($s === '') {
            return '0.00';
        }
        if (str_contains($s, '.') && str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
        }
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? number_format(round((float) $s, 2), 2, '.', '') : '0.00';
    }

    private function perto(string $a, string $b): bool
    {
        return bccomp(ltrim(bcsub($a, $b, 2), '-'), '1.00', 2) <= 0;
    }

    /** @param  array<array-key, array<string, mixed>>  $docs */
    private function resumo(array $docs, string $base, string $iva): array
    {
        return ['documentos' => count($docs),
            'base' => array_reduce($docs, fn ($s, $d) => bcadd($s, $d[$base], 2), '0.00'),
            'iva' => array_reduce($docs, fn ($s, $d) => bcadd($s, $d[$iva], 2), '0.00')];
    }
}
