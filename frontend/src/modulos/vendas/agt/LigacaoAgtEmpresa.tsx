import { Alert, Button, Card, Descriptions, Flex, Form, Input, Modal, Select, Skeleton, Space, Switch, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { COLUNAS_DESCRICOES, larguraModal } from '@/componentes/responsivo';
import { formatarDataHora } from '@/utilitarios/formatacao';

/** GET /vendas/faturacao-eletronica/ligacao-empresa (ServicoLigacaoAgt::obter) — nunca traz segredos. */
export interface LigacaoEmpresa {
  configurada: boolean;
  ativo: boolean;
  ambiente: 'homologacao' | 'producao';
  url_base: string | null;
  url_efectiva: string;
  nif: string | null;
  credenciais: { configuradas: boolean; origem: 'empresa' | 'servidor' | null; atualizadas_em: string | null; atualizadas_por: string | null };
  chave: {
    configurada: boolean;
    origem: 'empresa' | 'servidor' | null;
    impressao_digital: string | null;
    bits: number | null;
    versao: number;
    tem_certificado: boolean;
    certificado_valido_ate: string | null;
    dias_para_expirar: number | null;
    expira_em_breve: boolean;
    atualizada_em: string | null;
    atualizada_por: string | null;
  };
  webhook: { ativo: boolean; configurado: boolean; atualizado_em: string | null; atualizado_por: string | null; nota: string };
  software: { productId: string | null; productVersion: string | null; softwareValidationNumber: string | null; configurado: boolean; chave_produtor: boolean };
  ultimo_teste: { em: string; ok: boolean; mensagem: string } | null;
}

export const ROTULO_AMBIENTE: Record<string, string> = { homologacao: 'Homologação (testes)', producao: 'Produção' };

/** Texto «configurado em … por …» (os segredos nunca voltam à interface). */
export function textoConfigurado(em: string | null, por: string | null): string {
  if (!em) return 'Por configurar';
  return `Configurado em ${formatarDataHora(em)}${por ? ` por ${por}` : ''}`;
}

/** Lê um ficheiro .pem como texto (no browser); o servidor valida e cifra. */
function lerFicheiro(f: File): Promise<string> {
  return new Promise((ok, falha) => {
    const r = new FileReader();
    r.onload = () => ok(String(r.result ?? ''));
    r.onerror = () => falha(r.error);
    r.readAsText(f);
  });
}

/**
 * Vendas › Facturação electrónica › «Ligação da empresa à AGT» (r4_agt): ambiente, credenciais, chave do contribuinte,
 * webhook e teste da ligação. Os segredos são gravados cifrados no servidor e nunca são mostrados de novo.
 */
export function LigacaoAgtEmpresa() {
  const consulta = useQuery({ queryKey: ['vendas', 'fe', 'ligacao-empresa'], queryFn: () => obter<LigacaoEmpresa>('/vendas/faturacao-eletronica/ligacao-empresa'), retry: false });
  const accao = useAccao<unknown>({ invalidar: [['vendas', 'fe']], tituloErro: 'Não foi possível gravar a ligação à AGT' });
  const [modal, setModal] = useState<'credenciais' | 'chave' | null>(null);
  const [webhook, setWebhook] = useState<{ url: string; segredo: string; cabecalhos: Record<string, string> } | null>(null);
  const [formLigacao] = Form.useForm<{ ativo: boolean; ambiente: string; url_base?: string | null }>();
  const [formCred] = Form.useForm<{ utilizador: string; palavra_passe: string }>();
  const [formChave] = Form.useForm<{ chave_privada: string; publica_ou_certificado?: string; frase_passe?: string }>();
  const l = consulta.data;

  useEffect(() => {
    if (l) formLigacao.setFieldsValue({ ativo: l.ativo, ambiente: l.ambiente, url_base: l.url_base });
  }, [l, formLigacao]);

  if (consulta.isLoading) return <Skeleton active />;
  if (consulta.error || !l) return <Alert type="error" showIcon message="Não foi possível obter a ligação da empresa à AGT." />;

  const carregarFicheiro = async (campo: 'chave_privada' | 'publica_ou_certificado', ficheiros: FileList | null) => {
    const f = ficheiros?.[0];
    if (f) formChave.setFieldValue(campo, await lerFicheiro(f));
  };

  return (
    <Card
      title={<><IconeFa nome="plug" style={{ marginRight: 6 }} />Ligação da empresa à AGT {l.ativo ? <Tag color="green">Activa</Tag> : <Tag>Inactiva</Tag>}</>}
      extra={
        <Button icon={<IconeFa nome="vial" />} loading={accao.isPending && accao.variables?.url.endsWith('testar')} onClick={() => accao.mutate({ url: '/vendas/faturacao-eletronica/ligacao-empresa/testar' })}>
          Testar ligação
        </Button>
      }
    >
      <Typography.Paragraph type="secondary">
        Cada empresa comunica com a AGT com a sua chave de contribuinte (descarregada no Portal do Contribuinte) e, se a AGT as atribuir, as suas credenciais. Tudo é guardado
        cifrado no servidor e nunca volta a ser mostrado. Sem ligação própria, usa-se a configuração do servidor.
      </Typography.Paragraph>
      {!l.software.configurado && (
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Os dados do software certificado (productId, versão e n.º de validação) não estão configurados no servidor." />
      )}
      {l.chave.expira_em_breve && (
        <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`O certificado da chave expira em ${l.chave.dias_para_expirar} dia(s). Carregue a chave nova antes dessa data.`} />
      )}
      {l.ultimo_teste && (
        <Alert type={l.ultimo_teste.ok ? 'success' : 'error'} showIcon style={{ marginBottom: 12 }} message={`Último teste (${formatarDataHora(l.ultimo_teste.em)}): ${l.ultimo_teste.mensagem}`} />
      )}
      <Descriptions size="small" column={COLUNAS_DESCRICOES} bordered style={{ marginBottom: 16 }}>
        <Descriptions.Item label="NIF da empresa">{l.nif ?? <Tag color="red">Inválido</Tag>}</Descriptions.Item>
        <Descriptions.Item label="Ambiente">{ROTULO_AMBIENTE[l.ambiente] ?? l.ambiente}</Descriptions.Item>
        <Descriptions.Item label="Endereço">{l.url_efectiva}</Descriptions.Item>
        <Descriptions.Item label="Credenciais">
          {l.credenciais.origem === 'empresa' ? textoConfigurado(l.credenciais.atualizadas_em, l.credenciais.atualizadas_por) : l.credenciais.origem === 'servidor' ? 'As do servidor (produtor)' : <Tag color="red">Por configurar</Tag>}
        </Descriptions.Item>
        <Descriptions.Item label="Chave do contribuinte">
          {l.chave.origem === 'empresa' ? (
            <>
              {textoConfigurado(l.chave.atualizada_em, l.chave.atualizada_por)} · RSA {l.chave.bits} bits · versão {l.chave.versao}
              {l.chave.certificado_valido_ate && <> · certificado válido até {formatarDataHora(l.chave.certificado_valido_ate)}</>}
              {l.chave.impressao_digital && (
                <Typography.Text type="secondary" style={{ display: 'block', fontFamily: 'monospace', fontSize: 12, wordBreak: 'break-all' }}>SHA-256 {l.chave.impressao_digital}</Typography.Text>
              )}
            </>
          ) : l.chave.origem === 'servidor' ? 'Ficheiro no servidor' : <Tag color="red">Por configurar</Tag>}
        </Descriptions.Item>
        <Descriptions.Item label="Software">{[l.software.productId, l.software.productVersion, l.software.softwareValidationNumber].filter(Boolean).join(' · ') || '—'}</Descriptions.Item>
        <Descriptions.Item label="Notificações (webhook)">{l.webhook.configurado ? textoConfigurado(l.webhook.atualizado_em, l.webhook.atualizado_por) : 'Desligadas'}</Descriptions.Item>
      </Descriptions>

      <Form
        form={formLigacao}
        layout="vertical"
        onFinish={(v) => accao.mutate({ metodo: 'put', url: '/vendas/faturacao-eletronica/ligacao-empresa', dados: { ativo: !!v.ativo, ambiente: v.ambiente, url_base: v.url_base?.trim() || null } })}
      >
        <Flex wrap gap={16} align="flex-end">
          <Form.Item name="ativo" label="Ligação própria" valuePropName="checked" style={{ marginBottom: 8 }}>
            <Switch checkedChildren="Activa" unCheckedChildren="Inactiva" />
          </Form.Item>
          <Form.Item name="ambiente" label="Ambiente" rules={[{ required: true }]} style={{ marginBottom: 8, minWidth: 220 }}>
            <Select options={Object.entries(ROTULO_AMBIENTE).map(([value, label]) => ({ value, label }))} />
          </Form.Item>
          <Form.Item name="url_base" label="Endereço (só se a AGT o mudar)" rules={[{ type: 'url', message: 'Endereço inválido.' }]} style={{ marginBottom: 8, flex: '1 1 260px' }}>
            <Input placeholder="https://sifphml.minfin.gov.ao/sigt/fe/v1/" />
          </Form.Item>
          <Button type="primary" htmlType="submit" loading={accao.isPending} style={{ marginBottom: 8 }}>Gravar</Button>
        </Flex>
      </Form>
      <Space wrap style={{ marginTop: 8 }}>
        <Button icon={<IconeFa nome="key" />} onClick={() => setModal('chave')}>{l.chave.origem === 'empresa' ? 'Substituir chave (rotação)' : 'Carregar chave do contribuinte'}</Button>
        <Button icon={<IconeFa nome="user-lock" />} onClick={() => setModal('credenciais')}>{l.credenciais.origem === 'empresa' ? 'Alterar credenciais' : 'Definir credenciais'}</Button>
        {l.credenciais.origem === 'empresa' && (
          <Button danger onClick={() => Modal.confirm({ title: 'Retirar as credenciais da empresa?', content: 'Passam a usar-se as do servidor, se existirem.', okText: 'Retirar', cancelText: 'Cancelar',
            onOk: () => accao.mutateAsync({ metodo: 'delete', url: '/vendas/faturacao-eletronica/ligacao-empresa/credenciais' }) })}>
            Retirar credenciais
          </Button>
        )}
        <Button
          icon={<IconeFa nome="satellite-dish" />}
          onClick={() =>
            Modal.confirm({
              title: l.webhook.configurado ? 'Gerar um endereço e um segredo novos?' : 'Activar as notificações da AGT (webhook)?',
              content: `${l.webhook.nota} O segredo é mostrado uma única vez.`,
              okText: 'Gerar',
              cancelText: 'Cancelar',
              onOk: async () => {
                const r = await accao.mutateAsync({ url: '/vendas/faturacao-eletronica/ligacao-empresa/webhook' });
                setWebhook(r.dados as { url: string; segredo: string; cabecalhos: Record<string, string> });
              },
            })
          }
        >
          {l.webhook.configurado ? 'Rodar segredo do webhook' : 'Activar webhook'}
        </Button>
        {l.webhook.configurado && (
          <Button danger onClick={() => accao.mutate({ metodo: 'delete', url: '/vendas/faturacao-eletronica/ligacao-empresa/webhook' })}>Desligar webhook</Button>
        )}
      </Space>

      <Modal
        open={modal === 'credenciais'}
        title="Credenciais de acesso aos serviços da AGT"
        width={larguraModal(480)}
        okText="Gravar"
        cancelText="Cancelar"
        confirmLoading={accao.isPending}
        destroyOnHidden
        onCancel={() => setModal(null)}
        onOk={() => formCred.submit()}
      >
        <Typography.Paragraph type="secondary">Utilizador e palavra-passe fornecidos pela AGT (autenticação Basic). Ficam cifrados e não voltam a ser mostrados.</Typography.Paragraph>
        <Form
          form={formCred}
          layout="vertical"
          onFinish={async (v) => {
            await accao.mutateAsync({ metodo: 'put', url: '/vendas/faturacao-eletronica/ligacao-empresa/credenciais', dados: v });
            formCred.resetFields();
            setModal(null);
          }}
        >
          <Form.Item name="utilizador" label="Utilizador" rules={[{ required: true, message: 'Indique o utilizador.' }, { max: 200 }]}>
            <Input autoComplete="off" />
          </Form.Item>
          <Form.Item name="palavra_passe" label="Palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }, { max: 500 }]}>
            <Input.Password autoComplete="new-password" />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        open={modal === 'chave'}
        title="Chave do contribuinte"
        width={larguraModal(640)}
        okText="Validar e gravar"
        cancelText="Cancelar"
        confirmLoading={accao.isPending}
        destroyOnHidden
        onCancel={() => setModal(null)}
        onOk={() => formChave.submit()}
      >
        <Typography.Paragraph type="secondary">
          Chave privada RSA (PEM, pelo menos 2048 bits) emitida pela AGT no Portal do Contribuinte. Opcional: a chave pública ou o certificado, para confirmar o par e a validade. A
          chave anterior deixa de ser usada de imediato.
        </Typography.Paragraph>
        <Form
          form={formChave}
          layout="vertical"
          onFinish={async (v) => {
            await accao.mutateAsync({ url: '/vendas/faturacao-eletronica/ligacao-empresa/chave', dados: { chave_privada: v.chave_privada, publica_ou_certificado: v.publica_ou_certificado || null, frase_passe: v.frase_passe || null } });
            formChave.resetFields();
            setModal(null);
          }}
        >
          <Form.Item name="chave_privada" label="Chave privada (PEM)" rules={[{ required: true, message: 'Cole a chave ou escolha o ficheiro .pem.' }]}>
            <Input.TextArea rows={5} spellCheck={false} style={{ fontFamily: 'monospace' }} placeholder="-----BEGIN PRIVATE KEY-----" />
          </Form.Item>
          <input type="file" accept=".pem,.key,.txt" aria-label="Ficheiro da chave privada" onChange={(e) => void carregarFicheiro('chave_privada', e.target.files)} style={{ marginBottom: 16 }} />
          <Form.Item name="frase_passe" label="Frase-passe (se a chave estiver cifrada)">
            <Input.Password autoComplete="off" />
          </Form.Item>
          <Form.Item name="publica_ou_certificado" label="Chave pública ou certificado (PEM, opcional)">
            <Input.TextArea rows={4} spellCheck={false} style={{ fontFamily: 'monospace' }} placeholder="-----BEGIN PUBLIC KEY----- ou -----BEGIN CERTIFICATE-----" />
          </Form.Item>
          <input type="file" accept=".pem,.crt,.cer,.txt" aria-label="Ficheiro da chave pública ou do certificado" onChange={(e) => void carregarFicheiro('publica_ou_certificado', e.target.files)} />
        </Form>
      </Modal>

      <Modal open={webhook !== null} title="Webhook da AGT" width={larguraModal(640)} footer={<Button type="primary" onClick={() => setWebhook(null)}>Já guardei</Button>} onCancel={() => setWebhook(null)}>
        {webhook && (
          <>
            <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Guarde já o segredo: não volta a ser mostrado." />
            <Descriptions size="small" column={1} bordered>
              <Descriptions.Item label="Endereço"><Typography.Text copyable style={{ wordBreak: 'break-all' }}>{webhook.url}</Typography.Text></Descriptions.Item>
              <Descriptions.Item label="Segredo HMAC"><Typography.Text copyable code style={{ wordBreak: 'break-all' }}>{webhook.segredo}</Typography.Text></Descriptions.Item>
              {Object.entries(webhook.cabecalhos).map(([k, v]) => (
                <Descriptions.Item key={k} label={k}>{v}</Descriptions.Item>
              ))}
            </Descriptions>
          </>
        )}
      </Modal>
    </Card>
  );
}
