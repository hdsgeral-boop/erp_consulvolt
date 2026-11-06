import { Alert, Button, Card, Divider, Flex, Form, Input, Typography } from 'antd';
import { LockOutlined, MailOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { http, obter } from '@/api/cliente';
import { LogotipoDoSistema } from '@/componentes/LogotipoSistema';
import { useSessao } from '@/sessao/SessaoContexto';
import { PassoMfa } from '@/paginas/PassoMfa';
import { useEntrada } from '@/paginas/useEntrada';
import { ErroApi } from '@/api/tipos';
import { podeBackofficeSuporte, podeConsolaPlataforma } from '@/sessao/areas';

/**
 * Login único do backoffice (plataforma + suporte): só autentica Super Administradores e Agentes de Suporte.
 * Qualquer outro utilizador é terminado de imediato e reencaminhado para o login normal do sistema (ADR-076).
 */
export function PlataformaLogin() {
  const { sair, utilizador } = useSessao();
  const navigate = useNavigate();
  const { erro, setErro, aEntrar: aEntrarComSenha, passoMfa, submeter: entrarComSenha, submeterCodigo, cancelarMfa } = useEntrada('Falha ao autenticar.');
  const [aCriar, setACriar] = useState(false);
  const aEntrar = aEntrarComSenha || aCriar;
  const [criado, setCriado] = useState<string | null>(null);
  // primeiro acesso: enquanto a plataforma não tem administrador, o servidor (só neste host) permite criar o primeiro
  const inicial = useQuery({
    queryKey: ['plataforma-inicial-estado'],
    queryFn: () => obter<{ precisa_criar_admin: boolean; exige_segredo: boolean }>('/plataforma-inicial/estado'),
    retry: false,
  });
  const precisaCriarAdmin = inicial.data?.precisa_criar_admin === true && criado === null;

  // Se já tem sessão aberta com acesso ao backoffice, encaminha directo para a sua área
  if (podeConsolaPlataforma(utilizador)) {
    navigate('/admin', { replace: true });
  } else if (utilizador?.papel === 'AGENTE_SUPORTE' && podeBackofficeSuporte(utilizador)) {
    navigate('/suporte', { replace: true });
  }

  const depoisDeEntrar = async (u: Awaited<ReturnType<typeof entrarComSenha>>) => {
    if (!u) return;
    if (podeConsolaPlataforma(u)) {
      navigate('/admin', { replace: true });
    } else if (u.papel === 'AGENTE_SUPORTE' && podeBackofficeSuporte(u)) {
      navigate('/suporte', { replace: true });
    } else {
      await sair('Acesso não permitido. Esta área é reservada à administração da plataforma e à equipa de suporte — entre com a sua conta normal.');
      navigate('/entrar', { replace: true });
    }
  };
  const submeter = async (v: { nome_utilizador: string; palavra_passe: string }) => depoisDeEntrar(await entrarComSenha(v.nome_utilizador, v.palavra_passe));
  const submeterCodigoMfa = async (codigo: string) => depoisDeEntrar(await submeterCodigo(codigo));

  /** A palavra-passe provisória é gerada pelo servidor e enviada para o e-mail indicado; a troca é obrigatória no primeiro acesso. */
  const criarAdmin = async (v: { nome_completo: string; nome_utilizador: string; email: string; segredo?: string }) => {
    setErro(null);
    setACriar(true);
    try {
      const { segredo, ...corpo } = v;
      const r = await http.post<{ mensagem?: string }>('/plataforma-inicial/criar-admin', corpo, segredo ? { headers: { 'X-Setup-Token': segredo } } : undefined);
      setCriado(r.data?.mensagem || 'Administrador criado. A palavra-passe provisória foi enviada para o e-mail indicado.');
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Não foi possível criar o administrador.');
    } finally {
      setACriar(false);
    }
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada">
      <Card className="erp-cartao-entrada" style={{ width: '100%', maxWidth: 440 }} styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24, textAlign: 'center' }}>
          <LogotipoDoSistema tamanho={72} />
          <h1 className="erp-entrada-titulo">Central de Backoffice</h1>
          <Typography.Text type="secondary">{precisaCriarAdmin ? 'Criar o administrador da plataforma' : 'Acesso restrito a pessoal autorizado'}</Typography.Text>
        </Flex>

        {erro && <Alert type="error" showIcon title={erro} style={{ marginBottom: 16 }} />}
        {criado && <Alert type="success" showIcon title={criado} style={{ marginBottom: 16 }} />}

        {precisaCriarAdmin ? (
          <Form layout="vertical" onFinish={criarAdmin} requiredMark={false}>
            <Alert type="info" showIcon title="A plataforma ainda não tem administrador. Crie o primeiro: a palavra-passe provisória é enviada para o e-mail indicado e tem de ser trocada no primeiro acesso." style={{ marginBottom: 16 }} />
            <Form.Item name="nome_completo" label="Nome completo" rules={[{ required: true, message: 'Indique o nome completo.' }]}>
              <Input size="large" prefix={<UserOutlined />} autoComplete="name" maxLength={200} autoFocus />
            </Form.Item>
            <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true, message: 'Indique o utilizador.' }]}>
              <Input size="large" prefix={<UserOutlined />} autoComplete="username" maxLength={100} />
            </Form.Item>
            <Form.Item name="email" label="E-mail" rules={[{ required: true, message: 'Indique o e-mail.' }, { type: 'email', message: 'E-mail inválido.' }]}>
              <Input size="large" prefix={<MailOutlined />} autoComplete="email" maxLength={200} />
            </Form.Item>
            {inicial.data?.exige_segredo && (
              <Form.Item name="segredo" label="Código de instalação" rules={[{ required: true, message: 'Indique o código de instalação.' }]}>
                <Input.Password size="large" autoComplete="off" />
              </Form.Item>
            )}
            <Button type="primary" htmlType="submit" block size="large" loading={aEntrar} style={{ marginTop: 8, fontWeight: 700 }}>
              Criar administrador
            </Button>
          </Form>
        ) : (
        passoMfa ? (
          <PassoMfa aEnviar={aEntrar} onSubmeter={(c) => void submeterCodigoMfa(c)} onCancelar={cancelarMfa} />
        ) : (
        <Form layout="vertical" onFinish={(v) => void submeter(v)} requiredMark={false} aria-label="Entrar na central de backoffice">
          <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true, whitespace: true, message: 'Indique o utilizador ou o e-mail.' }, { max: 150, message: 'Máximo de 150 caracteres.' }]}>
            <Input size="large" prefix={<UserOutlined />} autoComplete="username" autoCapitalize="none" autoCorrect="off" spellCheck={false} maxLength={150} autoFocus />
          </Form.Item>
          <Form.Item name="palavra_passe" label="Palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }, { max: 255, message: 'Máximo de 255 caracteres.' }]}>
            <Input.Password size="large" prefix={<LockOutlined />} autoComplete="current-password" maxLength={255} />
          </Form.Item>
          <Button type="primary" htmlType="submit" block size="large" loading={aEntrar} style={{ fontWeight: 700 }}>
            Entrar
          </Button>
        </Form>
        )
        )}

        <Divider style={{ margin: '20px 0 16px' }} />

        <Flex vertical align="center" gap={10}>
          <Link to="/" className="erp-link-voltar">
            Voltar ao site público
          </Link>
        </Flex>
      </Card>
    </main>
  );
}
