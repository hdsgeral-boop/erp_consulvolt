<?php

namespace App\Services\Vendas\Agt;

use App\Exceptions\ErroNegocio;
use App\Jobs\Vendas\ProcessarWebhookAgt;
use App\Models\ComunicacaoAgt;
use App\Models\EventoWebhookAgt;
use App\Models\LigacaoAgtEmpresa;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recepção de notificações da AGT («callback»), por empresa.
 *
 * Estado da norma (doc-agt, introdução): «Callback (se ativado) — A API faz POST para o endpoint do contribuinte quando o
 * documento for validado. (Disponível nas próximas versões)». O formato e a autenticação ainda não foram publicados; por
 * isso este endpoint segue as boas práticas de webhooks e trata a notificação APENAS como um aviso: o estado oficial é
 * sempre confirmado por consulta à AGT (obterEstado/consultarFactura), nunca lido do corpo recebido.
 *
 *   - endereço por empresa com identificador aleatório (256 bits; só o SHA-256 fica guardado);
 *   - assinatura HMAC-SHA256 com o segredo da empresa sobre «carimbo.nonce.corpo» (X-AGT-Assinatura: sha256=<hex>),
 *     comparada em tempo constante;
 *   - anti-replay: carimbo dentro da janela (±AGT_WEBHOOK_JANELA s) e nonce único por empresa (índice único);
 *   - idempotente: um nonce repetido devolve 200 «duplicado» sem voltar a processar;
 *   - responde de imediato (202) e processa na fila «agt»; só toca em documentos da empresa dona do endereço.
 */
final class ServicoWebhookAgt
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * Ligação dona do endereço do webhook. Sem utilizador nem tenant (pedido da AGT): com SaaS activo a empresa e o tenant
     * resolvem-se pela função SQL pp_empresa_por_webhook_token (SECURITY DEFINER, só devolve o id) e a leitura corre nesse contexto.
     */
    private function localizarLigacao(string $token): ?LigacaoAgtEmpresa
    {
        $hash = hash('sha256', $token);
        $consulta = fn () => LigacaoAgtEmpresa::query()->where('webhook_token_hash', $hash)->where('webhook_ativo', true)->first();

        if (! ContextoTenant::ativo()) {
            return $this->contexto->semIsolamento($consulta);
        }

        $empresa = DB::selectOne('SELECT app_empresa_por_webhook_token(?) AS empresa', [$hash])?->empresa;

        return $empresa === null ? null : $this->contexto->executarComo((int) $empresa, fn () => $this->contexto->semIsolamento($consulta));
    }

    /** @return array{estado: int, corpo: array} */
    public function receber(string $token, string $corpo, ?string $carimbo, ?string $nonce, ?string $assinatura, ?string $ip): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{20,100}$/', $token)) {
            return $this->negado(404);
        }
        $ligacao = $this->localizarLigacao($token);
        if (! $ligacao || ! $ligacao->webhook_segredo) {
            return $this->negado(404);   // sem revelar se o endereço existe
        }
        $empresa = (int) $ligacao->empresa_id;
        if (strlen($corpo) > (int) config('erp.agt.webhook_max_bytes', 65536)) {
            return $this->falha($empresa, 413, 'CORPO_GRANDE', 'Corpo demasiado grande.');
        }
        if ($carimbo === null || ! ctype_digit($carimbo) || $nonce === null || ! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $nonce) || ! $assinatura) {
            return $this->falha($empresa, 401, 'CABECALHOS_EM_FALTA', 'Faltam o carimbo, o nonce ou a assinatura.');
        }
        $janela = (int) config('erp.agt.webhook_janela_segundos', 300);
        if (abs(now()->timestamp - (int) $carimbo) > $janela) {
            return $this->falha($empresa, 401, 'CARIMBO_FORA_DA_JANELA', 'Carimbo temporal fora da janela permitida.');
        }
        $esperada = 'sha256='.hash_hmac('sha256', "{$carimbo}.{$nonce}.{$corpo}", (string) $ligacao->webhook_segredo);
        if (! hash_equals($esperada, trim($assinatura))) {
            return $this->falha($empresa, 401, 'ASSINATURA_INVALIDA', 'Assinatura inválida.');
        }
        $json = json_decode($corpo, true);
        if (! is_array($json)) {
            return $this->falha($empresa, 400, 'JSON_INVALIDO', 'O corpo tem de ser JSON.');
        }

        try {
            // savepoint: um nonce repetido não deixa a transacção (se houver) abortada
            $evento = $this->contexto->executarComo($empresa, fn () => DB::transaction(fn () => EventoWebhookAgt::create([
                'nonce' => $nonce, 'carimbo' => Carbon::createFromTimestamp((int) $carimbo), 'recebido_em' => now(), 'ip' => $ip ? mb_substr($ip, 0, 45) : null,
                'corpo' => MascaraAgt::limitar(MascaraAgt::aplicar($json)), 'estado' => 'RECEBIDO',
            ])));
        } catch (UniqueConstraintViolationException) {
            return ['estado' => 200, 'corpo' => ['sucesso' => true, 'duplicado' => true]];
        }
        $this->registar($empresa, 202, null, 'Notificação aceite.', true);
        try {
            ProcessarWebhookAgt::dispatch($empresa, $evento->id);
        } catch (Throwable $e) {
            report($e);   // o ciclo periódico (erp:agt:ciclo) consulta os estados de qualquer forma
        }

        return ['estado' => 202, 'corpo' => ['sucesso' => true, 'recebido' => true]];
    }

    /**
     * Processamento (na fila, como a empresa dona do endereço): extrai requestID/documentNo do corpo e pede à AGT o estado
     * dos documentos DESTA empresa que estão a aguardar validação.
     *
     * @return list<int> ids das vendas consultadas
     */
    public static function documentosReferidos(array $corpo): array
    {
        $pedidos = $numeros = [];
        $percorrer = function ($no) use (&$percorrer, &$pedidos, &$numeros) {
            if (! is_array($no)) {
                return;
            }
            foreach ($no as $k => $v) {
                if (in_array($k, ['requestID', 'successRequestID'], true) && is_scalar($v) && $v !== '') {
                    $pedidos[] = mb_substr((string) $v, 0, 40);
                } elseif (in_array($k, ['documentNo', 'invoiceNo'], true) && is_scalar($v) && $v !== '') {
                    $numeros[] = mb_substr((string) $v, 0, 60);
                } elseif (is_array($v)) {
                    $percorrer($v);
                }
            }
        };
        $percorrer($corpo);
        if (! $pedidos && ! $numeros) {
            return [];
        }

        // escopo da empresa activa (EscopoEmpresa): documentos de outra empresa nunca são encontrados
        return Venda::query()->where('fe_regime', true)->whereRaw("fe_envio->>'estado' = 'ENVIADO'")
            ->where(fn ($q) => $q->when($numeros, fn ($w) => $w->whereIn('numero_documento', array_unique($numeros)))
                ->when($pedidos, fn ($w) => $w->orWhereIn(DB::raw("fe_envio->>'requestID'"), array_unique($pedidos))))
            ->limit(500)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function negado(int $estado): array
    {
        return ['estado' => $estado, 'corpo' => ['sucesso' => false, 'mensagem' => 'Não encontrado.']];
    }

    private function falha(int $empresa, int $estado, string $codigo, string $mensagem): array
    {
        $this->registar($empresa, $estado, $codigo, $mensagem, false);

        return ['estado' => $estado, 'corpo' => ['sucesso' => false, 'codigo' => $codigo, 'mensagem' => $mensagem]];
    }

    private function registar(int $empresa, int $http, ?string $codigo, string $mensagem, bool $sucesso): void
    {
        try {
            $this->contexto->executarComo($empresa, fn () => ComunicacaoAgt::create([
                'operacao' => 'webhook', 'endpoint' => '/api/agt/webhook/…', 'http_estado' => $http, 'sucesso' => $sucesso,
                'codigo_erro' => $codigo, 'mensagem' => $mensagem, 'duracao_ms' => 0, 'tentativa' => 1,
            ]));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Usado pelo job. */
    public static function exigirEmpresa(EventoWebhookAgt $evento, int $empresa): void
    {
        if ((int) $evento->empresa_id !== $empresa) {
            throw new ErroNegocio('Notificação de outra empresa.', 'EMPRESA_DIFERENTE', 403);
        }
    }
}
