<?php

namespace App\Services\IA\Agente;

use App\Exceptions\ErroNegocio;
use App\Services\IA\Agente\Fornecedores\AnthropicIA;
use App\Services\IA\Agente\Fornecedores\FornecedorIA;
use App\Services\IA\Agente\Fornecedores\GeminiIA;
use App\Services\IA\Agente\Fornecedores\OpenAIIA;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Configuração da IA na plataforma: fornecedores (chave cifrada com a APP_KEY, nunca devolvida — só «configurada» e
 * os últimos 4 caracteres), atribuição a cada cliente (fornecedor, modelo, quota mensal de tokens, pesquisa na internet,
 * acções no ERP) e consumo. Leituras de todos os clientes pela ligação do dono (sem RLS); tudo agregado.
 */
final class ServicoConfiguracaoIA
{
    public const FORNECEDORES = [
        'anthropic' => ['nome' => 'Anthropic (Claude)', 'modelos' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], 'modelo_padrao' => 'claude-opus-5-5',
            'preco_entrada_mtok' => 4.0, 'preco_saida_mtok' => 20.0],
        'openai' => ['nome' => 'OpenAI', 'modelos' => ['gpt-5.1', 'gpt-5', 'gpt-5-mini', 'gpt-5-nano'], 'modelo_padrao' => 'gpt-5.1',
            'preco_entrada_mtok' => 0, 'preco_saida_mtok' => 0],
        'gemini' => ['nome' => 'Google Gemini', 'modelos' => ['gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3.5-flash-lite'], 'modelo_padrao' => 'gemini-3.8-flash',
            'preco_entrada_mtok' => 0, 'preco_saida_mtok' => 0],
    ];

    private function db(): ConnectionInterface
    {
        return DB::connection(config('erp.saas.ligacao_dono') ?: null);
    }

    /** @return list<array<string, mixed>> */
    public function fornecedores(): array
    {
        $gravados = $this->db()->table('ia_fornecedores')->get()->keyBy('fornecedor');

        return collect(self::FORNECEDORES)->map(function (array $base, string $codigo) use ($gravados) {
            $f = $gravados[$codigo] ?? null;
            $chave = $f?->chave ? rescue(fn () => Crypt::decryptString($f->chave), null, report: false) : null;
            $gravadosFornecedor = $f ? (json_decode((string) $f->modelos, true) ?: []) : [];
            $modelos = collect(array_merge($base['modelos'], $gravadosFornecedor))
                ->map(fn ($modelo) => trim((string) $modelo))->filter(fn ($modelo) => $this->modeloValido($codigo, $modelo))->unique()->values()->all();
            $padraoGravado = trim((string) ($f->modelo_padrao ?? ''));
            $modeloPadrao = in_array($padraoGravado, $modelos, true) ? $padraoGravado : $base['modelo_padrao'];

            return [
                'id' => $f?->id, 'fornecedor' => $codigo, 'nome' => $f->nome ?? $base['nome'],
                'configurado' => $chave !== null, 'chave_final' => $chave ? '…'.substr($chave, -4) : null,
                'modelos' => $modelos,
                'modelo_padrao' => $modeloPadrao,
                'preco_entrada_mtok' => (float) ($f->preco_entrada_mtok ?? $base['preco_entrada_mtok']),
                'preco_saida_mtok' => (float) ($f->preco_saida_mtok ?? $base['preco_saida_mtok']),
                'ativo' => (bool) ($f->ativo ?? false), 'testado_em' => $f->testado_em ?? null, 'ultimo_erro' => $f->ultimo_erro ?? null,
            ];
        })->values()->all();
    }

    /** @param  array<string, mixed>  $d */
    public function gravarFornecedor(string $codigo, array $d): void
    {
        if (! isset(self::FORNECEDORES[$codigo])) {
            throw new ErroNegocio('Fornecedor de IA desconhecido.', 'IA_FORNECEDOR_INVALIDO', 422);
        }
        $valores = array_filter([
            'nome' => $d['nome'] ?? null,
            'modelos' => isset($d['modelos']) ? json_encode(array_values(array_unique(array_filter(array_map('trim', $d['modelos']))))) : null,
            'modelo_padrao' => $d['modelo_padrao'] ?? null,
            'preco_entrada_mtok' => $d['preco_entrada_mtok'] ?? null,
            'preco_saida_mtok' => $d['preco_saida_mtok'] ?? null,
            'ativo' => array_key_exists('ativo', $d) ? (bool) $d['ativo'] : null,
        ], fn ($v) => $v !== null);
        if (! empty($d['chave'])) {
            $valores['chave'] = Crypt::encryptString(trim((string) $d['chave']));
            $valores['ultimo_erro'] = null;
        }
        $existe = $this->db()->table('ia_fornecedores')->where('fornecedor', $codigo)->exists();
        if (! $existe) {
            $base = self::FORNECEDORES[$codigo];
            $valores += ['nome' => $base['nome'], 'modelos' => json_encode($base['modelos']), 'modelo_padrao' => $base['modelo_padrao'],
                'preco_entrada_mtok' => $base['preco_entrada_mtok'], 'preco_saida_mtok' => $base['preco_saida_mtok']];
        }
        $this->db()->table('ia_fornecedores')->updateOrInsert(['fornecedor' => $codigo], $valores + ['atualizado_em' => now()]);
    }

    /** Testa a chave e o modelo padrão (ou o indicado) com um pedido mínimo; regista o resultado. */
    public function testar(string $codigo, ?string $modelo = null): void
    {
        $f = $this->db()->table('ia_fornecedores')->where('fornecedor', $codigo)->first();
        $modelo = $modelo ?: $f?->modelo_padrao;
        if (! $f || ! $modelo) {
            throw new ErroNegocio('Configure primeiro a chave e o modelo deste fornecedor.', 'IA_NAO_CONFIGURADA', 422);
        }
        try {
            $this->cliente($codigo)->testar($modelo);
            $this->db()->table('ia_fornecedores')->where('id', $f->id)->update(['testado_em' => now(), 'ultimo_erro' => null]);
        } catch (ErroNegocio $e) {
            $this->db()->table('ia_fornecedores')->where('id', $f->id)->update(['ultimo_erro' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }
    }

    public function cliente(string $codigo): FornecedorIA
    {
        $f = $this->db()->table('ia_fornecedores')->where('fornecedor', $codigo)->first();
        $chave = $f?->chave ? rescue(fn () => Crypt::decryptString($f->chave), null, report: false) : null;
        if (! $chave) {
            throw new ErroNegocio('O fornecedor de IA não está configurado na plataforma.', 'IA_NAO_CONFIGURADA', 503);
        }

        return match ($codigo) {
            'anthropic' => new AnthropicIA($chave),
            'openai' => new OpenAIIA($chave),
            'gemini' => new GeminiIA($chave),
        };
    }

    /** Confirma uma chave (nova ou guardada) e devolve os modelos realmente acessíveis à conta. */
    public function modelosDisponiveis(string $codigo, ?string $novaChave = null): array
    {
        if (! isset(self::FORNECEDORES[$codigo])) {
            throw new ErroNegocio('Fornecedor de IA desconhecido.', 'IA_FORNECEDOR_INVALIDO', 422);
        }
        $f = $this->db()->table('ia_fornecedores')->where('fornecedor', $codigo)->first();
        $chave = trim((string) ($novaChave ?: ($f?->chave ? rescue(fn () => Crypt::decryptString($f->chave), '', report: false) : '')));
        if ($chave === '') {
            throw new ErroNegocio('Indique uma chave para consultar os modelos.', 'IA_SEM_CHAVE', 422);
        }

        try {
            $pedido = Http::acceptJson()->timeout(25);
            $resposta = match ($codigo) {
                'anthropic' => $pedido->withHeaders(['x-api-key' => $chave, 'anthropic-version' => '2023-06-01'])->get('https://api.anthropic.com/v1/models', ['limit' => 100]),
                'openai' => $pedido->withToken($chave)->get('https://api.openai.com/v1/models'),
                'gemini' => $pedido->withHeaders(['x-goog-api-key' => $chave])->get('https://generativelanguage.googleapis.com/v1beta/models', ['pageSize' => 1000]),
            };
        } catch (ConnectionException) {
            throw new ErroNegocio('Não foi possível consultar os modelos. Tente novamente.', 'IA_FORNECEDOR_ERRO', 502);
        }
        if ($resposta->failed()) {
            $motivo = in_array($resposta->status(), [401, 403], true) ? 'A chave foi recusada. Confirme a chave e as permissões da conta.' : 'O serviço respondeu com HTTP '.$resposta->status().'.';
            throw new ErroNegocio($motivo, 'IA_CHAVE_INVALIDA', 422);
        }

        $itens = $codigo === 'gemini' ? $resposta->json('models', []) : $resposta->json('data', []);
        $modelos = collect(is_array($itens) ? $itens : [])->filter(function ($m) use ($codigo) {
            if ($codigo === 'gemini') {
                return in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true);
            }

            return isset($m['id']) && ($codigo !== 'openai' || preg_match('/^(gpt-|o[1-9]|chatgpt-)/', (string) $m['id']));
        })->map(fn ($m) => $codigo === 'gemini' ? preg_replace('#^models/#', '', (string) ($m['name'] ?? '')) : (string) $m['id'])
            ->filter()->unique()->values()->all();
        if (! $modelos) {
            throw new ErroNegocio('A chave é válida, mas não foram encontrados modelos de conversa disponíveis.', 'IA_SEM_MODELOS', 422);
        }

        return $modelos;
    }

    private function modeloValido(string $codigo, string $modelo): bool
    {
        return $modelo !== '' && match ($codigo) {
            'anthropic' => (bool) preg_match('/^claude-[a-z0-9.-]+$/i', $modelo),
            'openai' => (bool) preg_match('/^(gpt-|o[1-9]|chatgpt-)[a-z0-9.-]+$/i', $modelo),
            'gemini' => (bool) preg_match('/^gemini-[a-z0-9.-]+$/i', $modelo),
        };
    }

    /** @return list<array<string, mixed>> atribuições de todos os clientes (com o consumo do mês) */
    public function atribuicoes(): array
    {
        $db = $this->db();
        $mes = now()->startOfMonth();
        $consumo = $db->table('ia_consumo')->where('criado_em', '>=', $mes)->selectRaw('tenant_id, SUM(tokens_entrada + tokens_saida) AS tokens, SUM(custo_usd) AS custo')
            ->groupBy('tenant_id')->get()->keyBy('tenant_id');
        $atrib = $db->table('ia_atribuicoes as a')->join('ia_fornecedores as f', 'f.id', '=', 'a.fornecedor_id')
            ->get(['a.*', 'f.fornecedor'])->keyBy('tenant_id');

        return $db->table('tenants')->where('id', '<>', (int) config('erp.dominio.plataforma_tenant_id', 1))->orderBy('nome')->get(['id', 'nome', 'slug', 'estado'])
            ->map(fn ($t) => [
                'tenant_id' => $t->id, 'cliente' => $t->nome, 'slug' => $t->slug, 'estado' => $t->estado,
                'fornecedor' => $atrib[$t->id]->fornecedor ?? null, 'modelo' => $atrib[$t->id]->modelo ?? null,
                'ativo' => (bool) ($atrib[$t->id]->ativo ?? false), 'quota_tokens_mes' => isset($atrib[$t->id]) ? $atrib[$t->id]->quota_tokens_mes : null,
                'pesquisa_web' => (bool) ($atrib[$t->id]->pesquisa_web ?? true), 'accoes_erp' => (bool) ($atrib[$t->id]->accoes_erp ?? true),
                'tokens_mes' => (int) ($consumo[$t->id]->tokens ?? 0), 'custo_mes_usd' => round((float) ($consumo[$t->id]->custo ?? 0), 4),
            ])->values()->all();
    }

    /** @param  array<string, mixed>  $d */
    public function atribuir(int $tenant, array $d): void
    {
        $f = $this->db()->table('ia_fornecedores')->where('fornecedor', $d['fornecedor'])->first();
        if (! $f) {
            throw new ErroNegocio('Configure primeiro esse fornecedor de IA.', 'IA_NAO_CONFIGURADA', 422);
        }
        $this->db()->table('ia_atribuicoes')->updateOrInsert(['tenant_id' => $tenant], [
            'fornecedor_id' => $f->id, 'modelo' => $d['modelo'] ?: $f->modelo_padrao, 'ativo' => (bool) ($d['ativo'] ?? true),
            'quota_tokens_mes' => $d['quota_tokens_mes'] ?? null, 'pesquisa_web' => (bool) ($d['pesquisa_web'] ?? true),
            'accoes_erp' => (bool) ($d['accoes_erp'] ?? true), 'atualizado_em' => now(),
        ]);
    }

    public function removerAtribuicao(int $tenant): void
    {
        $this->db()->table('ia_atribuicoes')->where('tenant_id', $tenant)->delete();
    }

    /**
     * Atribuição activa de um cliente, com o consumo do mês.
     *
     * @return array{fornecedor: string, modelo: string, quota: ?int, usados: int, pesquisa_web: bool, accoes_erp: bool, preco_entrada: float, preco_saida: float}|null
     */
    public function doCliente(?int $tenant): ?array
    {
        if ($tenant === null) {
            return null;
        }
        $a = $this->db()->table('ia_atribuicoes as a')->join('ia_fornecedores as f', 'f.id', '=', 'a.fornecedor_id')
            ->where('a.tenant_id', $tenant)->where('a.ativo', true)->where('f.ativo', true)->whereNotNull('f.chave')
            ->first(['a.*', 'f.fornecedor', 'f.preco_entrada_mtok', 'f.preco_saida_mtok']);
        if (! $a) {
            return null;
        }
        $usados = (int) $this->db()->table('ia_consumo')->where('tenant_id', $tenant)->where('criado_em', '>=', now()->startOfMonth())
            ->sum(DB::raw('tokens_entrada + tokens_saida'));

        return ['fornecedor' => $a->fornecedor, 'modelo' => $a->modelo, 'quota' => $a->quota_tokens_mes !== null ? (int) $a->quota_tokens_mes : null, 'usados' => $usados,
            'pesquisa_web' => (bool) $a->pesquisa_web, 'accoes_erp' => (bool) $a->accoes_erp,
            'preco_entrada' => (float) $a->preco_entrada_mtok, 'preco_saida' => (float) $a->preco_saida_mtok];
    }

    /** @return array<string, mixed> consumo dos últimos 6 meses por mês e por cliente (consola da plataforma) */
    public function consumo(): array
    {
        $db = $this->db();
        $desde = now()->startOfMonth()->subMonths(5);
        $meses = collect(range(5, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'));
        $porMes = $db->table('ia_consumo')->where('criado_em', '>=', $desde)
            ->selectRaw("to_char(criado_em, 'YYYY-MM') AS mes, SUM(tokens_entrada) AS entrada, SUM(tokens_saida) AS saida, SUM(custo_usd) AS custo, COUNT(*) AS pedidos")
            ->groupBy('mes')->get()->keyBy('mes');
        $nomes = $db->table('tenants')->pluck('nome', 'id');
        $porCliente = $db->table('ia_consumo')->where('criado_em', '>=', now()->startOfMonth())
            ->selectRaw('tenant_id, SUM(tokens_entrada + tokens_saida) AS tokens, SUM(custo_usd) AS custo, COUNT(*) AS pedidos, SUM(pesquisas_web) AS pesquisas')
            ->groupBy('tenant_id')->orderByDesc('tokens')->get();

        return [
            'meses' => $meses->all(),
            'tokens_entrada' => $meses->map(fn ($m) => (int) ($porMes[$m]->entrada ?? 0))->all(),
            'tokens_saida' => $meses->map(fn ($m) => (int) ($porMes[$m]->saida ?? 0))->all(),
            'custo_usd' => $meses->map(fn ($m) => round((float) ($porMes[$m]->custo ?? 0), 4))->all(),
            'pedidos' => $meses->map(fn ($m) => (int) ($porMes[$m]->pedidos ?? 0))->all(),
            'clientes_mes' => $porCliente->map(fn ($c) => ['cliente' => $nomes[$c->tenant_id] ?? '—', 'tokens' => (int) $c->tokens, 'custo_usd' => round((float) $c->custo, 4),
                'pedidos' => (int) $c->pedidos, 'pesquisas' => (int) $c->pesquisas])->all(),
        ];
    }
}
