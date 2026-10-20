import { Alert, Button, Card, Checkbox, Form, Input, InputNumber, Modal, Space, Switch, Table, Tag, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarNumero } from '@/utilitarios/formatacao';
import { ModalNovoPlano } from './ModalNovoPlano';

interface Versao { id: number; numero: number; estado: 'RASCUNHO' | 'PUBLICADA' | 'DESCONTINUADA'; preco_mensal: number; moeda: string; max_empresas: number | null; max_utilizadores: number | null; modulos: string[]; publicada_em: string | null }
interface PlanoP { id: number; codigo: string; nome: string; descricao: string | null; ordem: number; ativo: boolean; tem_versao_publicada: boolean; preco_mensal: number; moeda: string; max_empresas: number | null; max_utilizadores: number | null; clientes: number; versoes: Versao[] }
interface Dados { planos: PlanoP[]; modulos: Record<string, string> }

const CHAVE = ['plataforma', 'planos'];
const COR = { RASCUNHO: 'gold', PUBLICADA: 'green', DESCONTINUADA: 'default' } as const;

/** Plataforma SaaS — planos e versões (plataforma_planos): rascunho editável, publicação imutável, limites e módulos. */
export default function PlataformaPlanos() {
  const qc = useQueryClient();
  const [edit, setEdit] = useState<Versao | null>(null);
  const [form] = Form.useForm();
  const [formPlano] = Form.useForm();
  const [novo, setNovo] = useState(false);
  const [editPlano, setEditPlano] = useState<PlanoP | null>(null);
  const { data, isLoading } = useQuery({ queryKey: CHAVE, queryFn: () => obter<Dados>('/plataforma/planos') });
  const refrescar = () => qc.invalidateQueries({ queryKey: CHAVE });
  const criar = useMutation({ mutationFn: (plano: number) => enviar('post', `/plataforma/planos/${plano}/versoes`, {}), onSuccess: () => { message.success('Rascunho criado.'); refrescar(); }, onError: (e) => notificarErro(e) });
  const gravar = useMutation({ mutationFn: (v: Record<string, unknown>) => enviar('put', `/plataforma/versoes/${edit?.id}`, v), onSuccess: () => { message.success('Rascunho guardado.'); setEdit(null); refrescar(); }, onError: (e) => notificarErro(e) });
  const publicar = useMutation({ mutationFn: (id: number) => enviar('post', `/plataforma/versoes/${id}/publicar`, {}), onSuccess: () => { message.success('Versão publicada.'); refrescar(); }, onError: (e) => notificarErro(e) });
  const gravarPlano = useMutation({
    mutationFn: (v: Record<string, unknown>) => enviar('put', `/plataforma/planos/${editPlano?.id}`, v),
    onSuccess: () => { message.success('Plano actualizado.'); setEditPlano(null); refrescar(); },
    onError: (e) => notificarErro(e),
  });
  const guardarPlano = (v: { nome: string; descricao?: string; ordem: number; ativo: boolean }) => {
    const corpo = { nome: v.nome, descricao: v.descricao || null, ordem: v.ordem ?? 0, ativo: v.ativo };
    if (editPlano?.ativo && !v.ativo && editPlano.clientes > 0) {
      Modal.confirm({
        title: 'Desactivar um plano em uso?',
        content: `${editPlano.clientes} cliente(s) têm este plano. Mantêm o plano e os limites, mas as empresas deixam de o poder escolher ou renovar.`,
        okText: 'Desactivar', onOk: () => gravarPlano.mutateAsync({ ...corpo, confirmar_desativacao: true }),
      });
      return;
    }
    gravarPlano.mutate(corpo);
  };
  const modulos = Object.entries(data?.modulos ?? {}).map(([value, label]) => ({ value, label }));
  return (
    <>
      <CabecalhoPagina titulo="Planos e versões" subtitulo="Cada alteração é um rascunho; publicar fixa a versão e aplica preço e limites ao plano"
        accoes={<Button type="primary" onClick={() => setNovo(true)}>Novo plano</Button>} />
      <Alert type="info" showIcon style={{ marginBottom: 16 }}
        message="Os módulos de cada plano são, por agora, apenas informativos: ainda não condicionam o acesso das empresas. O que se aplica já são o preço e os limites de empresas e utilizadores." />
      {isLoading ? (
        <Card loading={true} style={{ minHeight: 200 }} />
      ) : (data?.planos ?? []).length === 0 ? (
        <Card>
          <div style={{ textAlign: 'center', padding: '32px 0' }}>
            <p style={{ color: '#888', marginBottom: 16 }}>Nenhum plano comercial configurado no momento.</p>
            <Button type="primary" onClick={() => setNovo(true)}>Novo plano</Button>
          </div>
        </Card>
      ) : (
        <Space direction="vertical" size={16} style={{ width: '100%' }}>
          {(data?.planos ?? []).map((p) => (
            <Card key={p.id} loading={isLoading} title={<>{p.nome} <Tag color="geekblue">{p.codigo}</Tag> <Tag color="blue">{p.clientes} cliente(s)</Tag> {p.ativo ? <Tag color="green">Activo</Tag> : <Tag>Inactivo</Tag>}</>}
              extra={<Space>
                <Button onClick={() => { formPlano.setFieldsValue({ nome: p.nome, descricao: p.descricao, ordem: p.ordem, ativo: p.ativo }); setEditPlano(p); }}>Editar plano</Button>
                <Button type="dashed" onClick={() => criar.mutate(p.id)} loading={criar.isPending}>Novo rascunho</Button>
              </Space>}>
              {p.descricao && <p style={{ marginBottom: 8, color: '#718096' }}>{p.descricao}</p>}
              <p style={{ marginBottom: 16, color: '#4a5568' }}>
                {p.tem_versao_publicada ? (
                  <><strong>Em vigor:</strong> {formatarNumero(p.preco_mensal)} {p.moeda}/mês — empresas: {p.max_empresas ?? 'Sem limite'}, utilizadores: {p.max_utilizadores ?? 'Sem limite'}</>
                ) : (
                  <><strong>Sem versão publicada:</strong> publique uma versão para o plano poder ser activado e escolhido pelas empresas.</>
                )}
              </p>
              <Table<Versao> rowKey="id" size="small" pagination={false} dataSource={p.versoes} columns={[
                { title: 'Versão', render: (_, v) => <Tag color="blue">v{v.numero}</Tag> },
                { title: 'Estado', render: (_, v) => <Tag color={COR[v.estado]}>{v.estado}</Tag> },
                { title: 'Preço/mês', align: 'right', render: (_, v) => <strong>{formatarNumero(v.preco_mensal)} {v.moeda}</strong> },
                { title: 'Empresas', render: (_, v) => v.max_empresas ?? 'Sem limite' },
                { title: 'Utilizadores', render: (_, v) => v.max_utilizadores ?? 'Sem limite' },
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
      <ModalNovoPlano aberto={novo} modulos={data?.modulos ?? {}} onFechar={() => setNovo(false)} onCriado={() => { setNovo(false); refrescar(); }} />
      <Modal open={editPlano !== null} title={editPlano ? `Editar plano ${editPlano.codigo}` : ''} okText="Guardar" confirmLoading={gravarPlano.isPending} onCancel={() => setEditPlano(null)} destroyOnClose
        onOk={() => formPlano.validateFields().then((v) => guardarPlano(v)).catch(() => undefined)}>
        <Form form={formPlano} layout="vertical">
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={100} /></Form.Item>
          <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={2000} /></Form.Item>
          <Form.Item name="ordem" label="Ordem de apresentação"><InputNumber min={0} style={{ width: 100 }} /></Form.Item>
          <Form.Item name="ativo" label="Activo (escolhível pelas empresas)" valuePropName="checked" extra="Só pode ser activo com uma versão publicada. O código, o preço e os limites não se alteram aqui (use as versões)."><Switch /></Form.Item>
        </Form>
      </Modal>
      <Modal open={edit !== null} title={edit ? `Rascunho v${edit.numero}` : ''} okText="Guardar" confirmLoading={gravar.isPending} onCancel={() => setEdit(null)} destroyOnClose onOk={() => form.validateFields().then((v) => gravar.mutate(v))}>
        <Form form={form} layout="vertical">
          <Form.Item name="preco_mensal" label="Preço mensal" rules={[{ required: true }]}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="max_empresas" label="Máx. empresas (vazio = sem limite)"><InputNumber min={1} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="max_utilizadores" label="Máx. utilizadores (vazio = sem limite)"><InputNumber min={1} style={{ width: '100%' }} /></Form.Item>
          <Form.Item name="modulos" label="Módulos incluídos (registo; ainda não condicionam o acesso)"><Checkbox.Group options={modulos} /></Form.Item>
        </Form>
      </Modal>
    </>
  );
}