<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\OrdemTrabalhoImo;
use App\Models\PlanoManutencaoImo;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Planos de manutenção e vistoria (imobiliario_planos.js do legado): uma vistoria trimestral ou uma pintura anual não é uma ordem, é um
 * calendário; o plano guarda as datas e gera as ordens, uma por data. Só a manutenção preventiva e a vistoria se planeiam. Correcções (P3): gerar
 * é uma só transacção (o legado deixava as ordens já criadas se uma falhasse) e nunca duplica (UNIQUE plano + data, plano bloqueado); a
 * antecedência 0 é válida (o legado tratava-a como 30); o plano com ordens por fechar não se apaga; as datas validam-se (calendário real) e o
 * plano cuja data passou sem ordem aparece como «em falta», com o rasto de que as ordens foram recuperadas (data de pedido = a própria data).
 * A geração também corre sozinha (comando agendado), como o texto das permissões do legado prometia e o código dele nunca fazia.
 */
final class ServicoPlanosManutencaoImo
{
    public function __construct(private readonly ServicoObrasImoveis $obras) {}

    /** @param  array{imovel_id?: ?int, fracao_id?: ?int, tipo?: ?string, activo?: ?bool}  $f @return list<array<string, mixed>> */
    public function listar(array $f = []): array
    {
        $q = PlanoManutencaoImo::query()->orderByRaw("coalesce(proxima, '9999-12-31')")->orderBy('id')->limit(1000);
        foreach (['imovel_id', 'fracao_id'] as $c) {
            if (! empty($f[$c])) {
                $q->where($c, (int) $f[$c]);
            }
        }
        if (! empty($f['tipo'])) {
            $q->where('tipo', $f['tipo']);
        }
        if (isset($f['activo'])) {
            $q->where('activo', (bool) $f['activo']);
        }
        $planos = $q->get();
        $ordens = $this->ordensPorPlano($planos->pluck('id')->all());
        $imoveis = Imovel::query()->whereIn('id', $planos->pluck('imovel_id')->unique())->get(['id', 'codigo', 'nome'])->keyBy('id');
        $fracoes = Fracao::query()->whereIn('id', $planos->pluck('fracao_id')->filter()->unique())->pluck('designacao', 'id');
        $hoje = now()->toDateString();

        return $planos->map(function ($p) use ($ordens, $imoveis, $fracoes, $hoje) {
            $e = CalculadoraObras::estadoDasDatas($p->datas ?? [], $ordens[$p->id] ?? [], $hoje);

            return $p->attributesToArray() + ['imovel_nome' => $imoveis[$p->imovel_id]->nome ?? null, 'imovel_codigo' => $imoveis[$p->imovel_id]->codigo ?? null,
                'fracao_designacao' => $fracoes[$p->fracao_id] ?? null, 'n_datas' => count($p->datas ?? []), 'concluidas' => $e['concluidas'], 'em_falta' => $e['em_falta'], 'por_gerar' => $e['por_gerar'], 'proxima_data' => $e['proxima']];
        })->all();
    }

    /** @return array<string, mixed> o calendário do plano: cada data com o estado e a ordem */
    public function calendario(PlanoManutencaoImo $p): array
    {
        $e = CalculadoraObras::estadoDasDatas($p->datas ?? [], $this->ordensPorPlano([$p->id])[$p->id] ?? [], now()->toDateString());

        return ['plano' => $p->attributesToArray(), 'antecedencia_dias' => (int) $p->antecedencia_dias, 'horizonte' => CalculadoraObras::horizonte(now()->toDateString(), (int) $p->antecedencia_dias)] + $e;
    }

    /** @return array{activos: int, total: int, datas: int, em_falta: int, por_gerar: int} */
    public function resumo(): array
    {
        $planos = PlanoManutencaoImo::query()->get(['id', 'activo', 'datas']);
        $activos = $planos->where('activo', true);
        $ordens = $this->ordensPorPlano($activos->pluck('id')->all());
        $hoje = now()->toDateString();
        $r = ['total' => $planos->count(), 'activos' => $activos->count(), 'datas' => 0, 'em_falta' => 0, 'por_gerar' => 0];
        foreach ($activos as $p) {
            $e = CalculadoraObras::estadoDasDatas($p->datas ?? [], $ordens[$p->id] ?? [], $hoje);
            $r['datas'] += count($p->datas ?? []);
            $r['em_falta'] += $e['em_falta'];
            $r['por_gerar'] += $e['por_gerar'];
        }

        return $r;
    }

    /** @return list<array{tipo: string, texto: string, plano_id: int}> */
    public function avisos(): array
    {
        $planos = PlanoManutencaoImo::query()->where('activo', true)->orderBy('id')->limit(500)->get();
        $ordens = $this->ordensPorPlano($planos->pluck('id')->all());
        $hoje = now()->toDateString();
        $av = [];
        foreach ($planos as $p) {
            $e = CalculadoraObras::estadoDasDatas($p->datas ?? [], $ordens[$p->id] ?? [], $hoje);
            if ($e['em_falta'] > 0) {
                $av[] = ['tipo' => 'PLANO_EM_FALTA', 'texto' => "Plano «{$p->nome}»: {$e['em_falta']} data(s) já passada(s) sem ordem gerada.", 'plano_id' => (int) $p->id];
            }
            $prox = collect($e['linhas'])->first(fn ($l) => ! $l['passada']);
            if ($prox && $prox['ordem_id'] === null && $prox['data'] <= CalculadoraObras::horizonte($hoje, (int) $p->antecedencia_dias)) {
                $av[] = ['tipo' => 'PLANO_PROXIMO', 'texto' => "Plano «{$p->nome}»: a próxima data é {$prox['data']} e ainda não tem ordem.", 'plano_id' => (int) $p->id];
            }
        }

        return $av;
    }

    /** @param  list<int>  $ids  @return array<int, array<string, array{id: int, numero: string, estado: string}>> plano => data => ordem (a última, se houvesse duas) */
    private function ordensPorPlano(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $r = [];
        foreach (OrdemTrabalhoImo::query()->whereIn('plano_id', $ids)->whereNotNull('plano_data')->orderBy('id')->get(['id', 'numero', 'estado', 'plano_id', 'plano_data']) as $o) {
            $r[(int) $o->plano_id][$o->plano_data->format('Y-m-d')] = ['id' => (int) $o->id, 'numero' => $o->numero, 'estado' => $o->estado];
        }

        return $r;
    }

    // ---------------------------------------------------------------- gravar

    /**
     * @param  array<string, mixed>  $d  nome, tipo, descricao, imovel_id, fracao_id, periodicidade, primeira_data, quantas, datas[], prioridade, quem_paga, debitavel,
     *                                   responsavel, antecedencia_dias, custo_estimado, notas, activo
     */
    public function gravar(array $d, ?PlanoManutencaoImo $existente = null): PlanoManutencaoImo
    {
        $tipo = (string) ($d['tipo'] ?? $existente?->tipo ?? 'PREVENTIVA');
        if (! in_array($tipo, CalculadoraObras::TIPOS_PLANEAVEIS, true)) {
            throw new ErroNegocio('Só a manutenção preventiva e a vistoria se planeiam: uma reparação nasce de uma avaria e um apetrechamento de um arrendamento.', 'PLANO_TIPO');
        }
        $descricao = Normaliza::texto($d['descricao'] ?? $existente?->descricao) ?? throw new ErroNegocio('Descreva o que se vai fazer em cada data.', 'PLANO_DESCRICAO');
        $fracaoId = array_key_exists('fracao_id', $d) ? (! empty($d['fracao_id']) ? (int) $d['fracao_id'] : null) : $existente?->fracao_id;
        $imovelId = ! empty($d['imovel_id']) ? (int) $d['imovel_id'] : $existente?->imovel_id;
        if ($fracaoId !== null) {
            $f = Fracao::query()->find($fracaoId) ?? throw new ErroNegocio('Fracção não encontrada.', 'PLANO_FRACCAO');
            if ($imovelId !== null && (int) $f->imovel_id !== $imovelId) {
                throw new ErroNegocio('A fracção não pertence ao imóvel indicado.', 'PLANO_FRACCAO_IMOVEL');
            }
            $imovelId = (int) $f->imovel_id;
        } elseif ($imovelId === null) {
            throw new ErroNegocio('Indique o imóvel ou a fracção do plano.', 'PLANO_LOCAL');
        } elseif (! Imovel::query()->whereKey($imovelId)->exists()) {
            throw new ErroNegocio('Imóvel não encontrado.', 'PLANO_IMOVEL');
        }
        $periodicidade = (string) ($d['periodicidade'] ?? $existente?->periodicidade ?? 'ANUAL');
        $periodicidade = isset(CalculadoraObras::PERIODICIDADES[$periodicidade]) ? $periodicidade : 'ANUAL';
        $datas = CalculadoraObras::limparDatas(array_key_exists('datas', $d) ? (array) $d['datas'] : ($existente?->datas ?? []));
        if ($datas === [] && ! empty($d['primeira_data'])) {
            $datas = CalculadoraObras::proporDatas((string) $d['primeira_data'], $periodicidade, (int) ($d['quantas'] ?? 4));
        }
        if ($datas === []) {
            throw new ErroNegocio('O plano precisa de pelo menos uma data.', 'PLANO_SEM_DATAS');
        }
        if (count($datas) > 60) {
            throw new ErroNegocio('São mais de 60 datas. Faça um plano por ano ou por ciclo.', 'PLANO_DATAS_DEMAIS');
        }
        $prioridade = (string) ($d['prioridade'] ?? $existente?->prioridade ?? 'NORMAL');
        $quem = (string) ($d['quem_paga'] ?? $existente?->quem_paga ?? 'SENHORIO');
        $prioridade = isset(CalculadoraObras::PRIORIDADES[$prioridade]) ? $prioridade : 'NORMAL';
        $quem = isset(CalculadoraObras::QUEM_PAGA[$quem]) ? $quem : 'SENHORIO';
        $custo = array_key_exists('custo_estimado', $d) ? Normaliza::r2($d['custo_estimado'], true) : $existente?->custo_estimado;
        if ($custo !== null && $custo < 0) {
            throw new ErroNegocio('O custo estimado não pode ser negativo.', 'PLANO_CUSTO');
        }
        $antecedencia = array_key_exists('antecedencia_dias', $d) && $d['antecedencia_dias'] !== null && $d['antecedencia_dias'] !== '' ? max(0, (int) round((float) $d['antecedencia_dias'])) : ($existente?->antecedencia_dias ?? 30);
        $hoje = now()->toDateString();
        $nome = Normaliza::texto($d['nome'] ?? $existente?->nome) ?? mb_substr($descricao, 0, 60);
        $campos = ['nome' => mb_substr($nome, 0, 120), 'tipo' => $tipo, 'descricao' => $descricao, 'imovel_id' => $imovelId, 'fracao_id' => $fracaoId, 'periodicidade' => $periodicidade,
            'primeira_data' => Normaliza::texto($d['primeira_data'] ?? null) ?? $datas[0], 'quantas' => max(1, min(60, (int) ($d['quantas'] ?? count($datas)))), 'datas' => array_values($datas),
            'prioridade' => $prioridade, 'quem_paga' => $quem, 'debitavel' => $quem === 'SENHORIO' ? false : (array_key_exists('debitavel', $d) ? (bool) $d['debitavel'] : (bool) ($existente?->debitavel ?? false)),
            'responsavel' => Normaliza::texto($d['responsavel'] ?? $existente?->responsavel), 'antecedencia_dias' => $antecedencia, 'custo_estimado' => $custo,
            'notas' => array_key_exists('notas', $d) ? Normaliza::texto($d['notas']) : $existente?->notas, 'activo' => ! array_key_exists('activo', $d) ? ($existente?->activo ?? true) : (bool) $d['activo'],
            'proxima' => collect($datas)->first(fn ($x) => $x >= $hoje) ?? end($datas), 'atualizado_por' => Autor::nome()];

        return DB::transaction(function () use ($campos, $existente) {
            if ($existente) {
                $p = PlanoManutencaoImo::query()->whereKey($existente->id)->lockForUpdate()->firstOrFail();
                $p->update($campos);

                return $p->refresh();
            }

            return PlanoManutencaoImo::create($campos + ['criado_por' => Autor::nome()])->refresh();
        });
    }

    public function eliminar(PlanoManutencaoImo $p): array
    {
        return DB::transaction(function () use ($p) {
            $p = PlanoManutencaoImo::query()->whereKey($p->id)->lockForUpdate()->firstOrFail();
            $abertas = OrdemTrabalhoImo::query()->where('plano_id', $p->id)->whereNotIn('estado', OrdemTrabalhoImo::FECHADAS)->orderBy('id')->pluck('numero');
            if ($abertas->isNotEmpty()) {
                throw new ErroNegocio("O plano já gerou {$abertas->count()} ordem(ns) por fechar ({$abertas->implode(', ')}). Feche-as ou cancele-as antes de apagar o plano.", 'PLANO_COM_ORDENS');
            }
            $historicas = OrdemTrabalhoImo::query()->where('plano_id', $p->id)->count();
            $p->delete();

            return ['ordens_historicas' => $historicas];
        });
    }

    // ---------------------------------------------------------------- gerar

    /**
     * Gera as ordens do plano: «as próximas» (até hoje + antecedência, ou até à data indicada) ou todas. Salta as datas que já têm ordem (idempotente).
     * Uma só transacção: se uma falhar, nenhuma fica.
     *
     * @return array{criadas: list<array{id: int, numero: string, data: string}>, saltadas: int, horizonte: string}
     */
    public function gerarOrdens(PlanoManutencaoImo $plano, bool $todas = false, ?string $ate = null): array
    {
        return DB::transaction(function () use ($plano, $todas, $ate) {
            $p = PlanoManutencaoImo::query()->whereKey($plano->id)->lockForUpdate()->firstOrFail();
            if (! $p->activo) {
                throw new ErroNegocio('O plano está inactivo: reactive-o antes de gerar ordens.', 'PLANO_INACTIVO');
            }
            $hoje = now()->toDateString();
            $horizonte = $todas ? '9999-12-31' : ($ate ?: CalculadoraObras::horizonte($hoje, (int) $p->antecedencia_dias));
            $existentes = $this->ordensPorPlano([$p->id])[$p->id] ?? [];
            $criadas = [];
            $saltadas = 0;
            foreach ($p->datas ?? [] as $data) {
                if (isset($existentes[$data]) || $data > $horizonte) {
                    $saltadas++;

                    continue;
                }
                $o = $this->obras->gravar([
                    'tipo' => $p->tipo, 'prioridade' => $p->prioridade, 'imovel_id' => $p->imovel_id, 'fracao_id' => $p->fracao_id, 'descricao' => "{$p->descricao} ({$p->nome})", 'quem_paga' => $p->quem_paga,
                    'debitavel' => $p->debitavel, 'custo_estimado' => $p->custo_estimado, 'data_pedido' => $data < $hoje ? $data : $hoje, 'data_prevista' => $data, 'responsavel' => $p->responsavel,
                    'pedido_por' => "Plano {$p->nome}", 'plano_id' => $p->id, 'plano_data' => $data]);
                $criadas[] = ['id' => (int) $o->id, 'numero' => $o->numero, 'data' => $data];
            }
            if ($criadas !== []) {
                $sem = collect($p->datas ?? [])->first(fn ($x) => $x >= $hoje && ! isset($existentes[$x]) && ! in_array($x, array_column($criadas, 'data'), true));
                $p->update(['ultima_geracao' => $hoje, 'proxima' => $sem ?? $p->proxima]);
            }

            return ['criadas' => $criadas, 'saltadas' => $saltadas, 'horizonte' => $todas ? 'todas' : $horizonte];
        });
    }

    /**
     * Gera as ordens de todos os planos activos (a acção «gerar as ordens em dívida» e a tarefa agendada): um plano que falha não impede os restantes.
     *
     * @return array{criadas: int, planos: int, falhas: list<array{plano: string, erro: string}>}
     */
    public function gerarTodos(bool $todas = false): array
    {
        $r = ['criadas' => 0, 'planos' => 0, 'falhas' => []];
        foreach (PlanoManutencaoImo::query()->where('activo', true)->orderBy('id')->get() as $p) {
            try {
                $x = $this->gerarOrdens($p, $todas);
                $r['criadas'] += count($x['criadas']);
                $r['planos'] += count($x['criadas']) > 0 ? 1 : 0;
            } catch (Throwable $e) {
                report($e);
                $r['falhas'][] = ['plano' => $p->nome, 'erro' => $e instanceof ErroNegocio ? $e->getMessage() : 'Não foi possível gerar as ordens deste plano.'];
            }
        }

        return $r;
    }
}
