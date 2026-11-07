import { PlusOutlined } from '@ant-design/icons';
import { Button, Card, Empty, Space, Table, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { notificarErro } from '@/utilitarios/erros';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVES_OB, listarFichas, type Ficha } from './apiObras';
import { useListas } from './hooks';
import { rotuloDe } from './logica';
import { ModalFicha } from './ModalFicha';
import './imobiliario.css';

/**
 * Gestão Imobiliária › Fichas de Apetrechamento (ecrã imo_fichas): o que cada género de fracção deve ter — «T2 mobilado», «Loja», «Escritório» —, a medida contra a qual se vê o que
 * falta. Ver: imo_fichas_view · criar, editar e apagar: imo_ficha_editar (também recusadas no servidor). A ficha usada por ordens não se apaga: põe-se inactiva.
 */
export default function FichasApetrechamento() {
  const { pode } = useSessao();
  const listas = useListas();
  const [editar, setEditar] = useState<Ficha | null | undefined>(undefined);
  const q = useQuery({ queryKey: CHAVES_OB.fichas, queryFn: listarFichas });
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar as fichas'); }, [q.error]);
  const editavel = pode('imo_ficha_editar');

  return (
    <>
      <CabecalhoModulo titulo="Fichas de Apetrechamento" icone="clipboard-list" subtitulo="O que cada género de fracção deve ter. É contra isto que se mede o que falta."
        accoes={editavel ? <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar(null)}>Nova ficha</Button> : undefined} />
      <Card>
        <Table<Ficha> rowKey="id" size="middle" loading={q.isFetching} dataSource={q.data ?? []} pagination={false} scroll={{ x: 'max-content' }}
          locale={{ emptyText: <Empty description="Sem fichas. Crie uma por género de fracção — «T2 mobilado», «Loja», «Escritório» — e liste o que cada uma deve ter." /> }}
          columns={[
            { title: 'Ficha', dataIndex: 'nome', key: 'nome', render: (v: string, f: Ficha) => <span><strong>{v}</strong>{f.notas ? <><br /><small>{f.notas}</small></> : null}</span> },
            { title: 'Tipologia', dataIndex: 'tipologia', key: 'tipologia', render: (v: string | null) => rotuloDe(listas?.tipologias, v) },
            { title: 'Finalidade', dataIndex: 'finalidade', key: 'finalidade', render: (v: string | null) => rotuloDe(listas?.finalidades, v) },
            { title: 'Artigos', dataIndex: 'n_itens', key: 'itens', align: 'right' as const, render: (v: number, f: Ficha) => (
              <span title={f.itens.map((i) => `${i.quantidade} × ${i.nome ?? i.descricao}${i.obrigatorio ? '' : ' (opcional)'}`).join('\n')}>{v}</span>) },
            { title: 'Estado', dataIndex: 'activo', key: 'activo', render: (v: boolean) => <Tag color={v ? 'green' : 'default'}>{v ? 'Activa' : 'Inactiva'}</Tag> },
            { title: 'Acções', key: 'accoes', render: (_: unknown, f: Ficha) => <Space>{editavel && <Button size="small" aria-label={`Editar a ficha ${f.nome}`} onClick={() => setEditar(f)}>Editar</Button>}</Space> },
          ]} />
      </Card>
      <ModalFicha ficha={editar} listas={listas} aoFechar={() => setEditar(undefined)} />
    </>
  );
}
