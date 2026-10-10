<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\MapeamentoContabilRH;
use App\Models\MapeamentoContabilSistemaRH;
use App\Models\TipoOrganizacaoRH;
use App\Models\Utilizador;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Dados\VerificadorReferencias;
use App\Support\Tenancy\ContextoEmpresa;
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
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoEmpresas $empresas,
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
            'infotipos' => InfotipoSalarial::query()->orderBy('nome')->orderBy('id')->get(['id', 'nome', 'tipo']),
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

    /**
     * Copiar o mapeamento de outra empresa (openCopyAccountingMaposModal / _resolveAccountingMaposCopy /
     * executeCopyAccountingMapos, js/app_v2.js:2563-2702): correspondência pelo NOME da rubrica e do tipo de organização
     * (a coluna Avençado, org_type_id = -1 no legado, copia-se tal como está) e sem sobrepor as células já mapeadas.
     * Correcções: só empresas acessíveis ao utilizador (o legado listava todas); só contas que existam no plano da empresa
     * de destino e sejam de movimento (o legado copiava o número da conta às cegas); `simular` = pré-visualização (o legado
     * mostrava as contagens antes de confirmar).
     *
     * @return array{origem: string, simulacao: bool, rubricas: int, sistema: int, ja_mapeados: int, sem_correspondencia: int, contas_invalidas: list<string>}
     */
    public function copiarMapeamentosDe(int $origemId, Utilizador $u, bool $simular): array
    {
        $destino = $this->contexto->obrigatorio();
        if ($origemId === $destino) {
            throw new ErroNegocio('Escolha uma empresa diferente da empresa activa.', 'ORIGEM_IGUAL_DESTINO', 422);
        }
        if (! $this->empresas->podeAceder($u, $origemId)) {
            // OWASP A01: não revela se a empresa existe
            throw new ErroNegocio('Não tem acesso à empresa de origem.', 'SEM_ACESSO_EMPRESA', 403);
        }
        $comConta = fn (string $modelo) => $modelo::query()->whereNotNull('numero_conta')->where('numero_conta', '<>', '')->orderBy('id');
        $origem = $this->contexto->executarComo($origemId, fn () => [
            'infotipos' => InfotipoSalarial::query()->pluck('nome', 'id')->all(),
            'tipos' => TipoOrganizacaoRH::query()->pluck('nome', 'id')->all(),
            'rubricas' => $comConta(MapeamentoContabilRH::class)->get(['infotipo_salarial_id', 'tipo_organizacao_id', 'avencado', 'numero_conta'])->all(),
            'sistema' => $comConta(MapeamentoContabilSistemaRH::class)->get(['codigo', 'tipo_organizacao_id', 'avencado', 'numero_conta'])->all(),
        ]);

        $nome = fn (?string $n) => mb_strtolower(trim((string) $n));
        $porNome = function (string $modelo) use ($nome): array {
            $mapa = [];
            foreach ($modelo::query()->orderBy('id')->get(['id', 'nome']) as $x) {
                $mapa[$nome($x->nome)] ??= (int) $x->id;   // nomes repetidos (herdados do legado): fica o primeiro
            }

            return $mapa;
        };
        $infotipos = $porNome(InfotipoSalarial::class);
        $tipos = $porNome(TipoOrganizacaoRH::class);
        $coluna = fn (bool $avencado, ?int $org) => $avencado ? 'AV' : (string) ($org ?? 0);
        $ocupadas = [];
        foreach ($comConta(MapeamentoContabilRH::class)->get() as $m) {
            $ocupadas["R|{$m->infotipo_salarial_id}|".$coluna((bool) $m->avencado, $m->tipo_organizacao_id)] = true;
        }
        foreach ($comConta(MapeamentoContabilSistemaRH::class)->get() as $m) {
            $ocupadas["S|{$m->codigo}|".$coluna((bool) $m->avencado, $m->tipo_organizacao_id)] = true;
        }

        $r = ['origem' => (string) (Empresa::query()->whereKey($origemId)->value('nome') ?? "#{$origemId}"), 'simulacao' => $simular,
            'rubricas' => 0, 'sistema' => 0, 'ja_mapeados' => 0, 'sem_correspondencia' => 0, 'contas_invalidas' => []];
        $novos = [];
        $validas = [];
        foreach ([['R', MapeamentoContabilRH::class, 'infotipo_salarial_id', $origem['rubricas']], ['S', MapeamentoContabilSistemaRH::class, 'codigo', $origem['sistema']]] as [$t, $modelo, $campo, $lista]) {
            foreach ($lista as $m) {
                $avencado = (bool) $m->avencado;
                // tipo de organização: por nome; a coluna Avençado e as contas sem tipo (ROUNDING_DIFF) não dependem dele
                $org = $avencado || $m->tipo_organizacao_id === null ? null : ($tipos[$nome($origem['tipos'][$m->tipo_organizacao_id] ?? null)] ?? false);
                $linha = $t === 'R' ? ($infotipos[$nome($origem['infotipos'][$m->infotipo_salarial_id] ?? null)] ?? false) : $m->codigo;
                if ($org === false || $linha === false) {
                    $r['sem_correspondencia']++;

                    continue;
                }
                $k = "{$t}|{$linha}|".$coluna($avencado, $org);
                if (isset($ocupadas[$k])) {
                    $r['ja_mapeados']++;

                    continue;
                }
                $conta = (string) $m->numero_conta;
                if (! ($validas[$conta] ??= $this->eContaDeMovimento($conta))) {
                    $r['contas_invalidas'][$conta] = $conta;

                    continue;
                }
                $ocupadas[$k] = true;
                $novos[] = [$modelo, [$campo => $linha, 'tipo_organizacao_id' => $org, 'avencado' => $avencado, 'numero_conta' => $conta]];
                $r[$t === 'R' ? 'rubricas' : 'sistema']++;
            }
        }
        $r['contas_invalidas'] = array_values($r['contas_invalidas']);
        if (! $simular && $novos) {
            DB::transaction(function () use ($novos) {
                foreach ($novos as [$modelo, $dados]) {
                    $campo = array_key_first($dados);
                    // células vazias herdadas do legado (numero_conta '') dão lugar ao mapeamento copiado (um por chave)
                    $modelo::query()->where($campo, $dados[$campo])->where('avencado', $dados['avencado'])
                        ->when($dados['tipo_organizacao_id'], fn ($q, $org) => $q->where('tipo_organizacao_id', $org), fn ($q) => $q->whereNull('tipo_organizacao_id'))->delete();
                    $modelo::create($dados);
                }
            });
        }

        return $r;
    }

    private function eContaDeMovimento(string $conta): bool
    {
        try {
            $this->plano->contaDeMovimento($conta);

            return true;
        } catch (ErroNegocio) {
            return false;
        }
    }
}
