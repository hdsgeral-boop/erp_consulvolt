import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Alert, Button, Col, Form, Input, Row, Select, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import './publico.css';

type Plano = { codigo: string; nome: string };

export function RegistarEmpresa() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const { autenticarAposRegisto } = useSessao();
  const [erro, setErro] = useState<string | null>(null);
  const [aGuardar, setAGuardar] = useState(false);

  const planos = useQuery({
    queryKey: ['planos-publicos'],
    queryFn: () => obter<Plano[]>('/licenca/planos'),
    retry: false
  });

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
    <div className="cv-registo-layout">
      <header className="cv-registo-nav">
        <div className="cv-registo-nav-inner">
          <Link to="/" className="cv-brand">
            <span className="cv-brand-text">
              consulvolt<span>ERP</span>
            </span>
          </Link>
          <div style={{ fontSize: 14 }}>
            Já tem conta?{' '}
            <Link to="/entrar" style={{ color: '#0052ff', fontWeight: 700, textDecoration: 'none' }}>
              Entrar no sistema
            </Link>
          </div>
        </div>
      </header>

      <main className="cv-registo-card-box">
        <div style={{ textAlign: 'center', marginBottom: 28 }}>
          <Typography.Title level={2} style={{ margin: 0, fontWeight: 800, color: '#0f172a' }}>
            Crie a sua empresa
          </Typography.Title>
          <p style={{ color: '#64748b', fontSize: 15, marginTop: 8 }}>
            30 dias grátis, sem qualquer compromisso. A sua conta fica pronta em 2 minutos.
          </p>
        </div>

        {erro && <Alert type="error" showIcon message={erro} style={{ marginBottom: 20 }} />}

        <Form
          layout="vertical"
          initialValues={{ plano: params.get('plano') || undefined }}
          onFinish={submeter}
          requiredMark={false}
        >
          <div style={{ borderBottom: '1px solid #e2e8f0', paddingBottom: 6, marginBottom: 16 }}>
            <strong style={{ fontSize: 15, color: '#0f172a' }}>1. Dados da empresa</strong>
          </div>

          <Row gutter={16}>
            <Col xs={24} md={12}>
              <Form.Item name="nome_comercial" label="Nome comercial / Denominação social" rules={[{ required: true, message: 'Indique o nome da empresa.' }]}>
                <Input size="large" placeholder="Ex: Minha Empresa, Lda" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="nif" label="NIF da empresa" rules={[{ required: true }, { pattern: /^\d{9,14}$/, message: 'Indique um NIF válido (9 a 14 dígitos).' }]}>
                <Input size="large" placeholder="Ex: 5418291024" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="telefone" label="Telefone principal" rules={[{ required: true, message: 'Indique o contacto telefónico.' }]}>
                <Input size="large" placeholder="Ex: 923 000 000" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="provincia" label="Província" rules={[{ required: true, message: 'Indique a província.' }]}>
                <Input size="large" placeholder="Ex: Luanda" />
              </Form.Item>
            </Col>
          </Row>

          <div style={{ borderBottom: '1px solid #e2e8f0', paddingBottom: 6, marginTop: 12, marginBottom: 16 }}>
            <strong style={{ fontSize: 15, color: '#0f172a' }}>2. Administrador da conta</strong>
          </div>

          <Row gutter={16}>
            <Col xs={24} md={12}>
              <Form.Item name="nome_completo" label="Nome completo" rules={[{ required: true, message: 'Indique o seu nome.' }]}>
                <Input size="large" placeholder="Ex: João Baptista" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="nome_utilizador" label="Nome de utilizador (login)" rules={[{ required: true, message: 'Indique o nome de utilizador.' }]}>
                <Input size="large" autoComplete="username" placeholder="Ex: jbaptista" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="email" label="E-mail profissional" rules={[{ required: true, type: 'email', message: 'Indique um e-mail válido.' }]}>
                <Input size="large" autoComplete="email" placeholder="Ex: joao@empresa.co.ao" />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item name="palavra_passe" label="Palavra-passe (mín. 10 caracteres)" rules={[{ required: true, min: 10, message: 'Mínimo de 10 caracteres.' }]}>
                <Input.Password size="large" autoComplete="new-password" placeholder="••••••••••" />
              </Form.Item>
            </Col>
          </Row>

          <Form.Item name="plano" label="Plano selecionado" style={{ marginTop: 8 }}>
            <Select
              size="large"
              allowClear
              placeholder="Escolher plano inicial"
              options={planos.data?.map(p => ({ value: p.codigo, label: p.nome }))}
            />
          </Form.Item>

          <Button
            type="primary"
            size="large"
            htmlType="submit"
            loading={aGuardar}
            block
            className="cv-btn-pill-orange"
            style={{ height: 48, fontSize: 16, marginTop: 16 }}
          >
            Começar 30 dias grátis
          </Button>

          <div style={{ textAlign: 'center', marginTop: 14, fontSize: 12, color: '#94a3b8' }}>
            Ao registar-se aceita os Termos de Utilização e a Política de Privacidade do Consulvolt ERP.
          </div>
        </Form>
      </main>
    </div>
  );
}
