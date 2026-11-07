<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoArrendamento;
use App\Models\ContratoFracao;
use App\Models\Fracao;
use App\Models\VeiculoContrato;
use Illuminate\Support\Facades\DB;

/**
 * Veículos do contrato (gravarVeiculos do legado): ficam no contrato e não na vaga, porque mudam com o inquilino e o lugar é o mesmo; é
 * esta lista que a portaria consulta. A matrícula é normalizada e única dentro do contrato; a vaga indicada tem de pertencer ao contrato
 * (as do âmbito e as afectas às suas fracções). Correcção (P3): a cor e o dístico gravam-se (o legado perdia-os ao gravar pelo ecrã).
 * Uma matrícula que já consta de outro contrato em vigor do mesmo imóvel só se grava com confirmação.
 */
final class ServicoVeiculos
{
    /**
     * @return array{veiculos: list<array<string, mixed>>, vagas: list<array<string, mixed>>, lugares: int}
     */
    public function doContrato(ContratoArrendamento $c): array
    {
        $vagas = $this->vagasDoContrato($c);

        return [
            'veiculos' => VeiculoContrato::query()->where('contrato_id', $c->id)->orderBy('ordem')->orderBy('id')->get()
                ->map(fn ($v) => $v->only(['id', 'matricula', 'marca', 'modelo', 'cor', 'porte', 'condutor', 'distico', 'vaga_id']))->all(),
            'vagas' => $vagas->map(fn ($v) => ['id' => (int) $v->id, 'designacao' => $v->designacao, 'afecta' => $v->park_afectacao === 'PERMANENTE', 'porte' => $v->park_porte])->values()->all(),
            'lugares' => $vagas->count(),
        ];
    }

    /**
     * Substitui a lista de veículos do contrato.
     *
     * @param  list<array<string, mixed>>  $lista  matricula (obrigatória), marca, modelo, cor, porte, condutor, distico, vaga_id
     * @return array{gravados: int, repetidas: list<array{matricula: string, contratos: list<string>}>}
     */
    public function gravar(ContratoArrendamento $contrato, array $lista, bool $confirmarRepetidas = false): array
    {
        if ($contrato->posicao !== 'SENHORIO') {
            throw new ErroNegocio('Os veículos registam-se nos contratos em que a empresa é a senhoria.', 'VEICULOS_LADO');
        }

        return DB::transaction(function () use ($contrato, $lista, $confirmarRepetidas) {
            $c = ContratoArrendamento::query()->whereKey($contrato->id)->lockForUpdate()->firstOrFail();
            if (! in_array($c->estado, ContratoArrendamento::ESTADOS_VIVOS, true)) {
                throw new ErroNegocio('O contrato já terminou: os veículos não se alteram.', 'VEICULOS_CONTRATO_TERMINADO');
            }
            $abrangidas = $this->vagasDoContrato($c)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $limpos = [];
            $vistas = [];
            foreach ($lista as $i => $x) {
                $n = $i + 1;
                $matricula = CatalogoParqueamento::normalizarMatricula($x['matricula'] ?? null);
                if ($matricula === '') {
                    throw new ErroNegocio("Veículo {$n}: a matrícula é obrigatória.", 'VEICULOS_SEM_MATRICULA');
                }
                if (isset($vistas[$matricula])) {
                    throw new ErroNegocio("A matrícula {$matricula} aparece duas vezes neste contrato.", 'VEICULOS_DUPLICADO');
                }
                $vistas[$matricula] = true;
                $porte = Normaliza::texto($x['porte'] ?? null);
                if ($porte !== null && ! isset(CatalogoParqueamento::PARK_PORTES[$porte])) {
                    throw new ErroNegocio("Veículo {$matricula}: porte desconhecido.", 'VEICULOS_PORTE');
                }
                $vaga = Normaliza::inteiro($x['vaga_id'] ?? null, true) ?: null;
                if ($vaga !== null && ! in_array($vaga, $abrangidas, true)) {
                    throw new ErroNegocio("Veículo {$matricula}: o lugar escolhido não pertence a este contrato.", 'VEICULOS_VAGA');
                }
                $limpos[] = ['matricula' => $matricula, 'marca' => $this->curto($x['marca'] ?? null, 60), 'modelo' => $this->curto($x['modelo'] ?? null, 60), 'cor' => $this->curto($x['cor'] ?? null, 40),
                    'porte' => $porte, 'condutor' => $this->curto($x['condutor'] ?? null, 120), 'distico' => $this->curto($x['distico'] ?? null, 60), 'vaga_id' => $vaga, 'ordem' => $i];
            }
            $repetidas = $this->repetidasNoutros($c, array_keys($vistas));
            if ($repetidas !== [] && ! $confirmarRepetidas) {
                $texto = implode('; ', array_map(fn ($r) => "{$r['matricula']} já consta de ".implode(', ', $r['contratos']), $repetidas));

                throw new ErroNegocio("Há matrículas que já estão noutros contratos em vigor deste imóvel ({$texto}). Confirme para gravar mesmo assim.", 'VEICULOS_MATRICULA_REPETIDA', 409, ['repetidas' => $repetidas]);
            }
            VeiculoContrato::query()->where('contrato_id', $c->id)->delete();
            foreach ($limpos as $l) {
                VeiculoContrato::create($l + ['contrato_id' => $c->id]);
            }

            return ['gravados' => count($limpos), 'repetidas' => $repetidas];
        });
    }

    /**
     * Matrículas (normalizadas) que já constam de outros contratos em vigor do mesmo imóvel.
     *
     * @param  list<string>  $matriculas
     * @return list<array{matricula: string, contratos: list<string>}>
     */
    private function repetidasNoutros(ContratoArrendamento $c, array $matriculas): array
    {
        if ($matriculas === []) {
            return [];
        }
        $outros = ContratoArrendamento::query()->where('imovel_id', $c->imovel_id)->where('id', '<>', $c->id)->whereIn('estado', ContratoArrendamento::ESTADOS_VIVOS)->get(['id', 'numero'])->keyBy('id');
        if ($outros->isEmpty()) {
            return [];
        }
        $achadas = [];
        foreach (VeiculoContrato::query()->whereIn('contrato_id', $outros->keys())->whereIn('matricula', $matriculas)->get(['contrato_id', 'matricula']) as $v) {
            $achadas[$v->matricula][] = (string) $outros[$v->contrato_id]->numero;
        }

        return array_map(fn ($m, $cs) => ['matricula' => $m, 'contratos' => array_values(array_unique($cs))], array_keys($achadas), $achadas);
    }

    /** Vagas a que o contrato dá direito: as do âmbito e as afectas às fracções do âmbito. @return \Illuminate\Support\Collection<int, Fracao> */
    public function vagasDoContrato(ContratoArrendamento $c)
    {
        $ids = ContratoFracao::query()->where('contrato_id', $c->id)->pluck('fracao_id')->map(fn ($i) => (int) $i)->all();
        if ($ids === []) {
            return collect();
        }

        return Fracao::query()->where('natureza', 'ESTACIONAMENTO')->where(fn ($q) => $q->whereIn('id', $ids)->orWhere(fn ($q2) => $q2->where('park_afectacao', 'PERMANENTE')->whereIn('park_fracao_id', $ids)))
            ->orderBy('ordem')->orderBy('id')->get();
    }

    private function curto(mixed $v, int $max): ?string
    {
        $t = Normaliza::texto($v);

        return $t === null ? null : mb_substr($t, 0, $max);
    }
}
