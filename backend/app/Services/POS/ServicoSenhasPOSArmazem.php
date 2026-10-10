<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\SenhaPOSArmazem;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Monitor de Chamada de Senhas do POS de armazém (js/ui_pos_armazem.js:1126-1254, RELATORIO 2.6.1):
 *   - «Tirar Nova Senha» (posGenerateTicket) → P-001, P-002… por armazém e por dia (no legado era um contador em memória
 *     do navegador que recomeçava a cada recarga e podia repetir números entre dois postos);
 *   - «Chamar próxima» (posCallNextTicket) → a senha mais antiga em espera passa a CHAMADA (com lock: dois operadores
 *     não chamam a mesma senha);
 *   - «Repetir chamada» (novo) → volta a anunciar a senha actual no monitor;
 *   - o monitor público lê o estado (senha actual, fila e últimas chamadas) periodicamente e anuncia por voz as novas.
 */
final class ServicoSenhasPOSArmazem
{
    /** Últimas senhas chamadas mostradas no monitor (o legado guardava 8, incluindo a actual). */
    private const HISTORICO = 8;

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
    ) {}

    /** @return array{data: string, armazem_id: int, actual: ?array<string, mixed>, fila: list<array<string, mixed>>, chamadas: list<array<string, mixed>>} */
    public function estado(int $armazem): array
    {
        Armazem::query()->findOrFail($armazem);
        $hoje = $this->hoje();
        $base = fn () => SenhaPOSArmazem::query()->where('armazem_id', $armazem)->whereDate('data', $hoje);
        $fila = $base()->where('estado', SenhaPOSArmazem::AGUARDA)->orderBy('numero')->get();
        $chamadas = $base()->where('estado', SenhaPOSArmazem::CHAMADA)->orderByDesc('chamada_em')->orderByDesc('id')->limit(self::HISTORICO)->get();
        $actual = $chamadas->shift();

        return ['data' => $hoje, 'armazem_id' => $armazem, 'actual' => $actual ? $this->dados($actual) : null,
            'fila' => $fila->map(fn ($s) => $this->dados($s))->values()->all(), 'chamadas' => $chamadas->map(fn ($s) => $this->dados($s))->values()->all()];
    }

    /** @return array<string, mixed> */
    public function gerar(int $armazem): array
    {
        Armazem::query()->findOrFail($armazem);
        $hoje = $this->hoje();

        return DB::transaction(function () use ($armazem, $hoje) {
            $n = $this->numeracao->proximo($this->contexto->obrigatorio(), "pos_armazem:senha:{$armazem}:{$hoje}",
                fn () => (int) SenhaPOSArmazem::query()->where('armazem_id', $armazem)->whereDate('data', $hoje)->max('numero'));
            $s = SenhaPOSArmazem::create(['armazem_id' => $armazem, 'data' => $hoje, 'numero' => $n, 'codigo' => sprintf('P-%03d', $n),
                'estado' => SenhaPOSArmazem::AGUARDA, 'chamadas' => 0, 'criado_por' => Auth::user()?->nome_utilizador]);

            return $this->dados($s);
        });
    }

    /** @return array<string, mixed> */
    public function chamarProxima(int $armazem): array
    {
        Armazem::query()->findOrFail($armazem);
        $hoje = $this->hoje();

        return DB::transaction(function () use ($armazem, $hoje) {
            $s = SenhaPOSArmazem::query()->where('armazem_id', $armazem)->whereDate('data', $hoje)->where('estado', SenhaPOSArmazem::AGUARDA)
                ->orderBy('numero')->lockForUpdate()->first();
            if (! $s) {
                throw new ErroNegocio('Sem senhas pendentes na fila.', 'SEM_SENHAS', 422);
            }
            $s->update(['estado' => SenhaPOSArmazem::CHAMADA, 'chamada_em' => now(), 'chamadas' => 1, 'chamado_por' => Auth::user()?->nome_utilizador]);

            return $this->dados($s);
        });
    }

    /** @return array<string, mixed> */
    public function repetir(int $senha): array
    {
        return DB::transaction(function () use ($senha) {
            $s = SenhaPOSArmazem::query()->lockForUpdate()->findOrFail($senha);
            if ($s->estado !== SenhaPOSArmazem::CHAMADA) {
                throw new ErroNegocio("A senha {$s->codigo} ainda não foi chamada.", 'SENHA_NAO_CHAMADA', 422);
            }
            $s->update(['chamada_em' => now(), 'chamadas' => $s->chamadas + 1, 'chamado_por' => Auth::user()?->nome_utilizador]);

            return $this->dados($s);
        });
    }

    private function hoje(): string
    {
        return now()->toDateString();
    }

    /** @return array<string, mixed> */
    private function dados(SenhaPOSArmazem $s): array
    {
        return ['id' => $s->id, 'codigo' => $s->codigo, 'numero' => $s->numero, 'estado' => $s->estado, 'chamadas' => $s->chamadas,
            'chamada_em' => $s->chamada_em?->toAtomString(), 'criado_em' => $s->criado_em?->toAtomString()];
    }
}
