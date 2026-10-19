import { Alert, Button, Card, Flex, Form, Input, Modal, Typography } from 'antd';
import { LockOutlined, MailOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, http, obter } from '@/api/cliente';
import { RodapeEntrada } from '@/paginas/RodapeEntrada';
import { useSessao } from '@/sessao/SessaoContexto';
import { ErroApi } from '@/api/tipos';
import { naRotaPortal } from '@/portal/rota';

/** Entrada no sistema, com o aspecto do sistema anterior: fotografia de fundo e cartão translúcido ao centro. */
export function Entrar() {
  const { entrar, motivoSaida } = useSessao();
  const [erro, setErro] = useState<string | null>(null);
  const [aEntrar, setAEntrar] = useState(false);
  const [recuperar, setRecuperar] = useState(false);
  const [aRecuperar, setARecuperar] = useState(false);
  const [mensagemRecuperacao, setMensagemRecuperacao] = useState<string | null>(null);
  const [erroRecuperacao, setErroRecuperacao] = useState<string | null>(null);
  const portal = naRotaPortal();
  const logotipo = useQuery({ queryKey: ['logotipo-login'], queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'), retry: false });

  // Primeiro acesso da plataforma: só o servidor sabe se este endereço é o da plataforma e se ainda falta o administrador.
  const inicial = useQuery({ queryKey: ['plataforma-inicial-estado'], queryFn: () => obter<{ precisa_criar_admin: boolean; exige_segredo: boolean }>('/plataforma-inicial/estado'), retry: false });
  const [aCriar, setACriar] = useState(false);
  const precisaCriarAdmin = inicial.data?.precisa_criar_admin === true;

  const criarAdmin = async (v: { nome_utilizador: string; nome_completo: string; email: string; palavra_passe: string; palavra_passe_confirmation: string; segredo?: string }) => {
    setErro(null);
    setACriar(true);
    try {
      const { segredo, ...corpo } = v;
      // o interceptor do cliente já converte as falhas em ErroApi (mensagem em português do servidor)
      await http.post('/plataforma-inicial/criar-admin', corpo, segredo ? { headers: { 'X-Setup-Token': segredo } } : undefined);
      await entrar(v.nome_utilizador, v.palavra_passe);
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Não foi possível criar o administrador.');
    } finally {
      setACriar(false);
    }
  };

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

  const fecharRecuperar = () => {
    setRecuperar(false);
    setMensagemRecuperacao(null);
    setErroRecuperacao(null);
  };

  const pedirRecuperacao = async (v: { identificador: string }) => {
    setErroRecuperacao(null);
    setARecuperar(true);
    try {
      const r = await enviar('post', '/autenticacao/recuperar-senha', { identificador: v.identificador });
      setMensagemRecuperacao(r.mensagem);
    } catch (e) {
      setErroRecuperacao(e instanceof ErroApi ? e.message : 'Não foi possível enviar o pedido.');
    } finally {
      setARecuperar(false);
    }
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada" style={{ paddingBottom: 64 }}>
      <Card className="erp-cartao-entrada" style={{ width: '100%', maxWidth: 440 }} styles={{ body: { padding: 'clamp(24px, 6vw, 44px)' } }}>
        <Flex vertical align="center" gap={8} style={{ marginBottom: 24, textAlign: 'center' }}>
          {logotipo.data?.logotipo ? <img src={logotipo.data.logotipo} alt="Logótipo" style={{ maxHeight: 110, maxWidth: 'min(240px, 100%)', objectFit: 'contain', marginBottom: 8 }} /> : null}
          <h1 className="erp-entrada-titulo">{portal ? 'Portal do Colaborador' : 'MEU ERP'}</h1>
          <Typography.Text type="secondary">
            {precisaCriarAdmin ? 'Criar administrador da plataforma' : portal ? 'Entre com o seu utilizador para aceder ao portal' : 'Aceda à sua conta para continuar'}
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
            <Form.Item name="nome_utilizador" label="Utilizador" rules={[{ required: true, message: 'Indique o utilizador.' }]}>
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
            <Button type="primary" htmlType="submit" block size="large" loading={aCriar} style={{ marginTop: 8, fontWeight: 700 }}>
              Criar administrador
            </Button>
          </Form>
        ) : (
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
          <Flex justify="center" style={{ marginTop: 12 }}>
            <Button type="link" onClick={() => setRecuperar(true)}>
              Esqueceu a senha?
            </Button>
          </Flex>
        </Form>
        )}
      </Card>
      <Modal title="Recuperar senha" open={recuperar} onCancel={fecharRecuperar} footer={null} destroyOnClose>
        {mensagemRecuperacao ? (
          <>
            <Alert type="success" showIcon message={mensagemRecuperacao} style={{ marginBottom: 16 }} />
            <Button block onClick={fecharRecuperar}>
              Fechar
            </Button>
          </>
        ) : (
          <Form layout="vertical" onFinish={pedirRecuperacao} requiredMark={false}>
            <Typography.Paragraph type="secondary">Indique o seu e-mail ou nome de utilizador. Se existir uma conta com e-mail registado, enviaremos uma ligação para criar nova palavra-passe.</Typography.Paragraph>
            {erroRecuperacao && <Alert type="error" showIcon message={erroRecuperacao} style={{ marginBottom: 16 }} />}
            <Form.Item name="identificador" label="E-mail ou utilizador" rules={[{ required: true, message: 'Indique o e-mail ou o utilizador.' }]}>
              <Input size="large" prefix={<MailOutlined />} autoFocus />
            </Form.Item>
            <Button type="primary" htmlType="submit" block size="large" loading={aRecuperar}>
              Enviar ligação
            </Button>
          </Form>
        )}
      </Modal>
      <RodapeEntrada />
    </main>
  );
}
