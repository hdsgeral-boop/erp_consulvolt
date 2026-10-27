<?php

namespace App\Services\IA\Agente;

use App\Exceptions\ErroNegocio;
use App\Models\Utilizador;
use App\Services\IA\Agente\Fornecedores\GeminiIA;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Support\Email\EnviarEmail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Assistente IA do MEU ERP (chat com histórico, anexos, consultas e acções no ERP com confirmação, pesquisa na internet).
 *
 * Cada envio: verifica a atribuição e a quota do cliente; guarda os anexos; monta o histórico (formato do fornecedor da
 * conversa); corre o ciclo modelo → ferramentas até à resposta final (no máximo MAX_PASSOS); grava a mensagem do
 * utilizador e a do assistente (texto, fontes, acções propostas) e o consumo (tokens e custo estimado).
 */
final class ServicoAgenteIA
{
    private const MAX_PASSOS = 10;

    private const MAX_ANEXOS = 5;

    private const MAX_ANEXO_KB = 10240;

    private const MAX_TEXTO_FOLHA = 60000;

    public function __construct(
        private readonly ServicoConfiguracaoIA $config,
        private readonly ServicoFerramentasIA $ferramentas,
        private readonly ServicoLeituraFolha $folhas,
    ) {}

    /** @return array<string, mixed> */
    public function estado(Utilizador $u): array
    {
        $a = $this->config->doCliente($u->tenant_id);

        return [
            'ativo' => $a !== null,
            'quota_tokens_mes' => $a['quota'] ?? null,
            'tokens_usados_mes' => $a['usados'] ?? 0,
        ];
    }

    /** @return array{regras: string} */
    public function regras(Utilizador $u): array
    {
        return ['regras' => (string) (DB::table('ia_regras_cliente')->where('tenant_id', $u->tenant_id)->value('regras') ?? '')];
    }

    /** @return array{regras: string} */
    public function guardarRegras(Utilizador $u, string $regras): array
    {
        DB::table('ia_regras_cliente')->updateOrInsert(['tenant_id' => $u->tenant_id], ['regras' => trim($regras), 'atualizado_em' => now()]);

        return $this->regras($u);
    }

    /** @return list<array<string, mixed>> */
    public function conversas(Utilizador $u, int $empresa): array
    {
        return DB::table('ia_conversas')->where('utilizador_id', $u->id)->where('empresa_id', $empresa)->whereNull('eliminado_em')
            ->orderByDesc('atualizado_em')->limit(200)->get(['id', 'titulo', 'criado_em', 'atualizado_em'])->all();
    }

    public function renomear(Utilizador $u, int $empresa, int $id, string $titulo): void
    {
        $this->conversa($u, $empresa, $id);
        DB::table('ia_conversas')->where('id', $id)->update(['titulo' => mb_substr(trim($titulo), 0, 200) ?: 'Conversa', 'atualizado_em' => now()]);
    }

    public function eliminar(Utilizador $u, int $empresa, int $id): void
    {
        $this->conversa($u, $empresa, $id);
        DB::table('ia_conversas')->where('id', $id)->update(['eliminado_em' => now()]);
    }

    /** @return list<array<string, mixed>> mensagens para o ecrã (sem o histórico do fornecedor) */
    public function mensagens(Utilizador $u, int $empresa, int $id): array
    {
        $this->conversa($u, $empresa, $id);
        $accoes = DB::table('ia_accoes')->where('conversa_id', $id)->get()->keyBy('id');

        return DB::table('ia_mensagens')->where('conversa_id', $id)->orderBy('id')->get(['id', 'papel', 'texto', 'extra', 'criado_em'])
            ->map(function ($m) use ($accoes) {
                $extra = json_decode((string) $m->extra, true) ?: [];
                $extra['accoes'] = array_values(array_filter(array_map(fn ($id) => isset($accoes[$id]) ? $this->apresentarAccao($accoes[$id]) : null, $extra['accoes'] ?? [])));

                return ['id' => $m->id, 'papel' => $m->papel, 'texto' => $m->texto, 'extra' => $extra, 'criado_em' => $m->criado_em];
            })->all();
    }

    /**
     * Envia uma mensagem (cria a conversa se `$conversaId` for null) e devolve a conversa e as duas mensagens novas.
     *
     * @param  list<UploadedFile>  $ficheiros
     * @return array{conversa: array<string, mixed>, mensagens: list<array<string, mixed>>}
     */
    public function enviar(Utilizador $u, int $empresa, ?int $conversaId, string $texto, array $ficheiros, ?string $modulo = null, ?string $ecra = null): array
    {
        $a = $this->config->doCliente($u->tenant_id);
        if ($a === null) {
            throw new ErroNegocio('O assistente IA não está activo para a sua conta. Fale com o administrador da plataforma.', 'IA_INATIVA', 403);
        }
        if ($a['quota'] !== null && $a['usados'] >= $a['quota']) {
            throw new ErroNegocio('A quota mensal de IA da sua conta foi atingida. Volta a ficar disponível no próximo mês.', 'IA_QUOTA_ATINGIDA', 429);
        }
        if (count($ficheiros) > self::MAX_ANEXOS) {
            throw new ErroNegocio('No máximo '.self::MAX_ANEXOS.' anexos por mensagem.', 'IA_ANEXOS', 422);
        }

        if ($conversaId === null) {
            $conversaId = DB::table('ia_conversas')->insertGetId([
                'empresa_id' => $empresa, 'utilizador_id' => $u->id, 'titulo' => mb_substr(trim($texto) ?: 'Nova conversa', 0, 60),
                'fornecedor' => $a['fornecedor'], 'modelo' => $a['modelo'],
            ]);
        }
        $conversa = $this->conversa($u, $empresa, $conversaId);
        if ($conversa->fornecedor !== $a['fornecedor']) {
            throw new ErroNegocio('A configuração da IA mudou. Abra uma nova conversa para continuar.', 'IA_FORNECEDOR_MUDOU', 409);
        }
        $fornecedor = $this->config->cliente($a['fornecedor']);

        [$anexosModelo, $anexosEcra] = $this->guardarAnexos($ficheiros, $conversaId, $empresa, $u);
        $textoEnviado = $this->notasDeAccoes($conversaId).$texto;
        $daVez = $fornecedor->mensagemUtilizador($textoEnviado, $anexosModelo);
        $historico = array_merge($this->historico($conversaId), $daVez);

        $sistema = $this->sistema($u, $empresa, $a, $modulo, $ecra);
        $defs = $this->ferramentas->definicoes($u, $empresa, $a['accoes_erp']);
        $novas = [];
        $textos = [];
        $fontes = [];
        $accoes = [];
        $usadas = [];
        $tokE = 0;
        $tokS = 0;
        $pesquisas = 0;
        for ($passo = 0; $passo < self::MAX_PASSOS; $passo++) {
            $r = $fornecedor->conversar($conversa->modelo, $sistema, array_merge($historico, $novas), $defs, $a['pesquisa_web']);
            $novas = array_merge($novas, $r->historico);
            $tokE += $r->tokensEntrada;
            $tokS += $r->tokensSaida;
            $pesquisas += $r->pesquisas;
            $fontes = array_merge($fontes, $r->fontes);
            if (trim($r->texto) !== '') {
                $textos[] = $r->texto;
            }
            if ($r->recusa !== null) {
                $textos[] = $r->recusa;
                break;
            }
            if ($r->continuar) {
                continue;
            }
            if (! $r->chamadas) {
                break;
            }
            $resultados = [];
            foreach ($r->chamadas as $c) {
                $usadas[] = $c['nome'];
                if ($fornecedor instanceof GeminiIA && $c['nome'] === GeminiIA::FERRAMENTA_PESQUISA) {
                    [$tx, $fx, $e, $s] = $fornecedor->pesquisar((string) ($c['args']['consulta'] ?? ''));
                    $tokE += $e;
                    $tokS += $s;
                    $pesquisas++;
                    $fontes = array_merge($fontes, $fx);
                    $resultados[] = ['id' => $c['id'], 'nome' => $c['nome'], 'conteudo' => (string) json_encode(['resumo' => $tx, 'fontes' => $fx], JSON_UNESCAPED_UNICODE)];

                    continue;
                }
                $res = $this->ferramentas->executar($c['nome'], $c['args'], $u, $empresa, $conversaId, $a['accoes_erp']);
                if (isset($res['accao'])) {
                    $accoes[] = $res['accao']['id'];
                }
                $resultados[] = ['id' => $c['id'], 'nome' => $c['nome'], 'conteudo' => $res['conteudo'], 'erro' => $res['erro'] ?? false];
            }
            $novas = array_merge($novas, $fornecedor->resultados($resultados));
            if ($passo === self::MAX_PASSOS - 1) {
                $textos[] = 'Parei aqui: o pedido precisava de demasiados passos. Pode continuar a conversa para eu prosseguir.';
            }
        }

        $custo = ($tokE * $a['preco_entrada'] + $tokS * $a['preco_saida']) / 1_000_000;
        $fontes = array_values(collect($fontes)->filter(fn ($f) => $f['url'] !== '')->unique('url')->take(12)->all());
        $resposta = trim(implode("\n\n", $textos)) ?: ($accoes ? 'Preparei a proposta abaixo para confirmar.' : 'Sem resposta do modelo.');

        $ids = DB::transaction(function () use ($conversaId, $empresa, $texto, $anexosEcra, $daVez, $resposta, $fontes, $accoes, $usadas, $novas, $tokE, $tokS, $u, $a, $custo, $pesquisas) {
            $idU = DB::table('ia_mensagens')->insertGetId(['conversa_id' => $conversaId, 'empresa_id' => $empresa, 'papel' => 'utilizador', 'texto' => $texto,
                'extra' => json_encode(['anexos' => $anexosEcra], JSON_UNESCAPED_UNICODE), 'historico' => json_encode($daVez, JSON_UNESCAPED_UNICODE)]);
            $idA = DB::table('ia_mensagens')->insertGetId(['conversa_id' => $conversaId, 'empresa_id' => $empresa, 'papel' => 'assistente', 'texto' => $resposta,
                'extra' => json_encode(['fontes' => $fontes, 'accoes' => $accoes, 'ferramentas' => array_values(array_unique($usadas))], JSON_UNESCAPED_UNICODE),
                'historico' => json_encode($novas, JSON_UNESCAPED_UNICODE), 'tokens_entrada' => $tokE, 'tokens_saida' => $tokS]);
            DB::table('ia_conversas')->where('id', $conversaId)->update(['atualizado_em' => now()]);
            DB::table('ia_consumo')->insert(['tenant_id' => $u->tenant_id, 'empresa_id' => $empresa, 'utilizador_id' => $u->id, 'conversa_id' => $conversaId,
                'fornecedor' => $a['fornecedor'], 'modelo' => $a['modelo'], 'tokens_entrada' => $tokE, 'tokens_saida' => $tokS, 'pesquisas_web' => $pesquisas, 'custo_usd' => round($custo, 6)]);

            return [$idU, $idA];
        });

        $mensagens = array_values(array_filter($this->mensagens($u, $empresa, $conversaId), fn ($m) => in_array($m['id'], $ids, true)));

        return ['conversa' => (array) DB::table('ia_conversas')->where('id', $conversaId)->first(['id', 'titulo', 'criado_em', 'atualizado_em']), 'mensagens' => $mensagens];
    }

    /**
     * O utilizador confirmou (e o ecrã executou) ou rejeitou uma proposta. O resultado vai para o modelo na mensagem seguinte.
     *
     * @param  array<string, mixed>|null  $resultado
     * @return array<string, mixed>
     */
    public function decidirAccao(Utilizador $u, int $empresa, int $id, string $estado, ?array $resultado): array
    {
        $accao = DB::table('ia_accoes')->where('id', $id)->where('empresa_id', $empresa)->where('utilizador_id', $u->id)->first();
        if (! $accao) {
            throw new ErroNegocio('Proposta não encontrada.', 'IA_ACCAO_INEXISTENTE', 404);
        }
        if ($accao->estado !== 'PENDENTE') {
            throw new ErroNegocio('Esta proposta já foi decidida.', 'IA_ACCAO_DECIDIDA', 422);
        }
        DB::table('ia_accoes')->where('id', $id)->update(['estado' => $estado, 'resultado' => $resultado ? json_encode($resultado, JSON_UNESCAPED_UNICODE) : null, 'decidida_em' => now()]);

        return $this->apresentarAccao(DB::table('ia_accoes')->where('id', $id)->first());
    }

    // ───────────── internos ─────────────

    private function conversa(Utilizador $u, int $empresa, int $id): object
    {
        $c = DB::table('ia_conversas')->where('id', $id)->where('utilizador_id', $u->id)->where('empresa_id', $empresa)->whereNull('eliminado_em')->first();
        if (! $c) {
            throw new ErroNegocio('Conversa não encontrada.', 'IA_CONVERSA_INEXISTENTE', 404);
        }

        return $c;
    }

    /** @return list<array<string, mixed>> */
    private function historico(int $conversa): array
    {
        $h = [];
        foreach (DB::table('ia_mensagens')->where('conversa_id', $conversa)->orderBy('id')->pluck('historico') as $j) {
            $h = array_merge($h, json_decode((string) $j, true) ?: []);
        }

        return $h;
    }

    /** Decisões sobre propostas desde a última mensagem: o modelo fica a saber o que foi gravado ou rejeitado. */
    private function notasDeAccoes(int $conversa): string
    {
        $ultima = DB::table('ia_mensagens')->where('conversa_id', $conversa)->where('papel', 'utilizador')->max('criado_em');
        $decididas = DB::table('ia_accoes')->where('conversa_id', $conversa)->where('estado', '<>', 'PENDENTE')->where('tipo', '<>', 'navegar')
            ->when($ultima, fn ($q) => $q->where('decidida_em', '>', $ultima))->orderBy('id')->get();
        if ($decididas->isEmpty()) {
            return '';
        }
        $linhas = $decididas->map(fn ($x) => "- Proposta #{$x->id} ({$x->resumo}): ".match ($x->estado) {
            'EXECUTADA' => 'confirmada e gravada'.(($r = json_decode((string) $x->resultado, true)) && isset($r['mensagem']) ? " — {$r['mensagem']}" : ''),
            'REJEITADA' => 'rejeitada pelo utilizador',
            default => 'falhou'.(($r = json_decode((string) $x->resultado, true)) && isset($r['mensagem']) ? " — {$r['mensagem']}" : ''),
        });

        return "[Nota do sistema sobre as propostas anteriores]\n".$linhas->implode("\n")."\n\n";
    }

    /**
     * @param  list<UploadedFile>  $ficheiros
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function guardarAnexos(array $ficheiros, int $conversa, int $empresa, Utilizador $u): array
    {
        $paraModelo = [];
        $paraEcra = [];
        foreach ($ficheiros as $f) {
            if ($f->getSize() > self::MAX_ANEXO_KB * 1024) {
                throw new ErroNegocio("O anexo «{$f->getClientOriginalName()}» tem mais de 10 MB.", 'IA_ANEXOS', 422);
            }
            $nome = mb_substr($f->getClientOriginalName(), 0, 255);
            $ext = strtolower($f->getClientOriginalExtension());
            $mime = (string) $f->getMimeType();
            $caminho = $f->store("ia/{$empresa}/{$conversa}", 'local');
            DB::table('ia_anexos')->insert(['conversa_id' => $conversa, 'empresa_id' => $empresa, 'utilizador_id' => $u->id, 'nome' => $nome,
                'mime' => $mime, 'tamanho' => (int) $f->getSize(), 'caminho' => (string) $caminho]);
            $paraEcra[] = ['nome' => $nome, 'mime' => $mime, 'tamanho' => (int) $f->getSize()];

            if (in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                $linhas = $this->folhas->linhas($f);
                $texto = $linhas ? implode(';', array_keys($linhas[0]))."\n".implode("\n", array_map(fn ($l) => implode(';', array_map(fn ($v) => str_replace(["\n", ';'], [' ', ','], (string) $v), $l)), $linhas)) : '(folha vazia)';
                $paraModelo[] = ['nome' => $nome, 'mime' => 'text/csv', 'texto' => mb_substr($texto, 0, self::MAX_TEXTO_FOLHA)];
            } elseif (in_array($ext, ['txt', 'md', 'json', 'xml'], true)) {
                $paraModelo[] = ['nome' => $nome, 'mime' => 'text/plain', 'texto' => mb_substr((string) Storage::disk('local')->get((string) $caminho), 0, self::MAX_TEXTO_FOLHA)];
            } elseif ($mime === 'application/pdf' || in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                $paraModelo[] = ['nome' => $nome, 'mime' => $mime, 'base64' => base64_encode((string) Storage::disk('local')->get((string) $caminho))];
            } else {
                throw new ErroNegocio("Tipo de anexo não suportado: «{$nome}» (use PDF, imagem, Excel, CSV ou texto).", 'IA_ANEXOS', 422);
            }
        }

        return [$paraModelo, $paraEcra];
    }

    /** @param  array<string, mixed>  $a */
    private function sistema(Utilizador $u, int $empresa, array $a, ?string $modulo, ?string $ecra): string
    {
        $nomeEmpresa = (string) DB::table('empresas')->where('id', $empresa)->value('nome');
        $sistema = EnviarEmail::nomeSistemaDe($u);
        $capacidades = array_filter([
            'consultar dados do ERP com as ferramentas disponíveis (sempre com as permissões do utilizador)',
            $a['accoes_erp'] ? 'propor operações (lançamentos, criar clientes/fornecedores) que o utilizador confirma num cartão antes de serem gravadas' : null,
            'abrir ecrãs do ERP',
            $a['pesquisa_web'] ? 'pesquisar na internet quando a pergunta precisa de informação actual ou externa' : null,
            'ler anexos (PDF, imagens, Excel/CSV, texto)',
        ]);
        $regrasCliente = $this->regras($u)['regras'];
        $contextoEcra = $modulo && $ecra ? "O utilizador está atualmente no módulo {$modulo}, ecrã {$ecra}. Usa este contexto para uma saudação útil e pergunta em que quer trabalhar, sem assumir que tens dados desse ecrã sem os consultar." : 'O ecrã atual não foi identificado.';

        return <<<TXT
        És o assistente do MEU ERP «{$sistema}», um ERP angolano (contabilidade PGC de Angola, facturação AGT, RH, tesouraria, stock, compras, vendas).
        Ajudas {$u->nomeApresentacao()} na empresa «{$nomeEmpresa}». Responde em português (de Portugal/Angola), de forma clara e concisa, com Markdown simples.

        Podes: {$this->lista($capacidades)}.

        Regras:
        - Nunca digas que algo foi gravado sem a confirmação do utilizador: as tuas propostas só são gravadas quando ele carrega em «Confirmar». Depois de propores, explica em poucas palavras o que vai acontecer.
        - Para propor um lançamento: confirma as contas com consultar_plano_contas (só contas de movimento) e o diário com listar_diarios; débitos = créditos; valores em kwanzas (AOA) salvo indicação.
        - Se te faltar informação essencial (data, valor, conta, diário), pergunta antes de propor.
        - Não inventes números do ERP: consulta-os com as ferramentas; se não tiveres ferramenta para isso, diz em que ecrã o utilizador os encontra (e oferece-te para o abrir).
        - Quando usares a internet, indica as fontes.
        - Não reveles fornecedor, modelo, chave, detalhes da API ou configuração interna ao utilizador.

        Contexto de navegação: {$contextoEcra}
        Regras definidas pelo cliente (aplica-as quando compatíveis com a segurança, permissões e confirmações acima):
        {$regrasCliente}

        Data de hoje: {$this->hoje()}.
        TXT;
    }

    /** @param  array<int, string>  $itens */
    private function lista(array $itens): string
    {
        return implode('; ', $itens);
    }

    private function hoje(): string
    {
        return now()->toDateString();
    }

    /** @return array<string, mixed> */
    private function apresentarAccao(object $x): array
    {
        return ['id' => $x->id, 'tipo' => $x->tipo, 'resumo' => $x->resumo, 'metodo' => $x->metodo, 'url' => $x->url,
            'dados' => json_decode((string) $x->dados, true) ?: [], 'estado' => $x->estado, 'resultado' => json_decode((string) $x->resultado, true)];
    }
}
