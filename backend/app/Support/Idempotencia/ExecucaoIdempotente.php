<?php

namespace App\Support\Idempotencia;

use App\Exceptions\ErroNegocio;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Execução idempotente de um trabalho repetível (ADR-080): garante que, por (empresa, chave), o trabalho produz o seu efeito
 * UMA só vez mesmo que a fila o entregue várias vezes (retentativas, worker que morre a meio, entrega duplicada).
 *
 *   - sem registo → reclama a chave (EM_CURSO + lease) e executa;
 *   - CONCLUIDO → devolve o resultado guardado SEM voltar a executar;
 *   - EM_CURSO com lease válido (outro worker a executar) → ExecucaoEmCursoException: o job faz `release()` e a fila repete mais tarde;
 *   - EM_CURSO com lease expirado (o worker anterior morreu) → retoma: executa de novo, com `tentativa` > 1 (o chamador usa-o para
 *     tratar «já feito» como sucesso, ver os jobs de contabilização);
 *   - `ErroNegocio` é um RESULTADO (como sempre foi nestes jobs): fica CONCLUIDO com {sucesso:false, mensagem, codigo};
 *   - qualquer outra excepção liberta a reserva (a próxima tentativa retoma de imediato) e propaga para a fila repetir.
 *
 * O registo é gravado fora de qualquer transacção do trabalho (cada passo faz auto-commit): o efeito e o resultado não são atómicos
 * entre si — um crash entre os dois deixa o registo EM_CURSO e a retoma é que resolve (por isso o trabalho tem de reconhecer «já feito»).
 * Requer a tabela `execucoes_idempotentes` (RLS por empresa). Limpeza: erp:idempotencia:limpar.
 */
final class ExecucaoIdempotente
{
    /**
     * @param  callable(int): array<string, mixed>  $fn  recebe o n.º da tentativa (1 = primeira) e devolve o resultado (serializável)
     * @return array<string, mixed>
     *
     * @throws ExecucaoEmCursoException
     */
    public function executar(int $empresaId, string $chave, callable $fn, int $leaseSegundos = 300): array
    {
        $agora = now();
        $instante = $agora->format('Y-m-d H:i:s.uP');
        $lease = $agora->copy()->addSeconds($leaseSegundos)->format('Y-m-d H:i:s.uP');
        $ligacao = DB::connection();

        $novo = $ligacao->select('INSERT INTO execucoes_idempotentes (empresa_id, chave, estado, tentativas, reservado_ate, criado_em) VALUES (?, ?, ?, 1, ?, ?) '
            .'ON CONFLICT (empresa_id, chave) DO NOTHING RETURNING tentativas', [$empresaId, $chave, 'EM_CURSO', $lease, $instante], false);
        $tentativa = $novo !== [] ? (int) $novo[0]->tentativas : null;

        if ($tentativa === null) {
            // já existe: retomar só se EM_CURSO com o lease expirado (UPDATE atómico — dois workers nunca retomam o mesmo)
            $retomado = $ligacao->select("UPDATE execucoes_idempotentes SET tentativas = tentativas + 1, reservado_ate = ? WHERE empresa_id = ? AND chave = ? AND estado = 'EM_CURSO' "
                .'AND (reservado_ate IS NULL OR reservado_ate < ?) RETURNING tentativas', [$lease, $empresaId, $chave, $instante], false);
            if ($retomado === []) {
                $linha = $ligacao->selectOne('SELECT estado, resultado FROM execucoes_idempotentes WHERE empresa_id = ? AND chave = ?', [$empresaId, $chave], false);
                if ($linha === null || $linha->estado !== 'CONCLUIDO') {
                    throw new ExecucaoEmCursoException("A execução «{$chave}» está a ser tratada por outro worker.");
                }

                return (array) json_decode((string) $linha->resultado, true);
            }
            $tentativa = (int) $retomado[0]->tentativas;
        }

        try {
            try {
                $resultado = $fn($tentativa);
            } catch (ErroNegocio $e) {
                $resultado = ['sucesso' => false, 'mensagem' => $e->getMessage(), 'codigo' => $e->codigo];
            }
        } catch (Throwable $erro) {
            $this->libertar($empresaId, $chave);   // a próxima tentativa retoma já, sem esperar pelo fim do lease
            throw $erro;
        }

        $ligacao->update("UPDATE execucoes_idempotentes SET estado = 'CONCLUIDO', resultado = ?::jsonb, reservado_ate = NULL, concluido_em = ? WHERE empresa_id = ? AND chave = ?",
            [json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), now()->format('Y-m-d H:i:s.uP'), $empresaId, $chave]);

        return $resultado;
    }

    private function libertar(int $empresaId, string $chave): void
    {
        try {
            DB::connection()->update("UPDATE execucoes_idempotentes SET reservado_ate = NULL WHERE empresa_id = ? AND chave = ? AND estado = 'EM_CURSO'", [$empresaId, $chave]);
        } catch (Throwable $e) {
            report($e);   // o lease acaba por expirar sozinho
        }
    }
}
