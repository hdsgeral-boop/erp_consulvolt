import { Alert, Button, Card, Divider, Flex, Form, Input, Typography } from 'antd';
import { LockOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { ErroApi } from '@/api/tipos';
import { naRotaPortal } from '@/portal/rota';

/** Mensagens dos erros que o login Google devolve por redirect (AutenticacaoGoogleController) — nunca por AJAX. */
const ERROS_GOOGLE: Record<string, string> = {
  google_falhou: 'Não foi possível entrar com o Google. Tente novamente.',
  google_conta_inactiva: 'Esta conta está inactiva. Contacte o administrador da empresa.',
  google_mfa_obrigatorio: 'Esta conta tem autenticação de dois factores activa; entre com o utilizador e a palavra-passe.',
  google_conta_ja_ligada: 'Este email já está associado a outra conta Google. Contacte o suporte.',
};

function IconeGoogle() {
  return (
    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
      <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.9c1.7-1.57 2.7-3.88 2.7-6.62Z" />
      <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.9-2.26c-.8.54-1.84.86-3.06.86-2.35 0-4.34-1.59-5.05-3.72H.95v2.33A9 9 0 0 0 9 18Z" />
      <path fill="#FBBC05" d="M3.95 10.7a5.4 5.4 0 0 1 0-3.4V4.97H.95a9 9 0 0 0 0 8.06l3-2.33Z" />
      <path fill="#EA4335" d="M9 3.58c1.32 0 2.51.46 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .95 4.97l3 2.33C4.66 5.17 6.65 3.58 9 3.58Z" />
    </svg>
  );
}

/** Entrada no sistema, com o aspecto do sistema anterior: fotografia de fundo e cartão translúcido ao centro. */
export function Entrar() {
  const { entrar, motivoSaida } = useSessao();
  const navegar = useNavigate();
  const [parametros, definirParametros] = useSearchParams();
  const [erro, setErro] = useState<string | null>(null);
  const [aEntrar, setAEntrar] = useState(false);
  const portal = naRotaPortal();
  const logotipo = useQuery({ queryKey: ['logotipo-login'], queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'), retry: false });

  // Erro vindo do redirect do login Google (nunca por AJAX — ver AutenticacaoGoogleController): mostra-o uma vez
  // e limpa a query string, para um recarregar da página não voltar a mostrar o mesmo erro.
  useEffect(() => {
    const codigo = parametros.get('erro');
    if (codigo && ERROS_GOOGLE[codigo]) {
      setErro(ERROS_GOOGLE[codigo]);
      definirParametros({}, { replace: true });
    }
  }, [parametros, definirParametros]);

  const entrarComGoogle = () => {
    window.location.href = '/auth/google/redirecionar';
  };

  const submeter = async (v: { nome_utilizador: string; palavra_passe: string }) => {
    setErro(null);
    setAEntrar(true);
    try {
      const u = await entrar(v.nome_utilizador, v.palavra_passe);
      // só este login explícito (a seguir a um logout) cai na consola da plataforma; trocar de empresa a meio da
      // sessão (LayoutPrincipal) nunca mais passa por aqui — fica sempre dentro do ERP (Destino em App.tsx). Quem
      // entra pelo Portal nunca sai dele, nem um super-administrador (ADR-071: o Portal é o único destino possível).
      if (u.papel === 'SUPER_ADMINISTRADOR' && !portal) navegar('/admin');
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Não foi possível entrar.');
    } finally {
      setAEntrar(false);
    }
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada">
      <Card className="erp-cartao-entrada" style={{ width: '100%', maxWidth: 440 }} styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24, textAlign: 'center' }}>
          {logotipo.data?.logotipo ? <img src={logotipo.data.logotipo} alt="Logótipo" style={{ maxHeight: 110, maxWidth: 'min(240px, 100%)', objectFit: 'contain', marginBottom: 8 }} /> : null}
          <h1 className="erp-entrada-titulo">{portal ? 'Portal do Colaborador' : 'ERP Consulvolt'}</h1>
          <Typography.Text type="secondary">{portal ? 'Entre com o seu utilizador para aceder ao portal' : 'Aceda à sua conta para continuar'}</Typography.Text>
        </Flex>
        {motivoSaida && <Alert type="info" showIcon title={motivoSaida} style={{ marginBottom: 16 }} />}
        {erro && <Alert type="error" showIcon title={erro} style={{ marginBottom: 16 }} />}
        <Form layout="vertical" onFinish={submeter} requiredMark={false}>
          <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true, message: 'Indique o utilizador.' }]}>
            <Input size="large" prefix={<UserOutlined />} autoComplete="username" autoFocus />
          </Form.Item>
          <Form.Item name="palavra_passe" label="Palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }]}>
            <Input.Password size="large" prefix={<LockOutlined />} autoComplete="current-password" />
          </Form.Item>
          <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: -6, marginBottom: 16 }}>
            <Link to="/recuperar-password" style={{ fontSize: 13, color: '#b91c1c', fontWeight: 600 }}>
              Esqueceu-se da palavra-passe?
            </Link>
          </div>
          <Button type="primary" htmlType="submit" block size="large" loading={aEntrar} style={{ fontWeight: 700 }}>
            Entrar
          </Button>
        </Form>

        {!portal && (
          <>
            <Divider style={{ margin: '20px 0 12px' }} plain>
              ou
            </Divider>
            <Button block size="large" icon={<IconeGoogle />} onClick={entrarComGoogle} style={{ marginBottom: 4, fontWeight: 600 }}>
              Entrar com o Google
            </Button>
          </>
        )}

        <Divider style={{ margin: '20px 0 16px' }} />

        <Flex vertical align="center" gap={10}>
          <div style={{ fontSize: 14, color: '#475569' }}>
            Ainda não tem conta?{' '}
            <Link to="/registar" style={{ fontWeight: 700, color: '#b91c1c' }}>
              Criar conta grátis
            </Link>
          </div>
          <Link to="/" className="erp-link-voltar">
            Voltar ao site
          </Link>
        </Flex>
      </Card>
    </main>
  );
}
