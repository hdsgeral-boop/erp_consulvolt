<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Services\Sistema\ServicoAuditoria;

/**
 * Máquina de estados da licença por empresa (Fase 1 da extensão SaaS, secções 4.2/6/10 de `segurança.txt`):
 * substitui a inferência por datas (antigo Empresa::licencaExpirada()) por um estado explícito e auditável, com
 * transições validadas. Separada do estado administrativo da empresa (Empresa::ESTADO_ATIVO/INATIVO) — a secção 4.2
 * é explícita: "Não confundir o estado administrativo da empresa com o estado financeiro da subscrição."
 */
final class MaquinaEstadosLicenca
{
    /** Transições permitidas a partir de cada estado ("os estados devem possuir regras explícitas de transição"). */
    private const TRANSICOES = [
        Empresa::LICENCA_PENDENTE_ACTIVACAO => [Empresa::LICENCA_TRIAL, Empresa::LICENCA_ACTIVA, Empresa::LICENCA_CANCELADA],
        Empresa::LICENCA_TRIAL => [Empresa::LICENCA_ACTIVA, Empresa::LICENCA_PAGAMENTO_PENDENTE, Empresa::LICENCA_TOLERANCIA, Empresa::LICENCA_SUSPENSA, Empresa::LICENCA_CANCELADA],
        Empresa::LICENCA_ACTIVA => [Empresa::LICENCA_PAGAMENTO_PENDENTE, Empresa::LICENCA_TOLERANCIA, Empresa::LICENCA_SUSPENSA, Empresa::LICENCA_CANCELADA],
        Empresa::LICENCA_PAGAMENTO_PENDENTE => [Empresa::LICENCA_ACTIVA, Empresa::LICENCA_TOLERANCIA, Empresa::LICENCA_SUSPENSA, Empresa::LICENCA_CANCELADA],
        Empresa::LICENCA_TOLERANCIA => [Empresa::LICENCA_ACTIVA, Empresa::LICENCA_SUSPENSA, Empresa::LICENCA_CANCELADA],
        Empresa::LICENCA_SUSPENSA => [Empresa::LICENCA_ACTIVA, Empresa::LICENCA_CANCELADA, Empresa::LICENCA_EM_ENCERRAMENTO],
        Empresa::LICENCA_CANCELADA => [Empresa::LICENCA_EM_ENCERRAMENTO, Empresa::LICENCA_ACTIVA],
        Empresa::LICENCA_EM_ENCERRAMENTO => [Empresa::LICENCA_ENCERRADA, Empresa::LICENCA_ACTIVA],
        Empresa::LICENCA_ENCERRADA => [],
    ];

    private const MOTIVOS = [Empresa::MOTIVO_INCUMPRIMENTO, Empresa::MOTIVO_ADMINISTRATIVA, Empresa::MOTIVO_SEGURANCA, Empresa::MOTIVO_VOLUNTARIA];

    public function __construct(private readonly ServicoAuditoria $auditoria) {}

    /**
     * Aplica a transição, validando-a primeiro, e audita. `$motivo` só é guardado quando o novo estado é SUSPENSA;
     * qualquer outro estado limpa o motivo (deixou de estar suspensa). Sem efeito (nem auditoria) se já lá estava.
     */
    public function transicionar(Empresa $empresa, string $novoEstado, ?string $motivo = null, ?string $notas = null): Empresa
    {
        $actual = $empresa->estado_licenca;
        if ($actual === $novoEstado) {
            return $empresa;
        }
        if (! in_array($novoEstado, self::TRANSICOES[$actual] ?? [], true)) {
            throw new ErroNegocio("Transição de '{$actual}' para '{$novoEstado}' não é permitida.", 'TRANSICAO_INVALIDA', 422);
        }
        if ($novoEstado === Empresa::LICENCA_SUSPENSA && ! in_array($motivo, self::MOTIVOS, true)) {
            throw new ErroNegocio('A suspensão exige um motivo válido (incumprimento, administrativa, segurança ou voluntária).', 'MOTIVO_OBRIGATORIO', 422);
        }

        $empresa->update([
            'estado_licenca' => $novoEstado,
            'motivo_suspensao' => $novoEstado === Empresa::LICENCA_SUSPENSA ? $motivo : null,
            'estado_licenca_desde' => now(),
        ]);

        $this->auditoria->registar(
            modulo: 'Plataforma/Licencas',
            acao: 'Transição de estado da licença',
            detalhes: "Empresa '{$empresa->nome}' passou de '{$actual}' para '{$novoEstado}'".($motivo ? " (motivo: {$motivo})" : '').($notas ? " — {$notas}" : ''),
            tabela: 'empresas',
            registoId: $empresa->id,
            anteriores: ['estado_licenca' => $actual],
            novos: ['estado_licenca' => $novoEstado, 'motivo_suspensao' => $motivo],
            empresaId: $empresa->id,
        );

        return $empresa->refresh();
    }

    /**
     * Estado que a empresa DEVERIA ter agora, a partir dos dados de licenciamento (licenca_origem/licenca_fim) —
     * usado para sincronizar (Fase 3: comando agendado) e para corrigir o estado após renovação/pagamento. Nunca
     * deriva estados manuais (CANCELADA/EM_ENCERRAMENTO/ENCERRADA, ou SUSPENSA por motivo que não incumprimento) —
     * esses só mudam por acção explícita de um operador, nunca pelo recálculo automático.
     */
    public function calcular(Empresa $empresa): string
    {
        $actual = $empresa->estado_licenca;
        if (in_array($actual, [Empresa::LICENCA_CANCELADA, Empresa::LICENCA_EM_ENCERRAMENTO, Empresa::LICENCA_ENCERRADA], true)) {
            return $actual;
        }
        if ($actual === Empresa::LICENCA_SUSPENSA && $empresa->motivo_suspensao !== Empresa::MOTIVO_INCUMPRIMENTO) {
            return $actual;
        }

        if ($empresa->licenca_fim === null) {
            return Empresa::LICENCA_ACTIVA;
        }
        if (! $empresa->licenca_fim->lt(now()->startOfDay())) {
            return $empresa->licenca_origem === Empresa::LICENCA_TESTE ? Empresa::LICENCA_TRIAL : Empresa::LICENCA_ACTIVA;
        }

        $diasTolerancia = (int) config('erp.licencas.dias_tolerancia', 5);
        $diasDesdeExpirou = abs((int) now()->startOfDay()->diffInDays($empresa->licenca_fim->copy()->startOfDay()));

        return $diasDesdeExpirou <= $diasTolerancia ? Empresa::LICENCA_TOLERANCIA : Empresa::LICENCA_SUSPENSA;
    }

    /** Sincroniza o estado guardado com o calculado, só se forem diferentes (idempotente, sem efeito se já correcto). */
    public function sincronizar(Empresa $empresa): Empresa
    {
        $esperado = $this->calcular($empresa);
        if ($esperado === $empresa->estado_licenca) {
            return $empresa;
        }

        return $this->transicionar($empresa, $esperado,
            $esperado === Empresa::LICENCA_SUSPENSA ? Empresa::MOTIVO_INCUMPRIMENTO : null,
            'Sincronização automática a partir da data de validade da licença.');
    }
}
