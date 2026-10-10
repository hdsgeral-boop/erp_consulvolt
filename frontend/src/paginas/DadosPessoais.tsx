import { Alert, Button, Card, Col, Form, Input, Row, Skeleton, Typography, message } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { notificarErro } from '@/utilitarios/erros';

interface Dados {
  nome_utilizador: string;
  nome_completo: string | null;
  telefone: string | null;
  email: string | null;
  palavra_passe_alterada_em: string | null;
  minimo_palavra_passe: number;
}

const CHAVE = ['autenticacao', 'dados-pessoais'];

/**
 * «Gerir Dados Pessoais» (primeira opção do menu do utilizador): o próprio utilizador altera o nome, o telefone, o e-mail
 * e a palavra-passe. O nome de utilizador, o perfil e as empresas são geridos pelo administrador (Sistema › Utilizadores).
 */
export function DadosPessoais() {
  const { actualizarUtilizador } = useSessao();
  const cliente = useQueryClient();
  const dados = useQuery({ queryKey: CHAVE, queryFn: () => obter<Dados>('/autenticacao/dados-pessoais') });
  const [formDados] = Form.useForm<Pick<Dados, 'nome_completo' | 'telefone' | 'email'>>();
  const [formSenha] = Form.useForm<{ palavra_passe_actual: string; palavra_passe_nova: string; palavra_passe_nova_confirmation: string }>();
  const [aGravar, setAGravar] = useState(false);
  const [aAlterar, setAAlterar] = useState(false);

  useEffect(() => {
    if (dados.error) notificarErro(dados.error, 'Não foi possível obter os dados pessoais');
  }, [dados.error]);
  useEffect(() => {
    if (dados.data) formDados.setFieldsValue({ nome_completo: dados.data.nome_completo, telefone: dados.data.telefone, email: dados.data.email });
  }, [dados.data, formDados]);

  const gravarDados = async (v: Pick<Dados, 'nome_completo' | 'telefone' | 'email'>) => {
    setAGravar(true);
    try {
      const r = await enviar<Dados>('put', '/autenticacao/dados-pessoais', v);
      cliente.setQueryData(CHAVE, r.dados);
      actualizarUtilizador({ nome_completo: r.dados.nome_completo, email: r.dados.email, telefone: r.dados.telefone });
      message.success(r.mensagem);
    } catch (e) {
      notificarErro(e, 'Não foi possível gravar os dados pessoais');
    } finally {
      setAGravar(false);
    }
  };

  const alterarSenha = async (v: { palavra_passe_actual: string; palavra_passe_nova: string; palavra_passe_nova_confirmation: string }) => {
    setAAlterar(true);
    try {
      const r = await enviar<{ sessoes_terminadas: number }>('put', '/autenticacao/palavra-passe', v);
      formSenha.resetFields();
      void cliente.invalidateQueries({ queryKey: CHAVE });
      message.success(r.mensagem);
    } catch (e) {
      notificarErro(e, 'Não foi possível alterar a palavra-passe');
    } finally {
      setAAlterar(false);
    }
  };

  const minimo = dados.data?.minimo_palavra_passe ?? 8;

  return (
    <>
      <CabecalhoPagina titulo="Gerir Dados Pessoais" subtitulo="Os seus dados de contacto e a palavra-passe de acesso ao sistema." />
      {dados.isLoading ? (
        <Skeleton active />
      ) : (
        <Row gutter={[16, 16]}>
          <Col xs={24} lg={12}>
            <Card title={<span><IconeFa nome="user" /> Dados pessoais</span>}>
              <Form form={formDados} layout="vertical" onFinish={(v) => void gravarDados(v)} requiredMark>
                <Form.Item label="Nome de utilizador" extra="Usado para entrar no sistema; só o administrador o pode alterar.">
                  <Input value={dados.data?.nome_utilizador} disabled />
                </Form.Item>
                <Form.Item name="nome_completo" label="Nome" rules={[{ required: true, whitespace: true, message: 'Indique o nome.' }, { max: 255 }]}>
                  <Input autoComplete="name" />
                </Form.Item>
                <Form.Item name="telefone" label="Telefone" rules={[{ max: 30 }, { pattern: /^[0-9+()\s.-]*$/, message: 'Só algarismos, espaços e + ( ) - .' }]}>
                  <Input autoComplete="tel" placeholder="Ex.: +244 9XX XXX XXX" />
                </Form.Item>
                <Form.Item name="email" label="E-mail" rules={[{ type: 'email', message: 'Indique um e-mail válido.' }, { max: 150 }]}>
                  <Input autoComplete="email" placeholder="nome@empresa.ao" />
                </Form.Item>
                <Button type="primary" htmlType="submit" icon={<IconeFa nome="save" />} loading={aGravar}>
                  Gravar dados
                </Button>
              </Form>
            </Card>
          </Col>
          <Col xs={24} lg={12}>
            <Card title={<span><IconeFa nome="lock" /> Palavra-passe</span>}>
              <Typography.Paragraph type="secondary" style={{ marginTop: 0 }}>
                {dados.data?.palavra_passe_alterada_em ? `Última alteração: ${formatarDataHora(dados.data.palavra_passe_alterada_em)}.` : 'Ainda não alterou a palavra-passe neste sistema.'}
              </Typography.Paragraph>
              <Form form={formSenha} layout="vertical" onFinish={(v) => void alterarSenha(v)}>
                <Form.Item name="palavra_passe_actual" label="Palavra-passe actual" rules={[{ required: true, message: 'Indique a palavra-passe actual.' }]}>
                  <Input.Password autoComplete="current-password" />
                </Form.Item>
                <Form.Item
                  name="palavra_passe_nova"
                  label="Nova palavra-passe"
                  rules={[
                    { required: true, message: 'Indique a nova palavra-passe.' },
                    { min: minimo, message: `Pelo menos ${minimo} caracteres.` },
                    ({ getFieldValue }) => ({
                      validator: (_, v) => (v && v === getFieldValue('palavra_passe_actual') ? Promise.reject(new Error('Tem de ser diferente da actual.')) : Promise.resolve()),
                    }),
                  ]}
                  hasFeedback
                >
                  <Input.Password autoComplete="new-password" />
                </Form.Item>
                <Form.Item
                  name="palavra_passe_nova_confirmation"
                  label="Confirmar a nova palavra-passe"
                  dependencies={['palavra_passe_nova']}
                  hasFeedback
                  rules={[
                    { required: true, message: 'Repita a nova palavra-passe.' },
                    ({ getFieldValue }) => ({
                      validator: (_, v) => (!v || v === getFieldValue('palavra_passe_nova') ? Promise.resolve() : Promise.reject(new Error('Não coincide com a nova palavra-passe.'))),
                    }),
                  ]}
                >
                  <Input.Password autoComplete="new-password" />
                </Form.Item>
                <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Ao alterar a palavra-passe, as suas outras sessões abertas (outros computadores ou navegadores) são terminadas." />
                <Button type="primary" htmlType="submit" icon={<IconeFa nome="key" />} loading={aAlterar}>
                  Alterar palavra-passe
                </Button>
              </Form>
            </Card>
          </Col>
        </Row>
      )}
    </>
  );
}
