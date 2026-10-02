import { Alert, Button, Card, Flex, Form, Input, Typography } from 'antd';
import { LockOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { ErroApi } from '@/api/tipos';

/** Entrada no sistema, com o aspecto do sistema anterior: fotografia de fundo e cartão translúcido ao centro. */
export function Entrar() {
  const { entrar, motivoSaida } = useSessao();
  const [erro, setErro] = useState<string | null>(null);
  const [aEntrar, setAEntrar] = useState(false);
  const logotipo = useQuery({ queryKey: ['logotipo-login'], queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'), retry: false });

  const submeter = async (v: { nome_utilizador: string; palavra_passe: string }) => {
    setErro(null);
    setAEntrar(true);
    try {
      await entrar(v.nome_utilizador, v.palavra_passe);
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
          <h1 className="erp-entrada-titulo">ERP Consulvolt</h1>
          <Typography.Text type="secondary">Aceda à sua conta para continuar</Typography.Text>
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
          <Button type="primary" htmlType="submit" block size="large" loading={aEntrar} style={{ marginTop: 8, fontWeight: 700 }}>
            Entrar
          </Button>
        </Form>
      </Card>
    </main>
  );
}
