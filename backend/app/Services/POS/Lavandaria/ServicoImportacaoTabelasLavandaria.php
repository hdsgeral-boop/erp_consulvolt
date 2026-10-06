<?php

namespace App\Services\POS\Lavandaria;

use App\Exceptions\ErroNegocio;
use App\Models\PecaLavandaria;
use App\Models\Produto;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * M-16 — importação das tabelas da lavandaria a partir de Excel/CSV (legado: lavImportar, lavPreverImportacao,
 * lavConfirmarImportacao, js/lavandaria.js:2139-2330), com simulação (pré-visualização linha a linha) e gravação.
 *
 * Modelos (colunas por esta ordem, com cabeçalho na 1.ª linha — os nomes do cabeçalho também são reconhecidos):
 *   - pecas:    Código | Designação | Tecido | Cor | Unidade (Peça/Kg) | Preço | Activa (Sim/Não)
 *   - servicos: Código | Designação | Grupo (Lavandaria/Alfaiataria) | Conta (62…) | IVA | Prazo (dias) | Exige orçamento | Activo
 *   - precos:   Peça (código) | Serviço (código) | Preço (0 remove o preço específico)
 * Linha com código → actualiza esse registo (o código tem de existir); sem código → procura pela mesma designação
 * (tecido e unidade / grupo) e, não havendo, cria com código automático. As linhas com erros são ignoradas.
 * Cada linha válida é gravada pelo ServicoTabelasLavandaria (mesmas regras do ecrã), numa transacção com ponto de
 * salvaguarda por linha: uma linha que falhe na gravação não desfaz as outras e é devolvida com o motivo.
 */
final class ServicoImportacaoTabelasLavandaria
{
    public const TIPOS = ['pecas', 'servicos', 'precos'];

    private const COLUNAS = [
        'pecas' => ['codigo', 'designacao', 'tecido', 'cor', 'unidade', 'preco', 'activa'],
        'servicos' => ['codigo', 'designacao', 'grupo', 'conta', 'iva', 'prazo', 'orcamento', 'activo'],
        'precos' => ['peca', 'servico', 'preco'],
    ];

    public function __construct(
        private readonly ServicoTabelasLavandaria $tabelas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * @param  list<array<string, mixed>|list<mixed>>  $linhas  linhas do ficheiro (ServicoLeituraFolha: {cabeçalho: valor})
     * @return array{tipo: string, simulacao: bool, lidas: int, validas: int, criadas: int, actualizadas: int, ignoradas: int, linhas: list<array<string, mixed>>}
     */
    public function importar(string $tipo, array $linhas, bool $simular): array
    {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new ErroNegocio('Tipo de importação inválido (pecas, servicos ou precos).', 'VALIDACAO', 422);
        }
        $analise = [];
        $vistos = [];
        foreach ($linhas as $k => $bruto) {
            $r = $this->colunas($tipo, (array) $bruto);
            if (array_filter($r, fn ($v) => $v !== '') === []) {
                continue;
            }
            $a = match ($tipo) {
                'pecas' => $this->analisarPeca($r),
                'servicos' => $this->analisarServico($r),
                default => $this->analisarPreco($r),
            };
            if ($a['chave'] !== null && isset($vistos[$a['chave']])) {
                $a['erros'][] = 'linha repetida no ficheiro';
            }
            $vistos[$a['chave'] ?? uniqid()] = true;
            $analise[] = ['linha' => $k + 2] + $a;
        }

        $criadas = $actualizadas = 0;
        if (! $simular) {
            DB::transaction(function () use ($tipo, &$analise, &$criadas, &$actualizadas) {
                foreach ($analise as &$a) {
                    if ($a['erros']) {
                        continue;
                    }
                    try {
                        DB::transaction(fn () => $this->gravar($tipo, $a));   // ponto de salvaguarda por linha
                        $a['accao'] === 'CRIAR' ? $criadas++ : $actualizadas++;
                        $a['gravada'] = true;
                    } catch (ErroNegocio $e) {
                        $a['erros'][] = $e->getMessage();
                    } catch (Throwable) {
                        $a['erros'][] = 'Não foi possível gravar a linha.';
                    }
                }
                unset($a);
                if ($criadas || $actualizadas) {
                    $this->auditoria->registar('POS', 'Importou tabelas da lavandaria', "{$tipo}: {$criadas} criada(s), {$actualizadas} actualizada(s)", 'pecas_lavandaria');
                }
            });
        }
        $validas = count(array_filter($analise, fn ($a) => ! $a['erros']));

        return ['tipo' => $tipo, 'simulacao' => $simular, 'lidas' => count($analise), 'validas' => $validas, 'criadas' => $criadas, 'actualizadas' => $actualizadas,
            'ignoradas' => count($analise) - $validas, 'linhas' => array_map(fn ($a) => array_diff_key($a, ['dados' => true, 'chave' => true, 'alvo_id' => true]) + ['detalhe' => $a['dados']], $analise)];
    }

    // ───────────── análise ─────────────

    private function analisarPeca(array $r): array
    {
        $erros = [];
        $u = $r['unidade'];
        $unidade = $u === '' || preg_match('/^p/i', $u) ? 'PECA' : (preg_match('/^k/i', $u) ? 'KG' : null);
        $activa = self::simNao($r['activa'], true);
        $preco = self::numero($r['preco']);
        $dados = ['nome' => $r['designacao'], 'tecido' => $r['tecido'], 'cor' => $r['cor'], 'unidade' => $unidade, 'preco' => $preco, 'ativo' => $activa !== false];
        if ($unidade === null) {
            $erros[] = "unidade \"{$u}\" inválida (Peça ou Kg)";
        }
        if ($preco === null || $preco < 0) {
            $erros[] = 'preço inválido';
        }
        if ($activa === null) {
            $erros[] = 'Activa deve ser Sim ou Não';
        }
        if ($r['designacao'] === '') {
            $erros[] = 'designação em falta';
        }
        $alvo = null;
        if ($r['codigo'] !== '') {
            $alvo = PecaLavandaria::query()->whereRaw('upper(codigo) = ?', [mb_strtoupper($r['codigo'])])->first();
            if (! $alvo) {
                $erros[] = "o código {$r['codigo']} não existe (deixe vazio para criar)";
            }
        } elseif ($r['designacao'] !== '' && $unidade) {
            $alvo = PecaLavandaria::query()->whereRaw('lower(trim(nome)) = ?', [mb_strtolower($r['designacao'])])
                ->whereRaw("lower(coalesce(tecido, '')) = ?", [mb_strtolower($r['tecido'])])->where('unidade', $unidade)->first();
        }

        return ['accao' => $erros ? 'IGNORADA' : ($alvo ? 'ACTUALIZAR' : 'CRIAR'), 'codigo' => $alvo?->codigo, 'designacao' => $r['designacao'], 'erros' => $erros,
            'dados' => $dados, 'alvo_id' => $alvo?->id, 'chave' => $alvo ? "id{$alvo->id}" : mb_strtolower("{$r['designacao']}|{$r['tecido']}|{$unidade}")];
    }

    private function analisarServico(array $r): array
    {
        $erros = [];
        $g = $r['grupo'];
        $grupo = $g === '' || preg_match('/^lav/i', $g) ? 'LAVANDARIA' : (preg_match('/^alf/i', $g) ? 'ALFAIATARIA' : null);
        $orcamento = self::simNao($r['orcamento'], false);
        $activo = self::simNao($r['activo'], true);
        $iva = $r['iva'] === '' ? 14.0 : self::numero($r['iva']);
        $prazo = $r['prazo'] === '' ? 2.0 : self::numero($r['prazo']);
        $dados = ['nome' => $r['designacao'], 'grupo' => $grupo, 'codigo_conta' => $r['conta'], 'taxa_imposto' => $iva, 'dias_entrega' => $prazo === null ? null : (int) round($prazo),
            'requer_orcamento' => $orcamento === true, 'ativa' => $activo !== false];
        if ($grupo === null) {
            $erros[] = "grupo \"{$g}\" inválido (Lavandaria ou Alfaiataria)";
        }
        if (! str_starts_with($r['conta'], '62')) {
            $erros[] = 'a conta de proveitos tem de ser da classe 62';
        } elseif (! DB::table('plano_contas')->where('empresa_id', app(ContextoEmpresa::class)->obrigatorio())->where('codigo', $r['conta'])
            ->where(fn ($q) => $q->whereNull('tipo')->orWhere('tipo', 'M'))->whereNull('eliminado_em')->exists()) {
            $erros[] = "a conta {$r['conta']} não existe no plano de contas ou não é de movimento";
        }
        if ($iva === null || $iva < 0 || ! in_array((float) $iva, [0.0, 5.0, 7.0, 14.0], true)) {
            $erros[] = 'IVA inválido (0, 5, 7 ou 14)';
        }
        if ($prazo === null || $prazo < 0) {
            $erros[] = 'prazo inválido';
        }
        if ($orcamento === null || $activo === null) {
            $erros[] = 'Exige orçamento / Activo devem ser Sim ou Não';
        }
        if ($r['designacao'] === '') {
            $erros[] = 'designação em falta';
        }
        $alvo = null;
        $servicos = Produto::query()->whereIn('lavandaria_grupo', RegrasLavandaria::GRUPOS);
        if ($r['codigo'] !== '') {
            $alvo = (clone $servicos)->whereRaw('upper(codigo) = ?', [mb_strtoupper($r['codigo'])])->first();
            if (! $alvo) {
                $erros[] = "o código {$r['codigo']} não existe (deixe vazio para criar)";
            }
        } elseif ($r['designacao'] !== '' && $grupo) {
            $alvo = (clone $servicos)->whereRaw('lower(trim(nome)) = ?', [mb_strtolower($r['designacao'])])->where('lavandaria_grupo', $grupo)->first();
        }

        return ['accao' => $erros ? 'IGNORADA' : ($alvo ? 'ACTUALIZAR' : 'CRIAR'), 'codigo' => $alvo?->codigo, 'designacao' => $r['designacao'], 'erros' => $erros,
            'dados' => $dados, 'alvo_id' => $alvo?->id, 'chave' => $alvo ? "id{$alvo->id}" : mb_strtolower("{$r['designacao']}|{$grupo}")];
    }

    private function analisarPreco(array $r): array
    {
        $erros = [];
        $peca = $r['peca'] !== '' ? PecaLavandaria::query()->whereRaw('upper(codigo) = ?', [mb_strtoupper($r['peca'])])->first() : null;
        $servico = $r['servico'] !== '' ? Produto::query()->whereIn('lavandaria_grupo', RegrasLavandaria::GRUPOS)->whereRaw('upper(codigo) = ?', [mb_strtoupper($r['servico'])])->first() : null;
        $preco = self::numero($r['preco']);
        if (! $peca) {
            $erros[] = "peça \"{$r['peca']}\" inexistente";
        }
        if (! $servico) {
            $erros[] = "serviço \"{$r['servico']}\" inexistente";
        }
        if ($preco === null || $preco < 0) {
            $erros[] = 'preço inválido';
        }
        $actual = $peca && $servico ? collect($peca->precos_servico ?? [])->first(fn ($x) => (int) ($x['produto_id'] ?? 0) === $servico->id) : null;
        $accao = $erros ? 'IGNORADA' : ($preco == 0 ? ($actual ? 'REMOVER' : 'SEM_ALTERACAO') : ($actual ? 'ACTUALIZAR' : 'CRIAR'));

        return ['accao' => $accao, 'codigo' => $peca?->codigo, 'designacao' => trim(($peca ? "{$peca->codigo} · {$peca->nome}" : $r['peca']).' / '.($servico ? "{$servico->codigo} · {$servico->nome}" : $r['servico'])),
            'erros' => $erros, 'dados' => ['peca_id' => $peca?->id, 'produto_id' => $servico?->id, 'preco' => $preco, 'actual' => $actual['preco'] ?? null],
            'alvo_id' => $peca?->id, 'chave' => $peca && $servico ? "{$peca->id}|{$servico->id}" : null];
    }

    // ───────────── gravação ─────────────

    private function gravar(string $tipo, array $a): void
    {
        if ($tipo === 'pecas') {
            $this->tabelas->guardarPeca($a['dados'], $a['alvo_id'] ? PecaLavandaria::query()->findOrFail($a['alvo_id']) : null);
        } elseif ($tipo === 'servicos') {
            $this->tabelas->guardarServico($a['dados'], $a['alvo_id'] ? Produto::query()->findOrFail($a['alvo_id']) : null);
        } elseif ($a['accao'] !== 'SEM_ALTERACAO') {
            $peca = PecaLavandaria::query()->lockForUpdate()->findOrFail($a['dados']['peca_id']);
            $lista = collect($peca->precos_servico ?? [])->reject(fn ($x) => (int) ($x['produto_id'] ?? 0) === (int) $a['dados']['produto_id'])
                ->map(fn ($x) => ['produto_id' => (int) $x['produto_id'], 'preco' => $x['preco']])->values()->all();
            if ($a['dados']['preco'] > 0) {
                $lista[] = ['produto_id' => (int) $a['dados']['produto_id'], 'preco' => $a['dados']['preco']];
            }
            $this->tabelas->guardarPeca(['precos_servico' => $lista], $peca);
        }
    }

    // ───────────── apoio ─────────────

    /** Linha do ficheiro → colunas do modelo (pelo nome do cabeçalho, normalizado, ou pela posição). */
    private function colunas(string $tipo, array $bruto): array
    {
        $nomes = self::COLUNAS[$tipo];
        $porNome = [];
        foreach ($bruto as $k => $v) {
            if (is_string($k)) {
                $porNome[self::normalizar($k)] = $v;
            }
        }
        $sinonimos = ['designacao' => ['designacao', 'nome', 'descricao'], 'activa' => ['activa', 'ativa', 'activo', 'ativo'], 'activo' => ['activo', 'ativo', 'activa', 'ativa'],
            'orcamento' => ['exigeorcamento', 'orcamento', 'requerorcamento'], 'prazo' => ['prazo', 'prazodias', 'dias', 'diasentrega'], 'conta' => ['conta', 'contadeproveitos', 'codigoconta'],
            'peca' => ['peca', 'codigopeca'], 'servico' => ['servico', 'codigoservico'], 'iva' => ['iva', 'taxaiva', 'taxa'], 'preco' => ['preco', 'precobase', 'valor'],
            'unidade' => ['unidade', 'unidadedevenda'], 'codigo' => ['codigo', 'cod']];
        $valores = array_values($bruto);
        $r = [];
        foreach ($nomes as $i => $n) {
            $v = null;
            foreach ($sinonimos[$n] ?? [$n] as $s) {
                if (array_key_exists($s, $porNome)) {
                    $v = $porNome[$s];
                    break;
                }
            }
            $v ??= $porNome ? null : ($valores[$i] ?? null);
            $r[$n] = trim((string) ($v ?? ''));
        }

        return $r;
    }

    private static function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return preg_replace('/[^a-z0-9]/', '', $s) ?? $s;
    }

    private static function numero(string $v): ?float
    {
        if ($v === '') {
            return 0.0;
        }
        $v = str_replace([' ', "\u{00A0}"], '', $v);
        if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d+$/', $v) || (str_contains($v, ',') && ! str_contains($v, '.'))) {
            $v = str_replace(['.', ','], ['', '.'], $v);
        }

        return is_numeric($v) ? round((float) $v, 2) : null;
    }

    private static function simNao(string $v, bool $padrao): ?bool
    {
        if ($v === '') {
            return $padrao;
        }
        $v = mb_strtolower($v);

        return in_array($v, ['sim', 's', 'yes', 'y', '1', 'true', 'verdadeiro'], true) ? true : (in_array($v, ['não', 'nao', 'n', 'no', '0', 'false', 'falso'], true) ? false : null);
    }
}
