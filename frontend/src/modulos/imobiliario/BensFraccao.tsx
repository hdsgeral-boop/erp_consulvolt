import { PlusOutlined } from '@ant-design/icons';
import { Alert, Button, DatePicker, Empty, Form, Input, InputNumber, Modal, Popconfirm, Select, Space, Table, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, listarFracoes, listarImoveis } from './api';
import {
  abrirApetrechamento, apagarBem, CHAVES_OB, compararFicha, gravarBem, listarFichas, obterBens, trazerDoImobilizado, type Bem, type LinhaComparacao, type ListasObras, type OrdemLinha,
} from './apiObras';
import { useRefrescar } from './hooks';
import { formatarQuantidade, ROTULO_BEM, ROTULO_ORIGEM_BEM, temFalta, tituloComparacao } from './logicaObras';
import { SeletorArtigo } from './SeletorArtigo';

interface ValoresBem { produto_id?: number | null; descricao?: string; quantidade: number; estado: string; data: dayjs.Dayjs; notas?: string }

/** Novo bem ou edição (imo_bens_editar). A quantidade é decimal (meia unidade conta como meia); editar não perde a origem nem o activo ligado. */
function ModalBem({ fracao, bem, listas, aoFechar }: { fracao: number; bem: Bem | null | undefined; listas: ListasObras | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<ValoresBem>();
  const aberto = bem !== undefined;
  const refrescar = useRefrescar();
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(bem ? { produto_id: bem.produto_id, descricao: bem.descricao ?? undefined, quantidade: bem.quantidade, estado: bem.estado, data: dayjs(bem.data), notas: bem.notas ?? undefined }
      : { quantidade: 1, estado: 'NOVO', data: dayjs() });
  }, [aberto, bem, form]);
  const gravar = useMutation({
    mutationFn: (v: ValoresBem) => gravarBem(fracao, { produto_id: v.produto_id || null, descricao: v.produto_id ? null : (v.descricao || null), quantidade: v.quantidade, estado: v.estado, data: v.data.format('YYYY-MM-DD'), notas: v.notas || null }, bem?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar o bem'),
    onSuccess: async () => { message.success('Bem gravado.'); await refrescar(); aoFechar(); },
  });

  return (
    <Modal title={bem ? 'Editar o bem' : 'Novo bem'} open={aberto} onCancel={aoFechar} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} width={larguraModal(560)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => gravar.mutate(v)} disabled={gravar.isPending}>
        <Form.Item name="produto_id" label="Artigo do armazém"><SeletorArtigo id="imo-bem-artigo" nomeConhecido={bem?.nome} largura="100%" /></Form.Item>
        <Form.Item name="descricao" label="Descrição" extra="Se não houver artigo."><Input maxLength={200} /></Form.Item>
        <Space wrap align="start" size={12}>
          <Form.Item name="quantidade" label="Quantidade" rules={[{ required: true, type: 'number', min: 0.001, message: 'A quantidade tem de ser maior do que zero.' }]}><InputNumber min={0} step={0.5} precision={3} style={{ width: 130 }} /></Form.Item>
          <Form.Item name="estado" label="Estado" extra="Danificado ou em falta não conta como tendo."><Select style={{ width: 200 }} options={(listas?.estados_bem ?? []).map((e) => ({ value: e.chave, label: e.rotulo }))} /></Form.Item>
          <Form.Item name="data" label="Desde"><DatePicker format="DD/MM/YYYY" allowClear={false} /></Form.Item>
        </Space>
        <Form.Item name="notas" label="Notas"><Input maxLength={300} /></Form.Item>
      </Form>
    </Modal>
  );
}

/**
 * Bens da fracção e apetrechamento: o que está lá dentro (inventário comercial; a planta e o inventário físico são do Activos) e o que falta segundo uma ficha-tipo. A comparação só conta
 * os bens em condições (danificado ou em falta não conta) e, havendo faltas, abre a ordem de apetrechamento. Ver: imo_obras_view ou imo_fichas_view · bens: imo_bens_editar ·
 * ordem de apetrechamento: imo_obra_editar (também recusadas no servidor).
 */
export function BensFraccao({ listas, pode, aoAbrirOrdem }: { listas: ListasObras | undefined; pode: (...p: string[]) => boolean; aoAbrirOrdem: (o: OrdemLinha) => void }) {
  const refrescar = useRefrescar();
  const [imovel, setImovel] = useState<number | null>(null);
  const [fracao, setFracao] = useState<number | null>(null);
  const [ficha, setFicha] = useState<number | null>(null);
  const [editar, setEditar] = useState<Bem | null | undefined>(undefined);
  const imoveis = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis });
  const fracoes = useQuery({ queryKey: CHAVES.fracoes(imovel ?? 0), queryFn: () => listarFracoes(imovel as number), enabled: imovel !== null });
  const fichas = useQuery({ queryKey: CHAVES_OB.fichas, queryFn: listarFichas });
  const bens = useQuery({ queryKey: CHAVES_OB.bens(fracao ?? 0), queryFn: () => obterBens(fracao as number), enabled: fracao !== null });
  const comparacao = useQuery({ queryKey: CHAVES_OB.comparacao(fracao ?? 0, ficha ?? 0), queryFn: () => compararFicha(fracao as number, ficha as number), enabled: fracao !== null && ficha !== null });
  useEffect(() => { if (bens.error) notificarErro(bens.error, 'Não foi possível carregar os bens'); }, [bens.error]);
  const editavel = pode('imo_bens_editar');
  const apagar = useMutation({
    mutationFn: (id: number) => apagarBem(fracao as number, id), onError: (e) => notificarErro(e, 'Não foi possível apagar o bem'),
    onSuccess: async () => { message.success('Bem apagado.'); await refrescar(); },
  });
  const trazer = useMutation({
    mutationFn: () => trazerDoImobilizado(fracao as number), onError: (e) => notificarErro(e, 'Não foi possível trazer os bens do imobilizado'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); },
  });
  const abrir = useMutation({
    mutationFn: () => abrirApetrechamento(fracao as number, ficha as number), onError: (e) => notificarErro(e, 'Não foi possível abrir a ordem de apetrechamento'),
    onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); aoAbrirOrdem(r.dados.ordem); },
  });
  const c = comparacao.data;

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <label htmlFor="imo-bens-imovel">Imóvel</label>
        <Select id="imo-bens-imovel" showSearch optionFilterProp="label" style={{ minWidth: 260 }} value={imovel ?? undefined} placeholder="— imóvel —" loading={imoveis.isLoading}
          options={(imoveis.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} · ${i.nome}` }))} onChange={(v) => { setImovel(v); setFracao(null); setFicha(null); }} />
        <label htmlFor="imo-bens-fracao">Fracção</label>
        <Select id="imo-bens-fracao" showSearch optionFilterProp="label" style={{ minWidth: 200 }} value={fracao ?? undefined} placeholder="— fracção —" disabled={imovel === null} loading={fracoes.isFetching}
          options={(fracoes.data ?? []).map((f) => ({ value: f.id, label: f.designacao }))} onChange={(v) => { setFracao(v); setFicha(null); }} />
      </Space>
      {fracao === null ? <Empty description="Escolha o imóvel e a fracção para ver o que está lá dentro e o que falta." /> : (
        <>
          <Space wrap style={{ marginBottom: 12 }}>
            {editavel && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar(null)}>Bem</Button>}
            {editavel && bens.data?.fracao.ligada_a_porta && <Button loading={trazer.isPending} onClick={() => trazer.mutate()}>Trazer do imobilizado</Button>}
            <label htmlFor="imo-bens-ficha">Comparar com uma ficha</label>
            <Select id="imo-bens-ficha" allowClear style={{ minWidth: 240 }} value={ficha ?? undefined} placeholder="— comparar com uma ficha —" options={(fichas.data ?? []).filter((f) => f.activo).map((f) => ({ value: f.id, label: f.nome }))}
              onChange={(v) => setFicha(v ?? null)} notFoundContent="Crie primeiro uma ficha de apetrechamento." />
          </Space>
          {c && (
            <Alert type={c.completa ? 'success' : 'warning'} showIcon style={{ marginBottom: 12 }} title={tituloComparacao(c.faltam, c.faltam_obrigatorios)}
              description={<>
                <Table<LinhaComparacao> size="small" rowKey="nome" dataSource={c.linhas} pagination={false} scroll={{ x: 'max-content' }}
                  columns={[
                    { title: 'Artigo', dataIndex: 'nome', key: 'n', render: (v: string, l: LinhaComparacao) => <span>{v}{l.obrigatorio ? '' : ' (opcional)'}{l.danificados > 0 ? <small> · {l.danificados} danificado(s)/em falta não contam</small> : null}</span> },
                    { title: 'Precisa', dataIndex: 'precisa', key: 'p', align: 'right' as const, render: (v: string) => formatarQuantidade(v) },
                    { title: 'Tem', dataIndex: 'tem', key: 't', align: 'right' as const, render: (v: string) => formatarQuantidade(v) },
                    { title: 'Falta', dataIndex: 'falta', key: 'f', align: 'right' as const, render: (v: string, l: LinhaComparacao) => <strong style={{ color: temFalta(l) ? '#b91c1c' : '#15803d' }}>{formatarQuantidade(v)}</strong> },
                  ]} />
                {!c.completa && pode('imo_obra_editar') && <Button type="primary" style={{ marginTop: 8 }} loading={abrir.isPending} onClick={() => abrir.mutate()}>Abrir ordem de apetrechamento</Button>}
              </>} />
          )}
          <Table<Bem> size="middle" rowKey="id" loading={bens.isFetching} dataSource={bens.data?.bens ?? []} pagination={false} scroll={{ x: 'max-content' }} locale={{ emptyText: 'Sem bens registados.' }}
            columns={[
              { title: 'Bem', dataIndex: 'nome', key: 'nome', render: (v: string, b: Bem) => <span>{v}{b.ativo_id ? <Tag style={{ marginLeft: 8 }}>activo</Tag> : null}{b.notas ? <><br /><small>{b.notas}</small></> : null}</span> },
              { title: 'Qt.', dataIndex: 'quantidade', key: 'q', align: 'right' as const, render: (v: number) => formatarQuantidade(v) },
              { title: 'Estado', dataIndex: 'estado', key: 'e', render: (v: Bem['estado']) => ROTULO_BEM[v] },
              { title: 'Origem', dataIndex: 'origem', key: 'o', render: (v: string) => ROTULO_ORIGEM_BEM[v] ?? v },
              { title: 'Desde', dataIndex: 'data', key: 'd' },
              { title: 'Acções', key: 'a', render: (_: unknown, b: Bem) => editavel ? (
                <Space>
                  <Button size="small" aria-label={`Editar ${b.nome}`} onClick={() => setEditar(b)}>Editar</Button>
                  <Popconfirm title="Apagar este bem do inventário da fracção?" okText="Apagar" cancelText="Manter" okButtonProps={{ danger: true }} onConfirm={() => apagar.mutate(b.id)}><Button size="small" danger aria-label={`Apagar ${b.nome}`}>Apagar</Button></Popconfirm>
                </Space>) : null },
            ]} />
          <ModalBem fracao={fracao} bem={editar} listas={listas} aoFechar={() => setEditar(undefined)} />
        </>
      )}
    </>
  );
}
