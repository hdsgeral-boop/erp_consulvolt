<?php

namespace App\Services\Procedimentos;

use App\Models\CargoFuncao;
use App\Models\Manual;
use Illuminate\Support\Facades\Auth;

/**
 * Definições do documento «Manual de Procedimentos» (uma linha por empresa em `manuais`, tipo PROCEDIMENTOS) e recolha dos
 * dados do documento (porte de definicoes(), gravarDefinicoes() e recolher() do legado). Correcção B-03: guarda-se a lista
 * de processos EXCLUÍDOS e não a das incluídas, para que os processos que o ERP acrescente depois entrem por omissão.
 * O servidor recolhe e calcula (avisos, cruzamentos, cobertura, totais); a pré-visualização e a impressão são do ecrã.
 */
final class ServicoManualProcedimentos
{
    public const TEXTOS = [
        'apresentacao' => ['rot' => 'Apresentação', 'ajuda' => 'A mensagem de abertura. Normalmente diz porque se escreveu o manual e o que se espera de quem o lê.',
            'exemplo' => 'O presente Manual de Procedimentos descreve a forma como se executam os processos da empresa…'],
        'objetivo' => ['rot' => 'Objectivo e âmbito', 'ajuda' => 'Para que serve e a quem se aplica.',
            'exemplo' => 'Este manual normaliza a execução dos processos, identifica os responsáveis de cada etapa e os controlos a observar. Aplica-se a todos os colaboradores que neles intervenham.'],
        'principios' => ['rot' => 'Princípios e regras gerais', 'ajuda' => 'As regras que valem para todos os processos: segregação de funções, alçadas, documentação, excepções.',
            'exemplo' => 'Nenhum colaborador executa e aprova o mesmo acto. As excepções a este manual carecem de autorização expressa e ficam registadas.'],
        'notas' => ['rot' => 'Disposições finais', 'ajuda' => 'Entrada em vigor, revisão, incumprimento.',
            'exemplo' => 'O presente manual entra em vigor na data da sua aprovação e é revisto sempre que um processo seja alterado.'],
    ];

    public const OPCOES = ['incluir_diagrama', 'incluir_matriz', 'incluir_controlos', 'incluir_legenda', 'incluir_cruzamentos', 'incluir_cobertura', 'conferir_funcoes'];

    public const PADRAO = [
        'titulo' => 'Manual de Procedimentos', 'versao' => '1.0', 'data_documento' => null, 'elaborado_por' => '', 'aprovado_por' => '', 'aprovado_em' => null,
        'incluir_diagrama' => true, 'incluir_matriz' => true, 'incluir_controlos' => true, 'incluir_legenda' => true, 'incluir_cruzamentos' => true,
        'incluir_cobertura' => false, 'conferir_funcoes' => true, 'processos_excluidos' => [],
        'apresentacao' => '', 'objetivo' => '', 'principios' => '', 'notas' => '',
    ];

    public function __construct(private readonly ServicoProcedimentos $processos) {}

    /** @return array<string, mixed> */
    public function definicoes(): array
    {
        $m = Manual::query()->where('tipo', Manual::TIPO_PROCEDIMENTOS)->first();
        if ($m === null) {
            return self::PADRAO + ['guardado' => false, 'textos_ajuda' => self::TEXTOS];
        }
        $o = (array) $m->opcoes;
        $t = (array) $m->textos;
        $out = [
            'titulo' => trim((string) $m->titulo) !== '' ? (string) $m->titulo : self::PADRAO['titulo'], 'versao' => (string) $m->versao_rascunho,
            'data_documento' => $m->data_documento?->format('Y-m-d'), 'elaborado_por' => (string) $m->elaborado_por, 'aprovado_por' => (string) $m->aprovado_por,
            'aprovado_em' => $m->aprovado_em?->format('Y-m-d'),
            'processos_excluidos' => array_values(array_filter((array) ($o['processos_excluidos'] ?? []), 'is_string')),
        ];
        foreach (self::OPCOES as $k) {
            $out[$k] = array_key_exists($k, $o) ? (bool) $o[$k] : self::PADRAO[$k];
        }
        foreach (array_keys(self::TEXTOS) as $k) {
            $out[$k] = (string) ($t[$k] ?? '');
        }

        return $out + ['guardado' => true, 'textos_ajuda' => self::TEXTOS];
    }

    /**
     * @param  array<string, mixed>  $d  já validado por GravarManualProcedimentosRequest
     * @return array<string, mixed>
     */
    public function gravar(array $d): array
    {
        $t = fn ($v) => trim((string) ($v ?? ''));
        $textos = [];
        foreach (array_keys(self::TEXTOS) as $k) {
            $textos[$k] = $t($d[$k] ?? '');
        }
        $opcoes = ['processos_excluidos' => array_values(array_unique(array_map('strval', $d['processos_excluidos'] ?? [])))];
        foreach (self::OPCOES as $k) {
            $opcoes[$k] = (bool) ($d[$k] ?? false);
        }
        $reg = [
            'titulo' => $t($d['titulo'] ?? '') ?: self::PADRAO['titulo'], 'versao_rascunho' => $t($d['versao'] ?? '') ?: self::PADRAO['versao'],
            'data_documento' => $t($d['data_documento'] ?? '') ?: null, 'elaborado_por' => $t($d['elaborado_por'] ?? ''), 'aprovado_por' => $t($d['aprovado_por'] ?? ''),
            'aprovado_em' => $t($d['aprovado_em'] ?? '') ?: null, 'textos' => $textos, 'opcoes' => $opcoes, 'atualizado_por' => Auth::user()?->nome_utilizador,
        ];
        $m = Manual::query()->where('tipo', Manual::TIPO_PROCEDIMENTOS)->first();
        if ($m === null) {
            Manual::query()->create($reg + ['tipo' => Manual::TIPO_PROCEDIMENTOS, 'criado_por' => Auth::user()?->nome_utilizador]);
        } else {
            $m->update($reg);
        }

        return $this->definicoes();
    }

    /**
     * Os dados do documento: o que está gravado, com as sobreposições do pedido (o formulário ainda por gravar).
     *
     * @param  array<string, mixed>  $sobrepor
     * @return array<string, mixed>
     */
    public function recolher(array $sobrepor = []): array
    {
        $o = array_replace($this->definicoes(), array_intersect_key($sobrepor, self::PADRAO));
        $todos = $this->processos->listar();
        $excluidos = array_map('strval', (array) ($o['processos_excluidos'] ?? []));
        $escolhidos = array_values(array_filter($todos, fn ($p) => ! in_array($p['chave'], $excluidos, true)));

        // Intervenientes: quem aparece em quantos processos (base da matriz e forma de ver o mesmo papel escrito de duas maneiras).
        $papeis = [];
        foreach ($escolhidos as $p) {
            $lista = array_unique(array_merge($p['intervenientes'], array_filter(array_column($p['etapas'], 'quem'), fn ($q) => trim((string) $q) !== '')));
            foreach ($lista as $x) {
                $k = trim((string) $x);
                if ($k === '') {
                    continue;
                }
                $papeis[$k] ??= ['nome' => $k, 'processos' => []];
                if (! in_array($p['chave'], $papeis[$k]['processos'], true)) {
                    $papeis[$k]['processos'][] = $p['chave'];
                }
            }
        }
        $intervenientes = array_values($papeis);
        usort($intervenientes, fn ($a, $b) => count($b['processos']) <=> count($a['processos']) ?: strcmp(AnaliseProcedimentos::normal($a['nome']), AnaliseProcedimentos::normal($b['nome'])));

        // Controlos-chave: tudo o que se controla, por processo e etapa.
        $controlos = [];
        foreach ($escolhidos as $p) {
            foreach ($p['etapas'] as $i => $e) {
                if (trim($e['controlos']) !== '') {
                    $controlos[] = ['processo' => $p['chave'], 'etapa' => $e['nome'], 'quem' => $e['quem'], 'controlos' => $e['controlos'], 'n' => $i + 1];
                }
            }
        }

        // Conferência com RH › Funções: «Comercial / Director Comercial» conta como encontrado se qualquer das partes existir.
        $funcoes = [];
        if ($o['conferir_funcoes']) {
            foreach (CargoFuncao::query()->pluck('nome') as $nome) {
                $funcoes[AnaliseProcedimentos::normal((string) $nome)] = true;
            }
        }
        foreach ($intervenientes as &$p) {
            $p['partes'] = array_values(array_filter(array_map('trim', explode('/', $p['nome'])), fn ($x) => $x !== ''));
            $p['na_funcoes'] = $funcoes === [] ? null : (bool) array_filter($p['partes'], fn ($x) => isset($funcoes[AnaliseProcedimentos::normal($x)]));
        }
        unset($p);

        $cruz = AnaliseProcedimentos::cruzamentos($escolhidos);
        $totais = [
            'processos' => count($escolhidos), 'etapas' => array_sum(array_map(fn ($p) => count($p['etapas']), $escolhidos)), 'controlos' => count($controlos),
            'intervenientes' => count($intervenientes), 'editados' => count(array_filter($escolhidos, fn ($p) => $p['origem'] === 'EDITADO')),
            'proprios' => count(array_filter($escolhidos, fn ($p) => $p['origem'] === 'PROPRIO')), 'ligacoes' => count($cruz['ligacoes']),
        ];

        return [
            'opcoes' => $o, 'processos' => $escolhidos, 'intervenientes' => $intervenientes, 'controlos' => $controlos, 'cruzamentos' => $cruz,
            'cobertura' => $o['incluir_cobertura'] ? $this->processos->cobertura() : null, 'totais' => $totais,
            'avisos' => AnaliseProcedimentos::lacunas($escolhidos, $intervenientes, $o, $cruz),
        ];
    }
}
