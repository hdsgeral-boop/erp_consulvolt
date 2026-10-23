import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Alert, Button, Card, Col, Divider, Flex, Form, Input, Row, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVE_SESSAO_MOSTRAR_TOUR } from '@/componentes/tour/TourBoasVindas';

const DIAS_TESTE_PADRAO = 30;

export function RegistarEmpresa() {
  const [form] = Form.useForm();
  const navigate = useNavigate();
  const { autenticarAposRegisto } = useSessao();
  const [erro, setErro] = useState<string | null>(null);
  const [detalhesErros, setDetalhesErros] = useState<string[]>([]);
  const [aGuardar, setAGuardar] = useState(false);

  const logotipo = useQuery({
    queryKey: ['logotipo-login'],
    queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'),
    retry: false
  });

  const definicoes = useQuery({
    queryKey: ['definicoes-publicas'],
    queryFn: () => obter<{ dias_teste_padrao: number }>('/sistema/definicoes-publicas'),
    retry: false,
    staleTime: 5 * 60_000
  });
  const diasTeste = definicoes.data?.dias_teste_padrao ?? DIAS_TESTE_PADRAO;

  const submeter = async (v: Record<string, string>) => {
    setAGuardar(true);
    setErro(null);
    setDetalhesErros([]);
    try {
      const r = await enviar<{ token: string; empresa_id: number }>('post', '/autenticacao/registar-empresa', v);
      await autenticarAposRegisto(r.dados.token, r.dados.empresa_id);
      try {
        sessionStorage.setItem(CHAVE_SESSAO_MOSTRAR_TOUR, '1');
      } catch {
        /* modo privado ou armazenamento bloqueado: o tour de boas-vindas simplesmente não arranca sozinho */
      }
      navigate('/app', { replace: true });
    } catch (e) {
      if (e instanceof ErroApi) {
        if (e.erros && Object.keys(e.erros).length > 0) {
          const camposComErro: { name: string; errors: string[] }[] = [];
          const listaMensagens: string[] = [];

          for (const [campo, msgs] of Object.entries(e.erros)) {
            const arr = Array.isArray(msgs) ? msgs.map(String) : [String(msgs)];
            camposComErro.push({ name: campo, errors: arr });
            listaMensagens.push(...arr);
          }

          form.setFields(camposComErro);

          if (listaMensagens.length === 1) {
            setErro(listaMensagens[0]);
            setDetalhesErros([]);
          } else {
            setErro('Por favor, corrija os seguintes erros:');
            setDetalhesErros(listaMensagens);
          }
        } else {
          setErro(e.message || 'Não foi possível concluir o registo.');
          setDetalhesErros([]);
        }
      } else {
        setErro('Não foi possível concluir o registo.');
        setDetalhesErros([]);
      }
    } finally {
      setAGuardar(false);
    }
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada" style={{ padding: '24px 16px' }}>
      <Card
        className="erp-cartao-entrada"
        style={{ width: '100%', maxWidth: 660 }}
        styles={{ body: { padding: 'clamp(20px, 4vw, 36px)' } }}
      >
        <Flex vertical align="center" gap={8} style={{ marginBottom: 20, textAlign: 'center' }}>
          {logotipo.data?.logotipo ? (
            <img
              src={logotipo.data.logotipo}
              alt="Logótipo Consulvolt"
              style={{ maxHeight: 90, maxWidth: 'min(220px, 100%)', objectFit: 'contain', marginBottom: 4 }}
            />
          ) : null}
          <h1 className="erp-entrada-titulo">Criar Conta Consulvolt ERP</h1>
          <Typography.Text type="secondary">
            {diasTeste} dias de demonstração gratuitos, sem qualquer compromisso · Comece em 2 minutos
          </Typography.Text>
        </Flex>

        {erro && (
          <Alert
            type="error"
            showIcon
            message={erro}
            description={
              detalhesErros.length > 0 ? (
                <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
                  {detalhesErros.map((msg, i) => (
                    <li key={i}>{msg}</li>
                  ))}
                </ul>
              ) : undefined
            }
            closable
            onClose={() => {
              setErro(null);
              setDetalhesErros([]);
            }}
            style={{ marginBottom: 16 }}
          />
        )}

        <Form
          form={form}
          layout="vertical"
          onFinish={submeter}
          onValuesChange={() => {
            if (erro) {
              setErro(null);
              setDetalhesErros([]);
            }
          }}
          requiredMark={false}
        >
          <div style={{ borderBottom: '1px solid #e2e8f0', paddingBottom: 6, marginBottom: 14 }}>
            <strong style={{ fontSize: 14, color: '#0f172a', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              1. Dados da empresa
            </strong>
          </div>

          <Row gutter={16}>
            <Col xs={24} md={12}>
              <Form.Item
                name="nome_comercial"
                label="Nome comercial / Denominação social"
                rules={[{ required: true, message: 'Indique o nome da empresa.' }]}
              >
                <Input size="large" placeholder="Ex: Minha Empresa, Lda" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="nif"
                label="NIF da empresa"
                rules={[
                  { required: true, message: 'Indique o NIF.' },
                  { pattern: /^\d{9,14}$/, message: 'Indique um NIF válido (9 a 14 dígitos).' }
                ]}
              >
                <Input size="large" placeholder="Ex: 5418291024" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="telefone"
                label="Telefone principal"
                rules={[{ required: true, message: 'Indique o contacto telefónico.' }]}
              >
                <Input size="large" placeholder="Ex: 923 000 000" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="provincia"
                label="Província"
                rules={[{ required: true, message: 'Indique a província.' }]}
              >
                <Input size="large" placeholder="Ex: Luanda" />
              </Form.Item>
            </Col>
          </Row>

          <div style={{ borderBottom: '1px solid #e2e8f0', paddingBottom: 6, marginTop: 8, marginBottom: 14 }}>
            <strong style={{ fontSize: 14, color: '#0f172a', textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              2. Administrador da conta
            </strong>
          </div>

          <Row gutter={16}>
            <Col xs={24} md={12}>
              <Form.Item
                name="nome_completo"
                label="Nome completo"
                rules={[{ required: true, message: 'Indique o seu nome.' }]}
              >
                <Input size="large" placeholder="Ex: João Baptista" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="nome_utilizador"
                label="Nome de utilizador (login)"
                rules={[{ required: true, message: 'Indique o nome de utilizador.' }]}
              >
                <Input size="large" autoComplete="username" placeholder="Ex: jbaptista" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="email"
                label="E-mail profissional"
                rules={[{ required: true, type: 'email', message: 'Indique um e-mail válido.' }]}
              >
                <Input size="large" autoComplete="email" placeholder="Ex: joao@empresa.co.ao" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="palavra_passe"
                label="Palavra-passe (mín. 10 caracteres)"
                rules={[{ required: true, min: 10, message: 'Mínimo de 10 caracteres.' }]}
              >
                <Input.Password size="large" autoComplete="new-password" placeholder="••••••••••" />
              </Form.Item>
            </Col>
          </Row>

          <Alert
            type="info"
            showIcon
            message={`Durante os ${diasTeste} dias de demonstração tem acesso a todos os módulos do sistema, sem precisar de escolher um plano. Escolhe o plano apenas quando quiser continuar a usar o sistema depois do período gratuito.`}
            style={{ marginTop: 4, marginBottom: 12 }}
          />

          <Button
            type="primary"
            size="large"
            htmlType="submit"
            loading={aGuardar}
            block
            style={{
              height: 48,
              fontSize: 16,
              fontWeight: 700,
              marginTop: 12,
              background: '#b91c1c',
              borderColor: '#b91c1c'
            }}
          >
            Começar {diasTeste} dias grátis
          </Button>

          <div style={{ textAlign: 'center', marginTop: 12, fontSize: 12, color: '#94a3b8' }}>
            Ao registar-se aceita os Termos de Utilização e a Política de Privacidade do Consulvolt ERP.
          </div>
        </Form>

        <Divider style={{ margin: '20px 0 16px' }} />

        <Flex vertical align="center" gap={10}>
          <div style={{ fontSize: 14, color: '#475569' }}>
            Já tem conta?{' '}
            <Link to="/entrar" style={{ fontWeight: 700, color: '#b91c1c' }}>
              Entrar no sistema
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
