import { Alert, Button, Card, Checkbox, Descriptions, Empty, Form, Input, Modal, Space, Table, Tag, Typography, Upload, message } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import type { UploadFile } from 'antd/es/upload/interface';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { formatarDataHora, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { notificarErro } from '@/utilitarios/erros';

interface Pessoa {
  id: number;
  nome: string;
}

interface Equilibrio {
  empresa_id: number;
  empresa: string;
  linhas: number;
  debito: number;
  credito: number;
  diferenca: number;
  equilibrada: boolean;
  diferenca_no_legado: number;
}

interface Simulacao {
  execucao: string | null;
  resumo: Record<string, number>;
  tabelas: number;
  divergentes: string[];
  orfaos: Record<string, number> | unknown[];
  equilibrio: Equilibrio[];
  duracao_segundos: number | null;
  sem_problemas: boolean;
}

export interface PedidoRestauro {
  id: number;
  estado: string;
  nome_ficheiro: string;
  sha256: string;
  tamanho_bytes: number;
  pedido_por: Pessoa;
  pedido_em: string;
  simulacao: Simulacao | null;
  simulado_em: string | null;
  aprovado_por: Pessoa | null;
  aprovado_em: string | null;
  expira_em: string | null;
  executado_por: Pessoa | null;
  iniciado_em: string | null;
  concluido_em: string | null;
  copia_seguranca: string | null;
  relatorio: { execucao?: string; resumo?: Simulacao | null; fase?: string } | null;
  saida: string | null;
  erro: string | null;
  historico: { em: string; por: string; texto: string }[];
}

interface Resposta {
  pode: boolean;
  utilizador_id: number;
  pedidos: PedidoRestauro[];
}

const CHAVE = ['sistema', 'restauros-legado'];

export const ESTADOS_RESTAURO: Record<string, { rotulo: string; cor: string }> = {
  SIMULANDO: { rotulo: 'A simular…', cor: 'processing' },
  SIMULADO: { rotulo: 'Simulado — por aprovar', cor: 'blue' },
  SIMULACAO_FALHADA: { rotulo: 'Simulação falhada', cor: 'red' },
  APROVADO: { rotulo: 'Aprovado — por executar', cor: 'gold' },
  EM_EXECUCAO: { rotulo: 'Em execução…', cor: 'processing' },
  CONCLUIDO: { rotulo: 'Concluído', cor: 'green' },
  FALHADO: { rotulo: 'Falhado', cor: 'red' },
  CANCELADO: { rotulo: 'Cancelado', cor: 'default' },
};

/** Pode aprovar: outro super-administrador, simulação concluída sem problemas. */
export function podeAprovar(p: Pick<PedidoRestauro, 'estado' | 'pedido_por' | 'simulacao'>, utilizadorId: number): boolean {
  return p.estado === 'SIMULADO' && p.pedido_por.id !== utilizadorId && !!p.simulacao?.sem_problemas;
}

const inteiro = (n: number) => formatarNumero(Math.round(n));
const tamanho = (b: number) => (b >= 1048576 ? `${formatarNumero(Math.round((b / 1048576) * 10) / 10)} MB` : `${inteiro(b / 1024)} KB`);
const ROTULOS_RESUMO: Record<string, string> = {
  linhas_reais_lidas: 'Linhas lidas',
  linhas_migradas: 'Linhas migradas',
  linhas_em_quarentena: 'Postas de parte (quarentena)',
  linhas_ficticias_descartadas: 'Linhas fictícias descartadas',
  tabelas_com_divergencia_de_contagem: 'Tabelas com contagem diferente',
  fks_com_orfaos: 'Ligações sem registo (órfãos)',
  empresas_desequilibradas: 'Empresas com D ≠ C (vindas do legado)',
};

/**
 * Configurações › Manutenção de dados › «Restauro do sistema antigo»: substitui TODOS os dados pelos de um backup do
 * legado (JSON Dexie). Carregar → simulação automática → aprovação por outro super-administrador → execução com cópia
 * de segurança prévia. Regras no servidor (ServicoRestauroLegado).
 */
export function RestauroLegado() {
  const cliente = useQueryClient();
  const consulta = useQuery({
    queryKey: CHAVE,
    queryFn: () => obter<Resposta>('/sistema/restauros-legado'),
    retry: false,
    refetchInterval: (q) => ((q.state.data?.pedidos ?? []).some((p) => p.estado === 'SIMULANDO' || p.estado === 'EM_EXECUCAO') ? 5000 : false),
  });
  const [ficheiros, setFicheiros] = useState<UploadFile[]>([]);
  const [senha, setSenha] = useState('');
  const [aCarregar, setACarregar] = useState(false);
  const [aprovar, setAprovar] = useState<PedidoRestauro | null>(null);
  const [executar, setExecutar] = useState<PedidoRestauro | null>(null);
  const recarregar = () => void cliente.invalidateQueries({ queryKey: CHAVE });
  const dados = consulta.data;
  // só super-administradores: o servidor responde 403 a todos os outros
  const semAcesso = (consulta.error as { response?: { status?: number } } | null)?.response?.status === 403;
  const activo = (dados?.pedidos ?? []).some((p) => ['SIMULANDO', 'SIMULADO', 'APROVADO', 'EM_EXECUCAO'].includes(p.estado));

  const carregar = async () => {
    const f = ficheiros[0]?.originFileObj;
    if (!f) return;
    const fd = new FormData();
    fd.append('ficheiro', f);
    fd.append('palavra_passe', senha);
    setACarregar(true);
    try {
      const r = await enviar<PedidoRestauro>('post', '/sistema/restauros-legado', fd);
      message.success(r.mensagem);
      setFicheiros([]);
      setSenha('');
      recarregar();
    } catch (e) {
      notificarErro(e, 'Não foi possível carregar o backup');
    } finally {
      setACarregar(false);
    }
  };

  const cancelar = (p: PedidoRestauro) =>
    Modal.confirm({
      title: `Cancelar o restauro #${p.id}?`,
      content: 'O ficheiro carregado é apagado do servidor.',
      okText: 'Cancelar o restauro',
      cancelText: 'Voltar',
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          const r = await enviar('post', `/sistema/restauros-legado/${p.id}/cancelar`);
          message.success(r.mensagem);
          recarregar();
        } catch (e) {
          notificarErro(e, 'Não foi possível cancelar');
        }
      },
    });

  const colunas: ColumnsType<PedidoRestauro> = [
    { title: 'N.º', dataIndex: 'id', width: 70, render: (v: number) => `#${v}` },
    { title: 'Ficheiro', key: 'f', render: (_, p) => <><strong>{p.nome_ficheiro}</strong><div style={{ fontSize: 12, color: '#64748b' }}>{tamanho(p.tamanho_bytes)}</div></> },
    { title: 'Pedido por', key: 'p', render: (_, p) => <>{p.pedido_por.nome}<div style={{ fontSize: 12, color: '#64748b' }}>{formatarDataHora(p.pedido_em)}</div></> },
    { title: 'Aprovado por', key: 'a', render: (_, p) => (p.aprovado_por ? <>{p.aprovado_por.nome}<div style={{ fontSize: 12, color: '#64748b' }}>até {formatarDataHora(p.expira_em)}</div></> : '—') },
    { title: 'Estado', dataIndex: 'estado', render: (v: string) => <Tag color={ESTADOS_RESTAURO[v]?.cor}>{ESTADOS_RESTAURO[v]?.rotulo ?? v}</Tag> },
    {
      title: 'Acções',
      key: 'acc',
      render: (_, p) =>
        dados?.pode ? (
          <Space wrap>
            {p.estado === 'SIMULADO' && (
              <Button size="small" type="primary" disabled={!podeAprovar(p, dados.utilizador_id)} onClick={() => setAprovar(p)}
                title={p.pedido_por.id === dados.utilizador_id ? 'Tem de ser aprovado por outro super-administrador' : undefined}>
                Aprovar
              </Button>
            )}
            {p.estado === 'APROVADO' && <Button size="small" danger type="primary" onClick={() => setExecutar(p)}>Executar restauro</Button>}
            {['SIMULADO', 'SIMULACAO_FALHADA', 'APROVADO'].includes(p.estado) && <Button size="small" onClick={() => cancelar(p)}>Cancelar</Button>}
          </Space>
        ) : null,
    },
  ];

  return (
    <>
      <Alert
        type="error"
        showIcon
        style={{ marginBottom: 16 }}
        message="Restauro da plataforma a partir do backup do sistema antigo"
        description={
          <>
            Substitui <strong>todos</strong> os dados (todas as empresas, utilizadores, perfis e auditoria) pelos do ficheiro JSON exportado pelo sistema antigo.
            O processo tem três passos: a simulação corre sozinha e não grava nada; depois, <strong>outro</strong> super-administrador aprova; por fim, executa-se.
            Antes de substituir é feita uma cópia completa da base. Enquanto a simulação ou o restauro correm (alguns minutos), o sistema fica lento ou em espera.
            No fim do restauro todos os utilizadores têm de entrar de novo, com as contas que vierem no backup.
          </>
        }
      />
      {(semAcesso || (dados && !dados.pode)) && <Alert type="info" showIcon style={{ marginBottom: 16 }} message="Só um super-administrador pode carregar, aprovar ou executar um restauro." />}
      {dados?.pode && (
        <Card size="small" title={<span><IconeFa nome="upload" /> Carregar backup do sistema antigo</span>} style={{ marginBottom: 16 }}>
          {activo ? (
            <Typography.Text type="secondary">Já existe um restauro em curso ou por decidir: conclua-o ou cancele-o antes de carregar outro.</Typography.Text>
          ) : (
            <Space direction="vertical" style={{ width: '100%', maxWidth: 560 }}>
              <Upload.Dragger accept=".json,application/json" maxCount={1} fileList={ficheiros} beforeUpload={() => false} onChange={({ fileList }) => setFicheiros(fileList.slice(-1))}>
                <p style={{ margin: 0 }}><IconeFa nome="file-code" style={{ fontSize: 28, color: '#2563eb' }} /></p>
                <p style={{ margin: '8px 0 0' }}>Arraste aqui o ficheiro <strong>.json</strong> do backup do sistema antigo, ou clique para o escolher (máx. 100 MB).</p>
              </Upload.Dragger>
              <Input.Password placeholder="A sua palavra-passe" aria-label="A sua palavra-passe" autoComplete="current-password" value={senha} onChange={(e) => setSenha(e.target.value)} />
              <Button type="primary" icon={<IconeFa nome="play" />} disabled={!ficheiros.length || !senha} loading={aCarregar} onClick={() => void carregar()}>
                Carregar e simular
              </Button>
            </Space>
          )}
        </Card>
      )}
      <Card size="small" title="Pedidos de restauro">
        <Table<PedidoRestauro>
          rowKey="id"
          size="small"
          loading={consulta.isLoading}
          columns={colunas}
          dataSource={dados?.pedidos ?? []}
          pagination={false}
          scroll={{ x: 'max-content' }}
          locale={{ emptyText: <Empty description="Ainda não houve restauros." /> }}
          expandable={{ expandedRowRender: (p) => <DetalheRestauro p={p} />, rowExpandable: () => true }}
        />
      </Card>
      {aprovar && <ModalAprovar p={aprovar} aoFechar={() => setAprovar(null)} aoConcluir={recarregar} />}
      {executar && <ModalExecutar p={executar} aoFechar={() => setExecutar(null)} aoConcluir={recarregar} />}
    </>
  );
}

function ResumoSimulacao({ s }: { s: Simulacao }) {
  return (
    <>
      <Alert
        type={s.sem_problemas ? 'success' : 'error'}
        showIcon
        style={{ marginBottom: 12 }}
        message={s.sem_problemas ? 'Sem divergências de contagem nem órfãos: o backup pode ser restaurado.' : `Com problemas: ${s.divergentes.length} tabela(s) com contagem diferente${Array.isArray(s.orfaos) ? '' : `, ${Object.keys(s.orfaos).length} ligação(ões) com órfãos`}.`}
      />
      <Descriptions size="small" bordered column={{ xs: 1, md: 2 }} style={{ marginBottom: 12 }}>
        <Descriptions.Item label="Tabelas">{s.tabelas}</Descriptions.Item>
        {Object.entries(ROTULOS_RESUMO).map(([k, rotulo]) => (s.resumo[k] !== undefined ? <Descriptions.Item key={k} label={rotulo}>{inteiro(s.resumo[k])}</Descriptions.Item> : null))}
        {s.duracao_segundos != null && <Descriptions.Item label="Duração">{inteiro(s.duracao_segundos)} s</Descriptions.Item>}
      </Descriptions>
      {s.divergentes.length > 0 && <Typography.Paragraph type="danger">Contagem diferente em: {s.divergentes.join(', ')}</Typography.Paragraph>}
      {s.equilibrio.length > 0 && (
        <Table<Equilibrio>
          size="small"
          rowKey="empresa_id"
          pagination={false}
          scroll={{ x: 'max-content' }}
          dataSource={s.equilibrio}
          title={() => <strong>Equilíbrio contabilístico por empresa (D − C)</strong>}
          columns={[
            { title: 'Empresa', key: 'e', render: (_, e) => `#${e.empresa_id} ${e.empresa}` },
            { title: 'Linhas', dataIndex: 'linhas', align: 'right', render: (v: number) => inteiro(v) },
            { title: 'D − C', dataIndex: 'diferenca', align: 'right', render: (v: number) => formatarKz(v) },
            { title: 'D − C no legado', dataIndex: 'diferenca_no_legado', align: 'right', render: (v: number) => formatarKz(v) },
            { title: '', dataIndex: 'equilibrada', render: (v: boolean) => (v ? <Tag color="green">equilibrada</Tag> : <Tag color="orange">D ≠ C</Tag>) },
          ]}
        />
      )}
    </>
  );
}

function DetalheRestauro({ p }: { p: PedidoRestauro }) {
  const execucao = p.relatorio?.resumo;
  return (
    <div style={{ padding: '4px 8px' }}>
      <Typography.Paragraph type="secondary" style={{ fontSize: 12 }}>SHA-256 do ficheiro: <code>{p.sha256}</code></Typography.Paragraph>
      {p.erro && <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Erro" description={<pre style={{ whiteSpace: 'pre-wrap', margin: 0, fontSize: 12 }}>{p.erro}</pre>} />}
      {p.estado === 'SIMULANDO' && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="A simulação está a correr em segundo plano (1 a 3 minutos). Esta página actualiza-se sozinha." />}
      {p.estado === 'EM_EXECUCAO' && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`Restauro em execução${p.relatorio?.fase ? ` (fase: ${p.relatorio.fase === 'COPIA_SEGURANCA' ? 'cópia de segurança' : 'migração'})` : ''}. No fim terá de entrar de novo.`} />}
      {execucao ? (
        <>
          <Typography.Title level={5}>Resultado do restauro</Typography.Title>
          <ResumoSimulacao s={execucao} />
        </>
      ) : p.simulacao ? (
        <>
          <Typography.Title level={5}>Resultado da simulação (nada foi gravado)</Typography.Title>
          <ResumoSimulacao s={p.simulacao} />
        </>
      ) : null}
      {p.copia_seguranca && <Typography.Paragraph style={{ marginTop: 12 }}>Cópia de segurança anterior ao restauro: <code>{p.copia_seguranca}</code> (no disco privado do servidor, pasta «restauros»).</Typography.Paragraph>}
      {p.historico.length > 0 && (
        <>
          <Typography.Title level={5} style={{ marginTop: 12 }}>Histórico</Typography.Title>
          <ul style={{ margin: 0, paddingInlineStart: 18 }}>
            {p.historico.map((h, i) => <li key={i}>{formatarDataHora(h.em)} — {h.por}: {h.texto}</li>)}
          </ul>
        </>
      )}
    </div>
  );
}

function ModalAprovar({ p, aoFechar, aoConcluir }: { p: PedidoRestauro; aoFechar: () => void; aoConcluir: () => void }) {
  const [form] = Form.useForm<{ palavra_passe: string; nota?: string }>();
  const [aEnviar, setAEnviar] = useState(false);
  return (
    <Modal open title={`Aprovar o restauro #${p.id}`} okText="Aprovar" cancelText="Cancelar" confirmLoading={aEnviar} onCancel={aoFechar} onOk={() => form.submit()} destroyOnHidden>
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Ao aprovar, confirma que o ficheiro e o resultado da simulação estão correctos. A aprovação vale 24 horas." />
      <Form form={form} layout="vertical" onFinish={async (v) => {
        setAEnviar(true);
        try {
          const r = await enviar('post', `/sistema/restauros-legado/${p.id}/aprovar`, v);
          message.success(r.mensagem);
          aoConcluir();
          aoFechar();
        } catch (e) {
          notificarErro(e, 'Não foi possível aprovar');
        } finally {
          setAEnviar(false);
        }
      }}>
        <Form.Item name="palavra_passe" label="A sua palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }]}>
          <Input.Password autoComplete="current-password" />
        </Form.Item>
        <Form.Item name="nota" label="Nota (opcional)">
          <Input maxLength={255} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

function ModalExecutar({ p, aoFechar, aoConcluir }: { p: PedidoRestauro; aoFechar: () => void; aoConcluir: () => void }) {
  const [form] = Form.useForm<{ palavra_passe: string; confirmacao: string; li: boolean }>();
  const [aEnviar, setAEnviar] = useState(false);
  const frase = `RESTAURAR #${p.id}`;
  return (
    <Modal open title={`Executar o restauro #${p.id}`} okText="Executar restauro" okButtonProps={{ danger: true }} cancelText="Cancelar" confirmLoading={aEnviar} onCancel={aoFechar} onOk={() => form.submit()} destroyOnHidden>
      <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Todos os dados actuais vão ser substituídos pelos do backup" description="É feita antes uma cópia completa da base. No fim, todos os utilizadores (incluindo você) têm de entrar de novo com as contas do backup." />
      <Form form={form} layout="vertical" onFinish={async ({ palavra_passe, confirmacao }) => {
        setAEnviar(true);
        try {
          const r = await enviar('post', `/sistema/restauros-legado/${p.id}/executar`, { palavra_passe, confirmacao });
          message.warning(r.mensagem, 8);
          aoConcluir();
          aoFechar();
        } catch (e) {
          notificarErro(e, 'Não foi possível executar o restauro');
        } finally {
          setAEnviar(false);
        }
      }}>
        <Form.Item name="palavra_passe" label="A sua palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }]}>
          <Input.Password autoComplete="current-password" />
        </Form.Item>
        <Form.Item name="confirmacao" label={<>Escreva <strong>{frase}</strong> para confirmar</>} rules={[{ required: true, message: 'Escreva a confirmação.' }, { validator: (_, v) => (String(v ?? '').trim().toUpperCase() === frase ? Promise.resolve() : Promise.reject(new Error(`Escreva exactamente «${frase}».`))) }]}>
          <Input autoComplete="off" />
        </Form.Item>
        <Form.Item name="li" valuePropName="checked" rules={[{ validator: (_, v) => (v ? Promise.resolve() : Promise.reject(new Error('Tem de confirmar.'))) }]}>
          <Checkbox>Compreendo que todos os dados actuais serão substituídos.</Checkbox>
        </Form.Item>
      </Form>
    </Modal>
  );
}
