<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\ConfiguracaoImpressaoPOS;
use App\Models\TerminalPOS;
use Illuminate\Support\Facades\Auth;

/**
 * Configuração de impressão do POS (F7, D-13 do plano): por empresa na BD, com sobreposição por posto/terminal. Os valores
 * efectivos de um posto são: omissão do sistema < empresa < campos preenchidos da sobreposição do terminal.
 */
final class ServicoConfigImpressaoPOS
{
    public const CAMPOS = ['formato', 'largura', 'automatico', 'consulta', 'rodape'];

    public const PADRAO = ['formato' => 'TERMICO', 'largura' => 80, 'automatico' => true, 'consulta' => 'PREVISUALIZAR', 'rodape' => 'Obrigado pela preferência!'];

    /** @return array<string, mixed> */
    public function obter(?int $terminalId = null): array
    {
        $this->terminal($terminalId);
        $empresa = ConfiguracaoImpressaoPOS::query()->whereNull('terminal_pos_id')->first();
        $terminal = $terminalId ? ConfiguracaoImpressaoPOS::query()->where('terminal_pos_id', $terminalId)->first() : null;
        $efectiva = self::PADRAO;
        foreach ([$empresa, $terminal] as $linha) {
            foreach (self::CAMPOS as $c) {
                if ($linha && $linha->{$c} !== null) {
                    $efectiva[$c] = $linha->{$c};
                }
            }
        }

        return [
            'guardada' => $empresa !== null,
            'empresa' => $empresa ? $this->valores($empresa) : null,
            'terminal' => $terminal ? $this->valores($terminal) : null,
            'terminal_pos_id' => $terminalId,
            'efectiva' => $efectiva,
            'padrao' => self::PADRAO,
        ];
    }

    /**
     * Grava os valores da empresa (sem terminal; o que faltar fica com o valor já gravado ou o padrão) ou a sobreposição do
     * terminal (campo nulo = herda da empresa).
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public function guardar(array $d, ?int $terminalId = null): array
    {
        $this->terminal($terminalId);
        $valores = [];
        foreach (self::CAMPOS as $c) {
            if (array_key_exists($c, $d)) {
                $valores[$c] = $c === 'rodape' ? $this->rodape($d[$c]) : $d[$c];
            }
        }
        $linha = ConfiguracaoImpressaoPOS::query()->when($terminalId === null, fn ($q) => $q->whereNull('terminal_pos_id'), fn ($q) => $q->where('terminal_pos_id', $terminalId))->first();
        if ($terminalId === null) {
            // a empresa tem sempre todos os campos preenchidos; o rodapé pode ser vazio de propósito
            $valores = array_filter($valores, fn ($v) => $v !== null) + ($linha ? $this->valores($linha) : []) + self::PADRAO;
            if (array_key_exists('rodape', $d) && $d['rodape'] !== null) {
                $valores['rodape'] = $this->rodape($d['rodape']);
            }
        }
        $valores['atualizado_por'] = Auth::user()?->nome_utilizador;
        if ($linha) {
            $linha->update($valores);
        } else {
            ConfiguracaoImpressaoPOS::query()->create($valores + ['terminal_pos_id' => $terminalId]);
        }

        return $this->obter($terminalId);
    }

    /** Remove a sobreposição do terminal (volta a herdar da empresa). */
    public function removerSobreposicao(int $terminalId): array
    {
        $this->terminal($terminalId);
        ConfiguracaoImpressaoPOS::query()->where('terminal_pos_id', $terminalId)->get()->each->delete();

        return $this->obter($terminalId);
    }

    private function terminal(?int $terminalId): void
    {
        if ($terminalId !== null && ! TerminalPOS::query()->whereKey($terminalId)->exists()) {
            throw new ErroNegocio('O terminal indicado não existe nesta empresa.', 'TERMINAL_INEXISTENTE', 404);
        }
    }

    private function rodape(mixed $v): ?string
    {
        return $v === null ? null : mb_substr(trim(strip_tags((string) $v)), 0, 200);
    }

    /** @return array<string, mixed> */
    private function valores(ConfiguracaoImpressaoPOS $c): array
    {
        return array_intersect_key($c->toArray(), array_flip(self::CAMPOS));
    }
}
