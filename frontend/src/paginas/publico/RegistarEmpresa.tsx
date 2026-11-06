import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { Alert, Button, Card, Col, Divider, Flex, Form, Input, Progress, Row, Select, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVE_SESSAO_MOSTRAR_TOUR } from '@/componentes/tour/TourBoasVindas';
import { MENSAGEM_NIF, nifValido, normalizarNif } from '@/utilitarios/nifAngolano';
import { MENSAGEM_TELEFONE, normalizarTelefone, telefoneValido } from '@/utilitarios/telefoneAngolano';
import { normalizarNomeUtilizador, problemaNomeUtilizador } from '@/utilitarios/nomeUtilizador';
import { MINIMO_PALAVRA_PASSE_REGISTO, forcaPalavraPasse, problemaPalavraPasse, sugerirNomeUtilizador } from '@/utilitarios/palavraPasse';
import { PROVINCIAS_ANGOLA } from '@/utilitarios/provinciasAngola';

const DIAS_TESTE_PADRAO = 30;

/** Espaços das pontas e repetidos reduzidos a um (o servidor faz o mesmo: o que se vê é o que se grava). */
const limparEspacos = (v: unknown) => (typeof v === 'string' ? v.replace(/\s+/g, ' ').trim() : '');

export function RegistarEmpresa() {
  const [form] = Form.useForm();
  const navigate = useNavigate();
  const { autenticarAposRegisto } = useSessao();
  const [parametros] = useSearchParams();
  const [erro, setErro] = useState<string | null>(null);
  const [detalhesErros, setDetalhesErros] = useState<string[]>([]);
  const [aGuardar, setAGuardar] = useState(false);
  const emCurso = useRef(false);   // bloqueia o duplo envio (Enter repetido, duplo clique) antes de o estado actualizar
  const palavraPasse = Form.useWatch('palavra_passe', form) as string | undefined;
  const nomeUtilizadorEscrito = Form.useWatch('nome_utilizador', form) as string | undefined;
  const emailEscrito = Form.useWatch('email', form) as string | undefined;
  const evitar = [nomeUtilizadorEscrito ? normalizarNomeUtilizador(nomeUtilizadorEscrito) : null, emailEscrito ? emailEscrito.split('@')[0].toLowerCase() : null];
  const forca = forcaPalavraPasse(palavraPasse ?? '', evitar, MINIMO_PALAVRA_PASSE_REGISTO);
  // Chegou aqui vindo do login Google sem conta ainda (AutenticacaoGoogleController): nome e email pré-preenchidos,
  // o resto (NIF, telefone, província, nome de utilizador, palavra-passe) continua a ser preenchido à mão.
  const deGoogle = parametros.has('email');

  useEffect(() => {
    const nome = parametros.get('nome');
    const email = parametros.get('email');
    if (nome || email) {
      form.setFieldsValue({ nome_completo: nome || undefined, email: email || undefined });
    }
    // só no arranque: a query string não muda depois disto, e não queremos reaplicar a cada render
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

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

  const submeter = async (valores: Record<string, string>) => {
    if (emCurso.current) return;
    emCurso.current = true;
    // o servidor normaliza e valida tudo outra vez; aqui só se retira o que é só do ecrã (confirmação) e se limpam os textos
    const { palavra_passe_confirmacao: _confirmacao, ...resto } = valores;
    void _confirmacao;
    const v = {
      ...resto,
      nome_comercial: limparEspacos(resto.nome_comercial),
      nif: normalizarNif(resto.nif),
      telefone: normalizarTelefone(resto.telefone),
      nome_completo: limparEspacos(resto.nome_completo),
      nome_utilizador: normalizarNomeUtilizador(resto.nome_utilizador),
      email: limparEspacos(resto.email).toLowerCase(),
    };
    setAGuardar(true);
    setErro(null);
    setDetalhesErros([]);
    try {
      const r = await enviar<{ empresa_id: number }>('post', '/autenticacao/registar-empresa', v);
      await autenticarAposRegisto(r.dados.empresa_id);
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
      emCurso.current = false;
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

        {deGoogle && (
          <Alert
            type="info"
            showIcon
            title="Nome e email preenchidos a partir da sua conta Google. Falta só completar os dados da empresa e escolher uma palavra-passe."
            style={{ marginBottom: 16 }}
          />
        )}

        {erro && (
          <Alert
            type="error"
            showIcon
            title={erro}
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
          validateTrigger={['onBlur', 'onChange']}
          scrollToFirstError={{ behavior: 'smooth', block: 'center' }}
          aria-label="Criar conta de empresa"
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
                rules={[
                  { required: true, whitespace: true, message: 'Indique o nome da empresa.' },
                  { min: 2, message: 'O nome comercial deve ter pelo menos 2 caracteres.' },
                  { max: 255, message: 'O nome comercial não pode ter mais de 255 caracteres.' },
                  { pattern: /^[^<>]+$/u, message: 'O nome comercial não pode ter os caracteres < e >.' },
                  { pattern: /[\p{L}\p{N}]/u, message: 'O nome comercial tem de ter letras ou algarismos.' }
                ]}
              >
                <Input size="large" placeholder="Ex: Minha Empresa, Lda" autoComplete="organization" maxLength={255} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="nif"
                label="NIF da empresa"
                extra="10 dígitos. Empresário em nome individual: o número do BI."
                rules={[
                  { required: true, whitespace: true, message: 'Indique o NIF.' },
                  { validator: (_, v?: string) => (!v || !v.trim() || nifValido(v) ? Promise.resolve() : Promise.reject(new Error(MENSAGEM_NIF))) }
                ]}
              >
                <Input
                  size="large"
                  placeholder="Ex: 5418291024"
                  autoComplete="off"
                  autoCapitalize="characters"
                  autoCorrect="off"
                  spellCheck={false}
                  inputMode="text"
                  maxLength={24}
                />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="telefone"
                label="Telefone principal"
                rules={[
                  { required: true, whitespace: true, message: 'Indique o contacto telefónico.' },
                  { max: 30, message: 'O telefone não pode ter mais de 30 caracteres.' },
                  { validator: (_, v?: string) => (!v || !v.trim() || telefoneValido(v) ? Promise.resolve() : Promise.reject(new Error(MENSAGEM_TELEFONE))) }
                ]}
              >
                <Input size="large" type="tel" inputMode="tel" autoComplete="tel" placeholder="Ex: 923 000 000" maxLength={30} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="provincia"
                label="Província"
                rules={[{ required: true, message: 'Seleccione a província.' }]}
              >
                <Select
                  size="large"
                  showSearch
                  virtual={false}
                  placeholder="Seleccione a província"
                  options={PROVINCIAS_ANGOLA.map((p) => ({ value: p, label: p }))}
                  optionFilterProp="label"
                  filterOption={(entrada, opcao) =>
                    (opcao?.label ?? '').toString().normalize('NFD').replace(/\p{Mn}/gu, '').toLowerCase().includes(entrada.normalize('NFD').replace(/\p{Mn}/gu, '').toLowerCase())
                  }
                />
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
                rules={[
                  { required: true, whitespace: true, message: 'Indique o seu nome.' },
                  { min: 3, message: 'O nome completo deve ter pelo menos 3 caracteres.' },
                  { max: 200, message: 'O nome completo não pode ter mais de 200 caracteres.' },
                  { pattern: /^\p{L}[\p{L}\p{M}\s'’.-]*\p{L}\.?$/u, message: "O nome só pode ter letras, espaços e os sinais . ' -" }
                ]}
              >
                <Input
                  size="large"
                  autoComplete="name"
                  placeholder="Ex: João Baptista"
                  maxLength={200}
                  onBlur={() => {
                    // sugere o utilizador a partir do nome, só se ainda estiver vazio
                    if (!form.getFieldValue('nome_utilizador')) {
                      const sugestao = sugerirNomeUtilizador(String(form.getFieldValue('nome_completo') ?? ''));
                      if (sugestao.length >= 3) form.setFieldsValue({ nome_utilizador: sugestao });
                    }
                  }}
                />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="nome_utilizador"
                label="Nome de utilizador (login)"
                extra="Letras sem acentos, algarismos e . _ - (3 a 50). É gravado em minúsculas."
                rules={[
                  { required: true, whitespace: true, message: 'Indique o nome de utilizador.' },
                  { validator: (_, v?: string) => { const p = v && v.trim() ? problemaNomeUtilizador(v) : null; return p ? Promise.reject(new Error(p)) : Promise.resolve(); } }
                ]}
              >
                <Input
                  size="large"
                  autoComplete="username"
                  autoCapitalize="none"
                  autoCorrect="off"
                  spellCheck={false}
                  placeholder="Ex: jbaptista"
                  maxLength={50}
                />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="email"
                label="E-mail profissional"
                rules={[
                  { required: true, whitespace: true, message: 'Indique o e-mail.' },
                  { type: 'email', message: 'Indique um e-mail válido.' },
                  { max: 150, message: 'O e-mail não pode ter mais de 150 caracteres.' }
                ]}
              >
                <Input
                  size="large"
                  type="email"
                  inputMode="email"
                  autoComplete="email"
                  autoCapitalize="none"
                  autoCorrect="off"
                  spellCheck={false}
                  placeholder="Ex: joao@empresa.co.ao"
                  maxLength={150}
                />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="palavra_passe"
                label={`Palavra-passe (mín. ${MINIMO_PALAVRA_PASSE_REGISTO} caracteres)`}
                extra="Com maiúsculas, minúsculas e algarismos; sem o seu utilizador ou e-mail."
                rules={[
                  { required: true, message: 'Indique a palavra-passe.' },
                  {
                    validator: (_, v?: string) => {
                      const p = v ? problemaPalavraPasse(v, evitar, MINIMO_PALAVRA_PASSE_REGISTO) : null;
                      return p ? Promise.reject(new Error(p)) : Promise.resolve();
                    }
                  }
                ]}
              >
                <Input.Password size="large" autoComplete="new-password" placeholder="••••••••••" maxLength={128} />
              </Form.Item>
              {palavraPasse ? (
                <div aria-live="polite" style={{ marginTop: -14, marginBottom: 16 }}>
                  <Progress percent={forca.percentagem} status={forca.estado} showInfo={false} size="small" aria-label="Força da palavra-passe" />
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    Força: {forca.rotulo}
                  </Typography.Text>
                </div>
              ) : null}
            </Col>
            <Col xs={24} md={12}>
              <Form.Item
                name="palavra_passe_confirmacao"
                label="Confirmar a palavra-passe"
                dependencies={['palavra_passe']}
                rules={[
                  { required: true, message: 'Repita a palavra-passe.' },
                  ({ getFieldValue }) => ({
                    validator: (_, v) => (!v || v === getFieldValue('palavra_passe') ? Promise.resolve() : Promise.reject(new Error('Não coincide com a palavra-passe.')))
                  })
                ]}
              >
                <Input.Password size="large" autoComplete="new-password" placeholder="••••••••••" maxLength={128} />
              </Form.Item>
            </Col>
          </Row>

          <Alert
            type="info"
            showIcon
            title={`Durante os ${diasTeste} dias de demonstração tem acesso a todos os módulos do sistema, sem precisar de escolher um plano. Escolhe o plano apenas quando quiser continuar a usar o sistema depois do período gratuito.`}
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
