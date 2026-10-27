<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\PlanoConta;
use App\Services\Tesouraria\ServicoContasBancarias;
use App\Services\Tesouraria\ServicoContasCartao;
use App\Support\Cache\CacheComprimida;
use App\Support\Cache\ChaveCache;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Plano de contas da empresa activa. Leituras em cache (Redis, TTL 24 h, chave erp:{empresa}:contabilidade:plano_contas),
 * invalidada pelo model PlanoConta em qualquer escrita.
 * Regras do legado mantidas: código único por empresa; só contas de movimento (M) aceitam lançamentos
 * (js/db_v2.js:392-449); só contas da classe 4 podem ter moeda estrangeira (js/db_v2.js:695-698).
 * Fase A (legado novo, app_v2.js:9136-9145): dados bancários só nas contas 42/43 de movimento, validados e limpos se a conta
 * deixar de ser bancária (ServicoContasBancarias::normalizar).
 */
final class ServicoPlanoContas
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoContasBancarias $contasBancarias,
        private readonly ServicoContasCartao $contasCartao,
    ) {}

    /** @return array<string, array{id: int, codigo: string, descricao: ?string, tipo: string, codigo_moeda: ?string, cartao_tesouraria: bool}> codigo => conta */
    public function todas(): array
    {
        $empresa = $this->contexto->obrigatorio();

        // comprimido pela aplicação (~200 KB -> ~26 KB por empresa; CacheComprimida)
        return CacheComprimida::lembrar(ChaveCache::empresa($empresa, 'contabilidade', 'plano_contas'), (int) config('erp.cache.ttl.plano_contas'), fn () => PlanoConta::query()
            ->orderBy('codigo')->get(['id', 'codigo', 'descricao', 'tipo', 'codigo_moeda', 'cartao_tesouraria'])
            ->mapWithKeys(fn (PlanoConta $c) => [$c->codigo => ['id' => $c->id, 'codigo' => $c->codigo, 'descricao' => $c->descricao,
                'tipo' => $c->tipo ?? PlanoConta::TIPO_MOVIMENTO, 'codigo_moeda' => $c->codigo_moeda, 'cartao_tesouraria' => (bool) $c->cartao_tesouraria]])
            ->all());
    }

    /** @return array{id: int, codigo: string, descricao: ?string, tipo: string, codigo_moeda: ?string} */
    public function contaDeMovimento(string $codigo): array
    {
        $conta = $this->todas()[$codigo] ?? null;
        if ($conta === null) {
            throw new ErroNegocio("A conta {$codigo} não existe no plano de contas.", 'CONTA_INEXISTENTE', 422, ['codigo_conta' => $codigo]);
        }
        if ($conta['tipo'] === PlanoConta::TIPO_TOTALIZADORA) {
            throw new ErroNegocio("A conta {$codigo} é totalizadora: só contas de movimento aceitam lançamentos.", 'CONTA_TOTALIZADORA', 422, ['codigo_conta' => $codigo]);
        }

        return $conta;
    }

    /** @param  array<string, mixed>  $dados */
    public function criar(array $dados): PlanoConta
    {
        $this->validarMoeda($dados['codigo'], $dados['codigo_moeda'] ?? null, (bool) ($dados['cartao_tesouraria'] ?? false));
        if (PlanoConta::query()->where('codigo', $dados['codigo'])->exists()) {
            throw new ErroNegocio("Já existe a conta {$dados['codigo']}.", 'CONTA_DUPLICADA', 422);
        }
        $tipo = (string) ($dados['tipo'] ?? PlanoConta::TIPO_MOVIMENTO);
        $dados = $this->contasBancarias->normalizar($dados, (string) $dados['codigo'], $tipo);
        $dados = $this->contasCartao->normalizar($dados, (string) $dados['codigo'], $tipo);

        return PlanoConta::create($dados);
    }

    /** @param  array<string, mixed>  $dados */
    public function atualizar(PlanoConta $conta, array $dados): PlanoConta
    {
        if (isset($dados['codigo']) && $dados['codigo'] !== $conta->codigo) {
            if ($this->temMovimentos($conta->codigo)) {
                throw new ErroNegocio('Não é possível mudar o código de uma conta com movimentos (use "Substituir conta").', 'CONTA_COM_MOVIMENTOS', 422);
            }
            if (PlanoConta::query()->where('codigo', $dados['codigo'])->whereKeyNot($conta->id)->exists()) {
                throw new ErroNegocio("Já existe a conta {$dados['codigo']}.", 'CONTA_DUPLICADA', 422);
            }
        }
        if (($dados['tipo'] ?? $conta->tipo) === PlanoConta::TIPO_TOTALIZADORA && $conta->tipo !== PlanoConta::TIPO_TOTALIZADORA && $this->temMovimentos($conta->codigo)) {
            throw new ErroNegocio('Uma conta com movimentos não pode passar a totalizadora.', 'CONTA_COM_MOVIMENTOS', 422);
        }
        $cartaoMarcada = array_key_exists('cartao_tesouraria', $dados) ? (bool) $dados['cartao_tesouraria'] : (bool) $conta->cartao_tesouraria;
        $this->validarMoeda($dados['codigo'] ?? $conta->codigo, array_key_exists('codigo_moeda', $dados) ? $dados['codigo_moeda'] : $conta->codigo_moeda, $cartaoMarcada);
        $codigo = (string) ($dados['codigo'] ?? $conta->codigo);
        $tipo = (string) ($dados['tipo'] ?? $conta->tipo ?? PlanoConta::TIPO_MOVIMENTO);
        $dados = $this->contasBancarias->normalizar($dados, $codigo, $tipo);
        $dados = $this->contasCartao->normalizar($dados + ['cartao_tesouraria' => $cartaoMarcada], $codigo, $tipo);
        $conta->update($dados);

        return $conta;
    }

    public function eliminar(PlanoConta $conta): void
    {
        if ($this->temMovimentos($conta->codigo)) {
            throw new ErroNegocio('Não é possível eliminar uma conta com movimentos.', 'CONTA_COM_MOVIMENTOS', 422);
        }
        $conta->delete();   // eliminação lógica
    }

    /**
     * «Modificar em Massa» do legado (bulkEditAccounts / _executeBulkEditAccounts, js/app_v2.js:8917-9000): muda o tipo,
     * substitui a descrição ou acrescenta-lhe um sufixo nas contas escolhidas (da empresa activa). Como no legado, uma conta
     * de movimento com lançamentos não passa a totalizadora — mantém o tipo, mas recebe a descrição. Tudo numa transacção.
     *
     * @param  list<int>  $ids
     * @return array{actualizadas: int, sem_tipo: list<array{codigo: string, descricao: ?string, movimentos: int}>}
     */
    public function modificarEmMassa(array $ids, ?string $tipo, ?string $descricao, ?string $sufixo): array
    {
        $descricao = $descricao !== null && trim($descricao) !== '' ? trim($descricao) : null;
        $sufixo = $sufixo !== null && trim($sufixo) !== '' ? trim($sufixo) : null;
        if (! $tipo && $descricao === null && $sufixo === null) {
            throw new ErroNegocio('Nenhuma alteração definida: preencha pelo menos um campo.', 'SEM_ALTERACOES', 422);
        }

        return DB::transaction(function () use ($ids, $tipo, $descricao, $sufixo) {
            $actualizadas = 0;
            $semTipo = [];
            foreach (PlanoConta::query()->whereKey($ids)->orderBy('codigo')->lockForUpdate()->get() as $conta) {
                $dados = [];
                if ($tipo && $tipo !== $conta->tipo) {
                    $movimentos = $tipo === PlanoConta::TIPO_TOTALIZADORA ? $this->contarMovimentos($conta->codigo) : 0;
                    if ($movimentos > 0) {
                        $semTipo[] = ['codigo' => $conta->codigo, 'descricao' => $conta->descricao, 'movimentos' => $movimentos];
                    } else {
                        $dados['tipo'] = $tipo;
                    }
                }
                if ($descricao !== null) {
                    $dados['descricao'] = $descricao;
                } elseif ($sufixo !== null) {
                    $dados['descricao'] = mb_substr(trim(($conta->descricao ?? '').' '.$sufixo), 0, 255);
                }
                if ($dados) {
                    $this->atualizar($conta, $dados);
                    $actualizadas++;
                }
            }

            return ['actualizadas' => $actualizadas, 'sem_tipo' => $semTipo];
        });
    }

    /**
     * «Eliminar Seleccionadas» do legado (bulkDeleteAccounts, js/app_v2.js:9003-9037): as contas com lançamentos ficam;
     * as outras são eliminadas (eliminação lógica). Com `simular`, só devolve o que aconteceria (o legado confirma antes).
     *
     * @param  list<int>  $ids
     * @return array{eliminadas: list<string>, mantidas: list<array{codigo: string, descricao: ?string, movimentos: int}>, simulacao: bool}
     */
    public function eliminarEmMassa(array $ids, bool $simular): array
    {
        $executar = function () use ($ids, $simular) {
            $eliminadas = [];
            $mantidas = [];
            $contas = PlanoConta::query()->whereKey($ids)->orderBy('codigo');
            foreach (($simular ? $contas : $contas->lockForUpdate())->get() as $conta) {
                $movimentos = $this->contarMovimentos($conta->codigo);
                if ($movimentos > 0) {
                    $mantidas[] = ['codigo' => $conta->codigo, 'descricao' => $conta->descricao, 'movimentos' => $movimentos];

                    continue;
                }
                if (! $simular) {
                    $conta->delete();   // eliminação lógica
                }
                $eliminadas[] = $conta->codigo;
            }

            return ['eliminadas' => $eliminadas, 'mantidas' => $mantidas, 'simulacao' => $simular];
        };

        return $simular ? $executar() : DB::transaction($executar);
    }

    private function contarMovimentos(string $codigo): int
    {
        return DB::table('lancamentos_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->where('codigo_conta', $codigo)->count();
    }

    private function temMovimentos(string $codigo): bool
    {
        return DB::table('lancamentos_contabeis')->where('empresa_id', $this->contexto->obrigatorio())->where('codigo_conta', $codigo)->exists();
    }

    /** Classe 4 (meios monetários) sempre; classe 3 só quando a conta é um cartão de tesouraria (ADR cartão). */
    private function validarMoeda(string $codigo, ?string $moeda, bool $cartaoTesouraria = false): void
    {
        if ($moeda !== null && $moeda !== '' && ! str_starts_with($codigo, '4') && ! $cartaoTesouraria) {
            throw new ErroNegocio('Só contas da classe 4 (meios monetários) ou uma conta-cartão de tesouraria podem ter moeda estrangeira.', 'MOEDA_SO_CLASSE_4', 422);
        }
    }
}
