import { useRef, useState } from 'react';
import { Link } from 'react-router';
import { Alert, Button, Card, Divider, Flex, Form, Input, Typography } from 'antd';
import { UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { RodapeEntrada } from '@/paginas/RodapeEntrada';

/** Ecrã para recuperação de palavra-passe com o mesmo aspecto visual do login. */
export function RecuperarPassword() {
  const [erro, setErro] = useState<string | null>(null);
  const [sucesso, setSucesso] = useState<string | null>(null);
  const [aEnviar, setAEnviar] = useState(false);
  const emCurso = useRef(false);

  const logotipo = useQuery({
    queryKey: ['logotipo-login'],
    queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'),
    retry: false
  });

  const submeter = async (v: { identificador: string }) => {
    if (emCurso.current) return;   // duplo clique / Enter repetido: um pedido de cada vez (cada pedido gera um e-mail)
    emCurso.current = true;
    setErro(null);
    setSucesso(null);
    setAEnviar(true);
    try {
      v = { identificador: v.identificador.trim() };
      const res = await enviar<{ processado: boolean; mensagem: string }>(
        'post',
        '/autenticacao/recuperar-password',
        v
      );
      setSucesso(
        res.dados?.mensagem ||
          'Se os dados introduzidos corresponderem a uma conta ativa, as instruções de recuperação foram processadas.'
      );
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Não foi possível processar a recuperação.');
    } finally {
      emCurso.current = false;
      setAEnviar(false);
    }
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada" style={{ paddingBottom: 64 }}>
      <Card
        className="erp-cartao-entrada"
        style={{ width: '100%', maxWidth: 440 }}
        styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}
      >
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24, textAlign: 'center' }}>
          {logotipo.data?.logotipo ? (
            <img
              src={logotipo.data.logotipo}
              alt="Logótipo Consulvolt"
              style={{ maxHeight: 110, maxWidth: 'min(240px, 100%)', objectFit: 'contain', marginBottom: 8 }}
            />
          ) : null}
          <h1 className="erp-entrada-titulo">Recuperar Palavra-passe</h1>
          <Typography.Text type="secondary">
            Introduza o seu utilizador ou e-mail para recuperar o acesso
          </Typography.Text>
        </Flex>

        {erro && <Alert type="error" showIcon title={erro} style={{ marginBottom: 16 }} />}

        {sucesso ? (
          <div style={{ textAlign: 'center' }}>
            <Alert
              type="success"
              showIcon
              title="Pedido registado com sucesso"
              description={sucesso}
              style={{ marginBottom: 24, textAlign: 'left' }}
            />
            <Link to="/entrar">
              <Button type="primary" block size="large" style={{ fontWeight: 700 }}>
                Voltar ao Login
              </Button>
            </Link>
          </div>
        ) : (
          <Form layout="vertical" onFinish={submeter} requiredMark={false} aria-label="Recuperar a palavra-passe">
            <Form.Item
              name="identificador"
              label="Utilizador ou E-mail registado"
              rules={[
                { required: true, whitespace: true, message: 'Indique o nome de utilizador ou e-mail.' },
                { max: 255, message: 'O nome de utilizador ou e-mail não pode ter mais de 255 caracteres.' }
              ]}
            >
              <Input
                size="large"
                prefix={<UserOutlined />}
                placeholder="Ex: admin ou utilizador@empresa.co.ao"
                autoComplete="username"
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                inputMode="email"
                maxLength={255}
                autoFocus
              />
            </Form.Item>

            <Button
              type="primary"
              htmlType="submit"
              block
              size="large"
              loading={aEnviar}
              style={{ marginTop: 8, fontWeight: 700 }}
            >
              Recuperar Palavra-passe
            </Button>
          </Form>
        )}

        <Divider style={{ margin: '20px 0 16px' }} />

        <Flex vertical align="center" gap={10}>
          <div style={{ fontSize: 14, color: '#475569' }}>
            Lembrou-se da palavra-passe?{' '}
            <Link to="/entrar" style={{ fontWeight: 700, color: '#b91c1c' }}>
              Entrar na conta
            </Link>
          </div>
          <Link to="/" className="erp-link-voltar">
            Voltar ao site
          </Link>
        </Flex>
      </Card>
      <RodapeEntrada />
    </main>
  );
}
