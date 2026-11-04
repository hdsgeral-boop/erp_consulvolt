<?php

namespace App\Services\IA\Agente;

use App\Exceptions\ErroNegocio;
use App\Http\Requests\Terceiros\GuardarTerceiroRequest;
use App\Models\Utilizador;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Sistema\ServicoPermissoes;
use Illuminate\Support\Facades\DB;

/**
 * Ferramentas que o agente pode usar no ERP. Duas regras de segurança:
 *  - CONSULTAS correm com as permissões do utilizador (as mesmas do ecrã correspondente, chaves do catálogo de
 *    permissões do sistema) e só na empresa activa. Uma ferramenta que o utilizador não pode usar nem é anunciada ao
 *    modelo nem é executada se o modelo a pedir (recusa em `executar`);
 *  - ESCRITAS nunca acontecem aqui: o agente só PROPÕE (ia_accoes, estado PENDENTE). O utilizador revê e confirma no chat,
 *    e é o próprio ecrã que chama o endpoint normal do ERP (mesmas permissões, validações, controlo orçamental,
 *    exercício aberto, auditoria). O agente recebe depois o resultado.
 */
final class ServicoFerramentasIA
{
    private const MAX_LINHAS = 50;

    public function __construct(
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /**
     * Definições das ferramentas disponíveis para este utilizador (só as que pode usar).
     *
     * @return list<array{nome: string, descricao: string, parametros: array<string, mixed>}>
     */
    public function definicoes(Utilizador $u, int $empresa, bool $accoes): array
    {
        $pode = fn (string ...$k) => collect($k)->contains(fn ($x) => $this->permissoes->autoriza($u, $x, $empresa));
        $obj = fn (array $props, array $obrig = []) => ['type' => 'object', 'properties' => $props ?: new \stdClass, 'required' => $obrig];
        $f = [];

        $f[] = ['nome' => 'abrir_ecra', 'descricao' => 'Abre (propõe abrir) um ecrã do MEU ERP pelo nome, para o utilizador navegar até lá. Usar quando o utilizador quer ir a um ecrã ou fazer algo que se faz num ecrã.',
            'parametros' => $obj(['ecra' => ['type' => 'string', 'description' => 'Nome do ecrã ou da função, ex.: «Facturação», «Plano de Contas», «Novo lançamento»']], ['ecra'])];
        if ($pode('config_plano_view', 'lancamentos_view', 'relatorios_contabeis_view')) {
            $f[] = ['nome' => 'consultar_plano_contas', 'descricao' => 'Procura contas no plano de contas da empresa activa por código (prefixo) ou descrição. Devolve código, descrição e tipo (M = movimento, T = totalizadora). Só contas de movimento aceitam lançamentos.',
                'parametros' => $obj(['pesquisa' => ['type' => 'string', 'description' => 'Código (prefixo) ou parte da descrição']], ['pesquisa'])];
        }
        if ($pode('tabelas_aux_view', 'lancamentos_view')) {
            $f[] = ['nome' => 'listar_diarios', 'descricao' => 'Lista os diários contabilísticos da empresa activa (id, código, nome). Necessário para propor um lançamento.', 'parametros' => $obj([])];
        }
        if ($pode('lancamentos_view', 'relatorios_contabeis_view')) {
            $f[] = ['nome' => 'saldo_conta', 'descricao' => 'Saldo de uma conta (ou de todas as que começam pelo código) até uma data: débitos, créditos e saldo (débito - crédito).',
                'parametros' => $obj(['codigo' => ['type' => 'string'], 'ate' => ['type' => 'string', 'description' => 'Data AAAA-MM-DD (por omissão, hoje)']], ['codigo'])];
        }
        if ($pode('vendas_clientes_view', 'compras_fornecedores_view', 'tabelas_aux_view', 'lancamentos_view', 'vendas_faturacao_view')) {
            $f[] = ['nome' => 'procurar_terceiros', 'descricao' => 'Procura clientes, fornecedores ou colaboradores da empresa activa por nome ou NIF.',
                'parametros' => $obj(['pesquisa' => ['type' => 'string'], 'papel' => ['type' => 'string', 'enum' => ['CLIENTE', 'FORNECEDOR', 'COLABORADOR']]], ['pesquisa'])];
        }
        if ($accoes && $pode('lancamentos_post')) {
            $f[] = ['nome' => 'propor_lancamento', 'descricao' => 'Propõe um lançamento contabilístico (partida dobrada) para o utilizador confirmar. Débitos e créditos têm de somar o mesmo; só contas de movimento. Nada é gravado sem a confirmação do utilizador.',
                'parametros' => $obj([
                    'diario_id' => ['type' => 'integer', 'description' => 'id do diário (ver listar_diarios)'],
                    'data_documento' => ['type' => 'string', 'description' => 'AAAA-MM-DD'],
                    'descricao' => ['type' => 'string'],
                    'numero_documento' => ['type' => 'string'],
                    'linhas' => ['type' => 'array', 'items' => $obj([
                        'codigo_conta' => ['type' => 'string'], 'tipo_dc' => ['type' => 'string', 'enum' => ['D', 'C']],
                        'valor' => ['type' => 'number'], 'descricao' => ['type' => 'string'],
                    ], ['codigo_conta', 'tipo_dc', 'valor'])],
                ], ['diario_id', 'data_documento', 'linhas'])];
        }
        $papeis = $accoes ? array_values(array_filter(array_keys(GuardarTerceiroRequest::GERIR), fn ($p) => $pode(...GuardarTerceiroRequest::GERIR[$p]))) : [];
        if ($papeis) {
            $f[] = ['nome' => 'propor_terceiro', 'descricao' => 'Propõe criar um cliente, fornecedor ou colaborador na empresa activa, para o utilizador confirmar.',
                'parametros' => $obj([
                    'papel' => ['type' => 'string', 'enum' => $papeis], 'nome' => ['type' => 'string'], 'nif' => ['type' => 'string'],
                    'email' => ['type' => 'string'], 'telefone' => ['type' => 'string'], 'endereco' => ['type' => 'string'],
                ], ['papel', 'nome'])];
        }

        return $f;
    }

    /**
     * Executa uma ferramenta. Devolve o conteúdo para o modelo (JSON) e, para as propostas, a acção criada.
     *
     * @param  array<string, mixed>  $args
     * @return array{conteudo: string, accao?: array<string, mixed>, erro?: bool}
     */
    public function executar(string $nome, array $args, Utilizador $u, int $empresa, int $conversa, bool $accoes): array
    {
        $disponiveis = array_column($this->definicoes($u, $empresa, $accoes), 'nome');
        if (! in_array($nome, $disponiveis, true)) {
            return ['conteudo' => json_encode(['erro' => 'Ferramenta indisponível para este utilizador.']), 'erro' => true];
        }
        try {
            return match ($nome) {
                'abrir_ecra' => $this->abrirEcra($args, $u, $empresa, $conversa),
                'consultar_plano_contas' => $this->json($this->contas((string) ($args['pesquisa'] ?? ''))),
                'listar_diarios' => $this->json(DB::table('diarios_contabeis')->where('empresa_id', $empresa)->whereNull('eliminado_em')->orderBy('codigo')
                    ->limit(200)->get(['id', 'codigo', 'nome'])->all()),
                'saldo_conta' => $this->json($this->saldo($empresa, (string) ($args['codigo'] ?? ''), (string) ($args['ate'] ?? now()->toDateString()))),
                'procurar_terceiros' => $this->json($this->terceiros($empresa, (string) ($args['pesquisa'] ?? ''), $args['papel'] ?? null, $this->tiposPermitidos($u, $empresa))),
                'propor_lancamento' => $this->proporLancamento($args, $u, $empresa, $conversa),
                'propor_terceiro' => $this->proporTerceiro($args, $u, $empresa, $conversa),
            };
        } catch (ErroNegocio $e) {
            return ['conteudo' => json_encode(['erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE), 'erro' => true];
        }
    }

    /** @return array{conteudo: string} */
    private function json(mixed $dados): array
    {
        return ['conteudo' => (string) json_encode($dados, JSON_UNESCAPED_UNICODE)];
    }

    /** @return list<array<string, mixed>> */
    private function contas(string $pesquisa): array
    {
        $p = mb_strtolower(trim($pesquisa));

        return collect($this->plano->todas())
            ->filter(fn ($c) => $p === '' || str_starts_with($c['codigo'], $p) || str_contains(mb_strtolower((string) $c['descricao']), $p))
            ->take(self::MAX_LINHAS)->map(fn ($c) => ['codigo' => $c['codigo'], 'descricao' => $c['descricao'], 'tipo' => $c['tipo']])->values()->all();
    }

    /** @return array<string, mixed> */
    private function saldo(int $empresa, string $codigo, string $ate): array
    {
        $codigo = trim($codigo);
        if ($codigo === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
            throw new ErroNegocio('Indique o código da conta e a data no formato AAAA-MM-DD.', 'IA_ARGUMENTOS', 422);
        }
        $r = DB::table('lancamentos_contabeis')->where('empresa_id', $empresa)->where('codigo_conta', 'like', $codigo.'%')->where('data_documento', '<=', $ate)
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo_dc = 'D' THEN valor ELSE 0 END), 0) AS debito, COALESCE(SUM(CASE WHEN tipo_dc = 'C' THEN valor ELSE 0 END), 0) AS credito")
            ->first();

        return ['codigo' => $codigo, 'ate' => $ate, 'debito' => round((float) $r->debito, 2), 'credito' => round((float) $r->credito, 2),
            'saldo' => round((float) $r->debito - (float) $r->credito, 2), 'moeda' => 'AOA'];
    }

    /**
     * Tipos de terceiro que o utilizador pode consultar, pela permissão do ecrã de cada um (cliente, fornecedor, resto).
     *
     * @return list<string>
     */
    private function tiposPermitidos(Utilizador $u, int $empresa): array
    {
        $pode = fn (string ...$k) => collect($k)->contains(fn ($x) => $this->permissoes->autoriza($u, $x, $empresa));
        $cliente = $pode('vendas_clientes_view', 'vendas_faturacao_view');
        $fornecedor = $pode('compras_fornecedores_view');
        $outros = $pode('tabelas_aux_view', 'lancamentos_view');

        return array_values(array_filter([
            $cliente ? 'CLIENTE' : null, $fornecedor ? 'FORNECEDOR' : null, $cliente && $fornecedor ? 'CLIENTE_FORNECEDOR' : null,
            $outros ? 'COLABORADOR' : null, $outros ? 'CREDOR_DEVEDOR' : null,
        ]));
    }

    /**
     * @param  list<string>  $tipos
     * @return list<array<string, mixed>>
     */
    private function terceiros(int $empresa, string $pesquisa, ?string $papel, array $tipos): array
    {
        $p = '%'.mb_strtolower(trim($pesquisa)).'%';
        $filtro = $papel ? array_values(array_intersect($tipos, [$papel, 'CLIENTE_FORNECEDOR'])) : $tipos;

        return DB::table('terceiros')->where('empresa_id', $empresa)->whereNull('eliminado_em')->whereIn('tipo', $filtro)
            ->where(fn ($q) => $q->whereRaw('lower(nome) LIKE ?', [$p])->orWhere('nif', 'like', $p))
            ->orderBy('nome')->limit(self::MAX_LINHAS)->get(['id', 'nome', 'nif', 'tipo', 'email', 'telefone'])->all();
    }

    /** @return array{conteudo: string, accao: array<string, mixed>} */
    private function abrirEcra(array $args, Utilizador $u, int $empresa, int $conversa): array
    {
        $termo = trim((string) ($args['ecra'] ?? ''));
        if ($termo === '') {
            throw new ErroNegocio('Indique o ecrã.', 'IA_ARGUMENTOS', 422);
        }
        $accao = $this->criarAccao($conversa, $empresa, $u, 'navegar', "Abrir «{$termo}»", 'GET', '', ['ecra' => $termo], 'EXECUTADA');

        return ['conteudo' => json_encode(['resultado' => "Foi mostrado ao utilizador um botão para abrir «{$termo}»."], JSON_UNESCAPED_UNICODE), 'accao' => $accao];
    }

    /** @return array{conteudo: string, accao: array<string, mixed>} */
    private function proporLancamento(array $a, Utilizador $u, int $empresa, int $conversa): array
    {
        $linhas = array_values(array_filter((array) ($a['linhas'] ?? []), 'is_array'));
        if (count($linhas) < 2) {
            throw new ErroNegocio('Um lançamento precisa de pelo menos duas linhas.', 'IA_ARGUMENTOS', 422);
        }
        $data = (string) ($a['data_documento'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || ! checkdate((int) substr($data, 5, 2), (int) substr($data, 8, 2), (int) substr($data, 0, 4))) {
            throw new ErroNegocio('Indique a data do documento no formato AAAA-MM-DD.', 'IA_ARGUMENTOS', 422);
        }
        if (! DB::table('diarios_contabeis')->where('id', (int) ($a['diario_id'] ?? 0))->where('empresa_id', $empresa)->whereNull('eliminado_em')->exists()) {
            throw new ErroNegocio('O diário indicado não existe nesta empresa (use listar_diarios).', 'IA_ARGUMENTOS', 422);
        }
        $todas = $this->plano->todas();
        $deb = 0.0;
        $cred = 0.0;
        $limpas = [];
        foreach ($linhas as $l) {
            $codigo = trim((string) ($l['codigo_conta'] ?? ''));
            $tipo = strtoupper((string) ($l['tipo_dc'] ?? ''));
            $valor = round((float) ($l['valor'] ?? 0), 2);
            if (! isset($todas[$codigo])) {
                throw new ErroNegocio("A conta {$codigo} não existe no plano de contas.", 'IA_ARGUMENTOS', 422);
            }
            if ($todas[$codigo]['tipo'] === 'T') {
                throw new ErroNegocio("A conta {$codigo} é totalizadora: escolha uma conta de movimento.", 'IA_ARGUMENTOS', 422);
            }
            if (! in_array($tipo, ['D', 'C'], true) || $valor <= 0) {
                throw new ErroNegocio('Cada linha precisa de tipo D ou C e de um valor positivo.', 'IA_ARGUMENTOS', 422);
            }
            $tipo === 'D' ? $deb += $valor : $cred += $valor;
            $limpas[] = array_filter(['codigo_conta' => $codigo, 'tipo_dc' => $tipo, 'valor' => $valor, 'descricao' => isset($l['descricao']) ? mb_substr((string) $l['descricao'], 0, 1000) : null], fn ($v) => $v !== null);
        }
        if (abs($deb - $cred) > 0.004) {
            throw new ErroNegocio(sprintf('O lançamento não está equilibrado: débitos %.2f, créditos %.2f.', $deb, $cred), 'IA_ARGUMENTOS', 422);
        }
        $dados = array_filter([
            'diario_id' => (int) ($a['diario_id'] ?? 0), 'data_documento' => (string) ($a['data_documento'] ?? ''),
            'descricao' => isset($a['descricao']) ? mb_substr((string) $a['descricao'], 0, 1000) : null,
            'numero_documento' => isset($a['numero_documento']) ? mb_substr((string) $a['numero_documento'], 0, 100) : null,
            'linhas' => $limpas,
        ], fn ($v) => $v !== null);
        $resumo = sprintf('Lançamento de %s em %s%s', number_format($deb, 2, ',', ' '), $dados['data_documento'], isset($dados['descricao']) ? " — {$dados['descricao']}" : '');
        $accao = $this->criarAccao($conversa, $empresa, $u, 'lancamento', $resumo, 'POST', '/contabilidade/lancamentos', $dados);

        return ['conteudo' => json_encode(['resultado' => "Proposta #{$accao['id']} mostrada ao utilizador para confirmar. Nada foi gravado ainda."], JSON_UNESCAPED_UNICODE), 'accao' => $accao];
    }

    /** @return array{conteudo: string, accao: array<string, mixed>} */
    private function proporTerceiro(array $a, Utilizador $u, int $empresa, int $conversa): array
    {
        $nome = trim((string) ($a['nome'] ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique o nome.', 'IA_ARGUMENTOS', 422);
        }
        if (! in_array($a['papel'] ?? null, array_keys(GuardarTerceiroRequest::GERIR), true)) {
            throw new ErroNegocio('Papel inválido.', 'IA_ARGUMENTOS', 422);
        }
        if (! collect(GuardarTerceiroRequest::GERIR[$a['papel']])->contains(fn ($k) => $this->permissoes->autoriza($u, $k, $empresa))) {
            throw new ErroNegocio('Não tem permissão para criar terceiros com esse papel.', 'IA_SEM_PERMISSAO', 403);
        }
        $dados = array_filter([
            'papel' => (string) $a['papel'], 'nome' => mb_substr($nome, 0, 255),
            'nif' => isset($a['nif']) ? mb_substr(trim((string) $a['nif']), 0, 30) : null,
            'email' => isset($a['email']) && filter_var($a['email'], FILTER_VALIDATE_EMAIL) ? (string) $a['email'] : null,
            'telefone' => isset($a['telefone']) ? mb_substr((string) $a['telefone'], 0, 50) : null,
            'endereco' => isset($a['endereco']) ? mb_substr((string) $a['endereco'], 0, 1000) : null,
        ], fn ($v) => $v !== null && $v !== '');
        $rotulo = ['CLIENTE' => 'cliente', 'FORNECEDOR' => 'fornecedor', 'COLABORADOR' => 'colaborador', 'CLIENTE_FORNECEDOR' => 'cliente/fornecedor'][$dados['papel']] ?? 'terceiro';
        $accao = $this->criarAccao($conversa, $empresa, $u, 'terceiro', "Criar {$rotulo} «{$dados['nome']}»", 'POST', '/terceiros', $dados);

        return ['conteudo' => json_encode(['resultado' => "Proposta #{$accao['id']} mostrada ao utilizador para confirmar. Nada foi gravado ainda."], JSON_UNESCAPED_UNICODE), 'accao' => $accao];
    }

    /** @return array<string, mixed> */
    private function criarAccao(int $conversa, int $empresa, Utilizador $u, string $tipo, string $resumo, string $metodo, string $url, array $dados, string $estado = 'PENDENTE'): array
    {
        $id = DB::table('ia_accoes')->insertGetId([
            'conversa_id' => $conversa, 'empresa_id' => $empresa, 'utilizador_id' => $u->id, 'tipo' => $tipo, 'resumo' => mb_substr($resumo, 0, 500),
            'metodo' => $metodo, 'url' => $url, 'dados' => json_encode($dados, JSON_UNESCAPED_UNICODE), 'estado' => $estado,
        ]);

        return ['id' => $id, 'tipo' => $tipo, 'resumo' => $resumo, 'metodo' => $metodo, 'url' => $url, 'dados' => $dados, 'estado' => $estado];
    }
}
