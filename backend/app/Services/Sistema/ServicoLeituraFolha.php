<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use Throwable;

/**
 * Leitura de uma folha Excel (XLSX/XLS) ou CSV enviada em multipart, para as importações que recebem `linhas`
 * (centro de migração e câmbios). Devolve as linhas como objectos {cabeçalho: valor}, o mesmo formato JSON que o
 * cliente enviava quando lia o ficheiro no navegador, para que os serviços de importação não mudem.
 *
 * - Usa a folha «Template» do modelo descarregado (GET /api/sistema/migracao/modelos/{entidade}) quando existe; senão a activa.
 * - A primeira linha é a dos cabeçalhos; linhas totalmente vazias são ignoradas.
 * - Células com formato de data saem como AAAA-MM-DD; fórmulas saem calculadas; o resto sai como texto/número em bruto.
 */
final class ServicoLeituraFolha
{
    public const MAX_LINHAS = 20000;

    /** @return list<array<string, mixed>> */
    public function linhas(UploadedFile $ficheiro, string $folhaPreferida = 'Template'): array
    {
        try {
            // O ficheiro temporário do upload não tem extensão: o tipo vem do nome original.
            $tipo = match (strtolower($ficheiro->getClientOriginalExtension())) {
                'xlsx' => 'Xlsx', 'xls' => 'Xls', 'csv', 'txt' => 'Csv', default => null,
            };
            $leitor = $tipo ? IOFactory::createReader($tipo) : IOFactory::createReaderForFile($ficheiro->getRealPath());
            $livro = $leitor->load($ficheiro->getRealPath());
        } catch (Throwable) {
            throw new ErroNegocio('Não foi possível ler o ficheiro (use o modelo XLSX, ou XLS/CSV).', 'FICHEIRO_INVALIDO', 422);
        }
        $folha = $livro->getSheetByName($folhaPreferida) ?? $livro->getActiveSheet();

        $cabecalhos = null;
        $linhas = [];
        foreach ($folha->getRowIterator() as $linha) {
            $celulas = $linha->getCellIterator();
            $celulas->setIterateOnlyExistingCells(false);
            $valores = [];
            foreach ($celulas as $celula) {
                $valores[] = $this->valor($celula);
            }
            if ($cabecalhos === null) {
                $cabecalhos = array_map(fn ($v) => trim((string) $v), $valores);

                continue;
            }
            $registo = [];
            foreach ($cabecalhos as $i => $c) {
                if ($c === '') {
                    continue;
                }
                $registo[$c] = $valores[$i] ?? '';
            }
            // As linhas vazias ficam como [] para o n.º de linha dos erros (índice + 2) coincidir com o do Excel;
            // os serviços de importação ignoram-nas.
            $linhas[] = array_filter($registo, fn ($v) => $v !== '' && $v !== null) === [] ? [] : $registo;
            if (count($linhas) > self::MAX_LINHAS + 1000) {
                break;
            }
        }
        $livro->disconnectWorksheets();

        while ($linhas !== [] && end($linhas) === []) {
            array_pop($linhas);
        }
        if ($cabecalhos === null || array_filter($cabecalhos) === []) {
            throw new ErroNegocio('O ficheiro não tem a linha de cabeçalhos.', 'FICHEIRO_SEM_CABECALHOS', 422);
        }
        if (count($linhas) > self::MAX_LINHAS) {
            throw new ErroNegocio('O ficheiro tem mais de '.self::MAX_LINHAS.' linhas: divida-o em ficheiros mais pequenos.', 'FICHEIRO_GRANDE', 422);
        }
        if (array_filter($linhas) === []) {
            throw new ErroNegocio('O ficheiro não tem linhas de dados.', 'FICHEIRO_VAZIO', 422);
        }

        return $linhas;
    }

    private function valor(Cell $celula): mixed
    {
        try {
            $v = $celula->getCalculatedValue();
        } catch (Throwable) {
            $v = $celula->getValue();
        }
        if ($v === null) {
            return '';
        }
        if (is_numeric($v) && DataExcel::isDateTime($celula)) {
            return DataExcel::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        if ($v instanceof RichText) {
            return $v->getPlainText();
        }

        return is_string($v) ? trim($v) : $v;
    }
}
