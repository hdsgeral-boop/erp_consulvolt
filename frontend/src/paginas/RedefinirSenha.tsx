import { Alert, Button, Card, Flex, Form, Input, Typography } from 'antd';
import { LockOutlined } from '@ant-design/icons';
import { useState } from 'react';
import { enviar } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { RodapeEntrada } from '@/paginas/RodapeEntrada';

/** Página aberta pela ligação do e-mail de recuperação: /redefinir-senha?token=... */
export function RedefinirSenha() {
  const token = new URLSearchParams(window.location.search).get('token') ?? '';
  const [erro, setErro] = useState<string | null>(null);
  const [concluido, setConcluido] = useState(false);
  const [aGuardar, setAGuardar] = useState(false);

  const submeter = async (v: { palavra_passe: string; palavra_passe_confirmation: string }) => {
    setErro(null);
    setAGuardar(true);
    try {
      await enviar('post', '/autenticacao/redefinir-senha', { token, ...v });
      setConcluido(true);
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Não foi possível redefinir a palavra-passe.');
    } finally {
      setAGuardar(false);
    }
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada" style={{ paddingBottom: 64 }}>
      <Card className="erp-cartao-entrada" style={{ width: '100%', maxWidth: 440 }} styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24, textAlign: 'center' }}>
          <h1 className="erp-entrada-titulo">Nova palavra-passe</h1>
          <Typography.Text type="secondary">Escolha a nova palavra-passe da sua conta</Typography.Text>
        </Flex>
        {!token && <Alert type="error" showIcon message="Ligação inválida. Peça uma nova recuperação de senha." style={{ marginBottom: 16 }} />}
        {erro && <Alert type="error" showIcon message={erro} style={{ marginBottom: 16 }} />}
        {concluido ? (
          <>
            <Alert type="success" showIcon message="Palavra-passe redefinida. Já pode entrar com a nova palavra-passe." style={{ marginBottom: 16 }} />
            <Button type="primary" block size="large" href="/" style={{ fontWeight: 700 }}>
              Ir para o login
            </Button>
          </>
        ) : (
          <Form layout="vertical" onFinish={submeter} requiredMark={false} disabled={!token}>
            <Form.Item name="palavra_passe" label="Nova palavra-passe" rules={[{ required: true, message: 'Indique a nova palavra-passe.' }, { min: 8, message: 'Use pelo menos 8 caracteres.' }]}>
              <Input.Password size="large" prefix={<LockOutlined />} autoComplete="new-password" autoFocus />
            </Form.Item>
            <Form.Item
              name="palavra_passe_confirmation"
              label="Confirmar palavra-passe"
              dependencies={['palavra_passe']}
              rules={[
                { required: true, message: 'Confirme a palavra-passe.' },
                ({ getFieldValue }) => ({
                  validator: (_, valor) => (!valor || getFieldValue('palavra_passe') === valor ? Promise.resolve() : Promise.reject(new Error('As palavras-passe não coincidem.'))),
                }),
              ]}
            >
              <Input.Password size="large" prefix={<LockOutlined />} autoComplete="new-password" />
            </Form.Item>
            <Button type="primary" htmlType="submit" block size="large" loading={aGuardar} style={{ marginTop: 8, fontWeight: 700 }}>
              Redefinir palavra-passe
            </Button>
          </Form>
        )}
      </Card>
      <RodapeEntrada />
    </main>
  );
}
