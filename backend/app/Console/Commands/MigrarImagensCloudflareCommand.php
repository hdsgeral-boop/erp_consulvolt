<?php

namespace App\Console\Commands;

use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Services\Sistema\ServicoImagensPublicas;
use App\Services\Vendas\ServicoImagemProduto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Move as imagens que ainda vivem na base de dados (data URI em base64) ou no disco privado do servidor para o
 * Cloudflare R2 (ServicoImagensPublicas), deixando na base só o URL público:
 *   - empresas.logotipo (data URI → URL);
 *   - configuracoes_sistema.login_logo, o logótipo do ecrã de entrada (data URI → URL);
 *   - produtos.imagem_base64 (legado) → ficheiro no R2 + imagem_caminho/imagem_mime, coluna base64 esvaziada;
 *   - produtos.imagem_caminho no disco privado «local» → copiado para o R2 (mesmo caminho) e apagado do servidor.
 * Idempotente: o que já é URL / já está no R2 não é tocado. Com --simular só conta.
 */
final class MigrarImagensCloudflareCommand extends Command
{
    protected $signature = 'erp:imagens:migrar {--simular : Só conta o que seria migrado, sem alterar nada}';

    protected $description = 'Move logótipos e imagens de produtos (base64 na base ou ficheiro privado) para o Cloudflare R2, deixando só o URL público.';

    public function handle(ServicoImagensPublicas $imagens): int
    {
        if (! ServicoImagensPublicas::activo()) {
            $this->error('ERP_IMAGENS_DISCO não está definido (config/erp.php › imagens.disco): configure o Cloudflare R2 primeiro.');

            return self::FAILURE;
        }
        $simular = (bool) $this->option('simular');
        $this->info(($simular ? '[SIMULAÇÃO] ' : '').'Disco público: '.ServicoImagensPublicas::nomeDisco().' → '.rtrim((string) $imagens->disco()->url(''), '/'));

        $c = ['empresas' => 0, 'login' => 0, 'produtos_base64' => 0, 'produtos_ficheiro' => 0, 'invalidas' => 0];

        // 1. Logótipos das empresas (data URI → URL)
        foreach (DB::table('empresas')->whereNotNull('logotipo')->where('logotipo', 'like', 'data:image/%')->orderBy('id')->get(['id', 'logotipo']) as $e) {
            if (ServicoImagensPublicas::descodificarDataUri($e->logotipo, ServicoGestaoEmpresas::TAMANHO_MAXIMO_LOGOTIPO) === null) {
                $c['invalidas']++;
                $this->warn("empresa {$e->id}: logótipo não é uma imagem raster válida (mantido na base).");

                continue;
            }
            if (! $simular) {
                $url = $imagens->guardarDataUri($e->logotipo, "empresas/{$e->id}", 'logotipo', ServicoGestaoEmpresas::TAMANHO_MAXIMO_LOGOTIPO);
                DB::table('empresas')->where('id', $e->id)->update(['logotipo' => $url]);
            }
            $c['empresas']++;
        }

        // 2. Logótipo do ecrã de entrada
        $login = DB::table('configuracoes_sistema')->where('chave', 'login_logo')->value('valor');
        if (is_string($login) && str_starts_with($login, 'data:image/')) {
            if (ServicoImagensPublicas::descodificarDataUri($login, ServicoGestaoEmpresas::TAMANHO_MAXIMO_LOGOTIPO) === null) {
                $c['invalidas']++;
                $this->warn('login_logo: não é uma imagem raster válida (mantido na base).');
            } else {
                if (! $simular) {
                    $url = $imagens->guardarDataUri($login, 'sistema', 'logotipo-login', ServicoGestaoEmpresas::TAMANHO_MAXIMO_LOGOTIPO);
                    DB::table('configuracoes_sistema')->where('chave', 'login_logo')->update(['valor' => $url, 'atualizado_em' => now()]);
                }
                $c['login']++;
            }
        }

        // 3. Imagens de produtos
        $publico = $imagens->disco();
        $privado = Storage::disk(ServicoImagemProduto::DISCO);
        DB::table('produtos')->where(fn ($q) => $q->whereNotNull('imagem_base64')->orWhereNotNull('imagem_caminho'))
            ->orderBy('id')->chunkById(50, function ($produtos) use (&$c, $simular, $publico, $privado) {
                foreach ($produtos as $p) {
                    $opcoes = fn (string $mime) => ['ContentType' => $mime, 'CacheControl' => 'public, max-age=31536000, immutable'];
                    if ($p->imagem_caminho) {
                        // ficheiro ainda no disco privado do servidor → copiar para o R2 (mesmo caminho) e apagar do servidor
                        if ($privado->exists($p->imagem_caminho)) {
                            if (! $simular) {
                                $mime = $p->imagem_mime ?: 'application/octet-stream';
                                $publico->put($p->imagem_caminho, (string) $privado->get($p->imagem_caminho), $opcoes($mime));
                                $privado->delete($p->imagem_caminho);
                                if ($p->imagem_base64 !== null) {
                                    DB::table('produtos')->where('id', $p->id)->update(['imagem_base64' => null]);
                                }
                            }
                            $c['produtos_ficheiro']++;
                        } elseif ($p->imagem_base64 !== null && $publico->exists($p->imagem_caminho) && ! $simular) {
                            DB::table('produtos')->where('id', $p->id)->update(['imagem_base64' => null]);   // já no R2: só limpar o legado
                        }

                        continue;
                    }
                    // só base64 do legado → ficheiro novo no R2
                    $img = ServicoImagemProduto::descodificarLegado($p->imagem_base64);
                    if ($img === null) {
                        $c['invalidas']++;
                        $this->warn("produto {$p->id}: imagem_base64 não é JPEG/PNG/WebP válido (mantida na base).");

                        continue;
                    }
                    if (! $simular) {
                        $caminho = "empresas/{$p->empresa_id}/produtos/".Str::uuid()->toString().'.'.ServicoImagemProduto::TIPOS[$img['mime']];
                        $publico->put($caminho, $img['conteudo'], $opcoes($img['mime']));
                        DB::table('produtos')->where('id', $p->id)->update([
                            'imagem_caminho' => $caminho, 'imagem_mime' => $img['mime'], 'imagem_atualizada_em' => now(), 'imagem_base64' => null,
                        ]);
                    }
                    $c['produtos_base64']++;
                }
            }, 'id');

        $this->table(['Logótipos de empresas', 'Logótipo de entrada', 'Produtos (base64)', 'Produtos (ficheiro privado)', 'Inválidas (mantidas)'],
            [[$c['empresas'], $c['login'], $c['produtos_base64'], $c['produtos_ficheiro'], $c['invalidas']]]);
        $this->info($simular ? 'Simulação concluída: nada foi alterado.' : 'Migração concluída: as imagens estão no Cloudflare R2 e a base guarda só os URLs.');

        return self::SUCCESS;
    }
}
