<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\Terceiro;
use App\Services\Terceiros\ContaTerceiro;
use App\Services\Terceiros\ServicoTerceiros;
use Illuminate\Support\Facades\DB;

/**
 * Escolha do cliente nos documentos de venda: pesquisa na tabela de terceiros enquanto se escreve (nome, NIF, conta ou n.º),
 * no servidor e com limite. Mostra os clientes e também os fornecedores que ainda não são clientes: estes podem passar a
 * «Cliente e Fornecedor» (regra de ServicoTerceiros::combinarTipo, ADR-071), com a conta de cliente (classe 31, de movimento)
 * indicada pelo utilizador — sem contas escondidas (ADR-071, decisão 2 de 2026-10-14). Os colaboradores ficam de fora:
 * a ficha de um colaborador não pode ser também cliente (TIPO_TERCEIRO_INCOMPATIVEL).
 */
final class ServicoPesquisaClientes
{
    public const LIMITE_PADRAO = 20;

    public const LIMITE_MAXIMO = 50;

    public function __construct(
        private readonly ServicoTerceiros $terceiros,
        private readonly ServicoConfigVendas $configVendas,
    ) {}

    /**
     * @return array{itens: list<array<string, mixed>>, conta_cliente_sugerida: ?string}
     */
    public function pesquisar(?string $texto, ?int $limite = null): array
    {
        $texto = trim(preg_replace('/\s+/u', ' ', (string) $texto) ?? '');
        $limite = max(1, min(self::LIMITE_MAXIMO, $limite ?? self::LIMITE_PADRAO));
        $papeis = [Terceiro::CLIENTE, Terceiro::CLIENTE_FORNECEDOR, Terceiro::FORNECEDOR];

        $q = Terceiro::query()
            // sem texto: só os clientes (a lista inicial do seletor); com texto: também os fornecedores que podem passar a clientes
            ->whereIn('tipo', $texto === '' ? Terceiro::TIPOS_CLIENTE : $papeis);
        if ($texto !== '') {
            $termo = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $texto).'%';
            $q->where(function ($w) use ($texto, $termo) {
                $w->where('nome', 'ilike', $termo)->orWhere('nif', 'ilike', $termo)
                    ->orWhere('conta_cliente', 'like', $termo)->orWhere('codigo_conta', 'like', $termo);
                if (ctype_digit($texto) && strlen($texto) <= 18) {
                    $w->orWhere('id', (int) $texto);
                }
            });
        }
        $itens = $q->orderByRaw("CASE WHEN tipo IN ('CLIENTE', 'CLIENTE_FORNECEDOR') THEN 0 ELSE 1 END")
            ->ordenarPorNome()->orderBy('id')->limit($limite)
            ->get(['id', 'nome', 'nif', 'tipo', 'codigo_moeda', 'conta_cliente', 'codigo_conta'])
            ->map(fn (Terceiro $t) => self::resumo($t))->all();

        return ['itens' => $itens, 'conta_cliente_sugerida' => $this->contaSugerida()];
    }

    /** @return array<string, mixed> */
    public static function resumo(Terceiro $t): array
    {
        $conta = ContaTerceiro::cliente($t);

        return ['id' => $t->id, 'nome' => trim((string) $t->nome), 'nif' => $t->nif, 'tipo' => $t->tipo, 'codigo_moeda' => $t->codigo_moeda,
            'e_cliente' => $t->eCliente(), 'conta_cliente' => $conta !== '' ? $conta : null];
    }

    /**
     * Acrescenta o papel de cliente a um terceiro que ainda não o tem (fornecedor → «Cliente e Fornecedor») ou grava a conta de
     * cliente de um cliente que não a tem. A conta é validada (classe 31, existe no plano e é de movimento) por ServicoTerceiros.
     *
     * @return array<string, mixed>
     */
    public function tornarCliente(int $terceiroId, string $contaCliente): array
    {
        return DB::transaction(function () use ($terceiroId, $contaCliente) {
            $t = Terceiro::query()->lockForUpdate()->findOrFail($terceiroId);
            if ($t->tipo === Terceiro::COLABORADOR) {
                throw new ErroNegocio('Esta entidade está registada como colaborador: não pode ser também cliente. Crie uma ficha de cliente própria.',
                    'TIPO_TERCEIRO_INCOMPATIVEL', 422);
            }
            if ($t->eCliente() && ContaTerceiro::cliente($t) !== '') {
                return self::resumo($t);
            }

            return self::resumo($this->terceiros->guardar(Terceiro::CLIENTE, ['conta_cliente' => trim($contaCliente)], $t));
        });
    }

    /** Conta das configurações de vendas, só como sugestão visível (se for da classe 31). */
    private function contaSugerida(): ?string
    {
        $c = (string) $this->configVendas->conta('clientes_default');

        return $c !== '' && ContaTerceiro::classeCorrecta($c, ContaTerceiro::CLIENTE) ? $c : null;
    }
}
