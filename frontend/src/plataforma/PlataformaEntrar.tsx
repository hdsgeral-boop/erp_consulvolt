import { Alert, Button, Card, Flex, Form, Input, Typography } from 'antd';
import { LockOutlined, MailOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { http, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { LogotipoSistema } from '@/componentes/LogotipoSistema';
import { useSessao } from '@/sessao/SessaoContexto';

interface DadosAdmin {
  nome_utilizador: string;
  nome_completo: string;
  email: string;
  palavra_passe: string;
  palavra_passe_confirmation: string;
  segredo?: string;
}

/** Entrada própria da plataforma: login e primeiro acesso (criação do administrador). Nunca pede empresa. */
export function PlataformaEntrar() {
  const { entrar, motivoSaida } = useSessao();
  const [erro, setErro] = useState<string | null>(null);
  const [aEntrar, setAEntrar] = useState(false);
  const inicial = useQuery({
    queryKey: ['plataforma-inicial-estado'],
    queryFn: () => obter<{ precisa_criar_admin: boolean; exige_segredo: boolean }>('/plataforma-inicial/estado'),
    retry: false,
  });
  const precisaCriarAdmin = inicial.data?.precisa_criar_admin === true;

  const executar = async (accao: () => Promise<void>, falha: string) => {
    setErro(null);
    setAEntrar(true);
    try {
      await accao();
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : falha);
    } finally {
      setAEntrar(false);
    }
  };

  const criarAdmin = (v: DadosAdmin) =>
    executar(async () => {
      const { segredo, ...corpo } = v;
      await http.post('/plataforma-inicial/criar-admin', corpo, segredo ? { headers: { 'X-Setup-Token': segredo } } : undefined);
      await entrar(v.nome_utilizador, v.palavra_passe);
    }, 'Não foi possível criar o administrador.');

  const submeter = (v: { nome_utilizador: string; palavra_passe: string }) =>
    executar(() => entrar(v.nome_utilizador, v.palavra_passe), 'Não foi possível entrar.');

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada">
      <Card className="erp-cartao-entrada" style={{ width: '100%', maxWidth: 440 }} styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24, textAlign: 'center' }}>
          <LogotipoSistema tamanho={72} soSimbolo />
          <h1 className="erp-entrada-titulo">MEU ERP · Plataforma</h1>
          <Typography.Text type="secondary">
            {precisaCriarAdmin ? 'Criar administrador da plataforma' : 'Administração da plataforma'}
          </Typography.Text>
        </Flex>
        {motivoSaida && <Alert type="info" showIcon message={motivoSaida} style={{ marginBottom: 16 }} />}
        {erro && <Alert type="error" showIcon message={erro} style={{ marginBottom: 16 }} />}
        {precisaCriarAdmin ? (
          <Form layout="vertical" onFinish={criarAdmin} requiredMark={false}>
            <Alert type="info" showIcon message="A plataforma ainda não tem administrador. Crie o primeiro para começar." style={{ marginBottom: 16 }} />
            <Form.Item name="nome_completo" label="Nome completo" rules={[{ required: true, message: 'Indique o nome completo.' }]}>
              <Input size="large" prefix={<UserOutlined />} autoComplete="name" autoFocus />
            </Form.Item>
            <Form.Item name="nome_utilizador" label="Utilizador ou e-mail" rules={[{ required: true, message: 'Indique o utilizador ou o e-mail.' }]}>
              <Input size="large" prefix={<UserOutlined />} autoComplete="username" />
            </Form.Item>
            <Form.Item name="email" label="E-mail" rules={[{ required: true, message: 'Indique o e-mail.' }, { type: 'email', message: 'E-mail inválido.' }]}>
              <Input size="large" prefix={<MailOutlined />} autoComplete="email" />
            </Form.Item>
            <Form.Item name="palavra_passe" label="Palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }, { min: 8, message: 'Mínimo de 8 caracteres.' }]}>
              <Input.Password size="large" prefix={<LockOutlined />} autoComplete="new-password" />
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
          <Form layout="vertical" onFinish={submeter} requiredMark={false}>
            <Form.Item name="nome_utilizador" label="Utilizador ou e-mail" rules={[{ required: true, message: 'Indique o utilizador ou o e-mail.' }]}>
              <Input size="large" prefix={<UserOutlined />} autoComplete="username" autoFocus />
            </Form.Item>
            <Form.Item name="palavra_passe" label="Palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }]}>
              <Input.Password size="large" prefix={<LockOutlined />} autoComplete="current-password" />
            </Form.Item>
            <Button type="primary" htmlType="submit" block size="large" loading={aEntrar} style={{ marginTop: 8, fontWeight: 700 }}>
              Entrar
            </Button>
          </Form>
        )}
      </Card>
    </main>
  );
}
