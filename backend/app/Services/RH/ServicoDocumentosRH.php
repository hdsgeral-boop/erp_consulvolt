<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\CargoFuncao;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\CoordenadaBancariaColaborador;
use App\Models\Empresa;
use App\Models\ModeloDocumentoRH;
use App\Models\PedidoPortalColaborador;
use App\Models\UnidadeOrganica;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Texto\Extenso;
use Illuminate\Support\Facades\Auth;

/**
 * Modelos de documentos do RH e emissão (js/modules/rh/portal_dados.js:272-397). Paridade: 6 modelos padrão sempre
 * disponíveis (personalizáveis por empresa e repostos), modelos próprios, 21 variáveis {{…}} (desconhecidas são
 * recusadas), variável em falta aparece como [Rótulo] e impede a emissão automática (o destinatário em falta retira
 * a expressão «, a apresentar a …»), emissão exige texto ≥ 20 caracteres e assinante.
 * Correcção (ADR-040): numeração DOC/AAAA/NNNN atómica (ServicoNumeracao) — no legado contava os emitidos + 1 e
 * dois pedidos simultâneos recebiam o mesmo número.
 */
final class ServicoDocumentosRH
{
    public const VARIAVEIS = [
        'empresa' => 'Nome da empresa', 'empresa_nif' => 'NIF da empresa', 'empresa_morada' => 'Morada da empresa', 'nome' => 'Nome do colaborador',
        'nif' => 'NIF do colaborador', 'documento_identificacao' => 'N.º do BI / passaporte', 'nacionalidade' => 'Nacionalidade', 'funcao' => 'Função',
        'unidade' => 'Unidade orgânica', 'data_admissao' => 'Data de admissão (por extenso)', 'antiguidade' => 'Antiguidade (anos)',
        'tipo_contrato' => 'Tipo/estado do contrato', 'remuneracao' => 'Remuneração mensal ilíquida', 'remuneracao_extenso' => 'Remuneração por extenso',
        'banco' => 'Banco', 'iban' => 'IBAN', 'finalidade' => 'Finalidade indicada no pedido', 'destinatario' => 'Destinatário indicado no pedido',
        'observacoes' => 'Observações do pedido', 'data_hoje' => 'Data de emissão (por extenso)', 'local' => 'Local de emissão',
    ];

    private const FIM = 'A presente declaração destina-se a {{finalidade}}, a apresentar a {{destinatario}}, e vai assinada e autenticada com o carimbo em uso nesta empresa.';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
    ) {}

    /** @return array<string, array<string, mixed>> */
    public static function padrao(): array
    {
        $fimCert = str_replace(['A presente declaração', 'assinada e autenticada'], ['O presente certificado', 'assinado e autenticado'], self::FIM);
        $inicio = '{{empresa}}, com o NIF {{empresa_nif}}, declara para os devidos efeitos que {{nome}}, portador(a) do NIF {{nif}}, é trabalhador(a) desta empresa desde {{data_admissao}}';

        return [
            'DECL_SERVICO' => ['nome' => 'Declaração de efectividade de serviço', 'titulo' => 'DECLARAÇÃO DE EFECTIVIDADE DE SERVIÇO', 'auto_emitir' => true,
                'texto' => "{$inicio}, exercendo as funções de {{funcao}}, encontrando-se em efectividade de serviço.\n\n".self::FIM],
            'DECL_RENDIMENTOS' => ['nome' => 'Declaração de rendimentos', 'titulo' => 'DECLARAÇÃO DE RENDIMENTOS', 'auto_emitir' => true,
                'texto' => "{$inicio}, com a função de {{funcao}}, auferindo a remuneração mensal ilíquida de {{remuneracao}} ({{remuneracao_extenso}}).\n\n".self::FIM],
            'DECL_BANCO' => ['nome' => 'Declaração para efeitos bancários', 'titulo' => 'DECLARAÇÃO PARA EFEITOS BANCÁRIOS', 'auto_emitir' => true,
                'texto' => '{{empresa}}, com o NIF {{empresa_nif}}, declara que {{nome}}, portador(a) do NIF {{nif}}, é trabalhador(a) desta empresa desde {{data_admissao}}, com a função de {{funcao}} e a remuneração mensal ilíquida de {{remuneracao}} ({{remuneracao_extenso}}), sendo o salário pago por transferência para a conta com o IBAN {{iban}}, no {{banco}}.'."\n\n".self::FIM],
            'CERT_TRABALHO' => ['nome' => 'Certificado de trabalho', 'titulo' => 'CERTIFICADO DE TRABALHO', 'auto_emitir' => true,
                'texto' => '{{empresa}}, com o NIF {{empresa_nif}}, certifica que {{nome}}, portador(a) do documento de identificação n.º {{documento_identificacao}}, trabalha nesta empresa desde {{data_admissao}} ({{antiguidade}} anos), exercendo actualmente as funções de {{funcao}} na unidade {{unidade}}.'."\n\n".$fimCert],
            'DECL_VISTO' => ['nome' => 'Declaração para efeitos de visto / viagem', 'titulo' => 'DECLARAÇÃO', 'auto_emitir' => true,
                'texto' => '{{empresa}}, com o NIF {{empresa_nif}}, declara que {{nome}}, de nacionalidade {{nacionalidade}}, portador(a) do documento n.º {{documento_identificacao}}, é trabalhador(a) desta empresa desde {{data_admissao}}, com a função de {{funcao}} e a remuneração mensal ilíquida de {{remuneracao}}, mantendo o seu vínculo laboral durante a ausência e devendo regressar às suas funções no fim do período autorizado.'."\n\n".self::FIM],
            'OUTRO' => ['nome' => 'Outro documento', 'titulo' => 'DECLARAÇÃO', 'auto_emitir' => false,
                'texto' => "{{empresa}} declara, a pedido de {{nome}}, portador(a) do NIF {{nif}}, o seguinte: {{observacoes}}\n\n".self::FIM],
        ];
    }

    /** Modelos padrão (com a personalização da empresa, se existir) e modelos próprios. */
    public function modelos(bool $soActivos = false): array
    {
        $regs = ModeloDocumentoRH::query()->get()->keyBy('codigo');
        $lista = [];
        foreach (self::padrao() as $codigo => $m) {
            $r = $regs[$codigo] ?? null;
            $lista[] = array_merge(['codigo' => $codigo, 'padrao' => true, 'personalizado' => (bool) $r, 'ativo' => true, 'assinante' => '', 'cargo_assinante' => '', 'local' => '', 'id' => null], $m,
                $r ? array_filter($r->only(['id', 'nome', 'titulo', 'texto', 'ativo', 'auto_emitir', 'assinante', 'cargo_assinante', 'local']), fn ($v) => $v !== null) : []);
        }
        foreach ($regs as $codigo => $r) {
            if (! isset(self::padrao()[$codigo])) {
                $lista[] = ['padrao' => false, 'personalizado' => true] + $r->toArray();
            }
        }

        return array_values(array_filter($lista, fn ($m) => ! $soActivos || ($m['ativo'] ?? true)));
    }

    public function modelo(string $codigo): ?array
    {
        return collect($this->modelos())->firstWhere('codigo', $codigo);
    }

    /** @param  array<string, mixed>  $d */
    public function gravarModelo(array $d): array
    {
        $desconhecidas = collect(self::variaveisDe($d['titulo'].' '.$d['texto']))->reject(fn ($v) => isset(self::VARIAVEIS[$v]))->unique()->values();
        if ($desconhecidas->isNotEmpty()) {
            throw new ErroNegocio('Variáveis desconhecidas: '.$desconhecidas->map(fn ($v) => "{{{$v}}}")->implode(', ').'.', 'VARIAVEL_DESCONHECIDA', 422);
        }
        $codigo = $d['codigo'] ?? 'M_'.strtoupper(base_convert((string) (int) (microtime(true) * 1000), 10, 36));
        ModeloDocumentoRH::query()->updateOrCreate(['codigo' => $codigo], ['nome' => trim($d['nome']), 'titulo' => trim($d['titulo']), 'texto' => trim($d['texto']),
            'ativo' => $d['ativo'] ?? true, 'auto_emitir' => (bool) ($d['auto_emitir'] ?? false), 'assinante' => trim((string) ($d['assinante'] ?? '')),
            'cargo_assinante' => trim((string) ($d['cargo_assinante'] ?? '')), 'local' => trim((string) ($d['local'] ?? '')), 'atualizado_por' => Auth::user()?->nome_utilizador]);

        return $this->modelo($codigo);
    }

    /** Modelo padrão: repõe o texto original; modelo próprio: elimina (se não houver pedidos pendentes). */
    public function reporModelo(string $codigo): void
    {
        $r = ModeloDocumentoRH::query()->where('codigo', $codigo)->first();
        if (! $r) {
            return;
        }
        if (! isset(self::padrao()[$codigo]) && PedidoPortalColaborador::query()->where('tipo', 'DOCUMENTO')->where('estado', 'like', 'PENDENTE%')->where('dados->documento', $codigo)->exists()) {
            throw new ErroNegocio('Há pedidos por tratar com este modelo: desactive-o em vez de o eliminar.', 'REGISTO_EM_USO', 422);
        }
        $r->delete();
    }

    /** Valores das variáveis para um colaborador e os dados do pedido. */
    public function variaveis(int $colaborador, array $pedido = []): array
    {
        $c = Colaborador::query()->withTrashed()->findOrFail($colaborador);
        $e = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $hoje = now()->toDateString();
        $contrato = ContratoTrabalho::query()->where('colaborador_id', $c->id)->where('estado', 'ACTIVO')
            ->where(fn ($q) => $q->whereNull('data_inicio')->orWhere('data_inicio', '<=', $hoje))->where(fn ($q) => $q->whereNull('data_fim')->orWhere('data_fim', '>=', $hoje))
            ->orderBy('id')->first();
        $mensal = '0';
        if ($contrato) {
            $ctr = ['dias' => $contrato->dias_contrato_mes, 'horas' => $contrato->horas_por_dia];
            foreach ((array) $contrato->remuneracoes as $r) {
                $mensal = bcadd($mensal, MotorSalarial::mensal(['valor_mes' => $r['valor_mes'] ?? $r['value_month'] ?? null, 'valor_dia' => $r['valor_dia'] ?? $r['value_per_day'] ?? null], $ctr), 2);
            }
        }
        $admissao = $c->data_admissao?->toDateString() ?? $contrato?->data_inicio?->toDateString();
        $cb = CoordenadaBancariaColaborador::query()->where('colaborador_id', $c->id)->first();

        return [
            'empresa' => (string) $e->nome, 'empresa_nif' => (string) $e->nif, 'empresa_morada' => (string) $e->endereco,
            'nome' => (string) $c->nome_completo, 'nif' => (string) $c->nif, 'documento_identificacao' => (string) $c->documento_identificacao, 'nacionalidade' => (string) $c->nacionalidade,
            'funcao' => (string) ($c->cargo_funcao_id ? CargoFuncao::query()->withTrashed()->find($c->cargo_funcao_id)?->nome : ''),
            'unidade' => (string) ($c->unidade_organica_id ? UnidadeOrganica::query()->withTrashed()->find($c->unidade_organica_id)?->nome : ''),
            'data_admissao' => Extenso::data($admissao), 'antiguidade' => $admissao ? (string) max(0, (int) floor((strtotime($hoje) - strtotime($admissao)) / (365.25 * 86400))) : '',
            'tipo_contrato' => (string) ($contrato?->estado ?? ''),
            'remuneracao' => bccomp($mensal, '0', 2) > 0 ? number_format((float) $mensal, 2, ',', ' ').' Kz' : '',
            'remuneracao_extenso' => bccomp($mensal, '0', 2) > 0 ? Extenso::kwanzas($mensal) : '',
            'banco' => (string) ($cb?->banco_id ? Banco::query()->withTrashed()->find($cb->banco_id)?->nome : ''), 'iban' => (string) ($cb?->iban ?? ''),
            'finalidade' => (string) ($pedido['finalidade'] ?? ''), 'destinatario' => (string) ($pedido['destinatario'] ?? ''), 'observacoes' => (string) ($pedido['observacoes'] ?? ''),
            'data_hoje' => Extenso::data($hoje), 'local' => (string) ($e->municipio ?: ($e->provincia ?: (trim(explode(',', (string) $e->endereco)[0]) ?: 'Luanda'))),
        ];
    }

    /** @return array{titulo: string, texto: string, local: string, assinante: string, cargo_assinante: string, faltas: list<string>} */
    public static function aplicar(array $modelo, array $vars): array
    {
        $faltas = [];
        $sub = function (string $s) use ($vars, &$faltas) {
            return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($vars, &$faltas) {
                $v = $vars[$m[1]] ?? '';
                if ($v === '') {
                    $faltas[$m[1]] = true;

                    return '['.(self::VARIAVEIS[$m[1]] ?? $m[1]).']';
                }

                return $v;
            }, $s);
        };
        $texto = $sub((string) $modelo['texto']);
        $titulo = $sub((string) $modelo['titulo']);
        if (isset($faltas['destinatario'])) {   // destinatário é opcional
            $texto = preg_replace('/,? a apresentar a \['.preg_quote(self::VARIAVEIS['destinatario'], '/').'\]/u', '', $texto);
            unset($faltas['destinatario']);
        }

        return ['titulo' => $titulo, 'texto' => $texto, 'local' => ($modelo['local'] ?? '') ?: $vars['local'], 'assinante' => (string) ($modelo['assinante'] ?? ''),
            'cargo_assinante' => (string) ($modelo['cargo_assinante'] ?? ''), 'faltas' => array_map(fn ($k) => self::VARIAVEIS[$k] ?? $k, array_keys($faltas))];
    }

    /** Texto proposto para um pedido de documento. */
    public function proposta(PedidoPortalColaborador $p): array
    {
        $m = $this->modelo((string) ($p->dados['documento'] ?? 'OUTRO')) ?? ['codigo' => 'OUTRO'] + self::padrao()['OUTRO'];

        return self::aplicar($m, $this->variaveis($p->colaborador_id, (array) $p->dados)) + ['modelo' => $m['codigo'], 'auto_emitir' => (bool) ($m['auto_emitir'] ?? false)];
    }

    /** Número DOC/AAAA/NNNN (sequência da empresa e do ano, semeada pelo maior já emitido). */
    public function proximoNumero(): string
    {
        $empresa = $this->contexto->obrigatorio();
        $ano = now()->format('Y');
        $n = $this->numeracao->proximo($empresa, "rh:documento:{$ano}", fn () => (int) PedidoPortalColaborador::query()->whereNotNull('documento')
            ->where('documento->numero', 'like', "DOC/{$ano}/%")->get()->map(fn ($x) => (int) substr((string) $x->documento['numero'], -4))->max());

        return sprintf('DOC/%s/%04d', $ano, $n);
    }

    public static function variaveisDe(string $texto): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $texto, $m);

        return $m[1];
    }
}
