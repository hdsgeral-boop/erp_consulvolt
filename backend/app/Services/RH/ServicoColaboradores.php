<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\Colaborador;
use App\Models\CoordenadaBancariaColaborador;
use App\Models\DependenteColaborador;
use App\Models\HabilitacaoColaborador;
use App\Models\PostoTrabalho;
use App\Models\Terceiro;
use App\Support\Dados\VerificadorReferencias;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Colaboradores (renderColaboradores/saveEmployee, js/app_v2.js:3833-3997 e 9068-9127; ficha em
 * js/modules/rh/ficha_colaborador.js) e coordenadas bancárias (js/app_v2.js:9536-9600). Paridade:
 *   - nome e NIF obrigatórios; reformado e avençado são exclusivos; ficha (dependentes e habilitações) gravada
 *     por substituição, na mesma transacção; habilitação máxima calculada quando vazia;
 *   - cria o terceiro «COLABORADOR» com o mesmo NIF (e actualiza-lhe o nome).
 * Correcções (ADR-037):
 *   - NIF normalizado (sem espaços, maiúsculas) e único na empresa (o formulário do legado não verificava;
 *     só a importação); a procura do terceiro é na empresa (no legado era global, entre empresas);
 *   - dias úteis 1–31 validados no servidor; gestor ≠ o próprio; o posto tem de pertencer à unidade orgânica;
 *   - eliminar deixa de apagar em cascata e de deixar contratos/lançamentos órfãos: é lógica e só é permitida
 *     sem utilizações (para quem saiu: estado INACTIVO);
 *   - IBAN: validado (AO + 23 dígitos com dígitos de controlo, ou IBAN estrangeiro) — os botões do ecrã do
 *     legado nem funcionavam (funções com «º» no nome) — e banco obrigatório.
 */
final class ServicoColaboradores
{
    public const NIVEIS = ['Ensino primário', 'Ensino secundário (I ciclo)', 'Ensino médio (II ciclo)', 'Técnico médio', 'Bacharelato', 'Licenciatura',
        'Pós-graduação', 'Mestrado', 'Doutoramento', 'Outro'];

    public function __construct(private readonly VerificadorReferencias $referencias) {}

    /** @param  array<string, mixed>  $f */
    public function listar(array $f): LengthAwarePaginator
    {
        return Colaborador::query()
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($f['tipo_organizacao_id'] ?? null, fn ($q, $t) => $q->where('tipo_organizacao_id', $t))
            ->when($f['pesquisa'] ?? null, function ($q, $p) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $p).'%';
                $q->where(fn ($s) => $s->where('nome_completo', 'ilike', $termo)->orWhere('nif', 'ilike', $termo)->orWhere('numero_inss', 'ilike', $termo));
            })
            ->orderBy('nome_completo')
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));
    }

    /** Ficha completa: dados, dependentes, habilitações e coordenadas bancárias. */
    public function ficha(Colaborador $c): array
    {
        return $c->toArray() + [
            'dependentes' => DependenteColaborador::query()->where('colaborador_id', $c->id)->orderBy('ordem')->orderBy('id')->get()->toArray(),
            'habilitacoes' => HabilitacaoColaborador::query()->where('colaborador_id', $c->id)->orderBy('ordem')->orderBy('id')->get()->toArray(),
            'coordenada_bancaria' => CoordenadaBancariaColaborador::query()->where('colaborador_id', $c->id)->first()?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $d  dados validados; 'dependentes'/'habilitacoes' (se presentes) substituem os existentes
     */
    public function guardar(array $d, ?Colaborador $c = null): Colaborador
    {
        $d['nif'] = self::normalizarNif((string) $d['nif']);
        if (! empty($d['reformado']) && ! empty($d['avencado'])) {
            throw new ErroNegocio('Um colaborador não pode ser reformado e avençado ao mesmo tempo.', 'REFORMADO_E_AVENCADO', 422);
        }
        if ($c && isset($d['colaborador_gestor_id']) && (int) $d['colaborador_gestor_id'] === $c->id) {
            throw new ErroNegocio('O colaborador não pode ser o seu próprio gestor.', 'GESTOR_INVALIDO', 422);
        }
        if (! empty($d['posto_trabalho_id'])) {
            $posto = PostoTrabalho::query()->findOrFail($d['posto_trabalho_id']);
            if (empty($d['unidade_organica_id']) || (int) $posto->unidade_organica_id !== (int) $d['unidade_organica_id']) {
                throw new ErroNegocio('O posto de trabalho tem de pertencer à unidade orgânica indicada.', 'POSTO_FORA_DA_UNIDADE', 422);
            }
        }
        $outro = Colaborador::query()->where('nif', $d['nif'])->when($c, fn ($q) => $q->whereKeyNot($c->id))->first();
        if ($outro) {
            throw new ErroNegocio("Já existe um colaborador com o NIF {$d['nif']} ({$outro->nome_completo}).", 'NIF_DUPLICADO', 422, ['colaborador_id' => $outro->id]);
        }
        $dependentes = $d['dependentes'] ?? null;
        $habilitacoes = $d['habilitacoes'] ?? null;
        unset($d['dependentes'], $d['habilitacoes']);
        if ($habilitacoes !== null && empty($d['habilitacao_maxima'])) {
            $d['habilitacao_maxima'] = self::habilitacaoMaxima($habilitacoes);
        }

        return DB::transaction(function () use ($d, $c, $dependentes, $habilitacoes) {
            if ($c) {
                $c->update($d);
            } else {
                $c = Colaborador::create($d + ['estado' => 'ACTIVO', 'dias_uteis_mes' => 22, 'nacionalidade' => 'Angolana']);
            }
            if ($dependentes !== null) {
                DependenteColaborador::query()->where('colaborador_id', $c->id)->delete();
                foreach (array_values($dependentes) as $n => $dep) {
                    DependenteColaborador::create(['colaborador_id' => $c->id, 'ordem' => $n + 1, 'origem' => 'FICHA'] + $dep);
                }
            }
            if ($habilitacoes !== null) {
                HabilitacaoColaborador::query()->where('colaborador_id', $c->id)->delete();
                foreach (array_values($habilitacoes) as $n => $h) {
                    HabilitacaoColaborador::create(['colaborador_id' => $c->id, 'ordem' => (string) ($n + 1), 'estado' => 'Concluído'] + $h);
                }
            }
            $this->sincronizarTerceiro($c);

            return $c->refresh();
        });
    }

    public function eliminar(Colaborador $c): void
    {
        DB::transaction(function () use ($c) {
            $this->referencias->exigirLivre('colaboradores', $c->id, "o colaborador {$c->nome_completo}",
                ['dependentes_colaboradores', 'habilitacoes_colaboradores', 'coordenadas_bancarias_colaboradores'],
                ['resultados_folha_salarial' => 'colaborador_id'], 'Para quem saiu da empresa, altere o estado para INACTIVO.');
            DependenteColaborador::query()->where('colaborador_id', $c->id)->delete();
            HabilitacaoColaborador::query()->where('colaborador_id', $c->id)->delete();
            CoordenadaBancariaColaborador::query()->where('colaborador_id', $c->id)->delete();
            $c->delete();   // eliminação lógica
        });
    }

    // ───────────── Coordenadas bancárias ─────────────

    /** Um IBAN por colaborador (upsert, como no legado). */
    public function gravarCoordenada(Colaborador $c, int $bancoId, string $iban): CoordenadaBancariaColaborador
    {
        $banco = Banco::query()->findOrFail($bancoId);
        $iban = self::normalizarIban($iban);
        if (! self::ibanValido($iban)) {
            throw new ErroNegocio('IBAN inválido: indique AO + 23 dígitos (ou os 21 dígitos do NIB), ou um IBAN estrangeiro válido.', 'IBAN_INVALIDO', 422);
        }

        return DB::transaction(fn () => CoordenadaBancariaColaborador::query()->updateOrCreate(['colaborador_id' => $c->id], ['banco_id' => $banco->id, 'iban' => $iban]));
    }

    public function eliminarCoordenada(Colaborador $c): void
    {
        CoordenadaBancariaColaborador::query()->where('colaborador_id', $c->id)->firstOrFail()->delete();
    }

    // ───────────── Auxiliares ─────────────

    public static function normalizarNif(string $nif): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', $nif));
    }

    /** Sem espaços, maiúsculas; o NIB angolano (21 dígitos) passa a IBAN com AO06. */
    public static function normalizarIban(string $iban): string
    {
        $iban = strtoupper(preg_replace('/[\s.-]+/', '', $iban));

        return preg_match('/^\d{21}$/', $iban) ? 'AO06'.$iban : $iban;
    }

    /** ISO 13616 (resto 97 = 1); em Angola, AO + 23 dígitos. */
    public static function ibanValido(string $iban): bool
    {
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) || (str_starts_with($iban, 'AO') && ! preg_match('/^AO\d{23}$/', $iban))) {
            return false;
        }
        $numerico = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $ch) {
            $numerico .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }

        return bcmod($numerico, '97') === '1';
    }

    /** Maior nível concluído (ordem de NIVEIS; «Outro» não conta). */
    public static function habilitacaoMaxima(array $habilitacoes): ?string
    {
        $melhor = -1;
        foreach ($habilitacoes as $h) {
            $i = array_search($h['nivel'] ?? '', self::NIVEIS, true);
            if ($i !== false && $h['nivel'] !== 'Outro' && ($h['estado'] ?? 'Concluído') === 'Concluído' && $i > $melhor) {
                $melhor = $i;
            }
        }

        return $melhor >= 0 ? self::NIVEIS[$melhor] : null;
    }

    /** Terceiro «COLABORADOR» com o mesmo NIF (usado nos adiantamentos e movimentos de caixa ao colaborador). */
    private function sincronizarTerceiro(Colaborador $c): void
    {
        $t = Terceiro::query()->where('nif', $c->nif)->first();
        if (! $t) {
            Terceiro::create(['nif' => $c->nif, 'nome' => $c->nome_completo, 'tipo' => Terceiro::COLABORADOR, 'codigo_moeda' => 'AOA']);
        } elseif ($t->tipo === Terceiro::COLABORADOR && $t->nome !== $c->nome_completo) {
            $t->update(['nome' => $c->nome_completo]);
        }
    }
}
