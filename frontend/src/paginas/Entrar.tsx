import { Alert, Button, Card, Divider, Flex, Form, Input, Typography } from 'antd';
import { LockOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { ErroApi } from '@/api/tipos';
import { naRotaPortal } from '@/portal/rota';

/** Entrada no sistema, com o aspecto do sistema anterior: fotografia de fundo e cartão translúcido ao centro. */
export function Entrar() {
  const { entrar, motivoSaida } = useSessao();
  const navegar = useNavigate();
  const [erro, setErro] = useState<string | null>(null);
  const [aEntrar, setAEntrar] = useState(false);
  const portal = naRotaPortal();
  const logotipo = useQuery({ queryKey: ['logotipo-login'], queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'), retry: false });

  const submeter = async (v: { nome_utilizador: string; palavra_passe: string }) => {
    setErro(null);
    setAEntrar(true);
    try {
      const u = await entrar(v.nome_utilizador, v.palavra_passe);
      // só este login explícito (a seguir a um logout) cai na consola da plataforma; trocar de empresa a meio da
      // sessão (LayoutPrincipal) nunca mais passa por aqui — fica sempre dentro do ERP (Destino em App.tsx).
      if (u.papel === 'SUPER_ADMINISTRADOR') navegar('/admin');
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
        {motivoSaida && <Alert type="info" showIcon message={motivoSaida} style={{ marginBottom: 16 }} />}
        {erro && <Alert type="error" showIcon message={erro} style={{ marginBottom: 16 }} />}
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
