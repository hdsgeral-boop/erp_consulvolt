<?php

namespace App\Services\Acrescimos;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigAcrescimoDiferimento;
use App\Models\DiarioContabil;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\Auth;

/**
 * Definições do módulo (ad_settings; definicoes/gravarDefinicoes, ad_dados.js:73-105): as quatro contas 37 do PGC Angola
 * (37.3 proveitos a facturar, 37.5 encargos a pagar, 37.4 encargos e 37.6 proveitos a repartir por períodos futuros),
 * o diário dos lançamentos e o prazo (dias) para receber o documento real de um acréscimo (60 por omissão).
 * Paridade: todas as contas têm de ser de movimento e o diário é obrigatório. Correcção: o diário tem de existir
 * nesta empresa (o legado aceitava qualquer id).
 */
final class ServicoDefinicoesAcrescimos
{
    public const CONTAS_PADRAO = ['ACRESCIMO_PROVEITO' => '37.3', 'ACRESCIMO_CUSTO' => '37.5', 'DIFERIMENTO_CUSTO' => '37.4', 'DIFERIMENTO_PROVEITO' => '37.6'];

    public const ROTULO_CONTA = ['ACRESCIMO_PROVEITO' => 'Proveitos a facturar', 'ACRESCIMO_CUSTO' => 'Encargos a pagar',
        'DIFERIMENTO_CUSTO' => 'Encargos a repartir por períodos futuros', 'DIFERIMENTO_PROVEITO' => 'Proveitos a repartir por períodos futuros'];

    /** Modelos frequentes que pré-preenchem o formulário (ad_dados.js:49-58). */
    public const MODELOS = [
        ['id' => 'seguro', 'rotulo' => 'Seguro anual pago adiantado', 'tipo' => 'DIFERIMENTO', 'natureza' => 'CUSTO', 'meses' => 12, 'prefixo' => '75'],
        ['id' => 'renda', 'rotulo' => 'Renda paga adiantada', 'tipo' => 'DIFERIMENTO', 'natureza' => 'CUSTO', 'meses' => 3, 'prefixo' => '75'],
        ['id' => 'assinatura', 'rotulo' => 'Assinatura / licença anual', 'tipo' => 'DIFERIMENTO', 'natureza' => 'CUSTO', 'meses' => 12, 'prefixo' => '75'],
        ['id' => 'contrato', 'rotulo' => 'Contrato facturado antecipadamente (cliente)', 'tipo' => 'DIFERIMENTO', 'natureza' => 'PROVEITO', 'meses' => 12, 'prefixo' => '62'],
        ['id' => 'ferias', 'rotulo' => 'Férias e subsídio de férias a pagar', 'tipo' => 'ACRESCIMO', 'natureza' => 'CUSTO', 'meses' => 12, 'prefixo' => '72'],
        ['id' => 'consumos', 'rotulo' => 'Consumos por facturar (água, luz, comunicações)', 'tipo' => 'ACRESCIMO', 'natureza' => 'CUSTO', 'meses' => 1, 'prefixo' => '75'],
        ['id' => 'juros_pagar', 'rotulo' => 'Juros a pagar', 'tipo' => 'ACRESCIMO', 'natureza' => 'CUSTO', 'meses' => 1, 'prefixo' => '76'],
        ['id' => 'servicos_facturar', 'rotulo' => 'Serviços prestados por facturar', 'tipo' => 'ACRESCIMO', 'natureza' => 'PROVEITO', 'meses' => 1, 'prefixo' => '62'],
    ];

    public function __construct(private readonly ServicoPlanoContas $plano) {}

    /** @return array{id: ?int, contas: array<string, string>, diario_id: ?int, prazo_documento_dias: int} */
    public function obter(): array
    {
        $r = ConfigAcrescimoDiferimento::query()->orderBy('id')->first();

        return ['id' => $r?->id, 'contas' => array_merge(self::CONTAS_PADRAO, array_filter($r?->contas ?? [], fn ($c) => (string) $c !== '')),
            'diario_id' => $r?->diario_id, 'prazo_documento_dias' => $r?->prazo_documento_dias ?? 60];
    }

    /** @param  array{contas: array<string, mixed>, diario_id: int, prazo_documento_dias?: ?int}  $d */
    public function guardar(array $d): array
    {
        $contas = [];
        $erros = [];
        foreach (self::CONTAS_PADRAO as $k => $_) {
            $contas[$k] = trim((string) ($d['contas'][$k] ?? ''));
            if ($e = $this->erroConta($contas[$k], self::ROTULO_CONTA[$k])) {
                $erros[$k] = $e;
            }
        }
        if (empty($d['diario_id']) || ! DiarioContabil::query()->whereKey($d['diario_id'])->exists()) {
            $erros['diario_id'] = 'Escolha o diário onde são lançados os acréscimos e diferimentos.';
        }
        if ($erros) {
            throw new ErroNegocio(implode("\n", $erros), 'DEFINICOES_INVALIDAS', 422, $erros);
        }
        $reg = ['contas' => $contas, 'diario_id' => (int) $d['diario_id'], 'prazo_documento_dias' => max(0, (int) ($d['prazo_documento_dias'] ?? 0)),
            'atualizado_por' => Auth::user()?->nome_utilizador];
        $atual = ConfigAcrescimoDiferimento::query()->orderBy('id')->first();
        $atual ? $atual->update($reg) : ConfigAcrescimoDiferimento::create($reg);

        return $this->obter();
    }

    /** Diário configurado (erro se não estiver definido ou já não existir). */
    public function exigirDiario(): DiarioContabil
    {
        $id = $this->obter()['diario_id'];
        if (! $id) {
            throw new ErroNegocio('Defina primeiro o diário em Acréscimos e Diferimentos › Definições.', 'SEM_DIARIO', 422);
        }

        return DiarioContabil::query()->find($id) ?? throw new ErroNegocio('O diário configurado já não existe. Revise as definições.', 'SEM_DIARIO', 422);
    }

    /** Conta de movimento existente, opcionalmente com um dos prefixos (validarConta, ad_dados.js:85-93). */
    public function erroConta(?string $codigo, string $rotulo, ?array $prefixos = null): ?string
    {
        $c = trim((string) $codigo);
        if ($c === '') {
            return "{$rotulo}: indique a conta.";
        }
        try {
            $this->plano->contaDeMovimento($c);
        } catch (ErroNegocio $e) {
            return $e->codigo === 'CONTA_INEXISTENTE' ? "{$rotulo}: a conta {$c} não existe no plano de contas desta empresa."
                : "{$rotulo}: {$c} é uma conta totalizadora — escolha uma subconta de movimento.";
        }
        if ($prefixos && ! collect($prefixos)->contains(fn ($p) => str_starts_with($c, $p))) {
            return "{$rotulo}: {$c} deve começar por ".implode(' ou ', $prefixos).'.';
        }

        return null;
    }
}
