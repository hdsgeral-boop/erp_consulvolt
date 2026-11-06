<?php

namespace App\Services\Estrategia;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\IndicadorEstrategico;
use App\Models\MedicaoIndicador;
use App\Models\MetaIndicador;
use App\Models\ObjectivoEstrategico;
use App\Models\PlanoEstrategico;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores do plano (F2a): criar, editar, apagar e «testar medição». Origens disponíveis nesta onda: MANUAL (o dono entrega o
 * valor) e MODULO (dados de entregáveis e de colaboradores, só leitura, por lista fechada). As outras origens do legado
 * (indicador de gestão, contas, orçamento) existem na BD e no vocabulário mas só entram com o painel: aqui são recusadas com
 * uma mensagem clara. Num plano aprovado o indicador é estrutura congelada (motivo + est_plano_aprovar).
 */
final class ServicoIndicadoresEstrategicos
{
    public function __construct(private readonly ServicoPlanosEstrategicos $planos) {}

    /** Os indicadores do plano (um só pedido para o plano inteiro, com o número de metas e de medições). */
    public function listar(int $planoId): Collection
    {
        $plano = $this->planos->obter($planoId);

        return IndicadorEstrategico::query()->where('plano_estrategico_id', $plano->id)->with('objectivo:id,nome,perspectiva_id,horizonte,periodo')
            ->withCount(['metas', 'medicoes'])->orderBy('ordem')->orderBy('id')->get();
    }

    public function obter(int $id): IndicadorEstrategico
    {
        return IndicadorEstrategico::query()->with('objectivo:id,nome,perspectiva_id,horizonte,periodo')->withCount(['metas', 'medicoes'])->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public function catalogos(): array
    {
        $rotulos = fn (array $m) => array_map(fn ($k, $v) => ['id' => $k, 'nome' => $v], array_keys($m), $m);

        return [
            'origens' => array_map(fn ($k, $v) => ['id' => $k, 'nome' => $v, 'disponivel' => in_array($k, Vocabulario::ORIGENS_DISPONIVEIS, true)], array_keys(Vocabulario::ORIGENS), Vocabulario::ORIGENS),
            'polaridades' => $rotulos(Vocabulario::POLARIDADES), 'tipos' => $rotulos(Vocabulario::TIPOS_INDICADOR), 'frequencias' => $rotulos(Vocabulario::FREQUENCIAS),
            'formatos' => $rotulos(Vocabulario::FORMATOS), 'agregacoes' => $rotulos(Vocabulario::AGREGACOES), 'operacoes' => $rotulos(Vocabulario::OPERACOES),
            'operadores' => $rotulos(Vocabulario::OPERADORES), 'semaforos' => $rotulos(Vocabulario::SEMAFOROS), 'estados_plano' => $rotulos(Vocabulario::ESTADOS_PLANO),
            'estados_objectivo' => $rotulos(Vocabulario::ESTADOS_OBJECTIVO), 'horizontes' => $rotulos(Vocabulario::HORIZONTES),
            'fontes_modulo' => CatalogoFontesModulo::catalogo(),
        ];
    }

    /** @param array<string, mixed> $d */
    public function criar(int $planoId, array $d, ?string $motivo): IndicadorEstrategico
    {
        return DB::transaction(function () use ($planoId, $d, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($planoId);
            $this->planos->exigirEditavel($plano, $motivo, 'Indicador acrescentado.');
            $campos = $this->validar($plano, $d, null);
            $i = IndicadorEstrategico::query()->create($campos + [
                'plano_estrategico_id' => $plano->id, 'ordem' => (int) IndicadorEstrategico::query()->where('plano_estrategico_id', $plano->id)->max('ordem') + 10, 'criado_por' => $this->operador(),
            ]);
            $this->planos->tocar($plano);

            return $i;
        });
    }

    /** @param array<string, mixed> $d */
    public function actualizar(int $planoId, int $id, array $d, ?string $motivo): IndicadorEstrategico
    {
        return DB::transaction(function () use ($planoId, $id, $d, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($planoId);
            $i = IndicadorEstrategico::query()->where('plano_estrategico_id', $plano->id)->lockForUpdate()->findOrFail($id);
            $this->planos->exigirEditavel($plano, $motivo, "Indicador «{$i->nome}» alterado.");
            $campos = $this->validar($plano, $d, $i);
            if (($campos['frequencia'] !== $i->frequencia) && ($i->metas()->exists() || $i->medicoes()->exists())) {
                throw new ErroNegocio('O indicador já tem metas ou medições: não se muda a frequência. Crie outro indicador.', 'INDICADOR_FREQUENCIA_BLOQUEADA');
            }
            $i->fill($campos + ['atualizado_por' => $this->operador()])->save();
            $this->planos->tocar($plano);

            return $i;
        });
    }

    public function apagar(int $planoId, int $id, ?string $motivo): void
    {
        DB::transaction(function () use ($planoId, $id, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($planoId);
            $i = IndicadorEstrategico::query()->where('plano_estrategico_id', $plano->id)->lockForUpdate()->findOrFail($id);
            $this->planos->exigirEditavel($plano, $motivo, "Indicador «{$i->nome}» apagado.");
            $n = MedicaoIndicador::query()->where('indicador_id', $i->id)->count();
            if ($n > 0) {
                throw new ErroNegocio("O indicador já tem {$n} medição(ões) gravadas. Desactive-o em vez de o apagar, para não perder o histórico.", 'INDICADOR_COM_MEDICOES');
            }
            MetaIndicador::query()->where('indicador_id', $i->id)->delete();
            $i->delete();
            $this->planos->tocar($plano);
        });
    }

    /**
     * «Testar medição»: mede o período indicado (omissão: o período em curso da frequência do indicador) SEM gravar.
     *
     * @return array<string, mixed>
     */
    public function testar(int $id, ?string $periodo = null): array
    {
        $i = IndicadorEstrategico::query()->findOrFail($id);
        if ($i->origem === 'MANUAL') {
            throw new ErroNegocio('Indicador de recolha manual: o valor é escrito no painel, não há nada para testar.', 'INDICADOR_MANUAL');
        }
        if ($i->origem !== 'MODULO') {
            throw new ErroNegocio('Esta origem ainda não se mede no sistema novo.', 'ORIGEM_INDISPONIVEL');
        }
        $periodo = $periodo !== null && $periodo !== '' ? $periodo : Periodos::actual($i->frequencia, now());
        if (Periodos::frequenciaDe($periodo) !== $i->frequencia) {
            throw new ErroNegocio('O período não corresponde à frequência do indicador ('.Vocabulario::FREQUENCIAS[$i->frequencia].').', 'PERIODO_INVALIDO');
        }
        $r = CatalogoFontesModulo::medir(CatalogoFontesModulo::validar($i->fonte), $periodo);
        $oculto = PoliticaFinanceira::oculta($i->formato, $i->origem);

        return ['periodo' => $periodo, 'rotulo' => Periodos::rotulo($periodo), 'valor' => $oculto ? null : $r['valor'], 'detalhe' => $oculto ? null : $r['detalhe'], 'oculto' => $oculto];
    }

    /**
     * Valida e normaliza os campos de um indicador (criar ou alterar).
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function validar(PlanoEstrategico $plano, array $d, ?IndicadorEstrategico $actual): array
    {
        $g = fn (string $k, mixed $omissao = null) => array_key_exists($k, $d) ? $d[$k] : ($actual?->{$k} ?? $omissao);
        $nome = trim((string) $g('nome', ''));
        if ($nome === '') {
            throw new ErroNegocio('Dê um nome ao indicador.', 'INDICADOR_SEM_NOME');
        }
        $objectivoId = $g('objectivo_id');
        if (! $objectivoId || ! ObjectivoEstrategico::query()->where('plano_estrategico_id', $plano->id)->whereKey($objectivoId)->exists()) {
            throw new ErroNegocio('Um indicador tem de servir um objectivo deste plano.', 'INDICADOR_SEM_OBJECTIVO');
        }
        $origem = (string) $g('origem', '');
        if ($origem === '') {
            throw new ErroNegocio('Escolha de onde vem o valor do indicador.', 'PLANO_SEM_ORIGEM');
        }
        if (! in_array($origem, Vocabulario::ORIGENS_DISPONIVEIS, true)) {
            throw new ErroNegocio('Esta origem ainda não está disponível no sistema novo. Escolha «Dados de um módulo» ou «Recolha manual».', 'ORIGEM_INDISPONIVEL');
        }
        $fonte = [];
        if ($origem === 'MODULO') {
            $fonte = CatalogoFontesModulo::validar((array) ($d['fonte'] ?? ($actual && $actual->origem === 'MODULO' ? $actual->fonte : [])));
        }
        $donoId = $g('dono_colaborador_id');
        if ($donoId && ! Colaborador::query()->whereKey($donoId)->exists()) {
            throw new ErroNegocio('O dono escolhido não existe nesta empresa.', 'INDICADOR_DONO_INVALIDO');
        }
        $donoExterno = $donoId ? null : (($t = trim((string) $g('dono_externo', ''))) === '' ? null : mb_substr($t, 0, 150));
        if ($origem === 'MANUAL' && ! $donoId && $donoExterno === null) {
            throw new ErroNegocio('Um indicador de recolha manual precisa de dono: quem o entrega.', 'PLANO_MANUAL_SEM_DONO');
        }
        $verde = CalculadoraDesempenho::numero($g('tolerancia_verde', 100));
        $amarela = CalculadoraDesempenho::numero($g('tolerancia_amarela', 90));
        if ($verde === null || $amarela === null || bccomp($amarela, '0', 2) <= 0 || bccomp($verde, $amarela, 2) < 0) {
            throw new ErroNegocio('As tolerâncias têm de ser positivas e a amarela menor ou igual à verde.', 'INDICADOR_TOLERANCIAS');
        }
        $formato = (string) $g('formato', 'num');
        $base = CalculadoraDesempenho::numero($g('linha_base'));
        $periodoBase = trim((string) $g('linha_base_periodo', ''));
        if ($periodoBase !== '' && ! Periodos::valido($periodoBase)) {
            throw new ErroNegocio('O período da linha de base tem de ser como «2026», «2026-T1» ou «2026-03».', 'INDICADOR_PERIODO_BASE');
        }

        return [
            'objectivo_id' => (int) $objectivoId, 'nome' => mb_substr($nome, 0, 200), 'descricao' => ($t = trim((string) $g('descricao', ''))) === '' ? null : $t,
            'origem' => $origem, 'fonte' => $fonte, 'polaridade' => (string) $g('polaridade', 'SOBE'), 'tipo' => (string) $g('tipo', 'RESULTADO'), 'formato' => $formato,
            'frequencia' => (string) $g('frequencia', 'MENSAL'), 'agregacao' => (string) ($g('agregacao') ?: Vocabulario::agregacaoPorOmissao($formato)),
            'unidade_medida' => ($t = trim((string) $g('unidade_medida', ''))) === '' ? null : mb_substr($t, 0, 30),
            'linha_base' => $base, 'linha_base_periodo' => $periodoBase === '' ? null : $periodoBase,
            'dono_colaborador_id' => $donoId ? (int) $donoId : null, 'dono_externo' => $donoExterno,
            'prazo_dias' => $g('prazo_dias') === null || $g('prazo_dias') === '' ? null : (int) $g('prazo_dias'),
            'tolerancia_verde' => $verde, 'tolerancia_amarela' => $amarela, 'ativo' => (bool) $g('ativo', true),
        ];
    }

    private function operador(): ?string
    {
        return Auth::user()?->nome_utilizador;
    }
}
