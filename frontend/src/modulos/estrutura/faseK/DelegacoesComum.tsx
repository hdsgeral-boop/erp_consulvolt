import { useQuery } from '@tanstack/react-query';
import { Alert, Button, Checkbox, DatePicker, Form, Input, Modal, Popconfirm, Select, Table, Tag } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect } from 'react';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { SeletorColaborador } from '@/modulos/rh/comum/componentes';
import { useColaboradores } from '@/modulos/rh/comum/consultas';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { AMBITOS, MAX_DIAS_DELEGACAO, podeRevogar, SITUACAO_DELEGACAO, situacaoDelegacao, textoAmbito, TODOS_AMBITOS, validarPeriodoDelegacao } from './regras';
import { candidatosDelegacao } from './api';
import type { AmbitoDelegacao, DadosDelegacao, DelegacaoChefia } from './tipos';

interface Campos {
  delegante_colaborador_id?: number;
  delegado_colaborador_id: number;
  periodo: [Dayjs, Dayjs];
  ambito: AmbitoDelegacao[];
  motivo?: string;
}

/**
 * Formulário de uma delegação de chefia (K5). No portal o delegante é o próprio; na estrutura (`est_delegar`) escolhe-se
 * o delegante («em nome de outro»). As regras (90 dias, não no passado, sem cadeia nem sobreposição) são do servidor;
 * aqui só se trava o período óbvio.
 */
export function ModalNovaDelegacao({ aberto, emNomeDeOutro, aCarregar, aoFechar, aoGravar }: {
  aberto: boolean; emNomeDeOutro?: boolean; aCarregar?: boolean; aoFechar: () => void; aoGravar: (d: DadosDelegacao) => void;
}) {
  const [form] = Form.useForm<Campos>();
  // no portal o chefe comum não lê a lista de colaboradores do RH: o servidor devolve só id e nome dos possíveis delegados
  const candidatos = useQuery({ queryKey: ['rh', 'portal', 'delegacoes', 'candidatos'], queryFn: candidatosDelegacao, enabled: aberto && !emNomeDeOutro });
  useEffect(() => { if (aberto) { form.resetFields(); form.setFieldsValue({ ambito: [...TODOS_AMBITOS] }); } }, [aberto, form]);
  return (
    <Modal open={aberto} title={emNomeDeOutro ? 'Nova delegação de chefia (em nome de outro)' : 'Dar delegação'} okText="Delegar" cancelText="Cancelar" confirmLoading={aCarregar}
      onCancel={aoFechar} onOk={() => form.submit()} width={larguraModal(620)} destroyOnHidden>
      <Alert type="info" showIcon style={{ marginBottom: 12 }}
        title={`Durante o período, o delegado pode decidir o que a chefia decidiria (máximo ${MAX_DIAS_DELEGACAO} dias). Nunca decide o que lhe diga respeito a ele, e não pode delegar de novo o que recebeu.`} />
      <Form form={form} layout="vertical" onFinish={(v) => {
        const [i, f] = v.periodo;
        const erro = validarPeriodoDelegacao(dataApi(i), dataApi(f));
        if (erro) { form.setFields([{ name: 'periodo', errors: [erro] }]); return; }
        aoGravar({
          ...(emNomeDeOutro ? { delegante_colaborador_id: v.delegante_colaborador_id } : {}),
          delegado_colaborador_id: v.delegado_colaborador_id, data_inicio: dataApi(i)!, data_fim: dataApi(f)!, ambito: v.ambito, motivo: v.motivo?.trim() || null,
        });
      }}>
        {emNomeDeOutro && (
          <Form.Item name="delegante_colaborador_id" label="Quem delega (a chefia)" rules={[{ required: true, message: 'Escolha quem delega.' }]}><SeletorColaborador apenasActivos style={{ width: '100%' }} /></Form.Item>
        )}
        <Form.Item name="delegado_colaborador_id" label="Quem fica a decidir (delegado)" extra="Tem de ter utilizador ligado para poder agir."
          rules={[{ required: true, message: 'Escolha o delegado.' }]}>
          {emNomeDeOutro
            ? <SeletorColaborador apenasActivos style={{ width: '100%' }} />
            : <Select showSearch optionFilterProp="label" style={{ width: '100%' }} loading={candidatos.isLoading} placeholder="Escolha o colaborador"
                options={(candidatos.data ?? []).map((c) => ({ value: c.id, label: c.nome }))} />}
        </Form.Item>
        <Form.Item name="periodo" label="Período" rules={[{ required: true, message: 'Indique o período.' }]}>
          <DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isBefore(dayjs(), 'day')} />
        </Form.Item>
        <Form.Item name="ambito" label="Âmbito" rules={[{ required: true, type: 'array', min: 1, message: 'Escolha pelo menos uma área.' }]}>
          <Checkbox.Group options={TODOS_AMBITOS.map((a) => ({ value: a, label: AMBITOS[a].rotulo }))} />
        </Form.Item>
        <Form.Item name="motivo" label="Motivo"><Input maxLength={255} placeholder="Ex.: férias, missão fora do país" /></Form.Item>
      </Form>
    </Modal>
  );
}

/** Tabela das delegações; `mostrar` escolhe quem aparece (o delegante, o delegado ou ambos). */
export function TabelaDelegacoes({ dados, aCarregar, mostrar = 'ambos', aoRevogar, revogando }: {
  dados: DelegacaoChefia[]; aCarregar?: boolean; mostrar?: 'delegante' | 'delegado' | 'ambos'; aoRevogar?: (d: DelegacaoChefia) => void; revogando?: boolean;
}) {
  const colaboradores = useColaboradores();
  const colunas: ColumnsType<DelegacaoChefia> = [
    ...(mostrar !== 'delegado' ? [{ title: 'Delegante', key: 'de', render: (_: unknown, d: DelegacaoChefia) => d.delegante?.nome ?? d.delegante_nome ?? colaboradores.nome(d.delegante_colaborador_id) }] : []),
    ...(mostrar !== 'delegante' ? [{ title: 'Delegado', key: 'para', render: (_: unknown, d: DelegacaoChefia) => d.delegado?.nome ?? d.delegado_nome ?? colaboradores.nome(d.delegado_colaborador_id) }] : []),
    { title: 'Período', key: 'p', render: (_, d) => <span style={{ whiteSpace: 'nowrap' }}>{formatarData(d.data_inicio)} a {formatarData(d.data_fim)}</span> },
    { title: 'Âmbito', dataIndex: 'ambito', responsive: ['md'], render: (a: AmbitoDelegacao[]) => <span style={{ fontSize: '0.8rem' }}>{textoAmbito(a)}</span> },
    { title: 'Motivo', dataIndex: 'motivo', responsive: ['lg'], render: (v: string | null) => <span style={{ fontSize: '0.8rem', color: '#64748b' }}>{v ?? ''}</span> },
    { title: 'Estado', key: 'e', render: (_, d) => { const s = SITUACAO_DELEGACAO[situacaoDelegacao(d)]; return <Tag color={s.cor}>{s.rotulo}</Tag>; } },
    ...(aoRevogar ? [{
      title: '', key: 'a', align: 'right' as const,
      render: (_: unknown, d: DelegacaoChefia) => podeRevogar(d) && (
        <Popconfirm title="Revogar esta delegação?" description="O delegado deixa de poder decidir de imediato." okText="Revogar" cancelText="Cancelar" okButtonProps={{ danger: true, loading: revogando }} onConfirm={() => aoRevogar(d)}>
          <Button size="small" danger>Revogar</Button>
        </Popconfirm>
      ),
    }] : []),
  ];
  return <Table<DelegacaoChefia> rowKey="id" size="small" loading={aCarregar} dataSource={dados} columns={colunas} pagination={dados.length > 20 ? { pageSize: 20 } : false} scroll={scrollTabela()} locale={{ emptyText: 'Sem delegações.' }} />;
}
