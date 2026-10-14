<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Jobs\Sistema\ExecutarRestauroLegado;
use App\Jobs\Sistema\SimularRestauroLegado;
use App\Models\RestauroLegado;
use App\Models\Utilizador;
use App\Services\Autenticacao\ServicoAutenticacao;
use App\Services\Migracao\LeitorBackupDexie;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Restauro da plataforma a partir do backup do legado (JSON Dexie, o mesmo formato de wstb_payroll_backup_*.json), pelo
 * ecrã. Usa o motor da migração (erp:migrar-backup-legado) e por isso SUBSTITUI todos os dados de negócio, os
 * utilizadores e a auditoria. Salvaguardas (OWASP A01/A04/A07):
 *  - só super-administradores; cada passo exige a palavra-passe de quem o faz (tentativas falhadas na auditoria);
 *  - carregar → simulação automática em segundo plano (ROLLBACK) → aprovação por OUTRO super-administrador (válida
 *    24 h, só se a simulação não tiver divergências de contagem nem órfãos) → execução com «RESTAURAR #n»;
 *  - antes de substituir faz uma cópia completa da base (pg_dump); a migração é transaccional (falha = nada gravado);
 *  - um restauro activo de cada vez; os trabalhos nunca se repetem sozinhos;
 *  - o JSON (dados reais) fica no disco privado e é apagado no fim; o histórico (sem chaves estrangeiras) sobrevive.
 */
final class ServicoRestauroLegado
{
    public const VALIDADE_APROVACAO_HORAS = 24;

    public const PASTA = 'restauros';

    public function __construct(
        private readonly ServicoAutenticacao $autenticacao,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return list<RestauroLegado> */
    public function listar(): array
    {
        RestauroLegado::query()->where('estado', 'APROVADO')->where('expira_em', '<', now())
            ->update(['estado' => 'SIMULADO', 'aprovado_por' => null, 'aprovado_em' => null, 'expira_em' => null]);

        return RestauroLegado::query()->orderByDesc('id')->limit(50)->get()->all();
    }

    public function carregar(UploadedFile $ficheiro, string $palavraPasse, Utilizador $actor): RestauroLegado
    {
        $this->exigirSuperAdministrador($actor);
        $this->exigirPalavraPasse($actor, $palavraPasse, 'carregar um backup');
        if (RestauroLegado::query()->whereIn('estado', RestauroLegado::ACTIVOS)->exists()) {
            throw new ErroNegocio('Já existe um restauro em curso ou por decidir: conclua-o ou cancele-o primeiro.', 'RESTAURO_ACTIVO', 409);
        }

        $relativo = self::PASTA.'/backup_'.now()->format('Ymd_His').'_'.Str::lower(Str::random(8)).'.json';
        Storage::disk('local')->putFileAs(dirname($relativo), $ficheiro, basename($relativo));
        $caminho = Storage::disk('local')->path($relativo);
        try {
            (new LeitorBackupDexie($caminho))->validarFormato();
        } catch (Throwable $e) {
            Storage::disk('local')->delete($relativo);
            throw $e instanceof ErroNegocio ? $e : new ErroNegocio('O ficheiro não é um backup válido do sistema antigo (JSON do Dexie).', 'BACKUP_FORMATO', 422);
        }

        $r = RestauroLegado::create([
            'estado' => 'SIMULANDO', 'nome_ficheiro' => mb_substr(basename($ficheiro->getClientOriginalName()), 0, 255), 'caminho' => $relativo,
            'sha256' => hash_file('sha256', $caminho), 'tamanho_bytes' => filesize($caminho), 'pedido_por' => $this->quem($actor), 'pedido_em' => now(),
            'historico' => [$this->entrada($actor, 'Backup carregado; simulação iniciada.')],
        ]);
        $this->auditoria->registar('Sistema/Restauro', 'Carregar backup do legado', "Restauro #{$r->id}: '{$r->nome_ficheiro}' carregado (sha256 {$r->sha256}).", 'restauros_legado', $r->id);
        SimularRestauroLegado::dispatch($r->id);

        return $r;
    }

    public function aprovar(int $id, string $palavraPasse, ?string $nota, Utilizador $actor): RestauroLegado
    {
        $this->exigirSuperAdministrador($actor);
        $this->exigirPalavraPasse($actor, $palavraPasse, "aprovar o restauro #{$id}");

        return DB::transaction(function () use ($id, $nota, $actor) {
            $r = $this->bloquear($id);
            if ($r->estado !== 'SIMULADO') {
                throw new ErroNegocio('Só se aprova um restauro com a simulação concluída.', 'RESTAURO_ESTADO', 422);
            }
            if ((int) ($r->pedido_por['id'] ?? 0) === $actor->id) {
                throw new ErroNegocio('A aprovação tem de ser feita por OUTRO super-administrador (segregação de funções).', 'RESTAURO_MESMO_UTILIZADOR', 403);
            }
            if (! ($r->simulacao['sem_problemas'] ?? false)) {
                throw new ErroNegocio('A simulação tem divergências de contagem ou órfãos: não pode ser aprovada.', 'RESTAURO_SIMULACAO_COM_PROBLEMAS', 422);
            }
            $nota = trim((string) $nota);
            $r->update(['estado' => 'APROVADO', 'aprovado_por' => $this->quem($actor), 'aprovado_em' => now(), 'expira_em' => now()->addHours(self::VALIDADE_APROVACAO_HORAS),
                'historico' => [...($r->historico ?? []), $this->entrada($actor, 'Aprovado'.($nota !== '' ? ': '.mb_substr($nota, 0, 255) : '').'. Válido por '.self::VALIDADE_APROVACAO_HORAS.' h.')]]);
            $this->auditoria->registar('Sistema/Restauro', 'Aprovar restauro do legado', "Restauro #{$r->id} aprovado por {$actor->nome_utilizador}.", 'restauros_legado', $r->id);

            return $r;
        });
    }

    public function executar(int $id, string $palavraPasse, string $confirmacao, Utilizador $actor): RestauroLegado
    {
        $this->exigirSuperAdministrador($actor);
        if (! class_exists(ExecutarRestauroLegado::class)) {
            throw new ErroNegocio('A execução do restauro ainda não está instalada neste servidor.', 'RESTAURO_INDISPONIVEL', 503);
        }
        $this->exigirPalavraPasse($actor, $palavraPasse, "executar o restauro #{$id}");

        $r = DB::transaction(function () use ($id, $confirmacao, $actor) {
            $r = $this->bloquear($id);
            if ($r->estado !== 'APROVADO' || $r->expira_em === null || $r->expira_em->isPast()) {
                throw new ErroNegocio('O restauro não está aprovado (ou a aprovação expirou).', 'RESTAURO_ESTADO', 422);
            }
            if (mb_strtoupper(trim($confirmacao)) !== "RESTAURAR #{$r->id}") {
                throw new ErroNegocio("Escreva exactamente «RESTAURAR #{$r->id}» para confirmar.", 'RESTAURO_CONFIRMACAO', 422);
            }
            if (RestauroLegado::query()->where('estado', 'EM_EXECUCAO')->exists()) {
                throw new ErroNegocio('Já está um restauro em execução.', 'RESTAURO_ACTIVO', 409);
            }
            $r->update(['estado' => 'EM_EXECUCAO', 'executado_por' => $this->quem($actor), 'iniciado_em' => now(),
                'historico' => [...($r->historico ?? []), $this->entrada($actor, 'Execução iniciada.')]]);
            $this->auditoria->registar('Sistema/Restauro', 'Executar restauro do legado', "Restauro #{$r->id} iniciado por {$actor->nome_utilizador}.", 'restauros_legado', $r->id);

            return $r;
        });
        ExecutarRestauroLegado::dispatch($r->id);

        return $r;
    }

    public function cancelar(int $id, Utilizador $actor): RestauroLegado
    {
        $this->exigirSuperAdministrador($actor);

        return DB::transaction(function () use ($id, $actor) {
            $r = $this->bloquear($id);
            if (! in_array($r->estado, ['SIMULADO', 'SIMULACAO_FALHADA', 'APROVADO'], true)) {
                throw new ErroNegocio('Este restauro já não pode ser cancelado.', 'RESTAURO_ESTADO', 422);
            }
            $this->apagarFicheiro($r);
            $r->update(['estado' => 'CANCELADO', 'historico' => [...($r->historico ?? []), $this->entrada($actor, 'Cancelado.')]]);
            $this->auditoria->registar('Sistema/Restauro', 'Cancelar restauro do legado', "Restauro #{$r->id} cancelado por {$actor->nome_utilizador}.", 'restauros_legado', $r->id);

            return $r;
        });
    }

    /** Resumo da simulação a guardar no pedido (sem dados pessoais: só contagens). @param  array<string, mixed>  $rel */
    public static function resumoRelatorio(array $rel): array
    {
        $contagens = $rel['contagens'] ?? [];
        $divergentes = array_keys(array_filter($contagens, fn ($c) => ! ($c['confere'] ?? false)));
        $orfaos = $rel['orfaos'] ?? [];

        return [
            'execucao' => $rel['execucao'] ?? null,
            'resumo' => $rel['resumo'] ?? [],
            'tabelas' => count($contagens),
            'divergentes' => $divergentes,
            'orfaos' => $orfaos,
            'equilibrio' => array_values(array_map(fn ($e) => array_intersect_key((array) $e, array_flip(['empresa_id', 'empresa', 'linhas', 'debito', 'credito', 'diferenca', 'equilibrada', 'diferenca_no_legado'])),
                $rel['equilibrio_contabilistico'] ?? [])),
            'duracao_segundos' => $rel['duracao_segundos'] ?? null,
            'sem_problemas' => $divergentes === [] && $orfaos === [],
        ];
    }

    public function apagarFicheiro(RestauroLegado $r): void
    {
        if ($r->caminho) {
            Storage::disk('local')->delete($r->caminho);
            $r->caminho = null;
        }
    }

    /** @return array{id: int, nome: string} */
    private function quem(Utilizador $u): array
    {
        return ['id' => $u->id, 'nome' => $u->nome_completo ?: $u->nome_utilizador];
    }

    /** @return array{em: string, por: string, texto: string} */
    public function entrada(?Utilizador $u, string $texto): array
    {
        return ['em' => now()->toAtomString(), 'por' => $u ? ($u->nome_completo ?: $u->nome_utilizador) : 'sistema', 'texto' => $texto];
    }

    private function bloquear(int $id): RestauroLegado
    {
        return RestauroLegado::query()->lockForUpdate()->findOrFail($id);
    }

    private function exigirSuperAdministrador(Utilizador $u): void
    {
        if (! $u->eSuperAdministrador()) {
            throw new ErroNegocio('Só um super-administrador pode restaurar a plataforma.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
    }

    private function exigirPalavraPasse(Utilizador $u, string $palavraPasse, string $accao): void
    {
        if ($palavraPasse === '' || ! $this->autenticacao->palavraPasseCorrecta($u, $palavraPasse)) {
            $this->auditoria->registar('Sistema/Restauro', 'Tentativa falhada', "Palavra-passe incorrecta de {$u->nome_utilizador} ao {$accao}.", 'restauros_legado');
            throw new ErroNegocio('Palavra-passe incorrecta.', 'PALAVRA_PASSE_INCORRECTA', 422);
        }
    }
}
