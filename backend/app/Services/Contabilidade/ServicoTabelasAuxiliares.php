<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\CentroCusto;
use App\Models\DiarioContabil;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\PlanoConta;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Dados\VerificadorReferencias;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Tabelas auxiliares da contabilidade com código + descrição (renderTabelasAuxiliares, js/ui_aux.js:100-1041):
 * diários, notas às demonstrações (DEMO), notas de fluxo de caixa e centros de custo.
 * Operações do legado: criar/editar (código único por empresa), eliminar, importar Excel (Código, Descrição; os códigos
 * existentes só se actualizam se pedido — "dados mestre", 2026-09-22), copiar de outra empresa (só os códigos que faltam),
 * enviar um registo para outra empresa e sincronizar os centros de custo com as contas da classe 9 do plano.
 *
 * Correcções: eliminar verifica as utilizações (o legado apagava diários e notas usados em lançamentos, deixando-os
 * órfãos); copiar/enviar exige acesso do utilizador às duas empresas (o legado listava todas as empresas); a importação
 * é transaccional e com relatório (criados, actualizados, ignorados, repetidos, erros).
 */
final class ServicoTabelasAuxiliares
{
    /** @var array<string, array{modelo: class-string<Model>, tabela: string, rotulo: string, maiusculas: bool}> */
    public const TABELAS = [
        'diarios' => ['modelo' => DiarioContabil::class, 'tabela' => 'diarios_contabeis', 'rotulo' => 'o diário', 'maiusculas' => true],
        'notas-demonstracao' => ['modelo' => NotaDemonstracao::class, 'tabela' => 'notas_demonstracao_resultados', 'rotulo' => 'a nota às demonstrações', 'maiusculas' => false],
        'notas-fluxo-caixa' => ['modelo' => NotaFluxoCaixa::class, 'tabela' => 'notas_fluxo_caixa', 'rotulo' => 'a nota de fluxo de caixa', 'maiusculas' => false],
        'centros-custo' => ['modelo' => CentroCusto::class, 'tabela' => 'centros_custo', 'rotulo' => 'o centro de custo', 'maiusculas' => false],
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly VerificadorReferencias $referencias,
        private readonly ServicoEmpresas $empresas,
    ) {}

    public function listar(string $tabela): array
    {
        return $this->query($tabela)->orderBy('codigo')->orderBy('id')->get(['id', 'codigo', 'descricao'])->all();
    }

    /** @param  array{codigo: string, descricao: string}  $dados */
    public function guardar(string $tabela, ?int $id, array $dados): Model
    {
        $cfg = self::TABELAS[$tabela];
        $codigo = $this->codigo($tabela, $dados['codigo']);

        return DB::transaction(function () use ($tabela, $cfg, $id, $codigo, $dados) {
            $registo = $id ? $this->query($tabela)->lockForUpdate()->findOrFail($id) : new $cfg['modelo'];
            if ($this->query($tabela)->where('codigo', $codigo)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw new ErroNegocio("Já existe {$cfg['rotulo']} com o código {$codigo} nesta empresa.", 'CODIGO_DUPLICADO', 422, ['codigo' => $codigo]);
            }
            $registo->fill(['codigo' => $codigo, 'descricao' => trim($dados['descricao'])])->save();

            return $registo;
        });
    }

    public function eliminar(string $tabela, int $id): void
    {
        $cfg = self::TABELAS[$tabela];
        DB::transaction(function () use ($tabela, $cfg, $id) {
            $registo = $this->query($tabela)->lockForUpdate()->findOrFail($id);
            $this->referencias->exigirLivre($cfg['tabela'], $id, "{$cfg['rotulo']} {$registo->codigo}");
            $registo->delete();
        });
    }

    /**
     * Importação Excel (importAuxExcel, js/ui_aux.js:843-904): colunas Código e Descrição.
     *
     * @return array{criados: int, actualizados: int, ignorados: int, repetidos: int, erros: int}
     */
    public function importar(string $tabela, string $ficheiro, bool $actualizarExistentes): array
    {
        $linhas = $this->lerFolha($ficheiro);
        $existentes = $this->query($tabela)->get()->keyBy(fn ($r) => mb_strtolower(trim((string) $r->codigo)));
        $vistos = [];
        $r = ['criados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'repetidos' => 0, 'erros' => 0];
        DB::transaction(function () use ($tabela, $linhas, $existentes, $actualizarExistentes, &$vistos, &$r) {
            foreach ($linhas as $l) {
                $codigo = trim((string) ($l['codigo'] ?? ''));
                $descricao = trim((string) ($l['descricao'] ?? ''));
                if ($codigo === '' || $descricao === '') {
                    $r['erros']++;

                    continue;
                }
                $codigo = $this->codigo($tabela, $codigo);
                $k = mb_strtolower($codigo);
                if (isset($vistos[$k])) {
                    $r['repetidos']++;

                    continue;
                }
                $vistos[$k] = true;
                if ($e = $existentes[$k] ?? null) {
                    if ($actualizarExistentes) {
                        $e->update(['descricao' => $descricao]);
                        $r['actualizados']++;
                    } else {
                        $r['ignorados']++;
                    }

                    continue;
                }
                $modelo = self::TABELAS[$tabela]['modelo'];
                $modelo::create(['codigo' => $codigo, 'descricao' => $descricao]);
                $r['criados']++;
            }
        });

        return $r;
    }

    /**
     * Copiar de outra empresa (executeCopyTable, js/ui_aux.js:1003-1041): só os códigos que ainda não existem.
     *
     * @return array{origem: int, registos_origem: int, copiados: int, existentes: int}
     */
    public function copiarDe(string $tabela, int $empresaOrigem): array
    {
        $destino = $this->contexto->obrigatorio();
        $this->exigirAcesso($empresaOrigem, $destino);
        $origem = $this->contexto->executarComo($empresaOrigem, fn () => $this->query($tabela)->orderBy('id')->get(['codigo', 'descricao'])->all());
        $chaves = $this->query($tabela)->pluck('codigo')->map(fn ($c) => mb_strtolower(trim((string) $c)))->flip();
        $copiados = 0;
        DB::transaction(function () use ($tabela, $origem, $chaves, &$copiados) {
            $modelo = self::TABELAS[$tabela]['modelo'];
            foreach ($origem as $o) {
                $k = mb_strtolower(trim((string) $o->codigo));
                if ($k === '' || isset($chaves[$k])) {
                    continue;
                }
                $modelo::create(['codigo' => trim((string) $o->codigo), 'descricao' => $o->descricao]);
                $chaves[$k] = true;
                $copiados++;
            }
        });

        return ['origem' => $empresaOrigem, 'registos_origem' => count($origem), 'copiados' => $copiados, 'existentes' => count($origem) - $copiados];
    }

    /** Enviar um registo para outra empresa (sendAuxToOtherCompany, js/ui_aux.js:1186-1265): substitui só se pedido. */
    public function enviar(string $tabela, int $id, int $empresaDestino, bool $substituir): array
    {
        $origem = $this->contexto->obrigatorio();
        if ($empresaDestino === $origem) {
            throw new ErroNegocio('Escolha uma empresa de destino diferente da empresa activa.', 'EMPRESA_DESTINO_INVALIDA', 422);
        }
        $this->exigirAcesso($empresaDestino, $origem);
        $registo = $this->query($tabela)->findOrFail($id);

        return $this->contexto->executarComo($empresaDestino, fn () => DB::transaction(function () use ($tabela, $registo, $substituir, $empresaDestino) {
            $existente = $this->query($tabela)->where('codigo', $registo->codigo)->lockForUpdate()->first();
            if ($existente && ! $substituir) {
                throw new ErroNegocio("A empresa de destino já tem o código {$registo->codigo}: confirme a substituição.", 'REGISTO_EXISTE_DESTINO', 409,
                    ['codigo' => $registo->codigo, 'empresa_id' => $empresaDestino]);
            }
            if ($existente) {
                $existente->update(['descricao' => $registo->descricao]);

                return ['empresa_id' => $empresaDestino, 'id' => $existente->id, 'accao' => 'SUBSTITUIDO'];
            }
            $modelo = self::TABELAS[$tabela]['modelo'];

            return ['empresa_id' => $empresaDestino, 'id' => $modelo::create(['codigo' => $registo->codigo, 'descricao' => $registo->descricao])->id, 'accao' => 'CRIADO'];
        }));
    }

    /** syncCostCentersFromAccounts (js/ui_aux.js:1376-1392): um centro de custo por conta "9…" do plano que ainda não exista. */
    public function sincronizarCentrosCusto(): array
    {
        $contas = PlanoConta::query()->where('codigo', 'like', '9%')->orderBy('codigo')->get(['codigo', 'descricao']);
        if ($contas->isEmpty()) {
            throw new ErroNegocio('Não foram encontradas contas iniciadas por "9" no plano de contas.', 'SEM_CONTAS_CLASSE_9', 422);
        }
        $existentes = CentroCusto::query()->pluck('codigo')->flip();
        $criados = 0;
        DB::transaction(function () use ($contas, $existentes, &$criados) {
            foreach ($contas as $c) {
                if (! isset($existentes[$c->codigo])) {
                    CentroCusto::create(['codigo' => $c->codigo, 'descricao' => $c->descricao]);
                    $criados++;
                }
            }
        });

        return ['contas_classe_9' => $contas->count(), 'criados' => $criados];
    }

    private function query(string $tabela)
    {
        $modelo = self::TABELAS[$tabela]['modelo'] ?? throw new ErroNegocio('Tabela auxiliar desconhecida.', 'TABELA_DESCONHECIDA', 404);

        return $modelo::query();
    }

    private function codigo(string $tabela, string $codigo): string
    {
        $codigo = trim($codigo);
        if ($codigo === '' || mb_strlen($codigo) > 20 || ! preg_match('/^[\pL0-9.\-_\/ ]+$/u', $codigo)) {
            throw new ErroNegocio("Código inválido: \"{$codigo}\".", 'CODIGO_INVALIDO', 422);
        }

        return self::TABELAS[$tabela]['maiusculas'] ? mb_strtoupper($codigo) : $codigo;
    }

    private function exigirAcesso(int ...$empresas): void
    {
        $u = Auth::user();
        foreach ($empresas as $e) {
            if (! $u || ! $this->empresas->podeAceder($u, $e)) {
                throw new ErroNegocio('Não tem acesso à empresa indicada.', 'SEM_ACESSO_EMPRESA', 403, ['empresa_id' => $e]);
            }
        }
    }

    /** Cabeçalho normalizado: minúsculas, sem acentos nem separadores ("Descrição" → "descricao"). */
    public static function chave(mixed $texto): string
    {
        $s = strtr(mb_strtolower(trim((string) $texto)), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'º' => 'o', 'ª' => 'a']);

        return preg_replace('/[^a-z0-9]/', '', $s) ?? '';
    }

    /** @return list<array{codigo: mixed, descricao: mixed}> */
    private function lerFolha(string $ficheiro): array
    {
        try {
            $folha = IOFactory::load($ficheiro)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new ErroNegocio('Não foi possível ler o ficheiro (use XLSX, XLS ou CSV).', 'FICHEIRO_INVALIDO', 422);
        }
        $cab = array_map([self::class, 'chave'], array_shift($folha) ?? []);
        $iCodigo = array_search('codigo', $cab, true);
        $iDescricao = null;
        foreach ($cab as $i => $c) {
            if (str_starts_with($c, 'descri')) {
                $iDescricao = $i;
                break;
            }
        }
        if ($iCodigo === false || $iDescricao === null) {
            throw new ErroNegocio('O ficheiro tem de ter as colunas Código e Descrição.', 'FICHEIRO_INVALIDO', 422);
        }
        $linhas = [];
        foreach ($folha as $row) {
            if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $linhas[] = ['codigo' => $row[$iCodigo] ?? null, 'descricao' => $row[$iDescricao] ?? null];
        }
        if (! $linhas) {
            throw new ErroNegocio('O ficheiro está vazio.', 'FICHEIRO_VAZIO', 422);
        }

        return $linhas;
    }
}
