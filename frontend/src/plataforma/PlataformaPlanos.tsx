import { Button, Card, Checkbox, Form, InputNumber, Modal, Space, Table, Tag, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';

interface Versao { id: number; numero: number; estado: 'RASCUNHO' | 'PUBLICADA' | 'DESCONTINUADA'; preco_mensal: number; moeda: string; max_empresas: number | null; max_utilizadores: number | null; modulos: string[]; publicada_em: string | null }
interface PlanoP { id: number; codigo: string; nome: string; preco_mensal: number; moeda: string; max_empresas: number | null; max_utilizadores: number | null; clientes: number; versoes: Versao[] }
interface Dados { planos: PlanoP[]; modulos: Record<string, string> }

const CHAVE = ['plataforma', 'planos'];
const COR = { RASCUNHO: 'gold', PUBLICADA: 'green', DESCONTINUADA: 'default' } as const;

/** Plataforma SaaS — planos e versões (plataforma_planos): rascunho editável, publicação imutável, limites e módulos. */
export default function PlataformaPlanos() {
  const qc = useQueryClient();
  const [edit, setEdit] = useState<Versao | null>(null);
  const [form] = Form.useForm();
  const { data, isLoading } = useQuery({ queryKey: CHAVE, queryFn: () => obter<Dados>('/plataforma/planos') });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });
  const criar = useMutation({ mutationFn: (plano: number) => enviar('post', `/plataforma/planos/${plano}/versoes`, {}), onSuccess: () => { message.success('Rascunho criado.'); refrescar(); }, onError: (e) => notificarErro(e) });
  const gravar = useMutation({ mutationFn: (v: Record<string, unknown>) => enviar('put', `/plataforma/versoes/${edit?.id}`, v), onSuccess: () => { message.success('Rascunho guardado.'); setEdit(null); refrescar(); }, onError: (e) => notificarErro(e) });
  const publicar = useMutation({ mutationFn: (id: number) => enviar('post', `/plataforma/versoes/${id}/publicar`, {}), onSuccess: () => { message.success('Versão publicada.'); refrescar(); }, onError: (e) => notificarErro(e) });
  const modulos = Object.entries(data?.modulos ?? {}).map(([value, label]) => ({ value, label }));
  return (
    <>
      <CabecalhoPagina titulo="Planos e versões" subtitulo="Cada alteração é um rascunho; publicar fixa a versão e aplica preço e limites ao plano" />
      {isLoading ? (
        <Card loading={true} style={{ minHeight: 200 }} />
      ) : (data?.planos ?? []).length === 0 ? (
        <Card>
          <div style={{ textAlign: 'center', padding: '32px 0' }}>
            <p style={{ color: '#888', marginBottom: 16 }}>Nenhum plano comercial configurado no momento.</p>
            <Button type="primary" onClick={refrescar}>Actualizar</Button>
          </div>
        </Card>
      ) : (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
          {(data?.planos ?? []).map((p) => (
            <Card key={p.id} loading={isLoading} title={<>{p.nome} <Tag color="geekblue">{p.codigo}</Tag> <Tag color="blue">{p.clientes} cliente(s)</Tag></>}
              extra={<Button type="dashed" onClick={() => criar.mutate(p.id)} loading={criar.isPending}>Novo rascunho</Button>}>
              <p style={{ marginBottom: 16, color: '#4a5568' }}>
                <strong>Em vigor:</strong> {formatarNumero(p.preco_mensal)} {p.moeda}/mês — empresas {p.max_empresas ?? 'ilimitadas'}, utilizadores {p.max_utilizadores ?? 'ilimitados'}
              </p>
              <Table<Versao> rowKey="id" size="small" pagination={false} dataSource={p.versoes} columns={[
                { title: 'Versão', render: (_, v) => <Tag color="blue">v{v.numero}</Tag> },
                { title: 'Estado', render: (_, v) => <Tag color={COR[v.estado]}>{v.estado}</Tag> },
                { title: 'Preço/mês', align: 'right', render: (_, v) => <strong>{formatarNumero(v.preco_mensal)} {v.moeda}</strong> },
                { title: 'Empresas', render: (_, v) => v.max_empresas ?? '∞' },
                { title: 'Utilizadores', render: (_, v) => v.max_utilizadores ?? '∞' },
                { 
                  title: 'Módulos', 
                  render: (_, v) => (
                    <Space size={[0, 4]} wrap>
                      {v.modulos.map((m) => (
                        <Tag key={m} style={{ fontSize: 11 }}>{data?.modulos[m] ?? m}</Tag>
                      ))}
                    </Space>
                  ) 
                },
                { title: 'Publicada', render: (_, v) => (v.publicada_em ? formatarDataHora(v.publicada_em) : '—') },
                { title: '', render: (_, v) => v.estado === 'RASCUNHO' ? (
                  <Space>
                    <Button size="small" onClick={() => { form.setFieldsValue(v); setEdit(v); }}>Editar</Button>
                    <Button size="small" type="primary" onClick={() => Modal.confirm({ title: 'Publicar esta versão?', content: 'Fica imutável e passa a valer para o plano.', onOk: () => publicar.mutateAsync(v.id) })}>Publicar</Button>
                  </Space>
                ) : null },
              ]} />
            </Card>
          ))}
        </Space>
      )}
      <Modal open={edit !== null} title={edit ? `Rascunho v${edit.numero}` : ''} okText="Guardar" confirmLoading={gravar.isPending} onCancel={() => setEdit(null)} destroyOnClose onOk={() => form.validateFields().then((v) => gravar.mutate(v))}>
        <Form form={form} layout="vertical">
          <Form.Item name="preco_mensal" label="Preço mensal" rules={[{ required: true }]}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="max_empresas" label="Máx. empresas (vazio = ilimitadas)"><InputNumber min={1} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="max_utilizadores" label="Máx. utilizadores (vazio = ilimitados)"><InputNumber min={1} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="modulos" label="Módulos incluídos (registo; ainda não condicionam o acesso)"><Checkbox.Group options={modulos} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}