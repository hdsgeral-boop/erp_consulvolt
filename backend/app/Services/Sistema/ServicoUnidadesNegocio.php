<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\UnidadeNegocio;
use App\Support\Dados\VerificadorReferencias;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unidades de negócio da empresa activa (UnidadeNegocioServico, js/data/servicos.js:85-167; ecrã
 * js/modules/configuracoes/unidades_negocio.js).
 *
 * Paridade:
 *  - código e nome obrigatórios; código único na empresa (sem distinguir maiúsculas);
 *  - estado ATIVO/INATIVO; data de fim não anterior à de início; e-mail válido;
 *  - unidade-pai da mesma empresa, nunca a própria, sem ciclos na hierarquia;
 *  - gerente = colaborador da empresa;
 *  - ordenação por sequência e código;
 *  - não se elimina uma unidade com filhas ou em uso (colaboradores, lançamentos, vendas, compras, activos, caixa…) —
 *    sugere-se marcá-la INATIVO.
 *
 * Correcções face ao legado:
 *  - "em uso" é lido das chaves estrangeiras reais (todas as tabelas com unidade_negocio_id), não de 6 tabelas fixas;
 *  - eliminação lógica (o legado apagava fisicamente).
 * Nota de esquema: a coluna `estado_fluxo` guarda a província/estado da morada (legado: business_units.state).
 */
final class ServicoUnidadesNegocio
{
    public const CAMPOS = ['codigo', 'nome', 'nome_abreviado', 'descricao', 'unidade_negocio_pai_id', 'ordem_sequencia', 'estado', 'valido_de', 'valido_ate',
        'endereco', 'cidade', 'estado_fluxo', 'codigo_postal', 'pais', 'telefone', 'email', 'fax', 'website', 'codigo_moeda', 'bolsa_valores', 'simbolo_bolsa',
        'colaborador_gestor_id', 'tem_vendas', 'tem_servico'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly VerificadorReferencias $referencias,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    public function listar(?string $estado = null): Collection
    {
        $nomes = DB::table('colaboradores')->whereIn('id', UnidadeNegocio::query()->whereNotNull('colaborador_gestor_id')->select('colaborador_gestor_id'))
            ->pluck('nome_completo', 'id');

        return UnidadeNegocio::query()->when($estado, fn ($q) => $q->where('estado', $estado))
            ->orderBy('ordem_sequencia')->orderByRaw('codigo COLLATE "C"')->get()
            ->map(fn (UnidadeNegocio $u) => $u->toArray() + ['colaborador_gestor_nome' => $nomes[$u->colaborador_gestor_id] ?? null]);
    }

    /** @param  array<string, mixed>  $d */
    public function guardar(array $d, ?UnidadeNegocio $u = null): UnidadeNegocio
    {
        return DB::transaction(function () use ($d, $u) {
            if ($u) {
                $u = UnidadeNegocio::query()->lockForUpdate()->findOrFail($u->id);
            }
            $dados = array_intersect_key($d, array_flip(self::CAMPOS));
            foreach (['codigo', 'nome'] as $c) {
                if (array_key_exists($c, $dados)) {
                    $dados[$c] = trim((string) $dados[$c]);
                }
            }
            $codigo = $dados['codigo'] ?? $u?->codigo;
            $nome = $dados['nome'] ?? $u?->nome;
            if (! $codigo || ! $nome) {
                throw new ErroNegocio('Indique o código e o nome da unidade de negócio.', 'VALIDACAO', 422);
            }
            $inicio = $dados['valido_de'] ?? $u?->valido_de?->toDateString();
            $fim = array_key_exists('valido_ate', $dados) ? $dados['valido_ate'] : $u?->valido_ate;
            if ($inicio && $fim && substr((string) $fim, 0, 10) < substr((string) $inicio, 0, 10)) {
                throw new ErroNegocio('A data de fim não pode ser anterior à data de início.', 'DATAS_INVALIDAS', 422);
            }
            if (UnidadeNegocio::query()->whereRaw('lower(codigo) = lower(?)', [$codigo])->when($u, fn ($q) => $q->whereKeyNot($u->id))->exists()) {
                throw new ErroNegocio("Já existe uma unidade de negócio com o código «{$codigo}».", 'UNIDADE_DUPLICADA', 422);
            }
            if (array_key_exists('unidade_negocio_pai_id', $dados) && $dados['unidade_negocio_pai_id']) {
                $this->validarPai((int) $dados['unidade_negocio_pai_id'], $u?->id);
            }
            if (! empty($dados['colaborador_gestor_id']) && ! DB::table('colaboradores')->where('id', $dados['colaborador_gestor_id'])
                ->where('empresa_id', $this->contexto->obrigatorio())->whereNull('eliminado_em')->exists()) {
                throw new ErroNegocio('O gerente indicado não é um colaborador desta empresa.', 'COLABORADOR_INVALIDO', 422);
            }
            $antes = $u?->toArray();
            $u ? $u->update($dados) : $u = UnidadeNegocio::create($dados + ['estado' => 'ATIVO', 'ordem_sequencia' => 0]);
            $this->auditoria->registar('Sistema/Unidades de negócio', $antes ? 'Alterar unidade de negócio' : 'Criar unidade de negócio',
                "Unidade de negócio «{$u->codigo} — {$u->nome}» gravada.", 'unidades_negocio', $u->id, $antes, $u->toArray());

            return $u->refresh();
        });
    }

    public function eliminar(UnidadeNegocio $u): void
    {
        DB::transaction(function () use ($u) {
            $u = UnidadeNegocio::query()->lockForUpdate()->findOrFail($u->id);
            $this->referencias->exigirLivre('unidades_negocio', $u->id, "«{$u->codigo} — {$u->nome}»", [], [], 'Pode marcá-la como INATIVO.');
            $u->delete();
            $this->auditoria->registar('Sistema/Unidades de negócio', 'Eliminar unidade de negócio', "Unidade de negócio «{$u->codigo} — {$u->nome}» eliminada.",
                'unidades_negocio', $u->id, $u->toArray(), null);
        });
    }

    private function validarPai(int $paiId, ?int $proprio): void
    {
        if ($proprio && $paiId === $proprio) {
            throw new ErroNegocio('Uma unidade não pode ser pai de si própria.', 'HIERARQUIA_INVALIDA', 422);
        }
        $pais = UnidadeNegocio::query()->pluck('unidade_negocio_pai_id', 'id');
        if (! $pais->has($paiId)) {
            throw new ErroNegocio('A unidade pai escolhida não existe nesta empresa.', 'HIERARQUIA_INVALIDA', 422);
        }
        for ($cursor = $paiId, $passos = 0; $cursor && $passos < 100; $passos++) {
            if ($proprio && (int) $cursor === $proprio) {
                throw new ErroNegocio('A unidade pai escolhida criaria um ciclo na hierarquia.', 'HIERARQUIA_INVALIDA', 422);
            }
            $cursor = $pais[$cursor] ?? null;
        }
    }
}
