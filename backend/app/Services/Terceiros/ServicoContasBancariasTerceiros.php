<?php

namespace App\Services\Terceiros;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\ContaBancariaTerceiro;
use App\Models\Terceiro;
use App\Services\RH\ServicoColaboradores;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contas bancárias dos terceiros — paridade com auxDesenharContasBancarias / auxAdicionarContaBancaria /
 * auxContaBancariaPredefinida / auxEliminarContaBancaria (Versão Actual, js/ui_aux.js:920-1005):
 *   - só com o terceiro já gravado (a conta fica presa ao id do terceiro);
 *   - IBAN obrigatório e válido (ISO 13616, mod 97; o NIB de 21 dígitos passa a AO06) e não repetido no mesmo terceiro;
 *   - a primeira conta fica logo predefinida; só uma predefinida por terceiro (também garantido por índice único);
 *   - ao eliminar a predefinida, promove-se a primeira que restar.
 * Os modelos filtram a empresa activa (PertenceEmpresa); o terceiro chega já resolvido dentro da empresa.
 */
final class ServicoContasBancariasTerceiros
{
    /** @return Collection<int, ContaBancariaTerceiro> predefinida primeiro */
    public function listar(Terceiro $terceiro): Collection
    {
        return ContaBancariaTerceiro::query()->where('terceiro_id', $terceiro->id)
            ->orderByDesc('predefinida')->orderBy('id')->get();
    }

    /** @param  array<string, mixed>  $dados */
    public function criar(Terceiro $terceiro, array $dados): ContaBancariaTerceiro
    {
        $dados = $this->normalizar($dados);
        $this->recusarRepetido($terceiro, $dados['iban']);

        return DB::transaction(function () use ($terceiro, $dados) {
            Terceiro::query()->whereKey($terceiro->id)->lockForUpdate()->first();   // série por terceiro: a 1.ª fica predefinida
            $jaTem = ContaBancariaTerceiro::query()->where('terceiro_id', $terceiro->id)->exists();
            $predefinida = ! $jaTem || ! empty($dados['predefinida']);
            if ($predefinida && $jaTem) {
                $this->retirarPredefinida($terceiro);
            }

            return ContaBancariaTerceiro::create(['terceiro_id' => $terceiro->id, 'predefinida' => $predefinida] + $dados);
        });
    }

    /** @param  array<string, mixed>  $dados */
    public function atualizar(Terceiro $terceiro, ContaBancariaTerceiro $conta, array $dados): ContaBancariaTerceiro
    {
        $this->doTerceiro($terceiro, $conta);
        $dados = $this->normalizar($dados);
        $this->recusarRepetido($terceiro, $dados['iban'], $conta->id);
        unset($dados['predefinida']);   // a predefinida muda-se pela acção própria
        $conta->update($dados);

        return $conta->refresh();
    }

    public function predefinir(Terceiro $terceiro, ContaBancariaTerceiro $conta): ContaBancariaTerceiro
    {
        $this->doTerceiro($terceiro, $conta);

        return DB::transaction(function () use ($terceiro, $conta) {
            $this->retirarPredefinida($terceiro);
            $conta->update(['predefinida' => true]);

            return $conta->refresh();
        });
    }

    public function eliminar(Terceiro $terceiro, ContaBancariaTerceiro $conta): void
    {
        $this->doTerceiro($terceiro, $conta);
        DB::transaction(function () use ($terceiro, $conta) {
            $eraPredefinida = (bool) $conta->predefinida;
            $conta->delete();
            if ($eraPredefinida) {   // senão o terceiro ficava sem conta por omissão (ui_aux.js:998-1002)
                ContaBancariaTerceiro::query()->where('terceiro_id', $terceiro->id)->orderBy('id')->first()?->update(['predefinida' => true]);
            }
        });
    }

    /** IBAN sem espaços e em maiúsculas; o NIB angolano (21 dígitos) passa a AO06 — o mesmo normalizador dos colaboradores e da tesouraria. */
    public static function normalizarIban(?string $iban): string
    {
        return ServicoColaboradores::normalizarIban((string) $iban);
    }

    /** ISO 13616 (mod 97 = 1; em Angola AO + 23 dígitos) — o mesmo validador dos colaboradores e das contas 42/43. */
    public static function ibanValido(?string $iban): bool
    {
        return ServicoColaboradores::ibanValido(self::normalizarIban($iban));
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function normalizar(array $dados): array
    {
        $texto = fn (string $c) => isset($dados[$c]) && trim((string) $dados[$c]) !== '' ? trim((string) $dados[$c]) : null;
        $iban = self::normalizarIban($dados['iban'] ?? '');
        if ($iban === '') {
            throw new ErroNegocio('O IBAN é obrigatório — é ele que o banco usa para transferir.', 'IBAN_OBRIGATORIO', 422);
        }
        if (! self::ibanValido($iban)) {
            throw new ErroNegocio("O IBAN {$iban} não é válido (formato ou dígitos de controlo).", 'IBAN_INVALIDO', 422);
        }
        $banco = $texto('banco');
        $bancoId = isset($dados['banco_id']) && $dados['banco_id'] !== null && $dados['banco_id'] !== '' ? (int) $dados['banco_id'] : null;
        if ($bancoId !== null) {
            $registo = Banco::query()->find($bancoId) ?? throw new ErroNegocio('O banco indicado não existe nesta empresa.', 'BANCO_INEXISTENTE', 422);
            $banco ??= $registo->nome;
        }

        return [
            'iban' => $iban, 'banco_id' => $bancoId, 'banco' => $banco !== null ? mb_substr($banco, 0, 150) : null,
            'numero_conta' => $texto('numero_conta'), 'titular' => $texto('titular'),
            'codigo_moeda' => ($m = $texto('codigo_moeda')) !== null ? strtoupper($m) : null,
            'predefinida' => ! empty($dados['predefinida']),
        ];
    }

    private function recusarRepetido(Terceiro $terceiro, string $iban, ?int $excluir = null): void
    {
        $existe = ContaBancariaTerceiro::query()->where('terceiro_id', $terceiro->id)->where('iban', $iban)
            ->when($excluir, fn ($q) => $q->whereKeyNot($excluir))->exists();
        if ($existe) {
            throw new ErroNegocio('Este terceiro já tem uma conta com esse IBAN.', 'IBAN_REPETIDO', 422);
        }
    }

    private function retirarPredefinida(Terceiro $terceiro): void
    {
        ContaBancariaTerceiro::query()->where('terceiro_id', $terceiro->id)->where('predefinida', true)->update(['predefinida' => false]);
    }

    private function doTerceiro(Terceiro $terceiro, ContaBancariaTerceiro $conta): void
    {
        if ((int) $conta->terceiro_id !== (int) $terceiro->id) {
            throw new ErroNegocio('A conta bancária não pertence a este terceiro.', 'CONTA_BANCARIA_DE_OUTRO', 404);
        }
    }
}
