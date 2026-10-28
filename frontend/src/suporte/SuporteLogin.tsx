import { Alert, Button, Card, Divider, Flex, Form, Input, Typography } from 'antd';
import { CustomerServiceOutlined, LockOutlined, UserOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { ErroApi } from '@/api/tipos';

const ELEGIVEL = (papel: string | null | undefined) => papel === 'AGENTE_SUPORTE' || papel === 'SUPER_ADMINISTRADOR';

/**
 * Login dedicado ao backoffice de suporte (ADR-076) — só agentes de suporte e super-administradores. Um utilizador
 * comum do ERP que tente entrar aqui com as suas credenciais válidas autentica normalmente (é a mesma conta), mas é
 * imediatamente desligado outra vez, com um motivo claro — nunca fica "preso" num estado autenticado fora do sítio.
 */
export function SuporteLogin() {
  const { entrar, sair, utilizador, motivoSaida } = useSessao();
  const navigate = useNavigate();
  const [erro, setErro] = useState<string | null>(null);
  const [aEntrar, setAEntrar] = useState(false);

  useEffect(() => {
    if (ELEGIVEL(utilizador?.papel)) navigate('/suporte', { replace: true });
  }, [utilizador, navigate]);

  const submeter = async (v: { nome_utilizador: string; palavra_passe: string }) => {
    setErro(null);
    setAEntrar(true);
    try {
      const u = await entrar(v.nome_utilizador, v.palavra_passe);
      if (!ELEGIVEL(u.papel)) {
        await sair('Este acesso é reservado à equipa de suporte da plataforma. Entre pela página normal do ERP.');
        return;
      }
      navigate('/suporte', { replace: true });
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Falha ao autenticar no backoffice de suporte.');
    } finally {
      setAEntrar(false);
    }
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada">
      <Card className="erp-cartao-entrada" style={{ width: '100%', maxWidth: 440 }} styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24, textAlign: 'center' }}>
          <div
            style={{
              width: 52,
              height: 52,
              borderRadius: 14,
              background: 'linear-gradient(135deg, #1947b3 0%, #0f172a 100%)',
              color: '#ffffff',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 26,
              marginBottom: 4,
            }}
          >
            <CustomerServiceOutlined />
          </div>
          <h1 className="erp-entrada-titulo">Central de Suporte</h1>
          <Typography.Text type="secondary">Acesso restrito à equipa de suporte Consulvolt</Typography.Text>
        </Flex>

        {motivoSaida && <Alert type="info" showIcon message={motivoSaida} style={{ marginBottom: 16 }} />}
        {erro && <Alert type="error" showIcon message={erro} style={{ marginBottom: 16 }} />}

        <Form layout="vertical" onFinish={submeter} requiredMark={false}>
          <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true, message: 'Indique o utilizador.' }]}>
            <Input size="large" prefix={<UserOutlined />} autoComplete="username" autoFocus placeholder="agente" />
          </Form.Item>
          <Form.Item name="palavra_passe" label="Palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }]}>
            <Input.Password size="large" prefix={<LockOutlined />} autoComplete="current-password" />
          </Form.Item>
          <Button type="primary" htmlType="submit" block size="large" loading={aEntrar} style={{ fontWeight: 700 }}>
            Entrar na Central de Suporte
          </Button>
        </Form>

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
