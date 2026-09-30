<?php

namespace App\Services\POS\Lavandaria;

use App\Exceptions\ErroNegocio;
use App\Models\PecaLavandaria;
use App\Models\Produto;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Logistica\ServicoProdutos;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tabelas de peças e de serviços e produtos das taxas (js/lavandaria.js:90-121, 1288-1439), com as correcções:
 *   - códigos PC#### e SV#### por ServicoNumeracao (o legado calculava o máximo + 1 no ecrã: dois postos podiam repetir o código);
 *   - conta de proveitos do serviço validada no servidor (movimento, classe 62) — o legado só validava no ecrã;
 *   - preços por serviço só para serviços de lavandaria existentes; preço do serviço sempre da tabela (o cliente não o altera,
 *     salvo a estimativa dos serviços sujeitos a orçamento);
 *   - produto de uma taxa sem conta configurada: erro claro (o legado criava-o sem conta).
 * Nota de esquema: pecas_lavandaria.tecido (20) e cor (10) são curtos; os limites são validados até à correcção do tipo.
 */
final class ServicoTabelasLavandaria
{
    private const MAX_TECIDO = 20;

    private const MAX_COR = 10;

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
        private readonly ServicoProdutos $produtos,
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoConfigLavandaria $config,
    ) {}

    /** @param  array<string, mixed>  $d  nome, tecido?, cor?, unidade, preco, ativo?, precos_servico?[{produto_id, preco}] */
    public function guardarPeca(array $d, ?PecaLavandaria $p = null): PecaLavandaria
    {
        $nome = trim((string) ($d['nome'] ?? $p?->nome));
        $tecido = trim((string) ($d['tecido'] ?? $p?->tecido ?? ''));
        $cor = trim((string) ($d['cor'] ?? $p?->cor ?? ''));
        $unidade = $d['unidade'] ?? $p?->unidade ?? 'PECA';
        if ($nome === '') {
            throw new ErroNegocio('Indique a designação da peça.', 'PECA_INVALIDA', 422);
        }
        if (! in_array($unidade, RegrasLavandaria::UNIDADES, true)) {
            throw new ErroNegocio('A unidade de venda é PECA ou KG.', 'PECA_INVALIDA', 422);
        }
        if (mb_strlen($tecido) > self::MAX_TECIDO || mb_strlen($cor) > self::MAX_COR) {
            throw new ErroNegocio('O tipo de tecido tem no máximo '.self::MAX_TECIDO.' caracteres e a cor '.self::MAX_COR.'.', 'PECA_INVALIDA', 422);
        }
        $preco = RegrasLavandaria::dinheiro($d['preco'] ?? $p?->preco ?? 0);
        if (bccomp($preco, '0', 2) < 0) {
            throw new ErroNegocio('Indique um preço base válido.', 'PECA_INVALIDA', 422);
        }
        $duplicada = PecaLavandaria::query()->when($p, fn ($q) => $q->whereKeyNot($p->id))->whereRaw('lower(trim(nome)) = ?', [mb_strtolower($nome)])
            ->whereRaw("lower(coalesce(tecido, '')) = ?", [mb_strtolower($tecido)])->where('unidade', $unidade)->exists();
        if ($duplicada) {   // lavandaria.js:1346
            throw new ErroNegocio('Já existe uma peça com a mesma designação, tecido e unidade.', 'PECA_DUPLICADA', 422);
        }
        $precos = array_key_exists('precos_servico', $d) ? $this->precosServico($d['precos_servico'] ?? []) : ($p?->precos_servico ?? []);
        $dados = ['nome' => $nome, 'tecido' => $tecido ?: null, 'cor' => $cor ?: null, 'unidade' => $unidade, 'preco' => $preco, 'precos_servico' => $precos,
            'ativo' => (bool) ($d['ativo'] ?? $p?->ativo ?? true), 'atualizado_por' => Auth::user()?->nome_utilizador];

        return DB::transaction(function () use ($p, $dados) {
            if ($p) {
                $p->update($dados);

                return $p->refresh();
            }
            $n = $this->numeracao->proximo($this->contexto->obrigatorio(), 'lav_peca', fn () => $this->maiorCodigo(PecaLavandaria::query()->pluck('codigo')->all(), 'PC'));

            return PecaLavandaria::create($dados + ['codigo' => sprintf('PC%04d', $n), 'criado_por' => Auth::user()?->nome_utilizador]);
        });
    }

    /** @param  array<string, mixed>  $d  nome, grupo, codigo_conta, taxa_imposto, dias_entrega, requer_orcamento, ativa */
    public function guardarServico(array $d, ?Produto $s = null): Produto
    {
        if ($s && ! in_array($s->lavandaria_grupo, RegrasLavandaria::GRUPOS, true)) {
            throw new ErroNegocio('O produto não é um serviço de lavandaria.', 'SERVICO_INVALIDO', 422);
        }
        $nome = trim((string) ($d['nome'] ?? $s?->nome));
        $grupo = $d['grupo'] ?? $s?->lavandaria_grupo;
        $conta = (string) ($d['codigo_conta'] ?? $s?->codigo_conta ?? '');
        if ($nome === '') {
            throw new ErroNegocio('Indique a designação do serviço.', 'SERVICO_INVALIDO', 422);
        }
        if (! in_array($grupo, RegrasLavandaria::GRUPOS, true)) {
            throw new ErroNegocio('O grupo é LAVANDARIA ou ALFAIATARIA.', 'SERVICO_INVALIDO', 422);
        }
        if (! str_starts_with($conta, '62')) {   // lavandaria.js:1427
            throw new ErroNegocio('Seleccione a conta de proveitos da classe 62.', 'CONTA_CLASSE_INVALIDA', 422, ['campo' => 'codigo_conta']);
        }
        $this->plano->contaDeMovimento($conta);
        $taxa = (float) ($d['taxa_imposto'] ?? $s?->taxa_imposto ?? 14);
        $dias = (int) ($d['dias_entrega'] ?? $s?->lavandaria_dias_entrega ?? 2);
        if ($taxa < 0 || $dias < 0) {
            throw new ErroNegocio('O IVA e o prazo não podem ser negativos.', 'SERVICO_INVALIDO', 422);
        }
        $dados = ['nome' => $nome, 'codigo_conta' => $conta, 'taxa_imposto' => $taxa, 'preco_unitario' => 0, 'e_servico' => true, 'movimenta_stock' => false,
            'lavandaria_grupo' => $grupo, 'lavandaria_dias_entrega' => $dias, 'lavandaria_preco_peca' => null, 'lavandaria_preco_kg' => null,
            'lavandaria_requer_orcamento' => (bool) ($d['requer_orcamento'] ?? $s?->lavandaria_requer_orcamento ?? false),
            'lavandaria_ativa' => (bool) ($d['ativa'] ?? $s?->lavandaria_ativa ?? true)]
            + array_intersect_key($d, array_flip(['codigo_isencao_fe', 'conta_iva_liquidado']));

        return DB::transaction(function () use ($s, $dados) {
            if ($s) {
                return $this->produtos->guardar($dados, $s);
            }
            $n = $this->numeracao->proximo($this->contexto->obrigatorio(), 'lav_servico', fn () => $this->maiorCodigo(Produto::withTrashed()->pluck('codigo')->all(), 'SV'));

            return $this->produtos->guardar($dados + ['codigo' => sprintf('SV%04d', $n)]);
        });
    }

    /** Serviço activo de lavandaria/alfaiataria. */
    public function servico(int $id): Produto
    {
        $s = Produto::query()->find($id);
        if (! $s || ! in_array($s->lavandaria_grupo, RegrasLavandaria::GRUPOS, true) || $s->lavandaria_ativa === false) {
            throw new ErroNegocio("O serviço {$id} não existe ou está inactivo.", 'SERVICO_INVALIDO', 422, ['produto_id' => $id]);
        }

        return $s;
    }

    /** precoPecaServico (lavandaria.js:104-107): preço da peça para o serviço; na falta, o preço base da peça. */
    public static function precoPecaServico(PecaLavandaria $p, Produto $s): string
    {
        $e = collect($p->precos_servico ?? [])->first(fn ($x) => (int) ($x['produto_id'] ?? 0) === $s->id);

        return $e && (float) $e['preco'] > 0 ? RegrasLavandaria::dinheiro($e['preco']) : RegrasLavandaria::dinheiro($p->preco);
    }

    /** produtoExtra (lavandaria.js:109-121): produto da taxa, criado com a conta e o IVA das definições. */
    public function produtoExtra(string $chave): Produto
    {
        [$codigo, $nome] = RegrasLavandaria::EXTRAS[$chave];
        $cfg = $this->config->obter();
        $p = Produto::query()->where('codigo', $codigo)->first();
        if ($p && ($p->codigo_conta || ! $cfg['conta_extras'])) {
            return $p->codigo_conta ? $p : throw new ErroNegocio("A {$nome} não tem conta de proveitos: defina a conta das taxas nas definições da lavandaria.",
                'CONFIG_LAVANDARIA_EM_FALTA', 422, ['campo' => 'conta_extras']);
        }
        $conta = $cfg['conta_extras'] ?? throw new ErroNegocio("Defina a conta de proveitos das taxas nas definições da lavandaria (necessária para a {$nome}).",
            'CONFIG_LAVANDARIA_EM_FALTA', 422, ['campo' => 'conta_extras']);
        $dados = ['nome' => $nome, 'codigo_conta' => $conta, 'taxa_imposto' => $cfg['taxa_extras'], 'preco_unitario' => 0, 'e_servico' => true, 'movimenta_stock' => false,
            'lavandaria_grupo' => 'EXTRA'];

        return $p ? $this->produtos->guardar($dados, $p) : $this->produtos->guardar($dados + ['codigo' => $codigo]);
    }

    /** @return list<array{produto_id: int, preco: string}> */
    private function precosServico(array $lista): array
    {
        $saida = [];
        foreach ($lista as $x) {
            $preco = RegrasLavandaria::dinheiro($x['preco'] ?? 0);
            if (bccomp($preco, '0', 2) < 0) {
                throw new ErroNegocio('Há preços por serviço inválidos.', 'PECA_INVALIDA', 422);
            }
            if (bccomp($preco, '0', 2) === 0) {
                continue;   // vazio/0 = usa o preço base (lavandaria.js:1355-1358)
            }
            $id = (int) ($x['produto_id'] ?? 0);
            $s = Produto::query()->find($id);
            if (! $s || ! in_array($s->lavandaria_grupo, RegrasLavandaria::GRUPOS, true)) {
                throw new ErroNegocio("O serviço {$id} não é um serviço de lavandaria.", 'SERVICO_INVALIDO', 422, ['produto_id' => $id]);
            }
            $saida[$id] = ['produto_id' => $id, 'preco' => $preco];
        }

        return array_values($saida);
    }

    private function maiorCodigo(array $codigos, string $prefixo): int
    {
        return array_reduce($codigos, fn ($m, $c) => preg_match("/^{$prefixo}(\\d+)$/", trim((string) $c), $x) ? max($m, (int) $x[1]) : $m, 0);
    }
}
