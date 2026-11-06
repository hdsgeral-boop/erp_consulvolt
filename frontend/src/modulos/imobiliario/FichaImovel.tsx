import { Alert, Button, Descriptions, Drawer, Empty, Skeleton, Space, Tabs, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { TabelaComModos } from '@/componentes/vistas';
import { larguraGaveta } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, criarFracaoUnica, eliminarFracao, importarPortas, listarFracoes, obterImovel, type Fracao, type Listas } from './api';
import { FichaFracao, EtiquetaEstado } from './FichaFracao';
import { useRefrescar } from './hooks';
import { formatarArea, formatarKz, formatarPercentagem, principal, rotuloDe } from './logica';
import { ModalGerar, ModalFracao } from './ModaisFracao';

export interface PermissoesImovel { editarImovel: boolean; editarFraccao: boolean; mudarEstado: boolean }

/** Ficha do imóvel: resumo com avisos, fracções (nova, gerar por piso, importar portas, única) e documentos ligados. */
export function FichaImovel({ id, listas, pode, aoEditar, aoFechar }: { id: number | null; listas: Listas | undefined; pode: PermissoesImovel; aoEditar: () => void; aoFechar: () => void }) {
  const refrescar = useRefrescar();
  const imovel = useQuery({ queryKey: [...CHAVES.imoveis, id], queryFn: () => obterImovel(id as number), enabled: id !== null });
  const fracoes = useQuery({ queryKey: CHAVES.fracoes(id ?? 0), queryFn: () => listarFracoes(id as number), enabled: id !== null });
  const [editar, setEditar] = useState<Fracao | null | undefined>(undefined);
  const [gerar, setGerar] = useState(false);
  const [aberta, setAberta] = useState<number | null>(null);
  useEffect(() => { if (imovel.error) notificarErro(imovel.error, 'Não foi possível carregar o imóvel'); }, [imovel.error]);
  useEffect(() => { if (fracoes.error) notificarErro(fracoes.error, 'Não foi possível carregar as fracções'); }, [fracoes.error]);
  const i = imovel.data;
  const aoErro = (e: unknown) => notificarErro(e);
  const importar = useMutation({ mutationFn: () => importarPortas(id as number), onError: aoErro, onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); } });
  const unica = useMutation({ mutationFn: () => criarFracaoUnica(id as number), onError: aoErro, onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); } });
  const eliminar = useMutation({ mutationFn: (f: Fracao) => eliminarFracao(f.id), onError: aoErro, onSuccess: async () => { message.success('Fracção eliminada.'); await refrescar(); } });

  return (
    <Drawer title={i ? `${i.codigo} · ${i.nome}` : 'Imóvel'} open={id !== null} onClose={aoFechar} size={larguraGaveta(960)} destroyOnHidden
      extra={i && pode.editarImovel && <Button onClick={aoEditar}>Editar imóvel</Button>}>
      {!i ? <Skeleton active /> : (
        <Tabs items={[
          { key: 'resumo', label: 'Resumo', children: (
            <>
              <Descriptions bordered size="small" column={{ xs: 1, md: 2 }}>
                <Descriptions.Item label="Tipo">{rotuloDe(listas?.tipos_imovel, i.tipo)}</Descriptions.Item>
                <Descriptions.Item label="Papel">{rotuloDe(listas?.papeis, i.papel)}{i.proprietario_nome ? ` · ${i.proprietario_nome}` : ''}</Descriptions.Item>
                <Descriptions.Item label="Estado">{rotuloDe(listas?.estados_imovel, i.estado)}</Descriptions.Item>
                <Descriptions.Item label="Edifício do imobilizado">{i.edificio ? `${i.edificio.codigo ?? ''} ${i.edificio.descricao ?? ''}`.trim() : 'Sem ligação'}</Descriptions.Item>
                <Descriptions.Item label="Morada" span={2}>{[i.morada, i.municipio, i.provincia].filter(Boolean).join(', ') || '—'}</Descriptions.Item>
                <Descriptions.Item label="Artigo matricial">{i.matriz_predial ?? '—'}</Descriptions.Item>
                <Descriptions.Item label="Registo predial / licença">{[i.registo_predial, i.licenca_utilizacao].filter(Boolean).join(' · ') || '—'}</Descriptions.Item>
                <Descriptions.Item label="Áreas (terreno / bruta / útil)">{formatarArea(i.area_terreno)} / {formatarArea(i.area_bruta)} / {formatarArea(i.area_util)}</Descriptions.Item>
                <Descriptions.Item label="Valor patrimonial">{formatarKz(i.valor_patrimonial)}</Descriptions.Item>
                <Descriptions.Item label="Valor de aquisição">{formatarKz(i.valor_aquisicao)}</Descriptions.Item>
                <Descriptions.Item label="Isenção da detenção">{rotuloDe(listas?.isencoes_detencao, i.isencao_detencao)}</Descriptions.Item>
                <Descriptions.Item label="Ocupação">{i.ocupadas} de {i.total_fracoes} fracção(ões) · {formatarPercentagem(i.taxa_ocupacao)}</Descriptions.Item>
                <Descriptions.Item label="Renda de referência (soma)">{formatarKz(i.renda_referencia)}</Descriptions.Item>
                {i.notas && <Descriptions.Item label="Notas" span={2}>{i.notas}</Descriptions.Item>}
              </Descriptions>
              {(i.avisos?.length ?? 0) > 0 && (
                <Alert style={{ marginTop: 12 }} type="warning" showIcon title="Avisos (não impedem nada)"
                  description={<ul className="imo-lista">{i.avisos?.map((a) => <li key={a}>{a}</li>)}</ul>} />
              )}
            </>
          ) },
          { key: 'fracoes', label: `Fracções (${i.total_fracoes + i.acessorios})`, children: (
            <>
              {pode.editarFraccao && (
                <Space wrap style={{ marginBottom: 12 }}>
                  <Button type="primary" onClick={() => setEditar(null)}>Nova fracção</Button>
                  <Button onClick={() => setGerar(true)}>Gerar por piso</Button>
                  {i.ativo_edificio_id && <Button loading={importar.isPending} onClick={() => importar.mutate()}>Importar portas do edifício</Button>}
                  {(fracoes.data?.length ?? 0) === 0 && <Button loading={unica.isPending} onClick={() => unica.mutate()}>Criar fracção única</Button>}
                </Space>
              )}
              <TabelaComModos<Fracao>
                idVista="imobiliario_fracoes" rowKey="id" size="small" loading={fracoes.isFetching} dataSource={fracoes.data ?? []} scroll={{ x: 'max-content' }}
                impressao={{ titulo: `Fracções de ${i.codigo}` }}
                columns={[
                  { title: 'Código', dataIndex: 'codigo', ocultavel: false },
                  { title: 'Designação', dataIndex: 'designacao', principal: true, render: (v: string, f: Fracao) => <Button type="link" style={{ padding: 0 }} onClick={() => setAberta(f.id)}>{v}</Button>, valorImpressao: (f: Fracao) => f.designacao },
                  { title: 'Piso', dataIndex: 'andar', render: (v: string | null) => v ?? '—' },
                  { title: 'Natureza', dataIndex: 'natureza', render: (v: string) => rotuloDe(listas?.naturezas, v), valorImpressao: (f: Fracao) => rotuloDe(listas?.naturezas, f.natureza) },
                  { title: 'Tipologia', dataIndex: 'tipologia', render: (v: string) => rotuloDe(listas?.tipologias, v), valorImpressao: (f: Fracao) => rotuloDe(listas?.tipologias, f.tipologia) },
                  { title: 'Área bruta', dataIndex: 'area_bruta', align: 'right', render: (v: number) => formatarArea(v), valorImpressao: (f: Fracao) => formatarArea(f.area_bruta) },
                  { title: 'Renda de ref.', dataIndex: 'renda_referencia', align: 'right', render: (v: number | null) => formatarKz(v), valorImpressao: (f: Fracao) => formatarKz(f.renda_referencia) },
                  { title: 'Estado', dataIndex: 'estado', etiqueta: true, render: (v: Fracao['estado']) => <EtiquetaEstado estado={v} />, valorImpressao: (f: Fracao) => rotuloDe(listas?.estados_fracao, f.estado) },
                ]}
                accoes={(f) => [
                  { icone: 'eye', titulo: 'Abrir a ficha', onClick: () => setAberta(f.id) },
                  ...(pode.editarFraccao ? [
                    { icone: 'edit', titulo: 'Editar', onClick: () => setEditar(f) },
                    { icone: 'trash', titulo: 'Eliminar', variante: 'perigo' as const, desactivado: ['ARRENDADA', 'VENDIDA'].includes(f.estado) ? 'Mude o estado antes de apagar' : false,
                      confirmar: { titulo: `Eliminar a fracção «${f.designacao}»?`, textoOk: 'Eliminar', perigo: true }, onClick: () => eliminar.mutate(f) },
                  ] : []),
                ]}
              />
              {i.acessorios > 0 && <p style={{ marginTop: 8 }}>{(fracoes.data ?? []).filter((f) => !principal(f)).length} acessório(s) (arrecadações e vagas) não contam para a taxa de ocupação.</p>}
            </>
          ) },
          { key: 'documentos', label: `Documentos (${i.documentos.length})`, children: (
            i.documentos.length === 0 ? <Empty description="Sem documentos ligados. Edite o imóvel para juntar ligações." /> : (
              <ul className="imo-lista">
                {i.documentos.map((d) => <li key={d.url}><a href={d.url} target="_blank" rel="noopener noreferrer">{d.nome || d.url}</a> <span>{rotuloDe(listas?.tipos_documento, d.tipo)}{d.data ? ` · ${d.data}` : ''}</span></li>)}
              </ul>
            )
          ) },
        ]} />
      )}
      {i && <ModalFracao imovel={i} fracao={editar} listas={listas} aoFechar={() => setEditar(undefined)} />}
      {i && <ModalGerar imovel={i} listas={listas} aberto={gerar} aoFechar={() => setGerar(false)} />}
      <FichaFracao id={aberta} listas={listas} podeMudarEstado={pode.mudarEstado} aoFechar={() => setAberta(null)} />
    </Drawer>
  );
}

