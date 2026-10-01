import { Alert, Button, Card, Flex, Form, Input, Typography } from 'antd';
import { LockOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { ErroApi } from '@/api/tipos';

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
    <Flex align="center" justify="center" style={{ minHeight: '100vh', background: '#f0f2f5', padding: 16 }}>
      <Card style={{ width: 380 }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24 }}>
          {logotipo.data?.logotipo ? <img src={logotipo.data.logotipo} alt="Logótipo" style={{ maxHeight: 72, maxWidth: 220 }} /> : null}
          <Typography.Title level={3} style={{ margin: 0 }}>
            ERP Consulvolt
          </Typography.Title>
          <Typography.Text type="secondary">Entre com o seu utilizador</Typography.Text>
        </Flex>
        {motivoSaida && <Alert type="info" showIcon message={motivoSaida} style={{ marginBottom: 16 }} />}
        {erro && <Alert type="error" showIcon message={erro} style={{ marginBottom: 16 }} />}
        <Form layout="vertical" onFinish={submeter} requiredMark={false}>
          <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true, message: 'Indique o utilizador.' }]}>
            <Input prefix={<UserOutlined />} autoComplete="username" autoFocus />
          </Form.Item>
          <Form.Item name="palavra_passe" label="Palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }]}>
            <Input.Password prefix={<LockOutlined />} autoComplete="current-password" />
          </Form.Item>
          <Button type="primary" htmlType="submit" block loading={aEntrar}>
            Entrar
          </Button>
        </Form>
      </Card>
    </Flex>
  );
}
