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
        'GET api/ia/estado' => 'estado do assistente IA: sem ia_chat_view devolve ativo:false (alimenta o botão flutuante)',
        'GET api/sistema/menu' => 'menu já filtrado pelas permissões do utilizador',
        'GET api/sistema/identidade' => 'identidade da empresa activa (cabeçalho dos documentos impressos)',
        'GET api/sistema/moedas' => 'dados de referência dos formulários',
        'GET api/sistema/cambios/consultar' => 'câmbio de referência dos formulários (leitura)',
        'GET api/gestao/inicio' => 'página de início: pendentes filtrados por permissão no serviço',
        'GET api/rh/portal/aprovacoes' => 'portal do colaborador: só os pedidos em que o utilizador é aprovador',
        'GET api/rh/portal/modelos' => 'portal do colaborador: modelos visíveis ao colaborador (gestão exige rh_portal_modelos)',
        'POST api/rh/portal/pedidos' => 'portal do colaborador: pedido do próprio (exigirColaborador no serviço)',
        'POST api/rh/portal/pedidos/{pedido}/decidir' => 'portal: o serviço só deixa decidir o aprovador da etapa',
        'GET api/rh/portal/compras/aprovacoes' => 'portal do colaborador: só os pedidos de compra em que pode decidir (ServicoDeliberacaoCompras::podeDecidir)',
        'POST api/rh/portal/projetos/timesheets' => 'portal: só regista horas em tarefas do próprio (exigirMinhaTarefa no serviço)',
        'POST api/rh/portal/projetos/tarefas/{tarefa}/medicoes' => 'portal: só mede tarefas do próprio (exigirMinhaTarefa no serviço)',
        'POST api/rh/avaliacao/avaliacoes/{avaliacao}/contestar' => 'avaliação: só o próprio contesta (serviço, 403)',
        'POST api/rh/avaliacao/avaliacoes/{avaliacao}/decidir-contestacao' => 'avaliação: decisor designado ou rh_aval_parecer (serviço, 403)',
        'POST api/rh/avaliacao/feedbacks' => 'avaliação: chefia do colaborador (serviço)',
        'POST api/rh/avaliacao/360/respostas' => 'avaliação 360: o próprio colaborador responde',
        'GET api/rh/avaliacao/autoavaliacao' => 'autoavaliação do próprio (exigirColaborador)',
        'PUT api/rh/avaliacao/autoavaliacao' => 'autoavaliação do próprio (exigirColaborador)',
        'POST api/rh/avaliacao/ascendente' => 'avaliação ascendente do próprio',
        'GET api/rh/avaliacao/ascendente/{colaborador}' => 'avaliação ascendente do próprio',
        'PUT api/sistema/preferencias/{tipo}/{nome}' => 'preferências do próprio utilizador',
        'GET api/licenca/estado' => 'estado da licença da empresa activa (alimenta o aviso do cabeçalho; sem dados sensíveis)',
        'GET api/licenca/planos' => 'planos activos (catálogo da plataforma); todos os planos só para super-administradores',
        'GET api/licenca/suporte' => 'suporte da empresa: qualquer utilizador autenticado pode pedir ajuda (ver /minha-conta/suporte, reservado ao administrador)',
        'POST api/licenca/suporte' => 'suporte da empresa: qualquer utilizador autenticado pode abrir um pedido',
        'GET api/sistema/notificacoes' => 'notificações e alertas da empresa activa: itens filtrados por permissão no serviço',
        'POST api/sistema/notificacoes/{id}/ler' => 'marcar notificação como lida da empresa activa',
        'POST api/sistema/notificacoes/ler-todas' => 'marcar todas as notificações como lidas da empresa activa',
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
            foreach (array_diff($r->methods(), ['HEAD', 'OPTIONS']) as $metodo) {
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

    /**
     * Rotas SEM autenticação, por desenho — lista branca explícita e justificada. Uma rota pública nova só entra aqui com
     * motivo escrito (e revisão): é isto que faz o varrimento falhar quando alguém abre uma rota por engano.
     */
    private const PUBLICAS = [
        'POST api/autenticacao/entrar' => 'início de sessão (limite «entrar» por utilizador e por IP)',
        'POST api/autenticacao/recuperar-password' => 'recuperação de password (6/min; resposta neutra, não enumera contas)',
        'POST api/autenticacao/registar-empresa' => 'registo de um novo cliente (6/min)',
        'POST api/plataforma-inicial/criar-admin' => 'primeiro acesso: só funciona enquanto não existe administrador da plataforma',
        'GET api/plataforma-inicial/estado' => 'diz se o primeiro acesso ainda está disponível',
        'GET api/saude' => 'sonda de saúde (sem dados)',
        'GET api/sistema/definicoes-publicas' => 'nome e marca da plataforma para o ecrã de entrada',
        'GET api/sistema/dominio-permitido' => 'o domínio actual pode usar o sistema? (ecrã de entrada)',
        'GET api/sistema/logotipo-login' => 'logotipo do ecrã de entrada',
        'GET api/sistema/planos-publicos' => 'planos publicados na página de preços',
        'POST api/agt/webhook/{token}' => 'webhook da AGT: token secreto no caminho (60/min)',
        'POST api/pagamentos/webhook/{gateway}' => 'webhook de pagamentos: assinatura do corpo validada pelo processador',
        'GET api/bi/odata/{conjunto?}' => 'feed Power BI: token de leitura próprio (tokens_bi), 120 pedidos/min por token',
        'GET auth/google/redirecionar' => 'início do login com Google (6/min)',
        'GET auth/google/callback' => 'regresso do login com Google (6/min; estado validado)',
        'GET sanctum/csrf-cookie' => 'cookie CSRF do Sanctum',
        'GET up' => 'sonda de saúde do Laravel',
    ];

    /** Autenticadas, mas SEM empresa activa por desenho (dados da própria sessão/conta). "MÉTODO uri" => motivo */
    private const SO_AUTENTICADAS = [
        'GET api/autenticacao/eu' => 'o próprio utilizador e as suas empresas',
        'GET api/autenticacao/dados-pessoais' => 'dados do próprio',
        'PUT api/autenticacao/dados-pessoais' => 'dados do próprio (validados; sem papel/perfil)',
        'PUT api/autenticacao/palavra-passe' => 'password do próprio (exige a actual; limite «palavra-passe»)',
        'POST api/autenticacao/sair' => 'terminar a própria sessão',
        'POST api/autenticacao/mfa/iniciar' => 'MFA do próprio',
        'POST api/autenticacao/mfa/confirmar' => 'MFA do próprio',
        'POST api/autenticacao/mfa/desactivar' => 'MFA do próprio',
        'POST api/autenticacao/mfa/verificar' => 'MFA do próprio (limite «entrar»)',
        'GET api/sistema/empresas' => 'selector: só as empresas acessíveis ao utilizador (ServicoEmpresas::acessiveis)',
        'GET api/sistema/empresas/{empresa}' => 'selector: 404 sem acesso (podeAceder)',
    ];

    /** Middlewares de área que, por si, restringem a rota (negar por omissão). */
    private const AREAS = ['plataforma', 'suporte', 'admin_cliente'];

    #[Test]
    public function toda_a_rota_esta_classificada_publica_so_autenticada_de_area_ou_com_empresa(): void
    {
        $todas = [];
        $erros = [];
        foreach (Rotas::getRoutes()->getRoutes() as $r) {
            $mw = $r->gatherMiddleware();
            $autenticada = (bool) array_filter($mw, fn ($m) => is_string($m) && (str_contains($m, 'Authenticate') || str_starts_with($m, 'auth')));
            $comEmpresa = in_array('empresa', $mw, true);
            $deArea = array_intersect(self::AREAS, $mw) !== [] || (bool) array_filter($mw, fn ($m) => is_string($m) && preg_match('/Exigir(Plataforma|Suporte|AdministradorCliente)$/', $m));
            foreach (array_diff($r->methods(), ['HEAD', 'OPTIONS']) as $metodo) {
                $id = "{$metodo} {$r->uri()}";
                $todas[$id] = true;
                if (! $autenticada) {
                    if (! isset(self::PUBLICAS[$id])) {
                        $erros[] = "{$id}: rota SEM autenticação fora da lista PUBLICAS";
                    }
                } elseif (! $comEmpresa && ! $deArea && ! isset(self::SO_AUTENTICADAS[$id])) {
                    $erros[] = "{$id}: autenticada sem empresa nem área restrita fora da lista SO_AUTENTICADAS";
                }
            }
        }
        $this->assertSame([], $erros, 'Rotas por classificar (autorização)');

        // listas brancas sem entradas mortas: uma rota removida/renomeada tem de sair das listas
        foreach (array_merge(array_keys(self::PUBLICAS), array_keys(self::SO_AUTENTICADAS), array_keys(self::SEM_PERMISSAO_POR_DESENHO)) as $id) {
            $this->assertArrayHasKey($id, $todas, "entrada obsoleta nas listas brancas: {$id}");
        }
    }
}
