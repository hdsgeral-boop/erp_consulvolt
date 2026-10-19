import { Alert, Button, Card, Flex, Form, Input, Typography } from 'antd';
import { LockOutlined, SafetyCertificateOutlined, UserOutlined } from '@ant-design/icons';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { ErroApi } from '@/api/tipos';

/** Login dedicado para Super Administradores da plataforma SaaS */
export function PlataformaLogin() {
  const { entrar, utilizador } = useSessao();
  const navigate = useNavigate();
  const [erro, setErro] = useState<string | null>(null);
  const [aEntrar, setAEntrar] = useState(false);

  // Se já tem sessão aberta como Super Admin, encaminha directo
  if (utilizador?.papel === 'SUPER_ADMINISTRADOR') {
    navigate('/admin', { replace: true });
  }

  const submeter = async (v: { nome_utilizador: string; palavra_passe: string }) => {
    setErro(null);
    setAEntrar(true);
    try {
      await entrar(v.nome_utilizador, v.palavra_passe);
      // O estado é actualizado, agora verifica o papel
      navigate('/admin', { replace: true });
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Falha ao autenticar na consola de administração.');
    } finally {
      setAEntrar(false);
    }
  };

  return (
    <div style={{
      minHeight: '100vh',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      background: 'linear-gradient(135deg, #071e33 0%, #0c2a47 50%, #001e3d 100%)',
      padding: 24,
      fontFamily: "'Inter', sans-serif"
    }}>
      <Card
        style={{
          width: '100%',
          maxWidth: 440,
          borderRadius: 20,
          boxShadow: '0 25px 60px rgba(0, 0, 0, 0.45)',
          border: '1px solid rgba(255, 255, 255, 0.1)',
          background: 'rgba(255, 255, 255, 0.98)',
          backdropFilter: 'blur(16px)'
        }}
        styles={{ body: { padding: '40px 32px' } }}
      >
        <Flex vertical align="center" gap={8} style={{ marginBottom: 28, textAlign: 'center' }}>
          <div style={{
            width: 56,
            height: 56,
            borderRadius: 16,
            background: 'linear-gradient(135deg, #0052ff 0%, #0036a8 100%)',
            color: '#ffffff',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontSize: 28,
            boxShadow: '0 8px 20px rgba(0, 82, 255, 0.35)',
            marginBottom: 8
          }}>
            <SafetyCertificateOutlined />
          </div>
          <h1 style={{ fontSize: 22, fontWeight: 800, margin: 0, color: '#0f172a', letterSpacing: '-0.02em' }}>
            Consola da Plataforma
          </h1>
          <Typography.Text type="secondary" style={{ fontSize: 13 }}>
            Acesso restrito a Super Administradores SaaS
          </Typography.Text>
        </Flex>

        {erro && <Alert type="error" showIcon message={erro} style={{ marginBottom: 20, borderRadius: 10 }} />}

        <Form layout="vertical" onFinish={submeter} requiredMark={false}>
          <Form.Item
            name="nome_utilizador"
            label={<span style={{ fontWeight: 600, fontSize: 13 }}>Utilizador Administrador</span>}
            rules={[{ required: true, message: 'Indique o utilizador.' }]}
          >
            <Input size="large" prefix={<UserOutlined style={{ color: '#0052ff' }} />} autoFocus placeholder="admin" style={{ borderRadius: 10 }} />
          </Form.Item>

          <Form.Item
            name="palavra_passe"
            label={<span style={{ fontWeight: 600, fontSize: 13 }}>Chave Secreta / Palavra-passe</span>}
            rules={[{ required: true, message: 'Indique a palavra-passe.' }]}
          >
            <Input.Password size="large" prefix={<LockOutlined style={{ color: '#0052ff' }} />} placeholder="••••••••••••" style={{ borderRadius: 10 }} />
          </Form.Item>

          <Button
            type="primary"
            htmlType="submit"
            block
            size="large"
            loading={aEntrar}
            style={{
              marginTop: 12,
              height: 46,
              borderRadius: 10,
              fontWeight: 700,
              fontSize: 15,
              background: '#0052ff',
              borderColor: '#0052ff',
              boxShadow: '0 4px 14px rgba(0, 82, 255, 0.35)'
            }}
          >
            Entrar na Consola
          </Button>

          <div style={{ marginTop: 24, textAlign: 'center', fontSize: 13, color: '#64748b' }}>
            <Link to="/" style={{ color: '#64748b', textDecoration: 'none' }}>
              ← Voltar ao site público
            </Link>
            <span style={{ margin: '0 8px' }}>·</span>
            <Link to="/entrar" style={{ color: '#0052ff', fontWeight: 600, textDecoration: 'none' }}>
              Login de Empresa
            </Link>
          </div>
        </Form>
      </Card>
    </div>
  );
}
