<?php

namespace App\Services\Sistema;

use App\Mail\NotificacaoRHMail;
use App\Models\NotificacaoSistema;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Notificações de RH, Método de Trabalho e Estrutura Orgânica (Fase K, ADR-079) — um só ponto.
 *   - NUNCA falha a operação de negócio: tudo corre isolado em try/catch + report(), depois do commit (DB::afterCommit) e
 *     dentro de um ponto de salvaguarda (uma falha de SQL não envenena a transacção do chamador);
 *   - o destinatário é sempre um utilizador: o ligado ao colaborador (utilizador_empresa.colaborador_id) ou os que têm uma
 *     permissão na empresa. Uma notificação dirigida chega SEMPRE ao destinatário (ServicoNotificacoes::resumo), mesmo sem
 *     permissão para ver o módulo — é quem tem de agir;
 *   - categorias: `rh` (portal, férias, delegações), `metodo` (assuntos 5W1H), `estrutura` (entregáveis, multas); o `link` segue
 *     o formato das notificações existentes (/m/<módulo>/<ecrã>);
 *   - e-mail: ponte opcional, desligada por omissão (erp.notificacoes.email / ERP_NOTIFICACOES_EMAIL), em fila (Mailable);
 *   - `unica`: não repete a mesma notificação (mesmo destinatário, título e mensagem): `true` = nunca; um número = dentro dos últimos N
 *     dias — usado pelo comando diário de alertas.
 * Preferências por utilizador: fora do âmbito desta fase (todos os destinatários recebem tudo o que lhes diz respeito).
 * Resolve o ServicoNotificacoes sob pedido (a dependência directa criaria um ciclo com os serviços do Início).
 */
final class NotificadorRH
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** Utilizador activo ligado ao colaborador na empresa activa. */
    public function utilizadorDe(int $colaboradorId): ?int
    {
        $id = DB::table('utilizador_empresa as ue')->join('utilizadores as u', 'u.id', '=', 'ue.utilizador_id')->where('ue.empresa_id', $this->contexto->obrigatorio())
            ->where('ue.colaborador_id', $colaboradorId)->where('u.ativo', true)->whereNull('u.eliminado_em')->value('u.id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Utilizadores activos da empresa que têm a permissão.
     *
     * @return list<int>
     */
    public function utilizadoresComPermissao(string $permissao): array
    {
        $empresa = $this->contexto->obrigatorio();
        $ids = DB::table('utilizador_empresa as ue')->join('utilizadores as u', 'u.id', '=', 'ue.utilizador_id')->where('ue.empresa_id', $empresa)->where('u.ativo', true)
            ->whereNull('u.eliminado_em')->pluck('u.id')->all();
        $permissoes = app(ServicoPermissoes::class);

        return Utilizador::query()->with('perfil')->whereIn('id', $ids ?: [0])->get()->filter(fn (Utilizador $u) => $permissoes->autoriza($u, $permissao, $empresa))
            ->map(fn (Utilizador $u) => (int) $u->getKey())->values()->all();
    }

    public function paraColaborador(int $colaboradorId, string $titulo, string $mensagem, string $link, string $categoria = 'rh', string $tipo = 'INFO', bool|int $unica = false): void
    {
        $this->paraColaboradores([$colaboradorId], $titulo, $mensagem, $link, $categoria, $tipo, $unica);
    }

    /** @param  list<int>  $colaboradores */
    public function paraColaboradores(array $colaboradores, string $titulo, string $mensagem, string $link, string $categoria = 'rh', string $tipo = 'INFO', bool|int $unica = false): void
    {
        $this->despachar(function () use ($colaboradores) {
            return array_values(array_unique(array_filter(array_map(fn ($c) => $this->utilizadorDe((int) $c), array_unique(array_map('intval', array_filter($colaboradores)))))));
        }, $titulo, $mensagem, $link, $categoria, $tipo, $unica);
    }

    /**
     * Para utilizadores identificados pelo nome de utilizador (ex.: o criador de um assunto, guardado por nome) e/ou por
     * colaborador (ex.: o responsável principal): junta, sem repetir, e nunca avisa quem executou a acção (`$excluirNomes`).
     *
     * @param  list<string>  $nomes
     * @param  list<int>  $colaboradores
     * @param  list<string>  $excluirNomes
     */
    public function paraNomesEColaboradores(array $nomes, array $colaboradores, string $titulo, string $mensagem, string $link, string $categoria = 'rh', string $tipo = 'INFO', array $excluirNomes = []): void
    {
        $this->despachar(function () use ($nomes, $colaboradores, $excluirNomes) {
            $minusculas = fn (array $l) => array_values(array_filter(array_map(fn ($n) => mb_strtolower(trim((string) $n)), $l)));
            $porNome = $minusculas($nomes) ? Utilizador::query()->where('ativo', true)->whereIn(DB::raw('lower(nome_utilizador)'), $minusculas($nomes))->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
            $porColaborador = array_filter(array_map(fn ($c) => $this->utilizadorDe((int) $c), array_unique(array_map('intval', array_filter($colaboradores)))));
            $excluir = $minusculas($excluirNomes) ? Utilizador::query()->whereIn(DB::raw('lower(nome_utilizador)'), $minusculas($excluirNomes))->pluck('id')->map(fn ($i) => (int) $i)->all() : [];

            return array_values(array_diff(array_unique(array_merge($porNome, $porColaborador)), $excluir));
        }, $titulo, $mensagem, $link, $categoria, $tipo, false);
    }

    /** @param  list<int>  $excluirColaboradores  quem não deve receber (ex.: o requerente que também tem a permissão) */
    public function paraPermissao(string $permissao, string $titulo, string $mensagem, string $link, string $categoria = 'rh', string $tipo = 'INFO', array $excluirColaboradores = [], bool|int $unica = false): void
    {
        $this->despachar(function () use ($permissao, $excluirColaboradores) {
            $excluir = array_filter(array_map(fn ($c) => $this->utilizadorDe((int) $c), $excluirColaboradores));

            return array_values(array_diff($this->utilizadoresComPermissao($permissao), $excluir));
        }, $titulo, $mensagem, $link, $categoria, $tipo, $unica);
    }

    /**
     * @param  callable(): list<int>  $destinatarios  resolve os utilizadores (corre depois do commit, isolado)
     */
    private function despachar(callable $destinatarios, string $titulo, string $mensagem, string $link, string $categoria, string $tipo, bool|int $unica): void
    {
        $empresa = $this->contexto->id();
        $autor = Auth::user()?->nome_utilizador ?: 'Sistema';
        if ($empresa === null) {
            return;
        }
        $enviar = function () use ($empresa, $destinatarios, $titulo, $mensagem, $link, $categoria, $tipo, $unica, $autor) {
            try {
                $this->contexto->executarComo($empresa, function () use ($destinatarios, $titulo, $mensagem, $link, $categoria, $tipo, $unica, $autor, $empresa) {
                    $servico = app(ServicoNotificacoes::class);
                    foreach ($destinatarios() as $utilizador) {
                        // um ponto de salvaguarda por destinatário: um erro de SQL desfaz só esta notificação
                        DB::transaction(function () use ($servico, $utilizador, $titulo, $mensagem, $link, $categoria, $tipo, $unica, $autor, $empresa) {
                            if ($unica !== false && NotificacaoSistema::query()->where('utilizador_id', $utilizador)->where('categoria', strtolower($categoria))->where('titulo', $titulo)
                                ->where('mensagem', $mensagem)->when(is_int($unica), fn ($q) => $q->where('criado_em', '>=', now()->subDays($unica)))->exists()) {
                                return;   // já avisado (para sempre, ou dentro dos últimos N dias)
                            }
                            $servico->criar($empresa, $tipo, $categoria, mb_substr($titulo, 0, 200), $mensagem, mb_substr($link, 0, 255), $utilizador, $autor);
                        });
                        $this->email($utilizador, $titulo, $mensagem, $link);
                    }
                });
            } catch (Throwable $erro) {
                report($erro);   // notificar nunca faz falhar a operação de negócio
            }
        };
        try {
            DB::afterCommit($enviar);
        } catch (Throwable $erro) {
            report($erro);
        }
    }

    private function email(int $utilizador, string $titulo, string $mensagem, string $link): void
    {
        if (! config('erp.notificacoes.email')) {
            return;
        }
        try {
            $endereco = Utilizador::query()->whereKey($utilizador)->value('email');
            if ($endereco) {
                Mail::to($endereco)->queue(new NotificacaoRHMail($titulo, $mensagem, rtrim((string) config('app.url'), '/').$link));
            }
        } catch (Throwable $erro) {
            report($erro);
        }
    }
}
