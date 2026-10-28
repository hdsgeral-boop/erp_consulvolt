import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Button, ColorPicker, Input, Modal, Popconfirm, Space, Tag, Typography } from 'antd';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import type { ItemCatalogoProjeto } from './comum/tipos';

type Recurso = 'etiquetas' | 'sinalizacoes';

function useCatalogo(recurso: Recurso, activo: boolean) {
  const cliente = useQueryClient();
  const chave = ['projectos', recurso];
  const consulta = useQuery({ queryKey: chave, queryFn: () => obter<ItemCatalogoProjeto[]>(`/projetos/${recurso}`), enabled: activo, retry: false });
  const invalidar = () => {
    cliente.invalidateQueries({ queryKey: chave });
    cliente.invalidateQueries({ queryKey: ['projectos'] });
  };
  const criar = useMutation({
    mutationFn: (d: { nome: string; cor: string }) => enviar('post', `/projetos/${recurso}`, d),
    onSuccess: invalidar,
    onError: (e) => notificarErro(e, 'Não foi possível criar.'),
  });
  const actualizar = useMutation({
    mutationFn: ({ id, ...d }: { id: number; nome: string; cor: string }) => enviar('put', `/projetos/${recurso}/${id}`, d),
    onSuccess: invalidar,
    onError: (e) => notificarErro(e, 'Não foi possível actualizar.'),
  });
  const eliminar = useMutation({
    mutationFn: (id: number) => enviar('delete', `/projetos/${recurso}/${id}`),
    onSuccess: invalidar,
    onError: (e) => notificarErro(e, 'Não foi possível eliminar.'),
  });
  return { lista: consulta.data ?? [], aCarregar: consulta.isLoading, criar, actualizar, eliminar };
}

function ItemCatalogoEditavel({ item, aoActualizar, aoEliminar }: { item: ItemCatalogoProjeto; aoActualizar: (d: { nome: string; cor: string }) => void; aoEliminar: () => void }) {
  const [edicao, setEdicao] = useState(false);
  const [nome, setNome] = useState(item.nome);
  const [cor, setCor] = useState(item.cor);
  const gravar = () => {
    aoActualizar({ nome: nome.trim(), cor });
    setEdicao(false);
  };

  if (edicao) {
    return (
      <Space.Compact style={{ width: '100%' }}>
        <ColorPicker size="small" value={cor} onChange={(v) => setCor(v.toHexString().slice(0, 7))} />
        <Input size="small" value={nome} onChange={(e) => setNome(e.target.value)} maxLength={60} onPressEnter={gravar} autoFocus />
        <Button size="small" onClick={gravar} disabled={!nome.trim()}>Gravar</Button>
        <Button size="small" onClick={() => { setNome(item.nome); setCor(item.cor); setEdicao(false); }}>Cancelar</Button>
      </Space.Compact>
    );
  }

  return (
    <Space style={{ width: '100%', justifyContent: 'space-between' }}>
      <Tag color={item.cor}>{item.nome}</Tag>
      <Space size={4}>
        <Button size="small" type="text" icon={<EditOutlined />} onClick={() => setEdicao(true)} aria-label={`Editar ${item.nome}`} />
        <Popconfirm title={`Eliminar «${item.nome}»?`} description="Remove-a de todos os projectos que a têm." okText="Eliminar" cancelText="Cancelar" onConfirm={aoEliminar}>
          <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label={`Eliminar ${item.nome}`} />
        </Popconfirm>
      </Space>
    </Space>
  );
}

function ColunaCatalogo({ titulo, recurso, activo, corOmissao, placeholder }: { titulo: string; recurso: Recurso; activo: boolean; corOmissao: string; placeholder: string }) {
  const c = useCatalogo(recurso, activo);
  const [nome, setNome] = useState('');
  const [cor, setCor] = useState(corOmissao);
  const adicionar = () => {
    const t = nome.trim();
    if (!t) return;
    c.criar.mutate({ nome: t, cor }, { onSuccess: () => { setNome(''); setCor(corOmissao); } });
  };

  return (
    <div style={{ flex: '1 1 260px', minWidth: 240 }}>
      <Typography.Text strong>{titulo}</Typography.Text>
      <Space.Compact style={{ width: '100%', marginTop: 8, marginBottom: 12 }}>
        <ColorPicker size="small" value={cor} onChange={(v) => setCor(v.toHexString().slice(0, 7))} />
        <Input placeholder={placeholder} value={nome} onChange={(e) => setNome(e.target.value)} onPressEnter={adicionar} maxLength={60} />
        <Button icon={<PlusOutlined />} onClick={adicionar} loading={c.criar.isPending} disabled={!nome.trim()} />
      </Space.Compact>
      <Space direction="vertical" style={{ width: '100%' }} size={6}>
        {c.lista.map((item) => (
          <ItemCatalogoEditavel key={item.id} item={item} aoActualizar={(d) => c.actualizar.mutate({ id: item.id, ...d })} aoEliminar={() => c.eliminar.mutate(item.id)} />
        ))}
        {!c.aCarregar && c.lista.length === 0 && <Typography.Text type="secondary" style={{ fontSize: 12 }}>Ainda sem nenhuma.</Typography.Text>}
      </Space>
    </div>
  );
}

/** Modal «Etiquetas e sinalizações dos projectos»: catálogo partilhado (por empresa, com cor), aberto a partir do cabeçalho da Carteira. */
export function ModalCatalogoEtiquetasSinalizacoes({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  return (
    <Modal title="Etiquetas e sinalizações dos projectos" open={aberto} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>} width={larguraModal(760)}>
      <Typography.Paragraph type="secondary">
        As <b>etiquetas</b> classificam (sector, tipo de obra, cliente…). As <b>sinalizações</b> chamam a atenção e guardam o motivo,
        quem a levantou e quando. Apagar uma do catálogo retira-a de todos os projectos que a tinham.
      </Typography.Paragraph>
      <Space align="start" wrap size={24} style={{ width: '100%' }}>
        <ColunaCatalogo titulo="Etiquetas" recurso="etiquetas" activo={aberto} corOmissao="#2563eb" placeholder="Ex.: Consultoria, Projecto interno" />
        <ColunaCatalogo titulo="Sinalizações" recurso="sinalizacoes" activo={aberto} corOmissao="#dc2626" placeholder="Ex.: Em risco, Bloqueado" />
      </Space>
    </Modal>
  );
}
