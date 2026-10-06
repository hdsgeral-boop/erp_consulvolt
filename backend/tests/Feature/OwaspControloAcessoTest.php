<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Rotas;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OWASP A01 (docs/arquitetura/SEGURANCA_OWASP.md): varrimento de TODAS as rotas autenticadas com empresa activa com um
 * utilizador SEM nenhuma permissão, mas com acesso à empresa. Cada rota tem de recusar (403) — ou não encontrar o
 * recurso (404) antes de revelar dados — salvo as rotas pessoais da lista abaixo. Apanha «exigir» esquecido em rotas
 * novas (ronda 2: ronda2_g1/g2/g3, integracoes) sem depender de testes escritos à mão para cada uma.
 */
final class OwaspControloAcessoTest extends TestCase
{
    /** Rotas sem permissão de ecrã por desenho (dados do próprio utilizador ou da sessão). "MÉTODO uri" => motivo */
    private const SEM_PERMISSAO_POR_DESENHO = [
        'GET api/sistema/menu' => 'menu já filtrado pelas permissões do utilizador',
        'GET api/sistema/identidade' => 'identidade da empresa activa (cabeçalho dos documentos impressos)',
        'GET api/sistema/moedas' => 'dados de referência dos formulários',
        'GET api/sistema/cambios/consultar' => 'câmbio de referência dos formulários (leitura)',
        'GET api/gestao/inicio' => 'página de início: pendentes filtrados por permissão no serviço',
        'GET api/rh/portal/aprovacoes' => 'portal do colaborador: só os pedidos em que o utilizador é aprovador',
        'GET api/rh/portal/modelos' => 'portal do colaborador: modelos visíveis ao colaborador (gestão exige rh_portal_modelos)',
        'POST api/rh/portal/pedidos' => 'portal do colaborador: pedido do próprio (exigirColaborador no serviço)',
        'POST api/rh/portal/pedidos/{pedido}/decidir' => 'portal: o serviço só deixa decidir o aprovador da etapa',
        'POST api/rh/avaliacao/avaliacoes/{avaliacao}/contestar' => 'avaliação: só o próprio contesta (serviço, 403)',
        'POST api/rh/avaliacao/avaliacoes/{avaliacao}/decidir-contestacao' => 'avaliação: decisor designado ou rh_aval_parecer (serviço, 403)',
        'POST api/rh/avaliacao/feedbacks' => 'avaliação: chefia do colaborador (serviço)',
        'POST api/rh/avaliacao/360/respostas' => 'avaliação 360: o próprio colaborador responde',
        'GET api/rh/avaliacao/autoavaliacao' => 'autoavaliação do próprio (exigirColaborador)',
        'PUT api/rh/avaliacao/autoavaliacao' => 'autoavaliação do próprio (exigirColaborador)',
        'POST api/rh/avaliacao/ascendente' => 'avaliação ascendente do próprio',
        'GET api/rh/avaliacao/ascendente/{colaborador}' => 'avaliação ascendente do próprio',
        'PUT api/sistema/preferencias/{tipo}/{nome}' => 'preferências do próprio utilizador',
    ];

    /** Padrões que mostram uma verificação de autorização no corpo da acção (para acções que validam antes de autorizar). */
    private const AUTORIZACAO = '/\$this->exigir\(|Gate::(any|allows|check|authorize|denies)|->authorize\(|AccessDeniedHttpException/';

    private function autorizaNaAccao(Route $r): bool
    {
        $accao = $r->getActionName();
        [$classe, $metodo] = str_contains($accao, '@') ? explode('@', $accao) : [$accao, '__invoke'];
        if (! method_exists($classe, $metodo)) {
            return false;
        }
        $m = new \ReflectionMethod($classe, $metodo);
        $linhas = array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1);

        return (bool) preg_match(self::AUTORIZACAO, implode('', $linhas));
    }

    /** @return array{string, ?string} [uri concreta, motivo para saltar] */
    private function concretizar(Route $r): array
    {
        $candidatos = ['999999', 'x', 'produtos', 'colaboradores', 'clientes', 'contabilidade', 'vendas', 'ABC'];
        $uri = $r->uri();
        foreach ($r->parameterNames() as $p) {
            $regex = $r->wheres[$p] ?? null;
            $valor = $regex === null ? '999999' : null;
            foreach ($regex === null ? [] : $candidatos as $c) {
                if (preg_match('#^(?:'.$regex.')$#u', $c)) {
                    $valor = $c;
                    break;
                }
            }
            if ($valor === null) {
                return [$uri, "parâmetro {$p} sem candidato"];
            }
            $uri = preg_replace('#\{'.preg_quote($p, '#').'\??\}#', $valor, $uri);
        }

        return ['/'.$uri, null];
    }

    #[Test]
    public function todas_as_rotas_com_empresa_recusam_um_utilizador_sem_permissoes(): void
    {
        config(['erp.api.pedidos_por_minuto' => 100000]);   // o varrimento faz centenas de pedidos num minuto
        Http::preventStrayRequests();   // nenhuma rota pode chegar ao BAI, à AGT ou à Anthropic neste teste
        $empresa = $this->criarEmpresa(['nif' => '5417006511']);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true])->id]);
        $u->empresas()->attach($empresa->id);
        $cabecalhos = $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];

        $abertas = [];
        $total = 0;
        foreach (Rotas::getRoutes()->getRoutes() as $r) {
            $mw = $r->gatherMiddleware();
            if (! in_array('empresa', $mw, true) || ! str_starts_with($r->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($r->methods(), ['HEAD', 'OPTIONS', 'PATCH']) as $metodo) {
                [$uri, $saltar] = $this->concretizar($r);
                $id = "{$metodo} {$r->uri()}";
                if ($saltar !== null || isset(self::SEM_PERMISSAO_POR_DESENHO[$id])) {
                    continue;
                }
                $total++;
                try {
                    $estado = $this->json($metodo, $uri, [], $cabecalhos)->status();
                } catch (\Throwable $e) {
                    $estado = 'EXC '.class_basename($e);
                }
                // 422 = a acção valida antes de autorizar: aceitável só se a acção verificar a permissão logo a seguir
                if (! in_array($estado, [403, 404], true) && ! ($estado === 422 && $this->autorizaNaAccao($r))) {
                    $abertas[] = "{$id} -> {$estado}";
                }
            }
        }

        $this->assertGreaterThan(300, $total, 'o varrimento tem de cobrir as rotas da API');
        $this->assertSame([], $abertas, 'Rotas que não recusam um utilizador sem permissões (falta $this->exigir(...) antes da validação?)');
    }
}
