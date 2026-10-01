import { Alert, Button, Checkbox, DatePicker, Descriptions, Form, InputNumber, Modal, Radio, Select, Space, Table, Typography, Upload, message } from 'antd';
import { UploadOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { enviar } from '@/api/cliente';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarKz } from '@/utilitarios/formatacao';
import { SeletorActivo, SeletorCategoria, SeletorProjecto } from './comum/componentes';
import { lerCsvImportacao } from './comum/regras';
import type { Afectacao, LinhaImportacao } from './comum/tipos';

// ───────────── Importação ─────────────

interface ResultadoImportacao {
  novos: number;
  existentes: { linha?: number; codigo?: string; descricao?: string }[] | number;
  repetidos: number;
  importados: number;
  actualizados: number;
  ignorados: number;
  categorias_criadas: string[];
}

/**
 * Importação de activos a partir de um CSV (lido no navegador e enviado como JSON a POST /ativos/bens/importar).
 * Primeiro simula (mostra novos, existentes e categorias a criar) e só depois grava.
 */
export function ModalImportarActivos({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const [linhas, setLinhas] = useState<LinhaImportacao[]>([]);
  const [ignoradas, setIgnoradas] = useState<string[]>([]);
  const [decisao, setDecisao] = useState<'IGNORAR' | 'ACTUALIZAR'>('IGNORAR');
  const [simulacao, setSimulacao] = useState<ResultadoImportacao | null>(null);
  const [aEnviar, setAEnviar] = useState(false);
  const accao = useAccao<ResultadoImportacao>({ invalidar: [['activos']], aoSucesso: () => fechar() });

  const fechar = () => {
    setLinhas([]);
    setIgnoradas([]);
    setSimulacao(null);
    aoFechar();
  };

  const ler = async (f: File) => {
    const r = lerCsvImportacao(await f.text());
    if (!r.linhas.length) message.error('O ficheiro não tem linhas válidas (a 1.ª linha deve ter os cabeçalhos).');
    setLinhas(r.linhas);
    setIgnoradas(r.ignoradas);
    setSimulacao(null);
    return false;
  };

  const simular = async () => {
    setAEnviar(true);
    try {
      const r = await enviar<ResultadoImportacao>('post', '/ativos/bens/importar', { linhas, decisao, simular: true });
      setSimulacao(r.dados);
    } catch (e) {
      notificarErro(e, 'A simulação falhou');
    } finally {
      setAEnviar(false);
    }
  };

  const existentes = simulacao ? (Array.isArray(simulacao.existentes) ? simulacao.existentes.length : simulacao.existentes) : 0;

  return (
    <Modal
      title="Importar activos"
      open={aberto}
      onCancel={fechar}
      width={860}
      destroyOnClose
      footer={[
        <Button key="c" onClick={fechar}>Cancelar</Button>,
        <Button key="s" disabled={!linhas.length} loading={aEnviar} onClick={simular}>Simular</Button>,
        <Button key="i" type="primary" disabled={!simulacao} loading={accao.isPending} onClick={() => accao.mutate({ url: '/ativos/bens/importar', dados: { linhas, decisao } })}>
          Importar {linhas.length} linha(s)
        </Button>,
      ]}
    >
      <Typography.Paragraph type="secondary">
        CSV com cabeçalhos (separador «;» ou «,»): <code>codigo; descricao; valor_aquisicao; categoria; vida_util; anos_amortizados; amortizacao_acumulada;
        ano_amortizacao_acumulada; data_aquisicao</code>. As categorias que não existirem são criadas com taxa de 25%.
      </Typography.Paragraph>
      <Space direction="vertical" style={{ width: '100%' }}>
        <Upload accept=".csv,.txt" maxCount={1} beforeUpload={ler} showUploadList={false}>
          <Button icon={<UploadOutlined />}>Escolher ficheiro CSV</Button>
        </Upload>
        {ignoradas.length > 0 && <Alert type="warning" showIcon message={`Colunas ignoradas: ${ignoradas.join(', ')}`} />}
        <Radio.Group value={decisao} onChange={(e) => { setDecisao(e.target.value); setSimulacao(null); }}>
          <Radio value="IGNORAR">Ignorar os códigos que já existem</Radio>
          <Radio value="ACTUALIZAR">Actualizar a descrição e a categoria dos existentes</Radio>
        </Radio.Group>
        {linhas.length > 0 && (
          <Table<LinhaImportacao>
            size="small"
            rowKey={(_, i) => String(i)}
            dataSource={linhas}
            pagination={{ pageSize: 8 }}
            scroll={{ x: 'max-content' }}
            columns={[
              { title: 'Código', dataIndex: 'codigo' },
              { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
              { title: 'Categoria', dataIndex: 'categoria' },
              { title: 'Valor (Kz)', dataIndex: 'valor_aquisicao', align: 'right', render: (v) => formatarKz(v) },
              { title: 'Vida (meses)', dataIndex: 'vida_util', align: 'right' },
              { title: 'Data', dataIndex: 'data_aquisicao' },
            ]}
          />
        )}
        {simulacao && (
          <Descriptions bordered size="small" column={3} title="Resultado da simulação">
            <Descriptions.Item label="Novos">{simulacao.novos}</Descriptions.Item>
            <Descriptions.Item label="Já existentes">{existentes}</Descriptions.Item>
            <Descriptions.Item label="Repetidos no ficheiro">{simulacao.repetidos}</Descriptions.Item>
            <Descriptions.Item label="Categorias a criar" span={3}>{simulacao.categorias_criadas?.join(', ') || '—'}</Descriptions.Item>
          </Descriptions>
        )}
      </Space>
    </Modal>
  );
}

// ───────────── Edição em massa ─────────────

const CAMPOS_MASSA = [
  { value: 'categoria_ativo_id', label: 'Categoria' },
  { value: 'centro_custo_id', label: 'Centro de custo' },
  { value: 'unidade_negocio_id', label: 'Unidade de negócio' },
  { value: 'fornecedor_id', label: 'Fornecedor' },
  { value: 'estado', label: 'Estado' },
  { value: 'data_aquisicao', label: 'Data de aquisição' },
  { value: 'vida_util', label: 'Vida útil (meses)' },
  { value: 'vida_util_restante', label: 'Vida útil restante (meses)' },
  { value: 'valor_residual', label: 'Valor residual' },
  { value: 'quota_fixa', label: 'Quota fixa' },
];

/** Altera um ou mais campos em vários activos de uma vez (POST /ativos/bens/edicao-massa). Os activos bloqueados recusam campos de valor. */
export function ModalEdicaoMassa({ ids, aberto, aoFechar }: { ids: number[]; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<Record<string, unknown>>();
  const [campos, setCampos] = useState<string[]>([]);
  const accao = useAccao<{ actualizados: number }>({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (aberto) {
      form.resetFields();
      setCampos([]);
    }
  }, [aberto, form]);

  const gravar = (v: Record<string, unknown>) => {
    const valores: Record<string, unknown> = {};
    for (const c of campos) valores[c] = c === 'data_aquisicao' ? dataApi(v[c] as dayjs.Dayjs) : (v[c] ?? null);
    accao.mutate({ url: '/ativos/bens/edicao-massa', dados: { ids, campos: valores } });
  };

  const editor = (c: string) => {
    switch (c) {
      case 'categoria_ativo_id': return <SeletorCategoria />;
      case 'centro_custo_id': return <SeletorAux tabela="centros-custo" style={{ width: '100%' }} />;
      case 'unidade_negocio_id': return <SeletorUnidade style={{ width: '100%' }} />;
      case 'fornecedor_id': return <SeletorTerceiro papel="FORNECEDOR" />;
      case 'estado': return <Select options={[{ value: 'ACTIVO', label: 'Activo' }, { value: 'INACTIVO', label: 'Inactivo' }]} />;
      case 'data_aquisicao': return <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />;
      case 'valor_residual':
      case 'quota_fixa': return <InputNumber min={0} precision={2} style={{ width: '100%' }} />;
      default: return <InputNumber min={0} precision={0} style={{ width: '100%' }} />;
    }
  };

  return (
    <Modal
      title={`Edição em massa — ${ids.length} activo(s)`}
      open={aberto}
      onCancel={aoFechar}
      onOk={() => form.submit()}
      okText="Aplicar"
      cancelText="Cancelar"
      okButtonProps={{ disabled: !campos.length }}
      confirmLoading={accao.isPending}
      destroyOnClose
    >
      <Checkbox.Group options={CAMPOS_MASSA} value={campos} onChange={(v) => setCampos(v as string[])} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 4, marginBottom: 16 }} />
      <Form form={form} layout="vertical" onFinish={gravar}>
        {campos.map((c) => (
          <Form.Item key={c} name={c} label={CAMPOS_MASSA.find((x) => x.value === c)?.label}>
            {editor(c)}
          </Form.Item>
        ))}
      </Form>
    </Modal>
  );
}

// ───────────── Transferência de centro de custo ─────────────

export function ModalTransferir({ activoId, aberto, aoFechar }: { activoId: number | null; aberto: boolean; aoFechar: () => void }) {
  const [form] = Form.useForm<{ centro_custo_destino_id: number; data: dayjs.Dayjs; projeto_id?: number }>();
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (aberto) form.setFieldsValue({ data: dayjs(), centro_custo_destino_id: undefined, projeto_id: undefined });
  }, [aberto, form]);
  return (
    <Modal title="Transferir de centro de custo" open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Transferir" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => accao.mutate({ url: `/ativos/bens/${activoId}/transferencias`, dados: { ...v, data: dataApi(v.data), projeto_id: v.projeto_id ?? null } })}
      >
        <Form.Item name="centro_custo_destino_id" label="Centro de custo de destino" rules={[{ required: true, message: 'Escolha o destino.' }]}>
          <SeletorAux tabela="centros-custo" allowClear={false} style={{ width: '100%' }} />
        </Form.Item>
        <Form.Item name="data" label="Data" rules={[{ required: true }]}>
          <DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} />
        </Form.Item>
        <Form.Item name="projeto_id" label="Projecto (opcional)">
          <SeletorProjecto allowClear />
        </Form.Item>
      </Form>
    </Modal>
  );
}

// ───────────── Afectação a projecto ─────────────

export function ModalAfectacao({ aberto, afectacao, activoId, aoFechar }: { aberto: boolean; afectacao?: Afectacao | null; activoId?: number; aoFechar: () => void }) {
  const [form] = Form.useForm<{ ativo_imobilizado_id: number; projeto_id: number; data_inicio: dayjs.Dayjs; data_fim?: dayjs.Dayjs }>();
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(
      afectacao
        ? { ativo_imobilizado_id: afectacao.ativo_imobilizado_id, projeto_id: afectacao.projeto_id, data_inicio: dayjs(afectacao.data_inicio), data_fim: afectacao.data_fim ? dayjs(afectacao.data_fim) : undefined }
        : { ativo_imobilizado_id: activoId, data_inicio: dayjs() },
    );
  }, [aberto, afectacao, activoId, form]);
  return (
    <Modal title={afectacao ? 'Editar afectação' : 'Afectar activo a projecto'} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} destroyOnClose>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => {
          const dados = { ...v, data_inicio: dataApi(v.data_inicio), data_fim: dataApi(v.data_fim) ?? null };
          accao.mutate(afectacao ? { metodo: 'put', url: `/ativos/afetacoes/${afectacao.id}`, dados } : { url: '/ativos/afetacoes', dados });
        }}
      >
        <Form.Item name="ativo_imobilizado_id" label="Activo" rules={[{ required: true, message: 'Escolha o activo.' }]}>
          <SeletorActivo apenasActivos disabled={!!activoId || !!afectacao} rotuloInicial={afectacao?.ativo_imobilizado ? `${afectacao.ativo_imobilizado.codigo} — ${afectacao.ativo_imobilizado.descricao}` : undefined} />
        </Form.Item>
        <Form.Item name="projeto_id" label="Projecto" rules={[{ required: true, message: 'Escolha o projecto.' }]}>
          <SeletorProjecto />
        </Form.Item>
        <Space>
          <Form.Item name="data_inicio" label="Início" rules={[{ required: true }]}>
            <DatePicker format="DD/MM/YYYY" />
          </Form.Item>
          <Form.Item name="data_fim" label="Fim">
            <DatePicker format="DD/MM/YYYY" />
          </Form.Item>
        </Space>
      </Form>
    </Modal>
  );
}
