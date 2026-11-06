import { LineChartOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Empty, Input, Select, Skeleton, Space, Table, Tag, Tooltip } from 'antd';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVE_CATALOGOS, chaveIndicadores, chavePlano, listarIndicadores, obterCatalogos, obterPlano, type Indicador } from './api';
import { descreverFonte, FormularioIndicador } from './FormularioIndicador';
import { formatarValorIndicador } from './comum';
import { usePedirMotivo, usePlanoEscolhido } from './hooks';
import { ModalMetas } from './ModalMetas';
import { ModalSerie } from './ModalSerie';
import './estrategia.css';

/**
 * Indicadores e Metas (est_indicadores): de onde vem o valor de cada indicador e qual é a meta de cada período. Ver:
 * est_indicadores_view · indicadores: est_ind_editar · metas: est_metas_editar · entregar valores: est_entregar. As regras (origem,
 * dono, tolerâncias, congelamento do plano aprovado, valores financeiros reservados) são do servidor.
 */
export default function Indicadores() {
  const { pode } = useSessao();
  const qc = useQueryClient();
  const refrescar = () => qc.invalidateQueries({ queryKey: ['estrategia'] });
  const { planos, plano: escolhido, escolher, carregando } = usePlanoEscolhido();
  const planoId = escolhido?.id ?? 0;
  const { data: det } = useQuery({ queryKey: chavePlano(planoId), queryFn: () => obterPlano(planoId), enabled: planoId > 0 });
  const { data: indicadores, isLoading } = useQuery({ queryKey: chaveIndicadores(planoId), queryFn: () => listarIndicadores(planoId), enabled: planoId > 0 });
  const { data: cat } = useQuery({ queryKey: CHAVE_CATALOGOS, queryFn: obterCatalogos, staleTime: 5 * 60_000 });
  const [procura, setProcura] = useState('');
  const [formulario, setFormulario] = useState<{ indicador?: Indicador } | null>(null);
  const [metas, setMetas] = useState<Indicador | null>(null);
  const [serie, setSerie] = useState<Indicador | null>(null);

  const plano = det?.plano;
  const aprovador = pode('est_plano_aprovar');
  const encerrado = plano?.estado === 'ENCERRADO';
  const livre = !!plano && (!plano.congelado || (aprovador && !encerrado));
  const motivo = usePedirMotivo(!!plano?.congelado && aprovador && !encerrado);
  const podeIndicadores = pode('est_ind_editar') && livre;
  const podeMetas = pode('est_metas_editar') && livre;
  const nomeDono = (i: Indicador) => (i.dono_colaborador_id ? cat?.colaboradores.find((c) => c.id === i.dono_colaborador_id)?.nome ?? '—' : i.dono_externo ?? null);

  const visiveis = useMemo(() => {
    const q = procura.trim().toLowerCase();
    return (indicadores ?? []).filter((i) => !q || [i.nome, i.objectivo?.nome, i.origem_rotulo, i.dono_externo, nomeDono(i)].some((t) => (t ?? '').toLowerCase().includes(q)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [indicadores, procura, cat]);
  const semMetas = (indicadores ?? []).filter((i) => i.ativo && !i.metas_count).length;

  return (
    <>
      <CabecalhoModulo titulo="Indicadores e Metas" icone="tasks" subtitulo="De onde vem o valor de cada indicador e qual é a meta de cada período." />
      <Space wrap style={{ marginBottom: 12 }}>
        <Select aria-label="Plano" style={{ minWidth: 260 }} loading={carregando} value={escolhido?.id} placeholder="Sem planos" onChange={escolher} options={planos.map((p) => ({ value: p.id, label: `${p.nome} (${p.ano_inicio}-${p.ano_fim})` }))} />
        <Input.Search allowClear aria-label="Procurar" placeholder="indicador, objectivo ou dono" style={{ width: 280 }} onChange={(e) => setProcura(e.target.value)} />
        {podeIndicadores && (
          <Tooltip title={det && det.objectivos.length === 0 ? 'Crie primeiro um objectivo: um indicador serve sempre um objectivo.' : undefined}>
            <Button type="primary" icon={<PlusOutlined />} disabled={!det || det.objectivos.length === 0} onClick={() => setFormulario({})}>Novo indicador</Button>
          </Tooltip>
        )}
      </Space>
      {plano?.congelado && <Alert style={{ marginBottom: 12 }} type={encerrado ? 'info' : 'warning'} showIcon title={encerrado ? 'Plano encerrado: só leitura.' : 'Plano aprovado: indicadores e metas estão congelados.'} description={!encerrado && aprovador ? 'Alterar pede um motivo, que fica registado na auditoria.' : undefined} />}
      {semMetas > 0 && <Alert style={{ marginBottom: 12 }} type="warning" showIcon title={`${semMetas} indicador(es) sem metas — não serão avaliados.`} />}

      {isLoading || carregando ? <Skeleton active /> : !plano ? <Empty description="Ainda não há planos estratégicos. Crie o primeiro no ecrã «Plano e objectivos»." /> : (
        <Table<Indicador> rowKey="id" size="small" scroll={scrollTabela()} dataSource={visiveis} pagination={visiveis.length > 25 ? { pageSize: 25 } : false}
          locale={{ emptyText: 'Sem indicadores. Crie o primeiro com «Novo indicador».' }} rowClassName={(i) => (i.ativo ? '' : 'est-inactivo')}
          columns={[
            { title: 'Indicador', dataIndex: 'nome', className: 'erp-quebra', render: (_, i) => <><strong>{i.nome}</strong>{i.descricao && <div className="est-meta">{i.descricao}</div>}{!i.ativo && <Tag>Inactivo</Tag>}</> },
            { title: 'Objectivo', key: 'obj', className: 'erp-quebra', render: (_, i) => i.objectivo?.nome ?? '—' },
            { title: 'Tipo', dataIndex: 'tipo', render: (t: string) => cat?.tipos.find((x) => x.id === t)?.nome.split(' ')[0] ?? t },
            { title: 'Origem do valor', key: 'origem', className: 'erp-quebra', render: (_, i) => <><Tag>{i.origem_rotulo}</Tag><div className="est-meta">{descreverFonte(i, cat)}</div></> },
            { title: 'Frequência', dataIndex: 'frequencia', render: (f: string) => cat?.frequencias.find((x) => x.id === f)?.nome ?? f },
            { title: 'Polaridade', dataIndex: 'polaridade', render: (p: string) => cat?.polaridades.find((x) => x.id === p)?.nome ?? p },
            { title: 'Linha de base', key: 'base', align: 'right', render: (_, i) => <span className="est-valor">{formatarValorIndicador(i.linha_base, i.formato, i.oculto)}</span> },
            { title: 'Dono', key: 'dono', render: (_, i) => nomeDono(i) ?? (i.origem === 'MANUAL' ? <span style={{ color: '#cf1322' }}>em falta</span> : '—') },
            { title: 'Metas', dataIndex: 'metas_count', align: 'right', render: (n: number | null) => n ?? 0 },
            {
              title: '', key: 'acc', render: (_, i) => (
                <Space size={4} wrap>
                  <Button size="small" onClick={() => setMetas(i)} aria-label={`Metas por período de ${i.nome}`}>Metas</Button>
                  <Button size="small" icon={<LineChartOutlined />} onClick={() => setSerie(i)} aria-label={`Série e semáforo de ${i.nome}`}>Série</Button>
                  <Button size="small" onClick={() => setFormulario({ indicador: i })} aria-label={`${podeIndicadores ? 'Editar' : 'Ver'} ${i.nome}`}>{podeIndicadores ? 'Editar' : 'Ver'}</Button>
                </Space>
              ),
            },
          ]} />
      )}

      {formulario && det && cat && <FormularioIndicador detalhe={det} catalogos={cat} indicador={formulario.indicador} editavel={podeIndicadores} pedirMotivo={motivo.pedir} aoFechar={() => { setFormulario(null); void refrescar(); }} />}
      {metas && <ModalMetas indicador={metas} editavel={podeMetas} pedirMotivo={motivo.pedir} aoFechar={() => { setMetas(null); void refrescar(); }} />}
      {serie && plano && <ModalSerie indicador={serie} plano={plano} podeEntregar={pode('est_entregar')} aoFechar={() => { setSerie(null); void refrescar(); }} />}
      {motivo.dialogo}
    </>
  );
}
