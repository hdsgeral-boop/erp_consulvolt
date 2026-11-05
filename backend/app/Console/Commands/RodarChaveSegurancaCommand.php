<?php

namespace App\Console\Commands;

use App\Services\Seguranca\ServicoRotacaoChaves;
use Illuminate\Console\Command;

/**
 * Recifra os segredos reversíveis (MFA, AGT, gateways, IA) com a APP_KEY e a APP_CIPHER actuais (ADR-085).
 * Corre na ligação do dono da base (erp.saas.comandos_de_manutencao): atravessa todos os tenants. Ver SEGURANCA_CRIPTOGRAFIA.md.
 */
final class RodarChaveSegurancaCommand extends Command
{
    protected $signature = 'erp:seguranca:rodar-chave {--estado : só mostra o estado (nada é alterado)} {--simular : conta o que seria recifrado, sem gravar}';

    protected $description = 'Recifra os segredos reversíveis com a chave (APP_KEY) e a cifra (APP_CIPHER) actuais — rotação de chaves';

    public function handle(ServicoRotacaoChaves $servico): int
    {
        $this->line('Cifra: '.$servico->cifraActual().' · versão da chave actual: '.$servico->versaoDaChave());

        if ($this->option('estado')) {
            $e = $servico->estado();
            $this->table(['Segredo', 'actual', 'a rodar', 'texto simples', 'ilegível'], collect($e['por_coluna'])->map(fn ($c, $nome) => [$nome, $c['actual'], $c['a_rodar'], $c['texto_simples'], $c['ilegivel']])->values()->all());
            $this->info(sprintf('Total: %d actuais, %d a rodar, %d em texto simples, %d ilegíveis.', $e['total']['actual'], $e['total']['a_rodar'], $e['total']['texto_simples'], $e['total']['ilegivel']));

            return $e['total']['ilegivel'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $simular = (bool) $this->option('simular');
        $r = $servico->rodar($simular);
        $this->info(($simular ? '[simulação] ' : '').sprintf('%d recifrado(s), %d já actual(is), %d em texto simples cifrado(s), %d ilegível(is).', $r['rodados'], $r['ja_actuais'], $r['texto_simples_cifrado'], $r['ilegiveis']));
        if ($r['ilegiveis'] > 0) {
            $this->warn('Há segredos que nenhuma chave decifra: NÃO retire a chave antiga de APP_PREVIOUS_KEYS. Verifique-a (valores gravados com outra APP_KEY têm de ser introduzidos de novo).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
