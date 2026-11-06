<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ModeloDocumentoImo;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Modelos de documentos do módulo imobiliário (imobiliario_templates.js): 8 tipos, 59 variáveis `{{chave}}` e 8 modelos por
 * omissão (dados de fábrica de resources/imobiliario/fabrica.json). Uma variável sem valor NÃO desaparece: sai como `[Rótulo]`
 * e entra na lista de faltas. Correcções: `sistema` marca só os de fábrica (o legado marcava todos como master-data) e cada
 * alteração do texto sobe a versão. A aplicação a contratos reais (contexto com os dados do contrato) é da Onda dos contratos.
 */
final class ServicoModelosDocumento
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @return Collection<int, ModeloDocumentoImo> */
    public function listar(?string $tipo = null): Collection
    {
        return ModeloDocumentoImo::query()->when($tipo, fn ($q, $t) => $q->where('tipo', $t))->orderBy('tipo')->orderByRaw('lower(nome)')->get();
    }

    /** @return array{tipos: array<string, string>, variaveis: list<array{chave: string, rotulo: string}>} */
    public function catalogo(): array
    {
        $f = CatalogoImobiliario::fabrica();

        return ['tipos' => (array) $f['tipos'], 'variaveis' => array_values((array) $f['variaveis'])];
    }

    /** @param  array<string, mixed>  $d */
    public function criar(array $d): ModeloDocumentoImo
    {
        $reg = $this->normalizar($d);
        $this->conferirUnico($reg, null);

        return $this->gravar(fn () => ModeloDocumentoImo::query()->create($reg + ['empresa_id' => $this->contexto->id(), 'sistema' => false, 'versao' => 1,
            'criado_por' => $this->utilizador(), 'atualizado_por' => $this->utilizador()]));
    }

    /** @param  array<string, mixed>  $d */
    public function actualizar(ModeloDocumentoImo $m, array $d): ModeloDocumentoImo
    {
        $reg = $this->normalizar($d);
        $this->conferirUnico($reg, $m);
        $mudouTexto = $reg['corpo'] !== $m->corpo || $reg['assunto'] !== $m->assunto;
        $this->gravar(fn () => $m->update($reg + ['versao' => $m->versao + ($mudouTexto ? 1 : 0), 'atualizado_por' => $this->utilizador()]));

        return $m->refresh();
    }

    public function eliminar(ModeloDocumentoImo $m): void
    {
        $m->delete();
    }

    /** Importa os modelos por omissão que ainda não existem (compara tipo e nome sem maiúsculas). Idempotente. Devolve quantos criou. */
    public function importarPadrao(): int
    {
        return DB::transaction(function () {
            $existentes = ModeloDocumentoImo::query()->get(['tipo', 'nome'])->map(fn ($m) => $m->tipo.'|'.mb_strtolower($m->nome))->flip()->all();
            $n = 0;
            foreach ((array) CatalogoImobiliario::fabrica()['modelos'] as $m) {
                if (isset($existentes[$m['tipo'].'|'.mb_strtolower($m['nome'])])) {
                    continue;
                }
                ModeloDocumentoImo::query()->create(['empresa_id' => $this->contexto->id(), 'tipo' => $m['tipo'], 'nome' => $m['nome'], 'assunto' => $m['assunto'], 'corpo' => $m['corpo'],
                    'activo' => true, 'sistema' => true, 'versao' => 1, 'criado_por' => $this->utilizador(), 'atualizado_por' => $this->utilizador()]);
                $n++;
            }

            return $n;
        });
    }

    /**
     * Substitui as `{{variáveis}}` por valores. Sem valor: `[Rótulo]` e entra em `faltas` (rótulos únicos, pela ordem de aparição).
     *
     * @param  array<string, scalar|null>  $valores
     * @return array{texto: string, faltas: list<string>}
     */
    public function renderizar(string $texto, array $valores): array
    {
        $rotulos = array_column((array) CatalogoImobiliario::fabrica()['variaveis'], 'rotulo', 'chave');
        $faltas = [];
        $saida = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use ($valores, $rotulos, &$faltas) {
            $v = $valores[$m[1]] ?? null;
            if ($v !== null && trim((string) $v) !== '') {
                return (string) $v;
            }
            $r = $rotulos[$m[1]] ?? $m[1];
            $faltas[$r] = true;

            return "[{$r}]";
        }, $texto) ?? $texto;

        return ['texto' => $saida, 'faltas' => array_keys($faltas)];
    }

    /** @return list<string> variáveis usadas no texto que não existem no catálogo */
    public function variaveisInvalidas(string $texto): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $texto, $m);
        $validas = array_flip(array_column((array) CatalogoImobiliario::fabrica()['variaveis'], 'chave'));

        return array_values(array_unique(array_filter($m[1], fn ($k) => ! isset($validas[$k]))));
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{tipo: string, nome: string, assunto: ?string, corpo: string, activo: bool}
     */
    private function normalizar(array $d): array
    {
        $tipo = (string) ($d['tipo'] ?? '');
        if (! isset(((array) CatalogoImobiliario::fabrica()['tipos'])[$tipo])) {
            throw new ErroNegocio('Escolha o tipo de documento.', 'MODELO_TIPO');
        }
        $nome = Normaliza::texto($d['nome'] ?? null);
        if ($nome === null) {
            throw new ErroNegocio('Dê um nome ao modelo.', 'MODELO_NOME');
        }
        $corpo = trim((string) ($d['corpo'] ?? ''));
        if ($corpo === '') {
            throw new ErroNegocio('O modelo não pode ficar sem texto.', 'MODELO_CORPO');
        }
        $assunto = Normaliza::texto($d['assunto'] ?? null);
        $maus = $this->variaveisInvalidas($corpo.' '.$assunto);
        if ($maus !== []) {
            throw new ErroNegocio('Variáveis que não existem: '.implode(', ', $maus).'.', 'MODELO_VARIAVEIS');
        }

        return ['tipo' => $tipo, 'nome' => mb_substr($nome, 0, 200), 'assunto' => $assunto !== null ? mb_substr($assunto, 0, 300) : null, 'corpo' => $corpo, 'activo' => ($d['activo'] ?? true) !== false];
    }

    /** @param  array{tipo: string, nome: string}  $reg */
    private function conferirUnico(array $reg, ?ModeloDocumentoImo $actual): void
    {
        $q = ModeloDocumentoImo::query()->where('tipo', $reg['tipo'])->whereRaw('lower(nome) = ?', [mb_strtolower($reg['nome'])]);
        if ($actual) {
            $q->whereKeyNot($actual->id);
        }
        if ($q->exists()) {
            throw new ErroNegocio('Já existe um modelo com este nome para este tipo de documento.', 'MODELO_DUPLICADO');
        }
    }

    private function gravar(callable $f): mixed
    {
        try {
            return $f();
        } catch (UniqueConstraintViolationException) {
            throw new ErroNegocio('Já existe um modelo com este nome para este tipo de documento.', 'MODELO_DUPLICADO');
        }
    }

    private function utilizador(): string
    {
        return mb_substr((string) (Auth::user()?->nome ?? 'sistema'), 0, 100);
    }
}
