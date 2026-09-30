<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\InfotipoSalarial;
use App\Models\MapeamentoContabilRH;
use App\Models\MapeamentoContabilSistemaRH;
use App\Models\TipoOrganizacaoRH;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Dados\VerificadorReferencias;
use Illuminate\Support\Facades\DB;

/**
 * Tabelas de suporte dos salários: rubricas (infotipos, js/app_v2.js:4130-4259 e 9129-9206), tipos de
 * organização, bancos (9314-9534) e mapeamento contabilístico (saveAllAccountingMapos, 2287-2469).
 * Correcções (ADR-037):
 *   - rubrica: nome único na empresa (o formulário não verificava; há 2 pares repetidos no legado, que ficam);
 *     domínios de tipo, IRT e cálculo por horas validados; eliminar só sem utilizações (lançamentos, contratos,
 *     produtividade) — o legado apagava e deixava contratos e lançamentos a apontar para o vazio;
 *   - banco: nome e código únicos; conta de movimento; eliminar só sem IBAN associados (o legado dizia
 *     «desassociar» mas deixava os IBAN órfãos);
 *   - mapeamentos: a conta TEM de existir e ser de movimento (o legado só recusava totalizadoras e guardava contas
 *     «fora do plano»); um mapeamento por chave (havia 5 chaves duplicadas); limpar = apagar (não gravar '').
 */
final class ServicoCadastrosRH
{
    /** Códigos das contas do sistema no mapeamento (o legado mostrava os 6 primeiros; ROUNDING_DIFF vinha do assistente). */
    public const CODIGOS_SISTEMA = ['NET_PAY_CREDIT', 'IRT_CREDIT', 'IRT_AVENCADO_CREDIT', 'INSS_FUNC_CREDIT', 'INSS_EMP_DEBIT', 'INSS_EMP_CREDIT', 'ROUNDING_DIFF'];

    public function __construct(
        private readonly ServicoPlanoContas $plano,
        private readonly VerificadorReferencias $referencias,
    ) {}

    // ───────────── Rubricas ─────────────

    /** @param  array<string, mixed>  $d */
    public function guardarInfotipo(array $d, ?InfotipoSalarial $i = null): InfotipoSalarial
    {
        $d['nome'] = trim((string) $d['nome']);
        $repetido = InfotipoSalarial::query()->whereRaw('lower(nome) = lower(?)', [$d['nome']])->when($i, fn ($q) => $q->whereKeyNot($i->id))->exists();
        if ($repetido) {
            throw new ErroNegocio("Já existe a rubrica «{$d['nome']}».", 'RUBRICA_DUPLICADA', 422);
        }
        if (($d['calculo_horas'] ?? null) === '') {
            $d['calculo_horas'] = null;
        }
        if ($i) {
            $i->update($d);

            return $i->refresh();
        }

        return InfotipoSalarial::create($d + ['tipo' => 'VENCIMENTO', 'sujeito_inss' => true, 'irt' => 'true', 'base_horaria' => false]);
    }

    public function eliminarInfotipo(InfotipoSalarial $i): void
    {
        DB::transaction(function () use ($i) {
            $this->referencias->exigirLivre('infotipos_salariais', $i->id, "a rubrica {$i->nome}", ['mapeamentos_contabeis_rh']);
            $emContratos = DB::table('contratos_trabalho')->where('empresa_id', $i->empresa_id)
                ->whereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(remuneracoes) r WHERE COALESCE(r->>'infotipo_id', r->>'infotype_id') = ?)", [(string) $i->id])->count();
            if ($emContratos) {
                throw new ErroNegocio("Não é possível eliminar a rubrica {$i->nome}: está em {$emContratos} contrato(s).", 'REGISTO_EM_USO', 422,
                    ['utilizacoes' => ['contratos_trabalho.remuneracoes' => $emContratos]]);
            }
            MapeamentoContabilRH::query()->where('infotipo_salarial_id', $i->id)->delete();
            $i->delete();   // eliminação lógica
        });
    }

    // ───────────── Tipos de organização ─────────────

    public function guardarTipoOrganizacao(string $nome, ?TipoOrganizacaoRH $t = null): TipoOrganizacaoRH
    {
        $nome = trim($nome);
        if (TipoOrganizacaoRH::query()->whereRaw('lower(nome) = lower(?)', [$nome])->when($t, fn ($q) => $q->whereKeyNot($t->id))->exists()) {
            throw new ErroNegocio("Já existe o tipo de organização «{$nome}».", 'TIPO_ORGANIZACAO_DUPLICADO', 422);
        }
        $t ? $t->update(['nome' => $nome]) : $t = TipoOrganizacaoRH::create(['nome' => $nome]);

        return $t->refresh();
    }

    public function eliminarTipoOrganizacao(TipoOrganizacaoRH $t): void
    {
        DB::transaction(function () use ($t) {
            $this->referencias->exigirLivre('tipos_organizacao_rh', $t->id, "o tipo de organização {$t->nome}", ['mapeamentos_contabeis_rh', 'mapeamentos_contabeis_sistema_rh'],
                ['resultados_folha_salarial' => 'tipo_organizacao_id']);
            MapeamentoContabilRH::query()->where('tipo_organizacao_id', $t->id)->delete();
            MapeamentoContabilSistemaRH::query()->where('tipo_organizacao_id', $t->id)->delete();
            $t->delete();
        });
    }

    // ───────────── Bancos ─────────────

    /** @param  array<string, mixed>  $d */
    public function guardarBanco(array $d, ?Banco $b = null): Banco
    {
        $d['nome'] = trim((string) $d['nome']);
        if (Banco::query()->whereRaw('lower(nome) = lower(?)', [$d['nome']])->when($b, fn ($q) => $q->whereKeyNot($b->id))->exists()) {
            throw new ErroNegocio("Já existe o banco «{$d['nome']}».", 'BANCO_DUPLICADO', 422);
        }
        if (! empty($d['codigo']) && Banco::query()->where('codigo', $d['codigo'])->when($b, fn ($q) => $q->whereKeyNot($b->id))->exists()) {
            throw new ErroNegocio("Já existe um banco com o código {$d['codigo']}.", 'BANCO_DUPLICADO', 422);
        }
        if (! empty($d['codigo_conta'])) {
            $this->plano->contaDeMovimento((string) $d['codigo_conta']);
        }
        $b ? $b->update($d) : $b = Banco::create($d);

        return $b->refresh();
    }

    public function eliminarBanco(Banco $b): void
    {
        $this->referencias->exigirLivre('bancos', $b->id, "o banco {$b->nome}");
        $b->delete();
    }

    // ───────────── Mapeamento contabilístico ─────────────

    /** Matriz do ecrã: tipos de organização (+ coluna Avençado), rubricas e contas do sistema. */
    public function mapeamentos(): array
    {
        return [
            'tipos_organizacao' => TipoOrganizacaoRH::query()->orderBy('nome')->get(['id', 'nome']),
            'infotipos' => InfotipoSalarial::query()->whereIn('tipo', ['VENCIMENTO', 'DESCONTO'])->orderBy('tipo', 'desc')->orderBy('nome')->get(['id', 'nome', 'tipo']),
            'codigos_sistema' => self::CODIGOS_SISTEMA,
            'rubricas' => MapeamentoContabilRH::query()->whereNotNull('numero_conta')->where('numero_conta', '<>', '')->orderBy('id')
                ->get(['id', 'infotipo_salarial_id', 'tipo_organizacao_id', 'avencado', 'numero_conta']),
            'sistema' => MapeamentoContabilSistemaRH::query()->whereNotNull('numero_conta')->where('numero_conta', '<>', '')->orderBy('id')
                ->get(['id', 'codigo', 'tipo_organizacao_id', 'avencado', 'numero_conta']),
        ];
    }

    /**
     * Grava as células indicadas (upsert por chave; conta vazia = apagar). A chave é (rubrica|código,
     * tipo de organização) ou, na coluna Avençado, (rubrica|código, avençado).
     *
     * @param  list<array<string, mixed>>  $rubricas
     * @param  list<array<string, mixed>>  $sistema
     */
    public function gravarMapeamentos(array $rubricas, array $sistema): array
    {
        $erros = [];
        foreach ([...$rubricas, ...$sistema] as $n => $m) {
            if (! empty($m['numero_conta'])) {
                try {
                    $this->plano->contaDeMovimento((string) $m['numero_conta']);
                } catch (ErroNegocio $e) {
                    $erros[] = $e->getMessage();
                }
            }
            if (empty($m['avencado']) && empty($m['tipo_organizacao_id']) && ($m['codigo'] ?? null) !== 'ROUNDING_DIFF') {
                $erros[] = 'Indique o tipo de organização (ou a coluna Avençado) em cada mapeamento.';
            }
        }
        if ($erros) {
            throw new ErroNegocio(implode(' ', array_unique($erros)), 'MAPEAMENTO_INVALIDO', 422, ['erros' => array_values(array_unique($erros))]);
        }

        return DB::transaction(function () use ($rubricas, $sistema) {
            $n = 0;
            foreach ([[MapeamentoContabilRH::class, 'infotipo_salarial_id', $rubricas], [MapeamentoContabilSistemaRH::class, 'codigo', $sistema]] as [$modelo, $campo, $lista]) {
                foreach ($lista as $m) {
                    $avencado = ! empty($m['avencado']);
                    $org = $avencado ? null : ($m['tipo_organizacao_id'] ?? null);
                    $modelo::query()->where($campo, $m[$campo])->where('avencado', $avencado)
                        ->when($org, fn ($q) => $q->where('tipo_organizacao_id', $org), fn ($q) => $q->whereNull('tipo_organizacao_id'))->delete();
                    if (! empty($m['numero_conta'])) {
                        $modelo::create([$campo => $m[$campo], 'tipo_organizacao_id' => $org, 'avencado' => $avencado, 'numero_conta' => (string) $m['numero_conta']]);
                    }
                    $n++;
                }
            }

            return ['gravados' => $n] + $this->mapeamentos();
        });
    }
}
