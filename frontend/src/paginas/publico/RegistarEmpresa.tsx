import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Alert, Button, Card, Col, Divider, Flex, Form, Input, Row, Select, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';

type Plano = { codigo: string; nome: string; preco_mensal?: number };

const PLANOS_PADRAO: Plano[] = [
  { codigo: 'basico', nome: 'Plano Básico (9.500 Kz/mês)' },
  { codigo: 'profissional', nome: 'Plano Profissional (15.000 Kz/mês)' },
  { codigo: 'empresarial', nome: 'Plano Empresarial (21.000 Kz/mês)' },
];

export function RegistarEmpresa() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const { autenticarAposRegisto } = useSessao();
  const [erro, setErro] = useState<string | null>(null);
  const [aGuardar, setAGuardar] = useState(false);

  const logotipo = useQuery({
    queryKey: ['logotipo-login'],
    queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'),
    retry: false
  });

  const planos = useQuery({
    queryKey: ['planos-publicos'],
    queryFn: () => obter<Plano[]>('/sistema/planos-publicos'),
    retry: false
  });

  const opcoesPlanos = (planos.data && planos.data.length > 0)
    ? planos.data.map(p => ({
        value: p.codigo,
        label: p.preco_mensal
          ? `${p.nome} (${Number(p.preco_mensal).toLocaleString('pt-AO')} Kz/mês)`
          : p.nome
      }))
    : PLANOS_PADRAO.map(p => ({ value: p.codigo, label: p.nome }));

  const submeter = async (v: Record<string, string>) => {
    setAGuardar(true);
    setErro(null);
    try {
      const r = await enviar<{ token: string; empresa_id: number }>('post', '/autenticacao/registar-empresa', v);
      await autenticarAposRegisto(r.dados.token, r.dados.empresa_id);
      navigate('/app', { replace: true });
    } catch (e) {
      setErro(e instanceof ErroApi ? e.message : 'Não foi possível concluir o registo.');
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
            30 dias gratuitos sem qualquer compromisso · Comece em 2 minutos
          </Typography.Text>
        </Flex>

        {erro && <Alert type="error" showIcon message={erro} style={{ marginBottom: 16 }} />}

        <Form
          layout="vertical"
          initialValues={{ plano: params.get('plano') || 'basico' }}
          onFinish={submeter}
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

          <Form.Item name="plano" label="Plano selecionado" style={{ marginTop: 4 }}>
            <Select
              size="large"
              placeholder="Escolher plano inicial"
              options={opcoesPlanos}
            />
          </Form.Item>

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
            Começar 30 dias grátis
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
          <Link to="/" style={{ fontSize: 13, color: '#64748b', display: 'inline-flex', alignItems: 'center', gap: 4 }}>
            ← Voltar ao site
          </Link>
        </Flex>
      </Card>
    </main>
  );
}
