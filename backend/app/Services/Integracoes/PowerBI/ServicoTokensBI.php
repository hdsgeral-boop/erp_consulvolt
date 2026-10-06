<?php

namespace App\Services\Integracoes\PowerBI;

use App\Exceptions\ErroNegocio;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tokens de leitura do feed OData do Power BI (decisão 25). Um token pertence a UMA empresa e só lê os dados dela, nos
 * conjuntos da lista branca do cubo (todos ou os escolhidos). O valor (`erpbi_` + 48 caracteres aleatórios) é mostrado uma
 * única vez, ao criar; na base fica só o SHA-256. Revogar é imediato. Gerir os tokens exige `config_backup` (controlador).
 */
final class ServicoTokensBI
{
    public const PREFIXO = 'erpbi_';

    public function __construct(private readonly ServicoAuditoria $auditoria) {}

    /** @return list<array<string, mixed>> tokens da empresa (sem o valor) */
    public function listar(int $empresa): array
    {
        return DB::table('tokens_bi')->where('empresa_id', $empresa)->orderByDesc('id')->get()->map(fn ($t) => $this->apresentar($t))->all();
    }

    /**
     * @param  list<string>|null  $conjuntos
     * @return array{token: string, registo: array<string, mixed>}
     */
    public function criar(int $empresa, string $nome, ?array $conjuntos, ?string $expiraEm): array
    {
        $validos = array_keys(ServicoFeedBI::conjuntos());
        $conjuntos = $conjuntos ? array_values(array_unique($conjuntos)) : null;
        if ($conjuntos && ($fora = array_diff($conjuntos, $validos))) {
            throw new ErroNegocio('Conjunto de dados desconhecido: '.implode(', ', $fora).'.', 'BI_CONJUNTO_INVALIDO', 422);
        }
        if ($expiraEm && strtotime($expiraEm) <= time()) {
            throw new ErroNegocio('A data de expiração tem de ser futura.', 'BI_EXPIRACAO_INVALIDA', 422);
        }
        $token = self::PREFIXO.Str::random(48);
        $u = Auth::user();
        $id = DB::table('tokens_bi')->insertGetId([
            'empresa_id' => $empresa, 'nome' => mb_substr(trim($nome), 0, 255), 'prefixo' => substr($token, 0, 12), 'hash_token' => hash('sha256', $token),
            'conjuntos' => $conjuntos ? json_encode($conjuntos) : null, 'criado_por_id' => $u?->getKey(), 'criado_por' => $u?->nome_utilizador,
            'expira_em' => $expiraEm, 'utilizacoes' => 0, 'criado_em' => now(), 'atualizado_em' => now(),
        ]);
        $this->auditoria->registar('Sistema/Power BI', 'Criar token BI', "Token de leitura BI «{$nome}» criado (".($conjuntos ? implode(', ', $conjuntos) : 'todos os conjuntos').').',
            'tokens_bi', $id, null, null, $empresa);

        return ['token' => $token, 'registo' => $this->apresentar(DB::table('tokens_bi')->find($id))];
    }

    public function revogar(int $empresa, int $id): array
    {
        $t = DB::table('tokens_bi')->where('empresa_id', $empresa)->where('id', $id)->first();
        if (! $t) {
            throw new ErroNegocio('Token BI não encontrado nesta empresa.', 'NAO_ENCONTRADO', 404);
        }
        if ($t->revogado_em) {
            throw new ErroNegocio('O token já está revogado.', 'BI_TOKEN_REVOGADO', 422);
        }
        DB::table('tokens_bi')->where('id', $id)->update(['revogado_em' => now(), 'revogado_por' => Auth::user()?->nome_utilizador, 'atualizado_em' => now()]);
        $this->auditoria->registar('Sistema/Power BI', 'Revogar token BI', "Token de leitura BI «{$t->nome}» ({$t->prefixo}…) revogado.", 'tokens_bi', $id, null, null, $empresa);

        return $this->apresentar(DB::table('tokens_bi')->find($id));
    }

    /**
     * Token válido (não revogado, não expirado) → registo; regista o uso (no máximo uma escrita por minuto).
     */
    public function autenticar(?string $token): ?object
    {
        if (! $token || ! str_starts_with($token, self::PREFIXO) || strlen($token) > 100) {
            return null;
        }
        $t = DB::table('tokens_bi')->where('hash_token', hash('sha256', $token))->first();
        if (! $t || $t->revogado_em || ($t->expira_em && strtotime((string) $t->expira_em) <= time())) {
            return null;
        }
        if (! $t->ultimo_uso_em || strtotime((string) $t->ultimo_uso_em) < time() - 60) {
            DB::table('tokens_bi')->where('id', $t->id)->update(['ultimo_uso_em' => now(), 'utilizacoes' => DB::raw('COALESCE(utilizacoes, 0) + 1')]);
        }

        return $t;
    }

    /** @return array<string, mixed> */
    private function apresentar(object $t): array
    {
        $estado = $t->revogado_em ? 'REVOGADO' : ($t->expira_em && strtotime((string) $t->expira_em) <= time() ? 'EXPIRADO' : 'ATIVO');

        return ['id' => (int) $t->id, 'nome' => $t->nome, 'prefixo' => $t->prefixo, 'conjuntos' => $t->conjuntos ? json_decode($t->conjuntos, true) : null,
            'estado' => $estado, 'criado_por' => $t->criado_por, 'criado_em' => $t->criado_em, 'expira_em' => $t->expira_em,
            'ultimo_uso_em' => $t->ultimo_uso_em, 'utilizacoes' => (int) ($t->utilizacoes ?? 0), 'revogado_em' => $t->revogado_em, 'revogado_por' => $t->revogado_por];
    }
}
