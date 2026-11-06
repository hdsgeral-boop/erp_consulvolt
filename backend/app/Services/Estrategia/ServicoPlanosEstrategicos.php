<?php

namespace App\Services\Estrategia;

use App\Exceptions\ErroNegocio;
use App\Models\IndicadorEstrategico;
use App\Models\ObjectivoEstrategico;
use App\Models\PerspectivaEstrategica;
use App\Models\PlanoEstrategico;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Planos estratégicos: criação (com as quatro perspectivas do Balanced Scorecard), edição, máquina de estados com regras no
 * servidor e congelamento do plano aprovado.
 *
 *   RASCUNHO ──aprovar──▶ APROVADO ──iniciar execução──▶ EM_EXECUCAO ──encerrar──▶ ENCERRADO
 *      ▲                     │
 *      └────── reabrir ──────┘   (só a partir de APROVADO, com motivo)
 *
 * Um plano em vigor (APROVADO ou EM_EXECUCAO) por empresa (índice único parcial). Aprovado = congelado: a estrutura (plano,
 * perspectivas, objectivos, indicadores, metas) só se altera com est_plano_aprovar e um motivo registado na auditoria, ou
 * reabrindo o plano. Encerrado: só leitura. Segregação de funções: quem criou ou editou o plano por último não o aprova,
 * salvo excepção com motivo (guardada no plano e na auditoria). As medições não são estrutura: ficam livres até ao encerramento.
 */
final class ServicoPlanosEstrategicos
{
    public const MOTIVO_MIN = 10;

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return PlanoEstrategico::query()->orderByDesc('ano_inicio')->orderBy('nome')->orderByDesc('versao')->get()
            ->map(fn (PlanoEstrategico $p) => $this->resumo($p))->all();
    }

    /** @return array<string, mixed> */
    public function resumo(PlanoEstrategico $p): array
    {
        return [
            'id' => $p->id, 'nome' => $p->nome, 'ano_inicio' => $p->ano_inicio, 'ano_fim' => $p->ano_fim, 'estado' => $p->estado,
            'estado_rotulo' => Vocabulario::ESTADOS_PLANO[$p->estado] ?? $p->estado, 'versao' => $p->versao, 'congelado' => $this->congelado($p),
            'em_vigor' => in_array($p->estado, ['APROVADO', 'EM_EXECUCAO'], true),
        ];
    }

    public function obter(int $id): PlanoEstrategico
    {
        return PlanoEstrategico::query()->findOrFail($id);
    }

    public function congelado(PlanoEstrategico $p): bool
    {
        return $p->estado !== 'RASCUNHO';
    }

    /** @param array<string, mixed> $d */
    public function criar(array $d): PlanoEstrategico
    {
        $this->validarCiclo((int) $d['ano_inicio'], (int) $d['ano_fim']);

        return DB::transaction(function () use ($d) {
            $nome = trim((string) $d['nome']);
            if (PlanoEstrategico::query()->where('nome', $nome)->where('versao', 1)->exists()) {
                throw new ErroNegocio('Já existe um plano com este nome. Escolha outro.', 'PLANO_NOME_DUPLICADO');
            }
            $plano = PlanoEstrategico::query()->create([
                'nome' => $nome, 'ano_inicio' => (int) $d['ano_inicio'], 'ano_fim' => (int) $d['ano_fim'],
                'missao' => $this->texto($d['missao'] ?? null), 'visao' => $this->texto($d['visao'] ?? null), 'valores' => $this->texto($d['valores'] ?? null),
                'estado' => 'RASCUNHO', 'versao' => 1, 'autor_id' => Auth::id(), 'editado_por_id' => Auth::id(),
                'criado_por' => $this->operador(), 'atualizado_por' => $this->operador(),
            ]);
            foreach (Vocabulario::PERSPECTIVAS_OMISSAO as $i => $p) {
                PerspectivaEstrategica::query()->create($p + ['plano_estrategico_id' => $plano->id, 'ordem' => ($i + 1) * 10, 'criado_por' => $this->operador()]);
            }

            return $plano;
        });
    }

    /** @param array<string, mixed> $d */
    public function actualizar(int $id, array $d, ?string $motivoExcepcao): PlanoEstrategico
    {
        return DB::transaction(function () use ($id, $d, $motivoExcepcao) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($id);
            $this->exigirEditavel($plano, $motivoExcepcao, 'Dados do plano alterados.');
            $inicio = (int) ($d['ano_inicio'] ?? $plano->ano_inicio);
            $fim = (int) ($d['ano_fim'] ?? $plano->ano_fim);
            $this->validarCiclo($inicio, $fim);
            $nome = trim((string) ($d['nome'] ?? $plano->nome));
            if (PlanoEstrategico::query()->where('nome', $nome)->where('versao', $plano->versao)->where('id', '<>', $plano->id)->exists()) {
                throw new ErroNegocio('Já existe um plano com este nome. Escolha outro.', 'PLANO_NOME_DUPLICADO');
            }
            if (($inicio > $plano->ano_inicio || $fim < $plano->ano_fim) && $this->temDadosForaDoCiclo($plano, $inicio, $fim)) {
                throw new ErroNegocio('Há metas ou objectivos fora do novo ciclo. Retire-os antes de encurtar o plano.', 'PLANO_CICLO_COM_DADOS');
            }
            $plano->fill([
                'nome' => $nome, 'ano_inicio' => $inicio, 'ano_fim' => $fim,
                'missao' => array_key_exists('missao', $d) ? $this->texto($d['missao']) : $plano->missao,
                'visao' => array_key_exists('visao', $d) ? $this->texto($d['visao']) : $plano->visao,
                'valores' => array_key_exists('valores', $d) ? $this->texto($d['valores']) : $plano->valores,
                'editado_por_id' => Auth::id(), 'atualizado_por' => $this->operador(),
            ])->save();

            return $plano;
        });
    }

    /** RASCUNHO → APROVADO. `$excepcao`: motivo para aprovar um plano que o próprio criou ou editou por último. */
    public function aprovar(int $id, ?string $excepcao = null): PlanoEstrategico
    {
        return DB::transaction(function () use ($id, $excepcao) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($id);
            $this->exigirEstado($plano, ['RASCUNHO'], 'Só se aprova um plano em elaboração.');
            $vigente = PlanoEstrategico::query()->whereIn('estado', ['APROVADO', 'EM_EXECUCAO'])->where('id', '<>', $plano->id)->first();
            if ($vigente !== null) {
                throw new ErroNegocio("Já existe um plano em vigor: «{$vigente->nome}». Encerre-o antes de aprovar outro.", 'PLANO_JA_VIGENTE');
            }
            $problemas = $this->problemasDeAprovacao($plano);
            if ($problemas !== []) {
                throw new ErroNegocio('O plano ainda não pode ser aprovado: '.implode(' ', array_slice($problemas, 0, 3)), 'PLANO_INCOMPLETO', 422, ['problemas' => $problemas]);
            }
            $eu = Auth::id();
            $propria = $eu !== null && ((int) $plano->autor_id === (int) $eu || (int) $plano->editado_por_id === (int) $eu);
            $motivo = $this->motivo($excepcao);
            if ($propria && $motivo === null) {
                throw new ErroNegocio('Quem elaborou ou editou o plano não o aprova. Peça a outra pessoa ou, se não houver, indique o motivo da excepção (mínimo '.self::MOTIVO_MIN.' caracteres).', 'AUTO_APROVACAO');
            }
            $plano->fill(['estado' => 'APROVADO', 'aprovado_em' => now(), 'aprovado_por' => $this->operador(), 'aprovado_por_id' => $eu,
                'aprovacao_excepcao' => $propria ? $motivo : null, 'motivo_reabertura' => null, 'atualizado_por' => $this->operador()])->save();
            if ($propria) {
                app(ServicoAuditoria::class)->registar('Estratégia', 'Plano aprovado por quem o elaborou (excepção)', "Plano «{$plano->nome}»: {$motivo}", $plano->getTable(), $plano->id);
            }

            return $plano;
        });
    }

    public function iniciarExecucao(int $id): PlanoEstrategico
    {
        return DB::transaction(function () use ($id) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($id);
            $this->exigirEstado($plano, ['APROVADO'], 'Só se inicia a execução de um plano aprovado.');
            $plano->fill(['estado' => 'EM_EXECUCAO', 'execucao_iniciada_em' => now(), 'execucao_iniciada_por' => $this->operador(), 'atualizado_por' => $this->operador()])->save();

            return $plano;
        });
    }

    public function reabrir(int $id, ?string $motivo): PlanoEstrategico
    {
        $motivo = trim((string) $motivo);
        if (mb_strlen($motivo) < 5) {
            throw new ErroNegocio('Indique o motivo da reabertura do plano.', 'PLANO_MOTIVO_EM_FALTA');
        }

        return DB::transaction(function () use ($id, $motivo) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($id);
            $this->exigirEstado($plano, ['APROVADO'], 'Só se reabre um plano aprovado que ainda não entrou em execução.');
            $plano->fill(['estado' => 'RASCUNHO', 'aprovado_em' => null, 'aprovado_por' => null, 'aprovado_por_id' => null, 'aprovacao_excepcao' => null,
                'motivo_reabertura' => mb_substr($motivo, 0, 300), 'atualizado_por' => $this->operador()])->save();
            app(ServicoAuditoria::class)->registar('Estratégia', 'Plano reaberto', "Plano «{$plano->nome}»: {$motivo}", $plano->getTable(), $plano->id);

            return $plano;
        });
    }

    public function encerrar(int $id): PlanoEstrategico
    {
        return DB::transaction(function () use ($id) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($id);
            $this->exigirEstado($plano, ['APROVADO', 'EM_EXECUCAO'], 'Só se encerra um plano aprovado ou em execução.');
            $plano->fill(['estado' => 'ENCERRADO', 'encerrado_em' => now(), 'encerrado_por' => $this->operador(), 'atualizado_por' => $this->operador()])->save();

            return $plano;
        });
    }

    /** Só se apaga um rascunho sem objectivos nem indicadores (as perspectivas de origem vão com ele). */
    public function apagar(int $id): void
    {
        DB::transaction(function () use ($id) {
            $plano = PlanoEstrategico::query()->lockForUpdate()->findOrFail($id);
            if ($plano->estado !== 'RASCUNHO') {
                throw new ErroNegocio('Só se apaga um plano em elaboração. Um plano aprovado encerra-se; não se apaga.', 'PLANO_NAO_APAGAVEL');
            }
            if (ObjectivoEstrategico::query()->where('plano_estrategico_id', $plano->id)->exists() || IndicadorEstrategico::query()->where('plano_estrategico_id', $plano->id)->exists()) {
                throw new ErroNegocio('O plano já tem objectivos ou indicadores. Apague-os primeiro.', 'PLANO_COM_DADOS');
            }
            PerspectivaEstrategica::query()->where('plano_estrategico_id', $plano->id)->delete();
            $plano->delete();
        });
    }

    /**
     * Garante que o plano pode ter a estrutura alterada. Plano em elaboração: sim. Aprovado ou em execução: só com a tarefa
     * est_plano_aprovar e um motivo (≥ 10 caracteres), que fica na auditoria. Encerrado: nunca.
     */
    public function exigirEditavel(PlanoEstrategico $plano, ?string $motivoExcepcao, string $descricao): void
    {
        if ($plano->estado === 'RASCUNHO') {
            return;
        }
        if ($plano->estado === 'ENCERRADO') {
            throw new ErroNegocio('O plano está encerrado: só se pode consultar.', 'PLANO_FECHADO');
        }
        $motivo = $this->motivo($motivoExcepcao);
        if (! Gate::allows('est_plano_aprovar') || $motivo === null) {
            throw new ErroNegocio('O plano está aprovado e congelado. Reabra-o, ou, com a permissão de aprovação, indique o motivo da alteração (mínimo '.self::MOTIVO_MIN.' caracteres).', 'PLANO_APROVADO_BLOQUEADO');
        }
        app(ServicoAuditoria::class)->registar('Estratégia', 'Alteração a plano aprovado (excepção)', "Plano «{$plano->nome}»: {$descricao} Motivo: {$motivo}", $plano->getTable(), $plano->id);
    }

    /** Medições e entregas: livres, excepto num plano encerrado. */
    public function exigirNaoEncerrado(PlanoEstrategico $plano): void
    {
        if ($plano->estado === 'ENCERRADO') {
            throw new ErroNegocio('O plano está encerrado: só se pode consultar.', 'PLANO_FECHADO');
        }
    }

    /** Marca quem editou por último (a segregação aprovar ≠ editar usa-o). */
    public function tocar(PlanoEstrategico $plano): void
    {
        if ($plano->estado === 'RASCUNHO' && Auth::id() !== null) {
            $plano->forceFill(['editado_por_id' => Auth::id(), 'atualizado_por' => $this->operador()])->save();
        }
    }

    /**
     * Pré-condições da aprovação: ≥ 1 objectivo activo; cada objectivo activo com ≥ 1 indicador activo; cada indicador activo com
     * origem utilizável, dono (se manual) e ≥ 1 meta («um objectivo que não se mede não se cumpre»).
     *
     * @return list<string>
     */
    public function problemasDeAprovacao(PlanoEstrategico $plano): array
    {
        $objectivos = ObjectivoEstrategico::query()->where('plano_estrategico_id', $plano->id)->where('estado', 'ACTIVO')->orderBy('ordem')->orderBy('id')->get(['id', 'nome']);
        if ($objectivos->isEmpty()) {
            return ['Falta pelo menos um objectivo activo.'];
        }
        $indicadores = IndicadorEstrategico::query()->where('plano_estrategico_id', $plano->id)->where('ativo', true)->withCount('metas')->get();
        $porObjectivo = $indicadores->groupBy('objectivo_id');
        $problemas = [];
        foreach ($objectivos as $o) {
            if (! $porObjectivo->has($o->id)) {
                $problemas[] = "O objectivo «{$o->nome}» não tem indicadores: o que não se mede não se cumpre.";
            }
        }
        foreach ($indicadores as $i) {
            if (! in_array($i->origem, Vocabulario::ORIGENS_DISPONIVEIS, true)) {
                $problemas[] = "O indicador «{$i->nome}» tem uma origem que ainda não se mede.";
            }
            if ($i->metas_count === 0) {
                $problemas[] = "O indicador «{$i->nome}» não tem metas.";
            }
        }

        return $problemas;
    }

    private function validarCiclo(int $inicio, int $fim): void
    {
        if ($inicio <= 1900 || $fim < $inicio) {
            throw new ErroNegocio('Indique o ano de início e um ano de fim igual ou posterior.', 'PLANO_CICLO_INVALIDO');
        }
        if ($fim - $inicio > 14) {
            throw new ErroNegocio('Um plano estratégico cobre no máximo 15 anos.', 'PLANO_CICLO_LONGO');
        }
    }

    private function temDadosForaDoCiclo(PlanoEstrategico $plano, int $inicio, int $fim): bool
    {
        $metas = DB::table('metas_indicadores as m')->join('indicadores_estrategicos as i', 'i.id', '=', 'm.indicador_id')
            ->where('i.plano_estrategico_id', $plano->id)
            ->where(fn ($q) => $q->whereRaw('substr(m.periodo, 1, 4)::int < ?', [$inicio])->orWhereRaw('substr(m.periodo, 1, 4)::int > ?', [$fim]))->exists();
        $okrs = ObjectivoEstrategico::query()->where('plano_estrategico_id', $plano->id)->whereNotNull('periodo')
            ->where(fn ($q) => $q->whereRaw('substr(periodo, 1, 4)::int < ?', [$inicio])->orWhereRaw('substr(periodo, 1, 4)::int > ?', [$fim]))->exists();

        return $metas || $okrs;
    }

    /** @param list<string> $permitidos */
    private function exigirEstado(PlanoEstrategico $p, array $permitidos, string $mensagem): void
    {
        if (! in_array($p->estado, $permitidos, true)) {
            throw new ErroNegocio($mensagem, 'PLANO_ESTADO_INVALIDO');
        }
    }

    private function motivo(?string $m): ?string
    {
        $m = trim((string) $m);

        return mb_strlen($m) >= self::MOTIVO_MIN ? mb_substr($m, 0, 300) : null;
    }

    private function texto(mixed $v): ?string
    {
        $t = trim((string) $v);

        return $t === '' ? null : $t;
    }

    private function operador(): ?string
    {
        return Auth::user()?->nome_utilizador;
    }
}
