import { Alert, Button, Card, Divider, Flex, Form, Input, Typography } from 'antd';
import { LockOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { ErroApi } from '@/api/tipos';

/**
 * Login único do backoffice (plataforma + suporte): só autentica Super Administradores e Agentes de Suporte.
 * Qualquer outro utilizador é terminado de imediato e reencaminhado para o login normal do sistema (ADR-076).
 */
export function PlataformaLogin() {
  const { entrar, sair, utilizador } = useSessao();
  const navigate = useNavigate();
  const [erro, setErro] = useState<string | null>(null);
  const [aEntrar, setAEntrar] = useState(false);
  const logotipo = useQuery({ queryKey: ['logotipo-login'], queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'), retry: false });

  // Se já tem sessão aberta com acesso ao backoffice, encaminha directo para a sua área
  if (utilizador?.papel === 'SUPER_ADMINISTRADOR') {
    navigate('/admin', { replace: true });
  } else if (utilizador?.papel === 'AGENTE_SUPORTE') {
    navigate('/suporte', { replace: true });
  }

  const submeter = async (v: { nome_utilizador: string; palavra_passe: string }) => {
    setErro(null);
    setAEntrar(true);
    try {
      const u = await entrar(v.nome_utilizador, v.palavra_passe);
      if (u.papel === 'SUPER_ADMINISTRADOR') {
        navigate('/admin', { replace: true });
      } else if (u.papel === 'AGENTE_SUPORTE') {
        navigate('/suporte', { replace: true });
      } else {
        await sair('Acesso não permitido. Esta área é reservada à administração da plataforma e à equipa de suporte — entre com a sua conta normal.');
        navigate('/entrar', { replace: true });
      }
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Falha ao autenticar.');
    } finally {
      setAEntrar(false);
    }
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada">
      <Card className="erp-cartao-entrada" style={{ width: '100%', maxWidth: 440 }} styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24, textAlign: 'center' }}>
          {logotipo.data?.logotipo ? <img src={logotipo.data.logotipo} alt="Logótipo" style={{ maxHeight: 90, maxWidth: 'min(220px, 100%)', objectFit: 'contain', marginBottom: 8 }} /> : null}
          <h1 className="erp-entrada-titulo">Central de Backoffice</h1>
          <Typography.Text type="secondary">Acesso restrito a pessoal autorizado</Typography.Text>
        </Flex>

        {erro && <Alert type="error" showIcon message={erro} style={{ marginBottom: 16 }} />}

        <Form layout="vertical" onFinish={submeter} requiredMark={false}>
          <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true, message: 'Indique o utilizador.' }]}>
            <Input size="large" prefix={<UserOutlined />} autoComplete="username" autoFocus />
          </Form.Item>
          <Form.Item name="palavra_passe" label="Palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }]}>
            <Input.Password size="large" prefix={<LockOutlined />} autoComplete="current-password" />
          </Form.Item>
          <Button type="primary" htmlType="submit" block size="large" loading={aEntrar} style={{ fontWeight: 700 }}>
            Entrar
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
