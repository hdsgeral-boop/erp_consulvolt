<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Armazem\AcertarRequest;
use App\Http\Requests\POS\Armazem\ArtigosRequest;
use App\Http\Requests\POS\Armazem\ChamarSenhaRequest;
use App\Http\Requests\POS\Armazem\DiferencasRequest;
use App\Http\Requests\POS\Armazem\GerarSenhaRequest;
use App\Http\Requests\POS\Armazem\SenhasRequest;
use App\Services\POS\ServicoAcertoStockPOSArmazem;
use App\Services\POS\ServicoSenhasPOSArmazem;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * /api/pos/armazem — paridade com o legado (RELATORIO 2.6): catálogo de artigos em cartões, aviso e acerto do stock do
 * armazém com o stock global, e Monitor de Chamada de Senhas. Tudo filtrado pela empresa activa (EscopoEmpresa) e por
 * permissão do POS de armazém.
 */
final class ParidadePOSArmazemController extends Controller
{
    private const VER = ['pos_armazem_view', 'pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir', 'pos_armazem_acerto'];

    /** Operadores de balcão/picking tiram e chamam senhas. */
    private const OPERAR_SENHAS = ['pos_armazem_vender', 'pos_armazem_picking', 'pos_armazem_expedir'];

    public function __construct(
        private readonly ServicoAcertoStockPOSArmazem $acerto,
        private readonly ServicoSenhasPOSArmazem $senhas,
    ) {}

    public function artigos(ArtigosRequest $r): JsonResponse
    {
        $f = $r->validated();

        return RespostaApi::sucesso($this->acerto->artigos((int) $f['armazem_id']), 'Artigos do armazém.');
    }

    public function diferencas(DiferencasRequest $r): JsonResponse
    {
        $f = $r->validated();

        return RespostaApi::sucesso($this->acerto->diferencas((int) $f['armazem_id']), 'Diferenças entre o stock global e os armazéns.');
    }

    public function acertar(AcertarRequest $r): JsonResponse
    {
        $d = $r->validated();
        $res = $this->acerto->acertar((int) $d['armazem_id'], $d['produtos'] ?? null);

        return RespostaApi::sucesso($res, "Stock acertado em {$res['acertados']} produto(s).");
    }

    // ───────────── Senhas ─────────────

    public function senhas(SenhasRequest $r): JsonResponse
    {
        $f = $r->validated();

        return RespostaApi::sucesso($this->senhas->estado((int) $f['armazem_id']), 'Senhas.');
    }

    public function gerarSenha(GerarSenhaRequest $r): JsonResponse
    {
        $d = $r->validated();
        $s = $this->senhas->gerar((int) $d['armazem_id']);

        return RespostaApi::criado($s, "Senha {$s['codigo']} emitida.");
    }

    public function chamarSenha(ChamarSenhaRequest $r): JsonResponse
    {
        $d = $r->validated();
        $s = $this->senhas->chamarProxima((int) $d['armazem_id']);

        return RespostaApi::sucesso($s, "Senha {$s['codigo']} chamada.");
    }

    public function repetirSenha(int $senha): JsonResponse
    {
        $this->exigir(...self::OPERAR_SENHAS);
        $s = $this->senhas->repetir($senha);

        return RespostaApi::sucesso($s, "Senha {$s['codigo']} chamada de novo.");
    }
}
