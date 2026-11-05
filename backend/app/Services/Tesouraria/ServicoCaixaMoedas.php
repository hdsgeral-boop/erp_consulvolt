<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\Moeda;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoCambios;
use App\Services\Vendas\CalculadoraDocumento;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Caixa em moeda estrangeira (js/moedas_tesouraria.js:355-420 do legado), configurável por empresa:
 *   - a empresa escolhe QUAIS moedas (além do Kz, a moeda funcional) passam a liquidar pela caixa — por omissão nenhuma,
 *     com o comportamento da caixa em Kz intacto; a lista guarda-se em `configuracoes_sistema` (chave CHAVE) e cada
 *     alteração fica na auditoria;
 *   - gerir as moedas exige `teso_caixa_multimoeda_gerir`; abrir/operar/ver uma sessão numa moeda estrangeira exige
 *     `teso_caixa_multimoeda_liquidar` — verificado no servidor em cada pedido (sem a permissão a moeda não aparece
 *     nem funciona);
 *   - o câmbio do movimento é o da tabela na data ou manual dentro da tolerância configurável (ServicoCambios).
 */
final class ServicoCaixaMoedas
{
    public const CHAVE = 'caixa_moedas_habilitadas';

    public const GERIR = 'teso_caixa_multimoeda_gerir';

    public const LIQUIDAR = 'teso_caixa_multimoeda_liquidar';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoCambios $cambios,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    public static function eEstrangeira(?string $moeda): bool
    {
        return $moeda !== null && $moeda !== '' && $moeda !== ServicoCambios::BASE;
    }

    public function podeGerir(): bool
    {
        return Gate::any([self::GERIR]);
    }

    public function podeLiquidar(): bool
    {
        return Gate::any([self::LIQUIDAR]);
    }

    /** Moedas estrangeiras habilitadas para a caixa da empresa (códigos, por ordem alfabética). */
    public function habilitadas(): array
    {
        $v = DB::table('configuracoes_sistema')->where('chave', self::CHAVE)->value('valor');
        $lista = is_string($v) ? json_decode($v, true) : null;

        return is_array($lista) ? array_values(array_filter(array_map('strval', $lista), fn ($c) => self::eEstrangeira($c))) : [];
    }

    /**
     * Estado para o ecrã. Sem nenhuma das duas permissões a funcionalidade não existe: devolve-se desligada.
     *
     * @return array{activo: bool, pode_gerir: bool, pode_liquidar: bool, habilitadas: list<string>, moedas: list<array<string, mixed>>, tolerancia_pct: string}
     */
    public function estado(): array
    {
        $gerir = $this->podeGerir();
        $liquidar = $this->podeLiquidar();
        $moedas = [];
        if ($gerir) {
            $moedas = Moeda::query()->where('ativo', true)->where('codigo', '<>', ServicoCambios::BASE)->orderBy('codigo')->get()
                ->map(fn (Moeda $m) => ['codigo' => $m->codigo, 'nome' => $m->nome, 'simbolo' => $m->simbolo])->all();
        }

        return ['activo' => $gerir || $liquidar, 'pode_gerir' => $gerir, 'pode_liquidar' => $liquidar,
            'habilitadas' => $gerir || $liquidar ? $this->habilitadas() : [], 'moedas' => $moedas, 'tolerancia_pct' => $this->cambios->tolerancia()];
    }

    /** @param  list<string>  $moedas */
    public function definir(array $moedas): array
    {
        if (! $this->podeGerir()) {
            throw new AccessDeniedHttpException;
        }
        $novas = array_values(array_unique(array_map(fn ($c) => strtoupper(trim((string) $c)), $moedas)));
        sort($novas);
        foreach ($novas as $c) {
            if (! self::eEstrangeira($c)) {
                throw new ErroNegocio('O Kz é a moeda funcional e já liquida pela caixa: escolha só moedas estrangeiras.', 'MOEDA_BASE', 422);
            }
            if (! Moeda::query()->where('codigo', $c)->where('ativo', true)->exists()) {
                throw new ErroNegocio("A moeda {$c} não existe ou está inactiva nas moedas do sistema.", 'MOEDA_INEXISTENTE', 422, ['moeda' => $c]);
            }
        }
        $antes = $this->habilitadas();
        // uma moeda com sessão aberta não se desabilita: a sessão ficaria a meio
        $retiradas = array_diff($antes, $novas);
        if ($retiradas && DB::table('sessoes_caixa')->where('estado', 'ABERTA')->whereIn('codigo_moeda', $retiradas)->exists()) {
            throw new ErroNegocio('Há sessões de caixa abertas nessa moeda: feche-as antes de a desabilitar.', 'SESSAO_ABERTA_NA_MOEDA', 422);
        }
        DB::table('configuracoes_sistema')->updateOrInsert(['chave' => self::CHAVE], ['valor' => json_encode($novas), 'atualizado_em' => now()]);
        $this->auditoria->registar('Tesouraria/Caixa', 'Moedas da caixa',
            'Moedas estrangeiras da caixa: '.($antes ? implode(', ', $antes) : 'nenhuma').' → '.($novas ? implode(', ', $novas) : 'nenhuma').'.',
            'configuracoes_sistema', null, ['moedas' => $antes], ['moedas' => $novas]);

        return $novas;
    }

    /** Recusa (403) sem a permissão de liquidar e (422) se a moeda não estiver habilitada para a caixa desta empresa. */
    public function exigirDisponivel(string $moeda): void
    {
        if (! self::eEstrangeira($moeda)) {
            return;
        }
        if (! $this->podeLiquidar()) {
            throw new AccessDeniedHttpException;
        }
        if (! in_array($moeda, $this->habilitadas(), true)) {
            throw new ErroNegocio("A caixa em {$moeda} não está habilitada nesta empresa. Peça a quem gere as moedas da caixa para a habilitar.", 'MOEDA_NAO_HABILITADA', 422, ['moeda' => $moeda]);
        }
    }

    /**
     * Câmbio do movimento: o manual (dentro da tolerância) ou o da tabela até à data.
     *
     * @return array{taxa: string, taxa_id: ?int, manual: bool}
     */
    public function taxa(string $moeda, string $data, string|float|null $manual, string $contexto): array
    {
        $empresa = $this->contexto->obrigatorio();
        if ($manual !== null && (float) $manual > 0) {
            $this->cambios->validarManual($empresa, $moeda, $data, $manual, $contexto);

            return ['taxa' => number_format((float) $manual, 6, '.', ''), 'taxa_id' => null, 'manual' => true];
        }
        $t = $this->cambios->obter($empresa, $moeda, $data)
            ?? throw new ErroNegocio("Não há câmbio de {$moeda} registado até {$data}. Registe-o ou indique-o manualmente.", 'CAMBIO_EM_FALTA', 422);

        return ['taxa' => $t['taxa'], 'taxa_id' => $t['id'], 'manual' => false];
    }

    /**
     * Saldo das contas de caixa (45) por moeda, até à data: o Kz do Diário e, nas caixas em moeda estrangeira, o saldo na moeda.
     * As caixas em moeda estrangeira só aparecem a quem tem a permissão de liquidar ou de gerir.
     *
     * @return list<array{conta: string, descricao: ?string, moeda: string, saldo_moeda: ?string, saldo_kz: string}>
     */
    public function saldos(?string $ate = null): array
    {
        $empresa = $this->contexto->obrigatorio();
        $ate = $ate ?: now()->toDateString();
        $linhas = DB::select("SELECT l.codigo_conta AS conta, MAX(pc.descricao) AS descricao, COALESCE(MAX(pc.codigo_moeda), 'AOA') AS moeda,
                SUM(CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END) AS saldo_kz,
                SUM(CASE WHEN COALESCE(pc.codigo_moeda, 'AOA') <> 'AOA' AND l.codigo_moeda = pc.codigo_moeda THEN CASE WHEN l.tipo_dc = 'D' THEN COALESCE(l.valor_moeda, 0) ELSE -COALESCE(l.valor_moeda, 0) END ELSE 0 END) AS saldo_moeda
            FROM lancamentos_contabeis l LEFT JOIN plano_contas pc ON pc.empresa_id = l.empresa_id AND pc.codigo = l.codigo_conta AND pc.eliminado_em IS NULL
            WHERE l.empresa_id = ? AND l.data_documento <= ? AND l.codigo_conta LIKE '45%'
            GROUP BY l.codigo_conta ORDER BY l.codigo_conta COLLATE \"C\"", [$empresa, $ate]);
        $veEstrangeira = $this->podeLiquidar() || $this->podeGerir();

        return collect($linhas)->filter(fn ($c) => ! self::eEstrangeira($c->moeda) || $veEstrangeira)
            ->map(fn ($c) => ['conta' => $c->conta, 'descricao' => $c->descricao, 'moeda' => $c->moeda,
                'saldo_moeda' => self::eEstrangeira($c->moeda) ? number_format((float) $c->saldo_moeda, 2, '.', '') : null,
                'saldo_kz' => number_format((float) $c->saldo_kz, 2, '.', '')])->values()->all();
    }

    /** Contravalor em Kz: arred(valor × taxa), a 2 casas. */
    public static function kz(string|float $valor, string|float $taxa): string
    {
        return CalculadoraDocumento::arredondar(bcmul((string) $valor, (string) $taxa, 8));
    }
}
