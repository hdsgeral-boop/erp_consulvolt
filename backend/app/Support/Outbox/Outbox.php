<?php

namespace App\Support\Outbox;

use App\Services\Sistema\PublicadorOutbox;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ponto de entrada do Transactional Outbox (ADR-080). `registar()` grava o evento NA TRANSACÇÃO do chamador (atómico com a escrita
 * de negócio: se esta fizer ROLLBACK, o evento desaparece) e, depois do COMMIT, tenta publicá-lo logo (baixa latência). Se isso
 * falhar, ou o processo morrer, o agendador (`erp:outbox:publicar`) apanha-o. Ver docs/arquitetura/OUTBOX.md.
 */
final class Outbox
{
    /**
     * @param  array<string, mixed>  $carga  só dados serializáveis (escalares, listas): NUNCA objectos Eloquent nem segredos
     * @param  string  $chave  idempotência de quem produz: a mesma (empresa, chave) nunca cria dois eventos
     * @return int|null id do evento, ou null se já existia um com esta chave
     */
    public static function registar(string $tipo, array $carga, string $chave, ?int $empresaId = null, ?string $agregadoTipo = null, ?int $agregadoId = null): ?int
    {
        $id = DB::transaction(function () use ($tipo, $carga, $chave, $empresaId, $agregadoTipo, $agregadoId) {
            $linha = DB::connection()->select(
                'INSERT INTO outbox_eventos (empresa_id, tipo, agregado_tipo, agregado_id, chave, carga, estado, tentativas, proxima_tentativa_em, criado_em) '
                .'VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, 0, ?, ?) ON CONFLICT ((coalesce(empresa_id, 0)), chave) DO NOTHING RETURNING id',
                [$empresaId, mb_substr($tipo, 0, 60), $agregadoTipo, $agregadoId, mb_substr($chave, 0, 150), json_encode($carga, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'PENDENTE', $agora = now()->format('Y-m-d H:i:s.uP'), $agora],
                false,
            );
            if ($linha === []) {
                return null;
            }
            $id = (int) $linha[0]->id;
            DB::afterCommit(function () use ($id) {
                try {
                    app(PublicadorOutbox::class)->publicarUm($id);
                } catch (Throwable $erro) {
                    report($erro);   // nunca falha o pedido: o agendador recolhe o evento
                }
            });

            return $id;
        });

        return $id;
    }

    /**
     * Atalho para o manipulador `email.enviar`: `$argumentos` são os argumentos NOMEADOS do construtor do Mailable (escalares).
     * Nunca usar para e-mails com segredos (palavras-passe, tokens): a carga fica em claro na base de dados.
     *
     * @param  class-string  $mailable  tem de constar em `erp.outbox.emails_permitidos`
     * @param  array<string, scalar|null>  $argumentos
     */
    public static function email(string $mailable, string $para, array $argumentos, string $chave): ?int
    {
        return self::registar('email.enviar', ['mailable' => $mailable, 'para' => $para, 'argumentos' => $argumentos], $chave);
    }
}
