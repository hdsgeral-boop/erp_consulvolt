<?php

namespace App\Services\Procedimentos;

use App\Exceptions\ErroNegocio;
use App\Models\CruzamentoProcedimento;
use App\Models\EtapaProcedimento;
use App\Models\Procedimento;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Processos do Manual de Procedimentos por empresa: as narrativas de fábrica (que acompanham o ERP) + as edições da empresa
 * + os processos próprios. Porte de procedimentos_manual.js com as correcções deliberadas do legado:
 *  - B-02: esconder um processo de fábrica grava só uma bandeira, SEM copiar o texto (não deixa de acompanhar o ERP);
 *  - B-06: a chave é única por empresa (BD) e a verificação de duplicado inclui os escondidos;
 *  - B-04: a permissão aplica-se no servidor (nos controladores / pedidos).
 * Editar a narrativa muda o MANUAL, nunca o que o painel do Fluxo de Processos mede (o ficheiro do Fluxo não é tocado).
 */
final class ServicoProcedimentos
{
    public function __construct(private readonly CatalogoFabricaProcedimentos $fabrica) {}

    /**
     * A lista completa dos processos visíveis, com as edições aplicadas e ordenados.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $fab = $this->fabrica->todas();
        $linhas = $this->linhas();
        $out = [];
        $i = 0;
        foreach ($fab as $chave => $n) {
            $g = $linhas[$chave] ?? null;
            unset($linhas[$chave]);
            $base = ['chave' => $chave, 'origem' => 'FABRICA', 'editado' => false, 'fluxo_id' => $n['fluxo_id'], 'modulo' => $n['modulo'], 'tem_painel' => $n['fluxo_id'] !== null, 'ordem' => $i * 10];
            $i++;
            if ($g !== null && $g->oculto) {
                continue;
            }
            if ($g === null || ! $g->personalizado) {
                $out[] = $base + $this->corpo($n);

                continue;
            }
            $out[] = array_merge($base, $this->corpoDaLinha($g), ['origem' => 'EDITADO', 'editado' => true, 'ordem' => $g->ordem ?: $base['ordem']]);
        }
        foreach ($linhas as $g) {
            if ($g->oculto) {
                continue;
            }
            $out[] = array_merge(['chave' => $g->chave, 'origem' => 'PROPRIO', 'editado' => true, 'fluxo_id' => null, 'modulo' => $g->modulo, 'tem_painel' => false, 'ordem' => $g->ordem ?: 9000], $this->corpoDaLinha($g));
        }
        usort($out, fn ($a, $b) => [$a['ordem'], $this->chaveOrdem($a['titulo'])] <=> [$b['ordem'], $this->chaveOrdem($b['titulo'])]);

        return $out;
    }

    /** Os processos escondidos do manual (para o ecrã poder voltar a incluí-los). @return list<array{chave: string, titulo: string}> */
    public function ocultos(): array
    {
        $fab = $this->fabrica->todas();
        $out = [];
        foreach ($this->linhas() as $g) {
            if ($g->oculto) {
                $out[] = ['chave' => $g->chave, 'titulo' => trim((string) $g->titulo) !== '' ? (string) $g->titulo : ($fab[$g->chave]['titulo'] ?? $g->chave)];
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function cobertura(): array
    {
        return AnaliseProcedimentos::cobertura($this->listar(), $this->fabrica->paineis());
    }

    /** Chave a partir do nome («Recrutamento e selecção» → recrutamento_e_seleccao), até 30 caracteres. */
    public function chaveDoNome(string $nome): string
    {
        return substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(Str::ascii($nome))), '_'), 0, 30);
    }

    /**
     * Cria um processo próprio (uma «Primeira etapa» por preencher). A chave sai do título; não pode repetir nenhuma da
     * fábrica nem da empresa, escondidos incluídos (B-06).
     *
     * @return array<string, mixed>
     */
    public function criar(string $titulo): array
    {
        $titulo = trim($titulo);
        $chave = $this->chaveDoNome($titulo);
        if ($chave === '') {
            throw new ErroNegocio('Esse nome não dá uma chave válida. Use letras.', 'PROCEDIMENTO_CHAVE_INVALIDA');
        }
        if ($this->fabrica->existe($chave) || isset(CatalogoFabricaProcedimentos::EXTERNOS[$chave]) || Procedimento::query()->where('chave', $chave)->exists()) {
            throw new ErroNegocio('Já existe um processo com esse nome.', 'PROCEDIMENTO_JA_EXISTE');
        }

        return DB::transaction(function () use ($titulo, $chave) {
            $p = Procedimento::query()->create(['chave' => $chave, 'origem' => Procedimento::ORIGEM_PROPRIO, 'personalizado' => true, 'oculto' => false, 'titulo' => $titulo,
                'objetivo' => '', 'intervenientes' => [], 'ordem' => 0, 'criado_por' => $this->operador(), 'atualizado_por' => $this->operador()]);
            $this->substituirEtapas($p, [['nome' => 'Primeira etapa', 'quem' => '', 'descricao' => '', 'controlos' => '', 'resultado' => '']]);

            return $this->obter($chave);
        });
    }

    /**
     * Grava a edição de um processo (de fábrica ou próprio), substituindo o conjunto de etapas e de cruzamentos.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function gravar(string $chave, array $dados): array
    {
        $d = $this->limpar($dados);
        if ($d['titulo'] === '') {
            throw new ErroNegocio('Dê um título ao processo.', 'PROCEDIMENTO_SEM_TITULO');
        }
        if ($d['etapas'] === []) {
            throw new ErroNegocio('Um procedimento sem etapas não descreve nada. Acrescente pelo menos uma.', 'PROCEDIMENTO_SEM_ETAPAS');
        }

        return DB::transaction(function () use ($chave, $d) {
            $p = Procedimento::query()->where('chave', $chave)->lockForUpdate()->first();
            if ($p === null) {
                $n = $this->fabrica->todas()[$chave] ?? null;
                if ($n === null) {
                    throw $this->naoEncontrado();
                }
                $p = new Procedimento(['chave' => $chave, 'origem' => Procedimento::ORIGEM_FABRICA, 'oculto' => false, 'ordem' => 0, 'criado_por' => $this->operador(),
                    'fluxo_id' => $n['fluxo_id'], 'modulo' => $n['modulo'], 'base_hash' => $this->fabrica->hash($chave)]);
            }
            $this->validarCruzamentos($chave, $d['cruza']);
            $p->fill(['personalizado' => true, 'titulo' => $d['titulo'], 'objetivo' => $d['objetivo'], 'intervenientes' => $d['intervenientes'], 'atualizado_por' => $this->operador()])->save();
            $this->substituirEtapas($p, $d['etapas']);
            $this->substituirCruzamentos($p, $d['cruza']);

            return $this->obter($chave);
        });
    }

    /** Devolve um processo de fábrica ao texto original (e mostra-o); um processo próprio é apagado. */
    public function repor(string $chave): void
    {
        DB::transaction(function () use ($chave) {
            $p = Procedimento::query()->where('chave', $chave)->lockForUpdate()->first();
            if ($p === null || ($p->origem === Procedimento::ORIGEM_FABRICA && ! $p->personalizado && ! $p->oculto)) {
                throw new ErroNegocio('Este processo não tem alterações para repor.', 'PROCEDIMENTO_SEM_EDICAO');
            }
            $p->delete();
        });
    }

    /** Esconde (ou volta a incluir) um processo no manual sem apagar nada. O texto de fábrica nunca é copiado (B-02). */
    public function ocultar(string $chave, bool $ocultar): void
    {
        DB::transaction(function () use ($chave, $ocultar) {
            $p = Procedimento::query()->where('chave', $chave)->lockForUpdate()->first();
            if ($p === null) {
                if (! $ocultar) {
                    return;
                }
                $n = $this->fabrica->todas()[$chave] ?? null;
                if ($n === null) {
                    throw $this->naoEncontrado();
                }
                Procedimento::query()->create(['chave' => $chave, 'origem' => Procedimento::ORIGEM_FABRICA, 'personalizado' => false, 'oculto' => true, 'ordem' => 0,
                    'fluxo_id' => $n['fluxo_id'], 'modulo' => $n['modulo'], 'criado_por' => $this->operador(), 'atualizado_por' => $this->operador()]);

                return;
            }
            // Voltar a incluir um processo de fábrica que só tinha a bandeira: a linha deixa de ser precisa.
            if (! $ocultar && $p->origem === Procedimento::ORIGEM_FABRICA && ! $p->personalizado) {
                $p->delete();

                return;
            }
            $p->update(['oculto' => $ocultar, 'atualizado_por' => $this->operador()]);
        });
    }

    /** @return array<string, mixed> */
    public function obter(string $chave): array
    {
        foreach ($this->listar() as $p) {
            if ($p['chave'] === $chave) {
                return $p;
            }
        }
        // Escondido: devolve-o na mesma (a edição continua possível a partir do ecrã dos escondidos).
        $g = Procedimento::query()->with(['etapas', 'cruzamentos'])->where('chave', $chave)->first();
        $n = $this->fabrica->todas()[$chave] ?? null;
        if ($g === null && $n === null) {
            throw $this->naoEncontrado();
        }
        if ($g !== null && $g->personalizado) {
            return ['chave' => $chave, 'origem' => $g->origem === 'PROPRIO' ? 'PROPRIO' : 'EDITADO', 'editado' => true, 'fluxo_id' => $g->fluxo_id, 'modulo' => $g->modulo, 'tem_painel' => $g->fluxo_id !== null, 'ordem' => $g->ordem, 'oculto' => true] + $this->corpoDaLinha($g);
        }

        return ['chave' => $chave, 'origem' => 'FABRICA', 'editado' => false, 'fluxo_id' => $n['fluxo_id'], 'modulo' => $n['modulo'], 'tem_painel' => $n['fluxo_id'] !== null, 'ordem' => 0, 'oculto' => true] + $this->corpo($n);
    }

    /**
     * Normaliza o que vem do pedido: texto aparado, intervenientes sem vazios, etapas sem nome e cruzamentos sem destino descartados.
     *
     * @param  array<string, mixed>  $d
     * @return array{titulo: string, objetivo: string, intervenientes: list<string>, etapas: list<array<string, string>>, cruza: list<array<string, string>>}
     */
    public function limpar(array $d): array
    {
        $t = fn ($v) => trim((string) ($v ?? ''));
        $interv = $d['intervenientes'] ?? [];
        $interv = is_array($interv) ? $interv : preg_split('/\n+/', $t($interv));
        $etapas = [];
        foreach ((array) ($d['etapas'] ?? []) as $e) {
            $e = (array) $e;
            if ($t($e['nome'] ?? '') === '') {
                continue;
            }
            $etapas[] = ['nome' => $t($e['nome']), 'quem' => $t($e['quem'] ?? ''), 'descricao' => $t($e['descricao'] ?? ''), 'controlos' => $t($e['controlos'] ?? ''), 'resultado' => $t($e['resultado'] ?? '')];
        }
        $cruza = [];
        foreach ((array) ($d['cruza'] ?? []) as $c) {
            $c = (array) $c;
            if ($t($c['com'] ?? '') === '') {
                continue;
            }
            $cruza[] = ['com' => $t($c['com']), 'onde' => $t($c['onde'] ?? ''), 'o_que' => $t($c['o_que'] ?? '')];
        }

        return ['titulo' => $t($d['titulo'] ?? ''), 'objetivo' => $t($d['objetivo'] ?? ''),
            'intervenientes' => array_values(array_filter(array_map($t, $interv), fn ($x) => $x !== '')), 'etapas' => $etapas, 'cruza' => $cruza];
    }

    /** O destino de um cruzamento é outro processo (fábrica ∪ próprios ∪ escondidos) ou um documento externo; nunca o próprio. */
    private function validarCruzamentos(string $chave, array $cruza): void
    {
        if ($cruza === []) {
            return;
        }
        $validas = array_merge(array_keys($this->fabrica->todas()), array_keys(CatalogoFabricaProcedimentos::EXTERNOS), Procedimento::query()->pluck('chave')->all());
        foreach ($cruza as $c) {
            if ($c['com'] === $chave) {
                throw new ErroNegocio('Um processo não se cruza consigo próprio.', 'PROCEDIMENTO_CRUZAMENTO_INVALIDO');
            }
            if (! in_array($c['com'], $validas, true)) {
                throw new ErroNegocio('Um dos cruzamentos aponta para um processo que não existe.', 'PROCEDIMENTO_CRUZAMENTO_INVALIDO');
            }
        }
    }

    /** @param  list<array<string, string>>  $etapas */
    private function substituirEtapas(Procedimento $p, array $etapas): void
    {
        EtapaProcedimento::query()->where('procedimento_id', $p->id)->delete();
        foreach (array_values($etapas) as $i => $e) {
            EtapaProcedimento::query()->create($e + ['procedimento_id' => $p->id, 'ordem' => $i + 1]);
        }
    }

    /** @param  list<array<string, string>>  $cruza */
    private function substituirCruzamentos(Procedimento $p, array $cruza): void
    {
        CruzamentoProcedimento::query()->where('procedimento_id', $p->id)->delete();
        foreach (array_values($cruza) as $i => $c) {
            CruzamentoProcedimento::query()->create(['procedimento_id' => $p->id, 'ordem' => $i + 1, 'com_chave' => $c['com'], 'onde' => $c['onde'], 'o_que' => $c['o_que']]);
        }
    }

    /** @return array<string, Procedimento> */
    private function linhas(): array
    {
        $out = [];
        foreach (Procedimento::query()->with(['etapas', 'cruzamentos'])->get() as $g) {
            $out[$g->chave] = $g;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $n @return array<string, mixed> */
    private function corpo(array $n): array
    {
        return ['titulo' => $n['titulo'], 'objetivo' => $n['objetivo'], 'intervenientes' => $n['intervenientes'], 'etapas' => $n['etapas'], 'cruza' => $n['cruza'], 'oculto' => false];
    }

    /** @return array<string, mixed> */
    private function corpoDaLinha(Procedimento $g): array
    {
        return [
            'titulo' => (string) $g->titulo, 'objetivo' => (string) $g->objetivo, 'intervenientes' => array_values((array) $g->intervenientes), 'oculto' => (bool) $g->oculto,
            'etapas' => $g->etapas->map(fn ($e) => ['nome' => (string) $e->nome, 'quem' => (string) $e->quem, 'descricao' => (string) $e->descricao, 'controlos' => (string) $e->controlos, 'resultado' => (string) $e->resultado])->all(),
            'cruza' => $g->cruzamentos->map(fn ($c) => ['com' => (string) $c->com_chave, 'onde' => (string) $c->onde, 'o_que' => (string) $c->o_que])->all(),
        ];
    }

    private function chaveOrdem(string $titulo): string
    {
        return Str::ascii(mb_strtolower(trim($titulo)));
    }

    private function operador(): ?string
    {
        return Auth::user()?->nome_utilizador;
    }

    private function naoEncontrado(): ErroNegocio
    {
        return new ErroNegocio('Processo não encontrado.', 'PROCEDIMENTO_NAO_ENCONTRADO', 404);
    }
}
