import { PlusOutlined } from '@ant-design/icons';
import { Button, Card, Col, Row, Statistic, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { TabelaComModos } from '@/componentes/vistas';
import { notificarErro } from '@/utilitarios/erros';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVES, eliminarImovel, listarImoveis, obterResumo, type Imovel } from './api';
import { FichaImovel } from './FichaImovel';
import { useListas, useRefrescar } from './hooks';
import { formatarArea, formatarKz, formatarPercentagem, rotuloDe } from './logica';
import { ModalImovel } from './ModalImovel';
import './imobiliario.css';

/**
 * Gestão Imobiliária › Imóveis e Fracções (ecrã imo_imoveis): cadastro de imóveis, fracções e ficha. Ver: imo_imoveis_view ·
 * criar e editar imóveis: imo_imovel_editar · fracções: imo_fraccao_editar · estado: imo_estado_mudar (as escritas são também
 * recusadas no servidor). Contratos, rendas, parqueamento, obras e negócios chegam nas ondas seguintes.
 */
export default function Imoveis() {
  const { pode } = useSessao();
  const listas = useListas();
  const refrescar = useRefrescar();
  const lista = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis });
  const resumo = useQuery({ queryKey: CHAVES.resumo, queryFn: obterResumo });
  const [editar, setEditar] = useState<Imovel | null | undefined>(undefined);
  const [aberto, setAberto] = useState<number | null>(null);
  useEffect(() => { if (lista.error) notificarErro(lista.error, 'Não foi possível carregar os imóveis'); }, [lista.error]);
  useEffect(() => { if (resumo.error) notificarErro(resumo.error, 'Não foi possível carregar o resumo'); }, [resumo.error]);
  const permissoes = { editarImovel: pode('imo_imovel_editar'), editarFraccao: pode('imo_fraccao_editar'), mudarEstado: pode('imo_estado_mudar') };
  const eliminar = useMutation({
    mutationFn: (i: Imovel) => eliminarImovel(i.id), onError: (e) => notificarErro(e, 'Não foi possível eliminar o imóvel'),
    onSuccess: async () => { message.success('Imóvel eliminado.'); await refrescar(); },
  });
  const r = resumo.data;

  return (
    <>
      <CabecalhoModulo titulo="Imóveis e Fracções" icone="city" subtitulo="O cadastro do património: imóveis próprios, arrendados a terceiros ou em mediação, e as fracções que se arrendam ou vendem."
        accoes={permissoes.editarImovel ? <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar(null)}>Novo imóvel</Button> : undefined} />
      <Row gutter={[12, 12]} className="imo-kpis">
        <Col xs={12} md={6}><Card size="small"><Statistic title="Imóveis" value={r?.imoveis ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Fracções" value={r?.unidades ?? 0} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Taxa de ocupação" value={r?.taxa_ocupacao ?? '—'} suffix={r?.taxa_ocupacao != null ? '%' : undefined} loading={resumo.isLoading} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Área bruta das fracções" value={formatarArea(r?.area_bruta)} loading={resumo.isLoading} /></Card></Col>
      </Row>
      <Card style={{ marginTop: 12 }}>
        <TabelaComModos<Imovel>
          idVista="imobiliario_imoveis" rowKey="id" size="middle" loading={lista.isFetching} dataSource={lista.data ?? []} scroll={{ x: 'max-content' }}
          impressao={{ titulo: 'Imóveis' }}
          columns={[
            { title: 'Código', dataIndex: 'codigo', ocultavel: false, render: (v: string, i: Imovel) => <a role="button" tabIndex={0} onClick={() => setAberto(i.id)} onKeyDown={(e) => { if (e.key === 'Enter') setAberto(i.id); }}><strong>{v}</strong></a>, valorImpressao: (i: Imovel) => i.codigo },
            { title: 'Designação', dataIndex: 'nome', principal: true },
            { title: 'Tipo', dataIndex: 'tipo', render: (v: string) => rotuloDe(listas?.tipos_imovel, v), valorImpressao: (i: Imovel) => rotuloDe(listas?.tipos_imovel, i.tipo) },
            { title: 'Papel', dataIndex: 'papel', render: (v: string) => rotuloDe(listas?.papeis, v), valorImpressao: (i: Imovel) => rotuloDe(listas?.papeis, i.papel) },
            { title: 'Proprietário', dataIndex: 'proprietario_nome', ocultaPorOmissao: true, render: (v: string | null) => v ?? '—' },
            { title: 'Morada', dataIndex: 'morada', ocultaPorOmissao: true, render: (v: string | null) => v ?? '—' },
            { title: 'Fracções', dataIndex: 'total_fracoes', align: 'right' },
            { title: 'Ocupadas', dataIndex: 'ocupadas', align: 'right' },
            { title: 'Ocupação', dataIndex: 'taxa_ocupacao', align: 'right', render: (v: number | null) => formatarPercentagem(v), valorImpressao: (i: Imovel) => formatarPercentagem(i.taxa_ocupacao) },
            { title: 'Área bruta', dataIndex: 'area_bruta', align: 'right', ocultaPorOmissao: true, render: (v: number) => formatarArea(v), valorImpressao: (i: Imovel) => formatarArea(i.area_bruta) },
            { title: 'Valor patrimonial', dataIndex: 'valor_patrimonial', align: 'right', ocultaPorOmissao: true, render: (v: number | null) => formatarKz(v), valorImpressao: (i: Imovel) => formatarKz(i.valor_patrimonial) },
            { title: 'Estado', dataIndex: 'estado', etiqueta: true, render: (v: string) => <Tag color={v === 'ACTIVO' ? 'green' : v === 'INACTIVO' ? 'default' : 'orange'}>{rotuloDe(listas?.estados_imovel, v)}</Tag>, valorImpressao: (i: Imovel) => rotuloDe(listas?.estados_imovel, i.estado) },
          ]}
          accoes={(i) => [
            { icone: 'eye', titulo: 'Abrir a ficha', onClick: () => setAberto(i.id) },
            ...(permissoes.editarImovel ? [
              { icone: 'edit', titulo: 'Editar', onClick: () => setEditar(i) },
              { icone: 'trash', titulo: 'Eliminar', variante: 'perigo' as const, desactivado: i.total_fracoes + i.acessorios > 0 ? 'Apague primeiro as fracções (ou ponha o imóvel como inactivo)' : false,
                confirmar: { titulo: `Eliminar o imóvel «${i.codigo}»?`, textoOk: 'Eliminar', perigo: true }, onClick: () => eliminar.mutate(i) },
            ] : []),
          ]}
          vazio={{ titulo: 'Sem imóveis', texto: permissoes.editarImovel ? 'Comece por registar o primeiro imóvel.' : 'Ainda não há imóveis registados.' }}
        />
      </Card>
      <ModalImovel imovel={editar} listas={listas} aoFechar={(g) => { setEditar(undefined); if (g) setAberto(g.id); }} />
      <FichaImovel id={aberto} listas={listas} pode={permissoes} aoEditar={() => { const i = lista.data?.find((x) => x.id === aberto); if (i) setEditar(i); }} aoFechar={() => setAberto(null)} />
    </>
  );
}
