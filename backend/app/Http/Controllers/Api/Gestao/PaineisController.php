<?php

namespace App\Http\Controllers\Api\Gestao;

use App\Http\Controllers\Controller;
use App\Models\Utilizador;
use App\Services\Gestao\Paineis\PeriodoPainel;
use App\Services\Gestao\Paineis\ServicoComparacaoEmpresas;
use App\Services\Gestao\Paineis\ServicoInicio;
use App\Services\Gestao\Paineis\ServicoPaineis;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * /api/gestao — página de início (vista `welcome`) e dashboard por módulos (vista `dashboard`): lista dos painéis visíveis,
 * painel de um módulo (KPIs, séries e tabelas agregadas do período) e comparação de empresas.
 */
final class PaineisController extends Controller
{
    private const DASHBOARD = 'dashboard_view';

    public function __construct(
        private readonly ServicoPaineis $paineis,
        private readonly ServicoInicio $inicio,
        private readonly ServicoComparacaoEmpresas $comparacao,
        private readonly ServicoEmpresas $empresas,
    ) {}

    /** Início: empresa, saudação, processos pendentes visíveis, dica do dia, comunicado 360º e módulos (renderWelcome, ui_dashboard.js:621). */
    public function inicio(): JsonResponse
    {
        return RespostaApi::sucesso($this->inicio->inicio(), 'Página de início.');
    }

    /** Painéis a que o utilizador tem acesso, pela ordem do legado (MODULOS, ui_painel_modulos.js:31-50). */
    public function lista(): JsonResponse
    {
        $this->exigir(self::DASHBOARD);

        return RespostaApi::sucesso(['holding' => $this->paineis->holding(), 'paineis' => $this->paineis->lista()], 'Painéis disponíveis.');
    }

    public function painel(Request $r, string $modulo): JsonResponse
    {
        $this->exigir(self::DASHBOARD);
        $f = $r->validate([
            'ano' => ['nullable', 'integer', 'min:1900', 'max:2999'], 'mes' => ['nullable', 'integer', 'min:1', 'max:12'],
            'unidade_negocio_id' => ['nullable', 'integer'], 'centro_custo_id' => ['nullable', 'integer'],
            'iva' => ['nullable', 'in:sem,com'], 'actualizar' => ['nullable', 'boolean'],
        ]);

        return RespostaApi::sucesso($this->paineis->painel($modulo, $f), 'Painel '.(ServicoPaineis::CATALOGO[$modulo]['nome'] ?? $modulo).'.');
    }

    /**
     * Comparação livre de empresas (só as que o utilizador pode aceder). Sem lista: todas as empresas acessíveis que não são
     * holdings (até 30).
     */
    public function comparacao(Request $r): JsonResponse
    {
        $this->exigir(self::DASHBOARD);
        $f = $r->validate([
            'empresas' => ['nullable', 'array', 'max:30'], 'empresas.*' => ['integer', 'distinct'],
            'ano' => ['nullable', 'integer', 'min:1900', 'max:2999'], 'mes' => ['nullable', 'integer', 'min:1', 'max:12'], 'actualizar' => ['nullable', 'boolean'],
        ]);
        /** @var Utilizador $u */
        $u = $r->user();
        $ids = $f['empresas'] ?? DB::table('empresas')->whereIn('id', $this->empresas->idsAcessiveis($u))->where('e_consolidacao', false)
            ->orderBy('nome')->limit(30)->pluck('id')->map(fn ($x) => (int) $x)->all();
        $p = PeriodoPainel::de(isset($f['ano']) ? (int) $f['ano'] : null, isset($f['mes']) ? (int) $f['mes'] : null);
        $chave = 'gestao:comparacao:'.md5(json_encode([$u->getKey(), $ids, $p->chaveMes]));
        if (! empty($f['actualizar'])) {
            Cache::forget($chave);
        }
        $dados = Cache::remember($chave, ServicoPaineis::TTL_CACHE, fn () => ['periodo' => $p->descrever()] + $this->comparacao->comparar($ids, $p, []));

        return RespostaApi::sucesso($dados, 'Comparação de empresas.');
    }
}
