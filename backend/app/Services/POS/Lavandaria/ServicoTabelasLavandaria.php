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
 * Opções de preço (F5): cada peça tem `opcoes_preco` [{modo PECA|KG|TAMANHO, tamanho, preco}] (a 1.ª manda) e a matriz
 * `precos_servico` [{produto_id, modo?, tamanho?, preco}] dá o preço por serviço e opção (lavandaria.js:139-168).
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

    /**
     * Grava uma peça (lavandaria.js:1830-1880). O preço vive nas opções e `unidade`/`preco` espelham a 1.ª opção. Sem `opcoes_preco`,
     * `unidade`/`preco` actualizam a 1.ª opção e as restantes ficam (clientes e importações anteriores às opções). Gravar marca a
     * origem das opções como NOVO (deixam de ser reconstruídas pelo ETL «completar»).
     *
     * @param  array<string, mixed>  $d  nome, tecido?, cor?, unidade?, preco?, opcoes_preco?, ativo?, precos_servico?
     */
    public function guardarPeca(array $d, ?PecaLavandaria $p = null): PecaLavandaria
    {
        $nome = trim((string) ($d['nome'] ?? $p?->nome));
        $tecido = trim((string) ($d['tecido'] ?? $p?->tecido ?? ''));
        $cor = trim((string) ($d['cor'] ?? $p?->cor ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique a designação da peça.', 'PECA_INVALIDA', 422);
        }
        if (isset($d['unidade']) && ! in_array($d['unidade'], RegrasLavandaria::UNIDADES, true)) {
            throw new ErroNegocio('A unidade de venda é PECA ou KG.', 'PECA_INVALIDA', 422);
        }
        if (mb_strlen($tecido) > self::MAX_TECIDO || mb_strlen($cor) > self::MAX_COR) {
            throw new ErroNegocio('O tipo de tecido tem no máximo '.self::MAX_TECIDO.' caracteres e a cor '.self::MAX_COR.'.', 'PECA_INVALIDA', 422);
        }
        $opcoes = $this->opcoesDaPeca($d, $p);
        $assinatura = RegrasLavandaria::chaveOpcao($opcoes[0]);
        $duplicada = PecaLavandaria::query()->when($p, fn ($q) => $q->whereKeyNot($p->id))->whereRaw('lower(trim(nome)) = ?', [mb_strtolower($nome)])
            ->whereRaw("lower(coalesce(tecido, '')) = ?", [mb_strtolower($tecido)])->get()
            ->contains(fn (PecaLavandaria $o) => RegrasLavandaria::chaveOpcao(RegrasLavandaria::opcoesPeca($o->opcoes_preco, $o->unidade, $o->preco)[0]) === $assinatura);
        if ($duplicada) {   // lavandaria.js:1849: mesma designação, tecido e 1.ª opção de preço
            throw new ErroNegocio('Já existe uma peça com a mesma designação, tecido e primeira opção de preço. Junte-lhe uma opção em vez de criar outra peça.', 'PECA_DUPLICADA', 422);
        }
        $precos = array_key_exists('precos_servico', $d) ? $this->precosServico($d['precos_servico'] ?? [], $opcoes) : $this->precosMantidos($p?->precos_servico ?? [], $opcoes);
        $dados = ['nome' => $nome, 'tecido' => $tecido ?: null, 'cor' => $cor ?: null, 'unidade' => RegrasLavandaria::unidadeOpcao($opcoes[0]), 'preco' => $opcoes[0]['preco'],
            'precos_servico' => $precos, 'opcoes_preco' => $opcoes, 'opcoes_preco_origem' => 'NOVO',
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

    /**
     * Opções finais da peça: as indicadas (validadas) ou, via `unidade`/`preco`, a 1.ª das actuais substituída (as restantes ficam).
     *
     * @return list<array{modo: string, tamanho: string, preco: string}>
     */
    private function opcoesDaPeca(array $d, ?PecaLavandaria $p): array
    {
        if (array_key_exists('opcoes_preco', $d) && $d['opcoes_preco'] !== null) {
            return $this->validarOpcoes((array) $d['opcoes_preco']);
        }
        $actuais = $p ? RegrasLavandaria::opcoesPeca($p->opcoes_preco, $p->unidade, $p->preco) : [['modo' => 'PECA', 'tamanho' => '', 'preco' => '0.00']];
        $unidade = $d['unidade'] ?? null;
        $preco = array_key_exists('preco', $d) ? RegrasLavandaria::dinheiro($d['preco']) : null;
        if ($preco !== null && bccomp($preco, '0', 2) < 0) {
            throw new ErroNegocio('Indique um preço base válido.', 'PECA_INVALIDA', 422);
        }
        $primeira = $actuais[0];
        $mudaUnidade = $unidade !== null && $unidade !== RegrasLavandaria::unidadeOpcao($primeira);
        $mudaPreco = $preco !== null && $preco !== $primeira['preco'];
        if (! $mudaUnidade && ! $mudaPreco) {
            return $actuais;   // sem alteração: uma 1.ª opção por tamanho não se perde por reenviar o espelho
        }
        $base = ['modo' => $mudaUnidade ? $unidade : $primeira['modo'], 'tamanho' => $mudaUnidade ? '' : $primeira['tamanho'], 'preco' => $preco ?? $primeira['preco']];
        $restantes = array_values(array_filter(array_slice($actuais, 1), fn ($o) => RegrasLavandaria::chaveOpcao($o) !== RegrasLavandaria::chaveOpcao($base)));

        return [$base, ...$restantes];
    }

    /** @return list<array{modo: string, tamanho: string, preco: string}> */
    private function validarOpcoes(array $lista): array
    {
        if (! $lista) {
            throw new ErroNegocio('A peça tem de ter pelo menos uma opção de preço.', 'PECA_INVALIDA', 422);
        }
        $saida = [];
        foreach (array_values($lista) as $o) {
            $o = (array) $o;
            if (! in_array($o['modo'] ?? 'PECA', RegrasLavandaria::MODOS, true)) {
                throw new ErroNegocio('O modo de preço é Peça, Quilograma ou Tamanho.', 'PECA_INVALIDA', 422);
            }
            $n = RegrasLavandaria::opcao($o);
            if ($n['modo'] === 'TAMANHO' && $n['tamanho'] === '') {   // lavandaria.js:1838
                throw new ErroNegocio('Dê nome ao tamanho (ex.: M, Casal) ou remova essa opção de preço.', 'PECA_INVALIDA', 422);
            }
            if (bccomp($n['preco'], '0', 2) < 0 || (isset($o['preco']) && $o['preco'] !== '' && ! is_numeric($o['preco']))) {
                throw new ErroNegocio('Há preços base inválidos.', 'PECA_INVALIDA', 422);
            }
            if (isset($saida[RegrasLavandaria::chaveOpcao($n)])) {   // lavandaria.js:1842
                throw new ErroNegocio('Há duas opções de preço iguais. Cada opção só pode aparecer uma vez.', 'PECA_INVALIDA', 422);
            }
            $saida[RegrasLavandaria::chaveOpcao($n)] = $n;
        }

        return array_values($saida);
    }

    /**
     * Opção da peça pedida (modo e tamanho) ou, sem pedido, a 1.ª (opcaoDe, lavandaria.js:154).
     *
     * @return array{modo: string, tamanho: string, preco: string}
     */
    public static function opcaoDaPeca(PecaLavandaria $p, ?string $modo = null, ?string $tamanho = null): array
    {
        $todas = RegrasLavandaria::opcoesPeca($p->opcoes_preco, $p->unidade, $p->preco);
        if ($modo === null || $modo === '') {
            return $todas[0];
        }
        $pedida = ['modo' => $modo, 'tamanho' => $modo === 'TAMANHO' ? trim((string) $tamanho) : ''];
        foreach ($todas as $o) {
            if (RegrasLavandaria::chaveOpcao($o) === RegrasLavandaria::chaveOpcao($pedida)) {
                return $o;
            }
        }
        throw new ErroNegocio("A peça «{$p->nome}» não tem a opção de preço «".RegrasLavandaria::rotuloOpcao($pedida).'».', 'OPCAO_PRECO_INVALIDA', 422, ['peca_id' => $p->id]);
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

    /**
     * precoPecaServico (lavandaria.js:162-168): preço da peça para o serviço e a opção (modo/tamanho): a entrada da opção ou, na falta,
     * a antiga sem modo (valia para a opção única que a peça tinha); sem preço específico, o preço da própria opção.
     *
     * @param  array{modo: string, tamanho: string, preco: string}|null  $opcao  null = a 1.ª opção da peça
     */
    public static function precoPecaServico(PecaLavandaria $p, Produto $s, ?array $opcao = null): string
    {
        $o = $opcao ?? self::opcaoDaPeca($p);
        $lista = collect($p->precos_servico ?? [])->filter(fn ($x) => (int) ($x['produto_id'] ?? 0) === $s->id);
        $k = RegrasLavandaria::chaveOpcao($o);
        $e = $lista->first(fn ($x) => ! empty($x['modo']) && RegrasLavandaria::chaveOpcao($x) === $k) ?? $lista->first(fn ($x) => empty($x['modo']));

        return $e && (float) $e['preco'] > 0 ? RegrasLavandaria::dinheiro($e['preco']) : RegrasLavandaria::dinheiro($o['preco']);
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

    /**
     * Matriz serviço × opção. Uma entrada com modo vale para essa opção (as de opções que a peça já não tem descartam-se, como no
     * legado); sem modo, atribui-se à opção única da peça ou, havendo várias, fica sem modo (vale para todas, como as antigas).
     *
     * @param  list<array{modo: string, tamanho: string, preco: string}>  $opcoes
     * @return list<array{produto_id: int, modo?: string, tamanho?: string, preco: string}>
     */
    private function precosServico(array $lista, array $opcoes): array
    {
        $validas = [];
        foreach ($opcoes as $o) {
            $validas[RegrasLavandaria::chaveOpcao($o)] = $o;
        }
        $saida = [];
        foreach ($lista as $x) {
            $x = (array) $x;
            $preco = RegrasLavandaria::dinheiro($x['preco'] ?? 0);
            if (bccomp($preco, '0', 2) < 0) {
                throw new ErroNegocio('Há preços por serviço inválidos.', 'PECA_INVALIDA', 422);
            }
            if (bccomp($preco, '0', 2) === 0) {
                continue;   // vazio/0 = usa o preço da opção (lavandaria.js:1355-1358)
            }
            $id = (int) ($x['produto_id'] ?? 0);
            $s = Produto::query()->find($id);
            if (! $s || ! in_array($s->lavandaria_grupo, RegrasLavandaria::GRUPOS, true)) {
                throw new ErroNegocio("O serviço {$id} não é um serviço de lavandaria.", 'SERVICO_INVALIDO', 422, ['produto_id' => $id]);
            }
            $entrada = ['produto_id' => $id, 'preco' => $preco];
            if (! empty($x['modo'])) {
                $o = RegrasLavandaria::opcao($x);
                if (! isset($validas[RegrasLavandaria::chaveOpcao($o)])) {
                    continue;
                }
                $entrada = ['produto_id' => $id, 'modo' => $o['modo'], 'tamanho' => $o['tamanho'], 'preco' => $preco];
            } elseif (count($opcoes) === 1) {
                $entrada = ['produto_id' => $id, 'modo' => $opcoes[0]['modo'], 'tamanho' => $opcoes[0]['tamanho'], 'preco' => $preco];
            }
            $saida["{$id}|".(isset($entrada['modo']) ? RegrasLavandaria::chaveOpcao($entrada) : '*')] = $entrada;
        }

        return array_values($saida);
    }

    /**
     * Entradas existentes, sem perder modo/tamanho, quando só se edita o resto da peça; descartam-se as de opções que deixaram de existir.
     *
     * @param  list<array<string, mixed>>  $actuais
     * @param  list<array{modo: string, tamanho: string, preco: string}>  $opcoes
     * @return list<array<string, mixed>>
     */
    private function precosMantidos(array $actuais, array $opcoes): array
    {
        $chaves = array_map(fn ($o) => RegrasLavandaria::chaveOpcao($o), $opcoes);

        return array_values(array_filter($actuais, fn ($x) => empty($x['modo']) || in_array(RegrasLavandaria::chaveOpcao($x), $chaves, true)));
    }

    private function maiorCodigo(array $codigos, string $prefixo): int
    {
        return array_reduce($codigos, fn ($m, $c) => preg_match("/^{$prefixo}(\\d+)$/", trim((string) $c), $x) ? max($m, (int) $x[1]) : $m, 0);
    }
}
