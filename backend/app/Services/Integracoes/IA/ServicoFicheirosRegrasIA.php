<?php

namespace App\Services\Integracoes\IA;

use App\Exceptions\ErroNegocio;
use App\Models\FicheiroRegrasIA;
use App\Services\Integracoes\IA\Regras\RegraFicheiro;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Assistente IA › Regras em ficheiro (Versão Actual do legado, js/ia_regras_ficheiro.js; tabela ficheiros_regras_ia).
 *
 * - Importar (validação de esquema; mesma `rule_name` → substitui com a versão seguinte, só com confirmação), activar,
 *   escolher o motor (AMBOS | INTERNA | EXTERNA), ver, exportar o ficheiro original e eliminar;
 * - motor interno: os valores vêm do formulário gerado pela regra; o servidor calcula, valida e devolve propostas;
 * - IA externa: o servidor envia o documento ao Claude com o pedido de SÓ extrair os valores (esquema JSON das variáveis
 *   da regra) e faz depois o mesmo cálculo — a IA nunca escolhe contas nem valores finais;
 * - NADA é gravado no diário: cada dia dá uma proposta no formato do formulário de lançamento (o utilizador revê e grava
 *   pelo fluxo normal, como no assistente por palavras-chave). A chave do fornecedor fica só no servidor.
 */
final class ServicoFicheirosRegrasIA
{
    public const MAX_DIAS = 31;

    public function __construct(
        private readonly ServicoAssistenteIA $assistente,
        private readonly ClienteClaude $claude,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return array{ativo: bool, configurado: bool, ia_disponivel: bool} (nunca a chave) */
    public function estado(int $empresa): array
    {
        $ativo = $this->assistente->ativo($empresa);
        $configurado = $this->claude->configurado();

        return ['ativo' => $ativo, 'configurado' => $configurado, 'ia_disponivel' => $ativo && $configurado];
    }

    /** @return list<array<string, mixed>> */
    public function listar(int $empresa): array
    {
        $registos = FicheiroRegrasIA::query()->ordenarPorNome()->get();
        $lidas = [];
        $codigos = [];
        foreach ($registos as $r) {
            try {
                $lidas[$r->id] = RegraFicheiro::ler((string) $r->conteudo);
                array_push($codigos, ...$lidas[$r->id]->contas());
            } catch (ErroNegocio $e) {
                $lidas[$r->id] = $e;
            }
        }
        $existentes = $codigos ? DB::table('plano_contas')->where('empresa_id', $empresa)->whereNull('eliminado_em')->whereIn('codigo', array_values(array_unique($codigos)))
            ->pluck('tipo', 'codigo')->all() : [];

        return $registos->map(function (FicheiroRegrasIA $r) use ($lidas, $existentes) {
            $regra = $lidas[$r->id];
            $ok = $regra instanceof RegraFicheiro;

            return $this->apresentar($r) + [
                'problema' => $ok ? null : $regra->getMessage(),
                'descricao' => $ok ? $regra->descricao() : null,
                'unidade' => $ok ? ($regra->dados['entity']['unit'] ?? null) : null,
                'linhas' => $ok ? count($regra->dados['entry']['lines']) : 0,
                'diario' => $ok ? $regra->diario() : null,
                'contas_em_falta' => $ok ? array_values(array_filter($regra->contas(), fn ($c) => ! isset($existentes[$c]))) : [],
                'contas_totalizadoras' => $ok ? array_values(array_filter($regra->contas(), fn ($c) => ($existentes[$c] ?? null) === 'T')) : [],
            ];
        })->all();
    }

    /** Detalhe: conteúdo, variáveis pedidas e calculadas (para o formulário do motor interno). */
    public function obter(int $id): array
    {
        $r = FicheiroRegrasIA::query()->findOrFail($id);
        $regra = RegraFicheiro::ler((string) $r->conteudo);
        $v = $regra->variaveis();

        return $this->apresentar($r) + [
            'descricao' => $regra->descricao(), 'diario' => $regra->diario(), 'documento' => $regra->dados['source']['document'] ?? null,
            'por_dia' => ($regra->dados['output_format']['per_day'] ?? true) !== false,
            'colunas' => array_values(array_filter((array) ($regra->dados['output_format']['columns'] ?? []), 'is_string')),
            'entradas' => $v['entradas'], 'calculadas' => array_keys($v['derivadas']), 'avisos' => $regra->avisos,
            'conteudo' => $regra->dados,
        ];
    }

    /**
     * Importa um ficheiro de regras. Se já existir uma regra com o mesmo `rule_name`, só a substitui com `substituir`
     * (a versão sobe; o conteúdo anterior fica no registo de auditoria).
     */
    public function importar(string $conteudo, string $ficheiro, bool $substituir): array
    {
        $regra = RegraFicheiro::ler($conteudo);
        $ficheiro = mb_substr(basename(str_replace('\\', '/', $ficheiro)), 0, 100) ?: 'regra.json';
        $nomeRegra = mb_substr($regra->nome() !== '' ? $regra->nome() : (string) preg_replace('/\.json$/i', '', $ficheiro), 0, 255);
        $u = Auth::user();
        $quem = mb_substr((string) ($u?->nome_completo ?: $u?->nome_utilizador ?: ''), 0, 100);

        $registo = DB::transaction(function () use ($conteudo, $ficheiro, $nomeRegra, $quem, $substituir) {
            $existente = FicheiroRegrasIA::query()->whereRaw('lower(nome_regra) = lower(?)', [$nomeRegra])->lockForUpdate()->first();
            if ($existente && ! $substituir) {
                throw new ErroNegocio("Já existe a regra «{$existente->nome_regra}» (versão ".($existente->versao ?: 1).'). Confirme para a substituir pelo ficheiro novo.',
                    'IA_REGRA_EXISTE', 409, ['id' => $existente->id, 'versao' => (int) ($existente->versao ?: 1)]);
            }
            $dados = ['nome_regra' => $nomeRegra, 'nome' => $nomeRegra, 'ficheiro' => $ficheiro, 'conteudo' => $conteudo, 'importado_em' => now(), 'importado_por' => $quem];
            if ($existente) {
                $existente->update($dados + ['versao' => (int) ($existente->versao ?: 1) + 1]);

                return $existente;
            }

            return FicheiroRegrasIA::query()->create($dados + ['ativo' => true, 'motor' => 'AMBOS', 'versao' => 1]);
        });
        $this->auditoria->registar('Contabilidade/Assistente IA', 'Importar regra', "Regra «{$nomeRegra}» v{$registo->versao} importada de {$ficheiro} ("
            .count($regra->dados['entry']['lines']).' linhas).', 'ficheiros_regras_ia', $registo->id);

        return $this->apresentar($registo->refresh()) + ['avisos' => $regra->avisos];
    }

    /** @param  array{ativo?: bool, motor?: string}  $d */
    public function alterar(int $id, array $d): array
    {
        $r = FicheiroRegrasIA::query()->findOrFail($id);
        $r->update(array_intersect_key($d, array_flip(['ativo', 'motor'])));

        return $this->apresentar($r->refresh());
    }

    public function eliminar(int $id): void
    {
        $r = FicheiroRegrasIA::query()->findOrFail($id);
        $r->delete();
        $this->auditoria->registar('Contabilidade/Assistente IA', 'Eliminar regra', "Regra «{$r->nome_regra}» v{$r->versao} eliminada.", 'ficheiros_regras_ia', $id);
    }

    /** @return array{ficheiro: string, conteudo: string} o ficheiro tal como foi importado */
    public function exportar(int $id): array
    {
        $r = FicheiroRegrasIA::query()->findOrFail($id);
        $nome = trim((string) $r->ficheiro) !== '' ? (string) $r->ficheiro : ((string) $r->nome_regra).'.json';

        return ['ficheiro' => (string) preg_replace('/[^\w.\- ]+/u', '_', $nome), 'conteudo' => (string) $r->conteudo];
    }

    /**
     * Motor interno: calcula os dias com os valores do formulário e devolve as propostas (nada é gravado).
     *
     * @param  list<array<string, mixed>>  $dias  [{data, valores{}, outros[{metodo, valor}], ilegivel, net_impresso}]
     */
    public function calcular(int $empresa, int $id, array $dias): array
    {
        $r = $this->regraUtilizavel($id, 'INTERNA');
        $inicio = microtime(true);
        $res = $this->resultados($empresa, $r, RegraFicheiro::ler((string) $r->conteudo), $dias, 'INTERNA');
        $this->registarUso($empresa, ['motor' => 'REGRAS', 'estado' => $res['propostas_validas'] ? 'SUCESSO' : 'SEM_PROPOSTA', 'propostas' => $res['propostas_validas'],
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000)]);

        return $res;
    }

    /**
     * IA externa: o documento vai ao Claude só para LER os valores pedidos pela regra; o cálculo é o do motor interno.
     */
    public function extrair(int $empresa, int $id, UploadedFile $ficheiro, ?string $texto): array
    {
        $r = $this->regraUtilizavel($id, 'EXTERNA');
        if (! $this->assistente->ativo($empresa)) {
            throw new ErroNegocio('O assistente IA está desligado nesta empresa. Um administrador pode activá-lo em «Assistente IA › Configuração».', 'IA_DESACTIVADA', 422);
        }
        if (! $this->claude->configurado()) {
            throw new ErroNegocio('O assistente IA não está configurado no servidor: o administrador do sistema tem de definir a variável ANTHROPIC_API_KEY (ver docs/PRODUCAO.md).', 'IA_SEM_CHAVE', 422);
        }
        $regra = RegraFicheiro::ler((string) $r->conteudo);
        $inicio = microtime(true);
        $registo = ['caracteres_texto' => mb_strlen((string) $texto), 'tipo_ficheiro' => $ficheiro->getMimeType(), 'tamanho_ficheiro_kb' => (int) ceil($ficheiro->getSize() / 1024),
            'motor' => 'IA', 'modelo' => (string) config('assistente_ia.modelo')];
        $tipo = (string) $ficheiro->getMimeType();
        $dados = base64_encode((string) file_get_contents($ficheiro->getRealPath()));
        $blocos = [$tipo === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $dados]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $tipo, 'data' => $dados]]];
        $pedido = trim((string) $texto) !== '' ? mb_substr(trim((string) $texto), 0, 4000) : (string) ($regra->dados['daily_prompt'] ?? '');
        $blocos[] = ['type' => 'text', 'text' => ($pedido !== '' ? "{$pedido}\n\n" : '').'Lê o documento em anexo e devolve os valores pedidos.'];

        try {
            $resp = $this->claude->extrair($regra->promptExterno(), $blocos, $regra->esquemaExtracao());
        } catch (Throwable $e) {
            $codigo = $e instanceof ErroNegocio ? $e->codigo : 'IA_ERRO';
            $this->registarUso($empresa, $registo + ['estado' => $codigo === 'IA_RECUSA' ? 'RECUSA' : 'FALHA', 'codigo_erro' => $codigo, 'propostas' => 0,
                'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000)]);
            throw $e instanceof ErroNegocio ? $e : new ErroNegocio('Erro inesperado no assistente IA.', 'IA_ERRO', 502);
        }
        $dias = [];
        foreach (array_slice((array) ($resp['dados']['dias'] ?? []), 0, self::MAX_DIAS) as $d) {
            if (! is_array($d)) {
                continue;
            }
            $dias[] = ['data' => (string) ($d['data'] ?? ''), 'valores' => is_array($d['valores'] ?? null) ? $d['valores'] : [],
                'outros' => array_map(fn ($o) => ['metodo' => (string) ($o['metodo'] ?? ''), 'valor' => $o['valor'] ?? 0], array_filter((array) ($d['outros_metodos'] ?? []), 'is_array')),
                'ilegivel' => (string) ($d['ilegivel'] ?? ''), 'net_impresso' => $d['net_impresso'] ?? null];
        }
        if (! $dias) {
            $this->registarUso($empresa, $registo + ['estado' => 'SEM_PROPOSTA', 'propostas' => 0, 'tokens_entrada' => $resp['tokens_entrada'], 'tokens_saida' => $resp['tokens_saida'],
                'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000)]);
            throw new ErroNegocio('A IA não encontrou nenhum dia no documento. Confirme o documento ou use o motor interno.', 'IA_SEM_DIAS', 422);
        }
        $res = $this->resultados($empresa, $r, $regra, $dias, 'EXTERNA');
        $custo = $resp['tokens_entrada'] / 1e6 * (float) config('assistente_ia.preco_entrada_mtok') + $resp['tokens_saida'] / 1e6 * (float) config('assistente_ia.preco_saida_mtok');
        $this->registarUso($empresa, $registo + ['modelo' => $resp['modelo'], 'estado' => $res['propostas_validas'] ? 'SUCESSO' : 'SEM_PROPOSTA', 'propostas' => $res['propostas_validas'],
            'tokens_entrada' => $resp['tokens_entrada'], 'tokens_saida' => $resp['tokens_saida'], 'custo_estimado_usd' => round($custo, 6),
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000)]);

        return $res;
    }

    /** @return array<string, mixed> */
    public static function modelo(): array
    {
        return ['ficheiro' => 'modelo_regra_lancamento.json', 'conteudo' => json_encode(RegraFicheiro::modelo(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    // ---------------------------------------------------------------- internos

    private function regraUtilizavel(int $id, string $motor): FicheiroRegrasIA
    {
        $r = FicheiroRegrasIA::query()->findOrFail($id);
        if ($r->ativo === false) {
            throw new ErroNegocio("A regra «{$r->nome_regra}» está inactiva: active-a antes de a usar.", 'IA_REGRA_INACTIVA', 422);
        }
        $m = $r->motor ?: 'AMBOS';
        if ($m !== 'AMBOS' && $m !== $motor) {
            throw new ErroNegocio($motor === 'INTERNA' ? 'Esta regra só está disponível para a IA externa.' : 'Esta regra só está disponível para o motor interno.', 'IA_REGRA_MOTOR', 422);
        }

        return $r;
    }

    /**
     * @param  list<array<string, mixed>>  $dias
     * @return array{regra: array<string, mixed>, motor: string, dias: list<array<string, mixed>>, propostas_validas: int}
     */
    private function resultados(int $empresa, FicheiroRegrasIA $r, RegraFicheiro $regra, array $dias, string $motor): array
    {
        $saida = [];
        $validas = 0;
        foreach (array_slice($dias, 0, self::MAX_DIAS) as $d) {
            $data = (string) ($d['data'] ?? '');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || ! strtotime($data)) {
                $data = now()->toDateString();
            }
            $res = $regra->calcular((array) ($d['valores'] ?? []), $data, ['outros' => (array) ($d['outros'] ?? []), 'ilegivel' => $d['ilegivel'] ?? null, 'net_impresso' => $d['net_impresso'] ?? null]);
            $res['proposta'] = null;
            if (! $res['erros']) {
                $unidade = trim((string) ($regra->dados['entity']['unit'] ?? ''));
                $descricao = mb_substr(trim(($regra->nome() ?: $r->nome_regra).($unidade !== '' ? " — {$unidade}" : '').($res['referencia'] !== '' ? " · {$res['referencia']}" : '')), 0, 1000);
                $falhadas = count(array_filter($res['validacoes'], fn ($v) => $v['ok'] === false));
                $proposta = $this->assistente->validar($empresa, ['diario_codigo' => $regra->diario(), 'data_documento' => $data, 'numero_documento' => $res['referencia'],
                    'descricao' => $descricao, 'linhas' => $res['linhas'],
                    'justificacao' => "Regra em ficheiro «{$r->nome_regra}» v".($r->versao ?: 1).' ('.($motor === 'INTERNA' ? 'motor interno' : 'valores lidos pela IA, cálculo local').')'
                        .($falhadas ? " — {$falhadas} validação(ões) da regra por cumprir." : '.')], 'REGRA_FICHEIRO');
                foreach ($proposta['linhas'] as $i => $l) {
                    $proposta['linhas'][$i] += ['nota_demonstracao' => $res['linhas'][$i]['nota_demonstracao'] ?? null, 'nota_fluxo' => $res['linhas'][$i]['nota_fluxo'] ?? null];
                }
                $res['proposta'] = $proposta;
                $validas++;
            }
            $saida[] = $res;
        }

        return ['regra' => $this->apresentar($r), 'motor' => $motor, 'dias' => $saida, 'propostas_validas' => $validas];
    }

    private function registarUso(int $empresa, array $d): void
    {
        $u = Auth::user();
        DB::table('utilizacoes_assistente_ia')->insert($d + ['empresa_id' => $empresa, 'utilizador_id' => $u?->getKey(), 'nome_utilizador' => $u?->nome_utilizador,
            'criado_em' => now(), 'atualizado_em' => now()]);
    }

    /** @return array<string, mixed> */
    private function apresentar(FicheiroRegrasIA $r): array
    {
        return ['id' => $r->id, 'nome' => (string) ($r->nome ?: $r->nome_regra), 'nome_regra' => (string) $r->nome_regra, 'ficheiro' => $r->ficheiro,
            'ativo' => $r->ativo !== false, 'motor' => $r->motor ?: 'AMBOS', 'versao' => (int) ($r->versao ?: 1),
            'importado_em' => $r->importado_em?->toIso8601String(), 'importado_por' => $r->importado_por];
    }
}
