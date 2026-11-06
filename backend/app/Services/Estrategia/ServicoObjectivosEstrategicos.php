<?php

namespace App\Services\Estrategia;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\IndicadorEstrategico;
use App\Models\ObjectivoEstrategico;
use App\Models\PerspectivaEstrategica;
use App\Models\PlanoEstrategico;
use App\Models\UnidadeOrganica;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Perspectivas e objectivos de um plano. Regras no servidor: a perspectiva e o objectivo-pai são do mesmo plano; o pai nunca
 * fecha um ciclo (o legado só recusava a auto-referência); um OKR (horizonte TRIMESTRE) tem um trimestre dentro do ciclo do
 * plano; apagar é recusado com a mensagem do legado enquanto houver dependências. Num plano aprovado (congelado) toda a
 * alteração passa por ServicoPlanosEstrategicos::exigirEditavel (permissão + motivo na auditoria).
 */
final class ServicoObjectivosEstrategicos
{
    public function __construct(private readonly ServicoPlanosEstrategicos $planos) {}

    /**
     * A estrutura inteira do plano, em lote (3 consultas): perspectivas, objectivos com o número de indicadores e dependentes.
     *
     * @return array<string, mixed>
     */
    public function estrutura(int $planoId): array
    {
        $plano = $this->planos->obter($planoId);
        $perspectivas = PerspectivaEstrategica::query()->where('plano_estrategico_id', $plano->id)->orderBy('ordem')->orderBy('id')->get();
        $objectivos = ObjectivoEstrategico::query()->where('plano_estrategico_id', $plano->id)->orderBy('ordem')->orderBy('id')->get();
        $indicadores = IndicadorEstrategico::query()->where('plano_estrategico_id', $plano->id)->selectRaw('objectivo_id, count(*) as n, count(*) filter (where ativo) as activos')->groupBy('objectivo_id')->get()->keyBy('objectivo_id');

        return ['plano' => $plano, 'perspectivas' => $perspectivas, 'objectivos' => $objectivos, 'indicadores_por_objectivo' => $indicadores->map(fn ($r) => ['total' => (int) $r->n, 'activos' => (int) $r->activos])->all()];
    }

    // ---- perspectivas -------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $d */
    public function criarPerspectiva(int $planoId, array $d, ?string $motivo): PerspectivaEstrategica
    {
        return DB::transaction(function () use ($planoId, $d, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($planoId);
            $this->planos->exigirEditavel($plano, $motivo, 'Perspectiva acrescentada.');
            $nome = $this->nomePerspectiva($plano->id, $d['nome'] ?? '', null);
            $ordem = (int) ($d['ordem'] ?? (PerspectivaEstrategica::query()->where('plano_estrategico_id', $plano->id)->max('ordem') + 10));
            $p = PerspectivaEstrategica::query()->create([
                'plano_estrategico_id' => $plano->id, 'nome' => $nome, 'descricao' => $this->curto($d['descricao'] ?? null), 'ordem' => $ordem,
                'cor' => $d['cor'] ?? '#475569', 'icone' => $d['icone'] ?? 'fa-bullseye', 'criado_por' => $this->operador(),
            ]);
            $this->planos->tocar($plano);

            return $p;
        });
    }

    /** @param array<string, mixed> $d */
    public function actualizarPerspectiva(int $planoId, int $id, array $d, ?string $motivo): PerspectivaEstrategica
    {
        return DB::transaction(function () use ($planoId, $id, $d, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($planoId);
            $p = PerspectivaEstrategica::query()->where('plano_estrategico_id', $plano->id)->findOrFail($id);
            $this->planos->exigirEditavel($plano, $motivo, "Perspectiva «{$p->nome}» alterada.");
            // o ícone só muda quando é enviado: o legado regravava fa-bullseye em todas e perdia os ícones por omissão (UI:344)
            $p->fill([
                'nome' => array_key_exists('nome', $d) ? $this->nomePerspectiva($plano->id, $d['nome'], $p->id) : $p->nome,
                'descricao' => array_key_exists('descricao', $d) ? $this->curto($d['descricao']) : $p->descricao,
                'ordem' => $d['ordem'] ?? $p->ordem, 'cor' => $d['cor'] ?? $p->cor, 'icone' => $d['icone'] ?? $p->icone, 'atualizado_por' => $this->operador(),
            ])->save();
            $this->planos->tocar($plano);

            return $p;
        });
    }

    public function apagarPerspectiva(int $planoId, int $id, ?string $motivo): void
    {
        DB::transaction(function () use ($planoId, $id, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($planoId);
            $p = PerspectivaEstrategica::query()->where('plano_estrategico_id', $plano->id)->findOrFail($id);
            $this->planos->exigirEditavel($plano, $motivo, "Perspectiva «{$p->nome}» apagada.");
            $n = ObjectivoEstrategico::query()->where('perspectiva_id', $p->id)->count();
            if ($n > 0) {
                throw new ErroNegocio("A perspectiva tem {$n} objectivo(s). Mude-os de perspectiva antes de a apagar.", 'PERSPECTIVA_COM_OBJECTIVOS');
            }
            $p->delete();
            $this->planos->tocar($plano);
        });
    }

    // ---- objectivos ---------------------------------------------------------------------------------------------

    /** @param array<string, mixed> $d */
    public function criarObjectivo(int $planoId, array $d, ?string $motivo): ObjectivoEstrategico
    {
        return DB::transaction(function () use ($planoId, $d, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($planoId);
            $this->planos->exigirEditavel($plano, $motivo, 'Objectivo acrescentado.');
            $campos = $this->validar($plano, $d, null);
            $o = ObjectivoEstrategico::query()->create($campos + [
                'plano_estrategico_id' => $plano->id, 'ordem' => (int) ObjectivoEstrategico::query()->where('plano_estrategico_id', $plano->id)->max('ordem') + 10, 'criado_por' => $this->operador(),
            ]);
            $this->planos->tocar($plano);

            return $o;
        });
    }

    /** @param array<string, mixed> $d */
    public function actualizarObjectivo(int $planoId, int $id, array $d, ?string $motivo): ObjectivoEstrategico
    {
        return DB::transaction(function () use ($planoId, $id, $d, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($planoId);
            $o = ObjectivoEstrategico::query()->where('plano_estrategico_id', $plano->id)->lockForUpdate()->findOrFail($id);
            $this->planos->exigirEditavel($plano, $motivo, "Objectivo «{$o->nome}» alterado.");
            $o->fill($this->validar($plano, $d, $o) + ['atualizado_por' => $this->operador()])->save();
            $this->planos->tocar($plano);

            return $o;
        });
    }

    public function apagarObjectivo(int $planoId, int $id, ?string $motivo): void
    {
        DB::transaction(function () use ($planoId, $id, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($planoId);
            $o = ObjectivoEstrategico::query()->where('plano_estrategico_id', $plano->id)->lockForUpdate()->findOrFail($id);
            $this->planos->exigirEditavel($plano, $motivo, "Objectivo «{$o->nome}» apagado.");
            $ind = IndicadorEstrategico::query()->where('objectivo_id', $o->id)->count();
            if ($ind > 0) {
                throw new ErroNegocio("O objectivo tem {$ind} indicador(es). Apague-os ou mude-os de objectivo primeiro.", 'OBJECTIVO_COM_INDICADORES');
            }
            $dep = ObjectivoEstrategico::query()->where('objectivo_pai_id', $o->id)->count();
            if ($dep > 0) {
                throw new ErroNegocio("O objectivo tem {$dep} objectivo(s) dependente(s) (OKR ou desdobramento). Mude-os ou apague-os primeiro.", 'OBJECTIVO_COM_DEPENDENTES');
            }
            $o->delete();
            $this->planos->tocar($plano);
        });
    }

    /**
     * Valida e normaliza os campos de um objectivo (criar ou alterar). `$actual` é o objectivo em edição (ou null).
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function validar(PlanoEstrategico $plano, array $d, ?ObjectivoEstrategico $actual): array
    {
        $g = fn (string $k, mixed $omissao = null) => array_key_exists($k, $d) ? $d[$k] : ($actual?->{$k} ?? $omissao);
        $nome = trim((string) $g('nome', ''));
        if ($nome === '') {
            throw new ErroNegocio('Dê um nome ao objectivo.', 'OBJECTIVO_SEM_NOME');
        }
        $perspectivaId = $g('perspectiva_id');
        if (! $perspectivaId || ! PerspectivaEstrategica::query()->where('plano_estrategico_id', $plano->id)->whereKey($perspectivaId)->exists()) {
            throw new ErroNegocio('Escolha uma perspectiva do plano.', 'OBJECTIVO_PERSPECTIVA_INVALIDA');
        }
        $horizonte = (string) $g('horizonte', 'PLANO');
        $periodo = $horizonte === 'TRIMESTRE' ? (string) $g('periodo', '') : null;
        if ($horizonte === 'TRIMESTRE') {
            if ($periodo === '' || Periodos::frequenciaDe($periodo) !== 'TRIMESTRAL') {
                throw new ErroNegocio('Um OKR tem de dizer a que trimestre pertence.', 'OBJECTIVO_SEM_TRIMESTRE');
            }
            if (! Periodos::noCiclo($periodo, $plano->ano_inicio, $plano->ano_fim)) {
                throw new ErroNegocio("O trimestre do OKR tem de estar dentro do ciclo do plano ({$plano->ano_inicio}-{$plano->ano_fim}).", 'OBJECTIVO_TRIMESTRE_FORA_DO_CICLO');
            }
        }
        $paiId = $g('objectivo_pai_id');
        $paiId = $paiId ? (int) $paiId : null;
        if ($paiId !== null) {
            $this->validarPai($plano, $paiId, $actual?->id);
        }
        $unidadeId = $g('unidade_organica_id');
        if ($unidadeId && ! UnidadeOrganica::query()->whereKey($unidadeId)->exists()) {
            throw new ErroNegocio('A unidade escolhida não existe nesta empresa.', 'OBJECTIVO_UNIDADE_INVALIDA');
        }
        $respId = $g('responsavel_colaborador_id');
        if ($respId && ! Colaborador::query()->whereKey($respId)->exists()) {
            throw new ErroNegocio('O responsável escolhido não existe nesta empresa.', 'OBJECTIVO_RESPONSAVEL_INVALIDO');
        }
        $externo = $respId ? null : $this->curto($g('responsavel_externo'));
        $estado = (string) $g('estado', 'ACTIVO');
        $posicao = $g('posicao_mapa');

        return [
            'perspectiva_id' => (int) $perspectivaId, 'objectivo_pai_id' => $paiId, 'unidade_organica_id' => $unidadeId ? (int) $unidadeId : null,
            'responsavel_colaborador_id' => $respId ? (int) $respId : null, 'responsavel_externo' => $externo, 'nome' => mb_substr($nome, 0, 300),
            'descricao' => ($t = trim((string) $g('descricao', ''))) === '' ? null : $t, 'horizonte' => $horizonte, 'periodo' => $periodo, 'estado' => $estado,
            'posicao_mapa' => $posicao === null || $posicao === '' ? null : $posicao,
        ];
    }

    /** O pai é do mesmo plano, não é o próprio e não é um descendente (nenhum ciclo, a qualquer profundidade). */
    private function validarPai(PlanoEstrategico $plano, int $paiId, ?int $proprioId): void
    {
        if ($proprioId !== null && $paiId === $proprioId) {
            throw new ErroNegocio('Um objectivo não pode depender de si próprio.', 'OBJECTIVO_PAI_CICLO');
        }
        $pais = ObjectivoEstrategico::query()->where('plano_estrategico_id', $plano->id)->pluck('objectivo_pai_id', 'id')->all();
        if (! array_key_exists($paiId, $pais)) {
            throw new ErroNegocio('O objectivo de que depende não pertence a este plano.', 'OBJECTIVO_PAI_INVALIDO');
        }
        $cursor = $paiId;
        $vistos = [];
        while ($cursor !== null && ! isset($vistos[$cursor])) {
            if ($proprioId !== null && $cursor === $proprioId) {
                throw new ErroNegocio('Um objectivo não pode depender de um objectivo que depende dele (ciclo).', 'OBJECTIVO_PAI_CICLO');
            }
            $vistos[$cursor] = true;
            $cursor = $pais[$cursor] ?? null;
        }
    }

    private function nomePerspectiva(int $planoId, mixed $nome, ?int $excepto): string
    {
        $nome = trim((string) $nome);
        if ($nome === '') {
            throw new ErroNegocio('Dê um nome à perspectiva.', 'PERSPECTIVA_SEM_NOME');
        }
        $nome = mb_substr($nome, 0, 120);
        $dup = PerspectivaEstrategica::query()->where('plano_estrategico_id', $planoId)->where('nome', $nome)->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))->exists();
        if ($dup) {
            throw new ErroNegocio('Já existe uma perspectiva com este nome neste plano.', 'PERSPECTIVA_DUPLICADA');
        }

        return $nome;
    }

    private function curto(mixed $v): ?string
    {
        $t = trim((string) $v);

        return $t === '' ? null : mb_substr($t, 0, 300);
    }

    private function operador(): ?string
    {
        return Auth::user()?->nome_utilizador;
    }
}
