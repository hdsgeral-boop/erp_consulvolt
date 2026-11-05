<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\RH\ServicoMapaRemuneracoesAgt;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Mapa de Remunerações no modelo da AGT (IRT A2.1): verificação prévia (avisos) e descarga do .xlsx (permissão do Mapa Fiscal de IRT). */
final class MapaRemuneracoesAgtController extends Controller
{
    private const PERMISSAO = 'rh_rel_irt_view';

    /** GET /rh/salarios/periodos/{id}/mapa-agt/verificacao — colaboradores abrangidos e avisos (sem NIF, sem Nº SS, sem localização). */
    public function verificacao(int $id, ServicoMapaRemuneracoesAgt $mapa): JsonResponse
    {
        $this->exigir(self::PERMISSAO);
        $d = $mapa->dados(PeriodoProcessamentoSalarial::query()->findOrFail($id));

        return RespostaApi::sucesso(['colaboradores' => count($d['linhas']), 'avencados_excluidos' => $d['avencados_excluidos'], 'avisos' => $d['avisos'],
            'ficheiro' => ServicoMapaRemuneracoesAgt::nomeFicheiro(PeriodoProcessamentoSalarial::query()->findOrFail($id))], 'Verificação do mapa no modelo da AGT.');
    }

    /** GET /rh/salarios/periodos/{id}/mapa-agt — descarrega «Mapa Remuneração AAAA - Mês.xlsx». */
    public function descarregar(int $id, ServicoMapaRemuneracoesAgt $mapa, ServicoAuditoria $auditoria): Response
    {
        $this->exigir(self::PERMISSAO);
        $p = PeriodoProcessamentoSalarial::query()->findOrFail($id);
        $d = $mapa->dados($p);
        $caminho = $mapa->gerar($p);
        $auditoria->registar('RH', 'Exportação do Mapa de Remunerações (Modelo AGT IRT A2.1)', "Período {$p->mes_ano} · ".count($d['linhas']).' colaborador(es) · '.count($d['avisos']).' aviso(s)',
            $p->getTable(), $p->id);

        return response()->download($caminho, ServicoMapaRemuneracoesAgt::nomeFicheiro($p), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store',
            'X-Mapa-Agt-Avisos' => (string) count($d['avisos']),
        ])->deleteFileAfterSend();
    }
}
