<?php

namespace App\Services\Autenticacao;

use App\Mail\AvisoSessaoMail;
use App\Models\TokenAcesso;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Autenticacao\AgenteUtilizador;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ciclo de vida das sessões por dispositivo (ADR-082, docs/arquitetura/SESSOES_E_COOKIES.md): rotação do token com
 * tolerância, detecção de reutilização, vinculação ao navegador, limite por utilizador, dispositivos conhecidos,
 * avisos ao utilizador e a listagem/terminação das sessões do próprio.
 */
final class ServicoSessoes
{
    public function __construct(private readonly ServicoAuditoria $auditoria) {}

    /** Sessão completa = token com habilidade '*' (o token limitado do MFA tem outra). */
    private function completas(Utilizador $u)
    {
        return $u->tokens()->whereRaw("permissoes::jsonb @> '[\"*\"]'::jsonb");
    }

    // ---- criação --------------------------------------------------------------------------------------------------

    /** Um token completo por dispositivo: remove o anterior do mesmo dispositivo (nunca o de outros). */
    public function substituirDoDispositivo(Utilizador $u, string $dispositivoId): void
    {
        $this->completas($u)->where('dispositivo_id', $dispositivoId)->delete();
    }

    /** Termina as sessões mais antigas acima do limite configurado (nunca a que acabou de ser criada). */
    public function aplicarLimite(Utilizador $u, int $manterId): void
    {
        $max = (int) config('erp.sessao.max_sessoes_por_utilizador');
        if ($max <= 0) {
            return;
        }
        $ids = $this->completas($u)->whereKeyNot($manterId)->orderByDesc(DB::raw('COALESCE(ultimo_uso_em, criado_em)'))->pluck('id')->all();
        $excesso = array_slice($ids, max(0, $max - 1));
        if ($excesso !== []) {
            $u->tokens()->whereKey($excesso)->delete();
            $this->auditoria->registar('Autenticação', 'Limite de sessões', count($excesso).' sessão(ões) mais antiga(s) terminada(s) por exceder o limite de '.$max.'.',
                'utilizadores', $u->getKey(), utilizadorId: $u->getKey(), nomeUtilizador: $u->nome_utilizador);
        }
    }

    /**
     * Regista o dispositivo como conhecido; true se é a primeira vez que este utilizador o usa E já tinha outros
     * (o primeiro dispositivo de uma conta não gera aviso — seria ruído em cada conta nova).
     */
    public function registarDispositivo(Utilizador $u, string $dispositivoId): bool
    {
        $agora = now();
        $existe = DB::table('dispositivos_conhecidos')->where('utilizador_id', $u->getKey())->where('dispositivo_id', $dispositivoId)->update(['ultimo_em' => $agora]);
        if ($existe > 0) {
            return false;
        }
        $tinhaOutros = DB::table('dispositivos_conhecidos')->where('utilizador_id', $u->getKey())->exists();
        DB::table('dispositivos_conhecidos')->insertOrIgnore([
            'utilizador_id' => $u->getKey(), 'dispositivo_id' => $dispositivoId, 'primeiro_em' => $agora, 'ultimo_em' => $agora,
        ]);

        return $tinhaOutros;
    }

    // ---- rotação --------------------------------------------------------------------------------------------------

    /** O token completo já passou o intervalo de rotação? (a tolerância dos pedidos paralelos trata-se em findToken) */
    public function deveRotacionar(TokenAcesso $t): bool
    {
        if ($t->viaTokenAnterior || ! $t->can('*')) {
            return false;
        }
        $minutos = (int) config('erp.sessao.rotacao_minutos');
        if ($minutos <= 0) {
            return false;
        }
        $referencia = $t->rotacao_em ?? $t->criado_em;

        return $referencia !== null && $referencia->lte(now()->subMinutes($minutos));
    }

    /**
     * Troca o segredo da sessão por um novo, guardando o hash do anterior (só para detectar reutilização). A troca é
     * atómica (compare-and-swap sobre o hash actual): de dois pedidos em paralelo só um roda. Devolve o novo token
     * completo `id|segredo`, ou null se outro pedido rodou primeiro (ou a sessão já não existe).
     */
    public function rotacionar(TokenAcesso $t): ?string
    {
        $segredo = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $agora = now();
        $anteriores = $t->tokens_anteriores ?? [];
        $anteriores[] = ['h' => (string) $t->token, 'r' => $agora->getTimestamp()];
        $anteriores = array_slice($anteriores, -20);

        $n = DB::table('tokens_acesso')->where('id', $t->getKey())->where('token', $t->token)->update([
            'token' => hash('sha256', $segredo),
            'tokens_anteriores' => json_encode($anteriores),
            'rotacao_em' => $agora,
            'atualizado_em' => $agora,
        ]);

        return $n === 1 ? $t->getKey().'|'.$segredo : null;
    }

    // ---- reutilização e vinculação -------------------------------------------------------------------------------

    /** Um token já rodado foi apresentado fora da tolerância: termina a sessão do dispositivo e avisa o utilizador. */
    public function reutilizacaoDetectada(TokenAcesso $t): void
    {
        $this->revogarPorSeguranca($t, 'Reutilização de token detectada',
            'Um token já substituído foi apresentado fora da janela de tolerância: sessão do dispositivo terminada.', true);
    }

    /**
     * Vinculação ao dispositivo (só para sessões completas): mudança de navegador/sistema termina a sessão; mudança de IP
     * só fica em auditoria (redes móveis mudam de IP). Devolve false se a sessão deixou de ser válida.
     */
    public function validarVinculo(TokenAcesso $t, Request $pedido): bool
    {
        if (! $t->can('*')) {
            return true;
        }

        if (config('erp.sessao.vincular_agente') && $t->agente_assinatura !== null
            && ! hash_equals((string) $t->agente_assinatura, AgenteUtilizador::assinatura($pedido->userAgent()))) {
            $this->revogarPorSeguranca($t, 'Sessão terminada: navegador diferente',
                'O token foi apresentado por um navegador/sistema diferente do que iniciou a sessão ('.($t->rotulo ?? 'desconhecido').'): sessão terminada.', true);

            return false;
        }

        $ip = $pedido->ip();
        if ($ip !== null && $t->endereco_ip !== null && $t->endereco_ip !== $ip) {
            DB::table('tokens_acesso')->where('id', $t->getKey())->update(['endereco_ip' => $ip]);
            $this->auditoria->registar('Autenticação', 'Mudança de IP da sessão', 'A sessão mudou de '.AgenteUtilizador::ipAproximado($t->endereco_ip).' para '.AgenteUtilizador::ipAproximado($ip).'.',
                'tokens_acesso', $t->getKey(), utilizadorId: (int) $t->portador_id);
            $t->endereco_ip = $ip;
        }

        return true;
    }

    private function revogarPorSeguranca(TokenAcesso $t, string $acao, string $detalhe, bool $avisar): void
    {
        try {
            $utilizador = $t->tokenable;
            $dispositivo = $t->dispositivo_id;
            $rotulo = $t->rotulo ?? 'dispositivo desconhecido';
            $ip = $t->endereco_ip;
            // a «família» é o dispositivo: o token de sessão e qualquer token limitado desse dispositivo
            $q = TokenAcesso::query()->where('portador_tipo', $t->portador_tipo)->where('portador_id', $t->portador_id);
            $dispositivo !== null ? $q->where('dispositivo_id', $dispositivo)->delete() : $t->delete();

            $this->auditoria->registar('Autenticação', $acao, $detalhe, 'tokens_acesso', $t->getKey(),
                utilizadorId: (int) $t->portador_id, nomeUtilizador: $utilizador?->nome_utilizador);
            Log::warning('Segurança de sessão: '.$acao, ['utilizador_id' => $t->portador_id, 'sessao_id' => $t->getKey()]);

            if ($avisar && $utilizador instanceof Utilizador && $utilizador->email) {
                Mail::to($utilizador->email)->send(new AvisoSessaoMail(AvisoSessaoMail::TOKEN_REUTILIZADO,
                    $utilizador->nome_completo ?: $utilizador->nome_utilizador, $rotulo, AgenteUtilizador::ipAproximado($ip), now()->format('d/m/Y H:i')));
            }
        } catch (Throwable $e) {
            Log::error('Falha ao tratar a revogação de uma sessão por segurança.', ['erro' => $e->getMessage()]);
        }
    }

    public function avisarNovoDispositivo(Utilizador $u, ?string $agente, ?string $ip): void
    {
        $this->auditoria->registar('Autenticação', 'Novo dispositivo', 'Primeiro acesso a partir de '.AgenteUtilizador::rotulo($agente).'.',
            'utilizadores', $u->getKey(), utilizadorId: $u->getKey(), nomeUtilizador: $u->nome_utilizador);
        if (! $u->email) {
            return;
        }
        try {
            Mail::to($u->email)->send(new AvisoSessaoMail(AvisoSessaoMail::NOVO_DISPOSITIVO,
                $u->nome_completo ?: $u->nome_utilizador, AgenteUtilizador::rotulo($agente), AgenteUtilizador::ipAproximado($ip), now()->format('d/m/Y H:i')));
        } catch (Throwable $e) {
            Log::warning('Falha ao enviar o aviso de novo dispositivo.', ['erro' => $e->getMessage()]);
        }
    }

    // ---- «As minhas sessões» ----------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public function listar(Utilizador $u, ?int $actualId): array
    {
        $idle = now()->subMinutes((int) config('erp.sessao.inatividade_minutos'));

        return $this->completas($u)
            ->where(fn ($q) => $q->whereNull('expira_em')->orWhere('expira_em', '>', now()))
            ->where(fn ($q) => $q->where('ultimo_uso_em', '>', $idle)->orWhere(fn ($q2) => $q2->whereNull('ultimo_uso_em')->where('criado_em', '>', $idle)))
            ->orderByDesc(DB::raw('COALESCE(ultimo_uso_em, criado_em)'))
            ->get()
            ->map(fn (TokenAcesso $t) => [
                'id' => $t->getKey(),
                'dispositivo' => $t->rotulo ?? 'Dispositivo desconhecido',
                'ip_aproximado' => AgenteUtilizador::ipAproximado($t->endereco_ip),
                'criada_em' => $t->criado_em?->toAtomString(),
                'ultima_actividade_em' => ($t->ultimo_uso_em ?? $t->criado_em)?->toAtomString(),
                'expira_em' => $t->expira_em?->toAtomString(),
                'esta_sessao' => $actualId !== null && $t->getKey() === $actualId,
            ])->values()->all();
    }

    /** Só tenta nas sessões do próprio utilizador (a relação filtra por portador): um id alheio devolve false. */
    public function terminar(Utilizador $u, int $id): bool
    {
        $n = $this->completas($u)->whereKey($id)->delete();
        if ($n > 0) {
            $this->auditoria->registar('Autenticação', 'Terminar sessão', 'O utilizador terminou uma das suas sessões.', 'utilizadores', $u->getKey(),
                utilizadorId: $u->getKey(), nomeUtilizador: $u->nome_utilizador);
        }

        return $n > 0;
    }

    public function terminarOutras(Utilizador $u, int $actualId): int
    {
        $n = $u->tokens()->whereKeyNot($actualId)->delete();
        $this->auditoria->registar('Autenticação', 'Terminar outras sessões', "O utilizador terminou as suas outras sessões ({$n}).", 'utilizadores', $u->getKey(),
            utilizadorId: $u->getKey(), nomeUtilizador: $u->nome_utilizador);

        return $n;
    }

    // ---- controlo por empresa (administrador) ----------------------------------------------------------------------

    /**
     * Sessões activas de UM utilizador (para o administrador que o gere). Mesmo formato de `listar`, com o nome do titular.
     * A visibilidade do alvo é verificada por quem chama (ServicoUtilizadores::exigirGerivel).
     *
     * @return list<array<string, mixed>>
     */
    public function listarDoUtilizador(Utilizador $alvo): array
    {
        return array_map(fn (array $l) => $l + ['utilizador_id' => $alvo->getKey(), 'nome_utilizador' => $alvo->nome_utilizador], $this->listar($alvo, null));
    }

    /**
     * Sessões activas dos utilizadores da empresa (os ligados a ela por `utilizador_empresa` e os de acesso total) do
     * tenant indicado — o controlo de sessões da empresa. O filtro por tenant é explícito (não depende só da RLS).
     *
     * @return list<array<string, mixed>>
     */
    public function daEmpresa(int $empresaId, int $tenantId): array
    {
        $idle = now()->subMinutes((int) config('erp.sessao.inatividade_minutos'));
        $limiteAbsoluto = now()->subHours((int) config('erp.sessao.validade_horas'));

        return DB::table('tokens_acesso as t')
            ->join('utilizadores as u', fn ($j) => $j->on('u.id', '=', 't.portador_id')->where('t.portador_tipo', '=', (new Utilizador)->getMorphClass()))
            ->where('u.tenant_id', $tenantId)
            ->whereNull('u.eliminado_em')
            ->where(fn ($q) => $q->where('u.acesso_todas_empresas', true)->orWhereExists(fn ($e) => $e->selectRaw('1')->from('utilizador_empresa as ue')
                ->whereColumn('ue.utilizador_id', 'u.id')->where('ue.empresa_id', $empresaId)))
            ->whereRaw("t.permissoes::jsonb @> '[\"*\"]'::jsonb")
            ->where('t.criado_em', '>', $limiteAbsoluto)
            ->where(fn ($q) => $q->where('t.ultimo_uso_em', '>', $idle)->orWhere(fn ($q2) => $q2->whereNull('t.ultimo_uso_em')->where('t.criado_em', '>', $idle)))
            ->orderByDesc(DB::raw('COALESCE(t.ultimo_uso_em, t.criado_em)'))
            ->limit(500)
            ->get(['t.id', 't.rotulo', 't.endereco_ip', 't.criado_em', 't.ultimo_uso_em', 'u.id as utilizador_id', 'u.nome_utilizador'])
            ->map(fn ($t) => [
                'id' => (int) $t->id, 'utilizador_id' => (int) $t->utilizador_id, 'nome_utilizador' => $t->nome_utilizador,
                'dispositivo' => $t->rotulo ?? 'Dispositivo desconhecido', 'ip_aproximado' => AgenteUtilizador::ipAproximado($t->endereco_ip),
                'criada_em' => $t->criado_em, 'ultima_actividade_em' => $t->ultimo_uso_em ?? $t->criado_em,
            ])->all();
    }

    /**
     * Termina todas as sessões de um utilizador a pedido de um administrador (conta comprometida/perdida). Auditado.
     * A visibilidade/gestão do alvo é verificada por quem chama.
     */
    public function terminarTodasDe(Utilizador $alvo, Utilizador $actor, string $motivo = ''): int
    {
        $n = (int) $alvo->tokens()->delete();
        $this->auditoria->registar('Autenticação', 'Sessões terminadas por administrador',
            "O administrador '{$actor->nome_utilizador}' terminou {$n} sessão(ões) de '{$alvo->nome_utilizador}'".($motivo !== '' ? " ({$motivo})." : '.'),
            'utilizadores', $alvo->getKey(), utilizadorId: $actor->getKey(), nomeUtilizador: $actor->nome_utilizador);

        return $n;
    }

    /** Termina UMA sessão (por id) de um utilizador que o administrador gere; false se não for desse utilizador. */
    public function terminarDe(Utilizador $alvo, Utilizador $actor, int $sessaoId): bool
    {
        $n = $alvo->tokens()->whereKey($sessaoId)->delete();
        if ($n > 0) {
            $this->auditoria->registar('Autenticação', 'Sessão terminada por administrador',
                "O administrador '{$actor->nome_utilizador}' terminou uma sessão de '{$alvo->nome_utilizador}'.",
                'utilizadores', $alvo->getKey(), utilizadorId: $actor->getKey(), nomeUtilizador: $actor->nome_utilizador);
        }

        return $n > 0;
    }

    public function expiraEm(): Carbon
    {
        return now()->addHours((int) config('erp.sessao.validade_horas'));
    }
}
