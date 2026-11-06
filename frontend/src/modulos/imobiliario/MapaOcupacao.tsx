import { Card, Col, Empty, Row, Select, Space, Statistic, Tag } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { notificarErro } from '@/utilitarios/erros';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVES, listarFracoes, listarImoveis, type Fracao } from './api';
import { FichaFracao } from './FichaFracao';
import { useListas } from './hooks';
import { agruparPorPiso, ESTADOS_FRACAO, formatarArea, formatarPercentagem, taxaOcupacao } from './logica';
import './imobiliario.css';

/**
 * Gestão Imobiliária › Mapa de Ocupação (ecrã imo_ocupacao): as fracções de um imóvel por piso, com o estado de cada uma (cor e
 * texto) e a taxa de ocupação. Abrir uma fracção mostra a ficha; mudar o estado exige imo_estado_mudar (também no servidor).
 */
export default function MapaOcupacao() {
  const { pode } = useSessao();
  const listas = useListas();
  const imoveis = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis });
  const [escolhido, setEscolhido] = useState<number | null>(null);
  const [aberta, setAberta] = useState<number | null>(null);
  const [filtro, setFiltro] = useState<string | null>(null);
  const imovelId = escolhido ?? imoveis.data?.[0]?.id ?? null;
  const fracoes = useQuery({ queryKey: CHAVES.fracoes(imovelId ?? 0), queryFn: () => listarFracoes(imovelId as number), enabled: imovelId !== null });
  useEffect(() => { if (imoveis.error) notificarErro(imoveis.error, 'Não foi possível carregar os imóveis'); }, [imoveis.error]);
  useEffect(() => { if (fracoes.error) notificarErro(fracoes.error, 'Não foi possível carregar as fracções'); }, [fracoes.error]);

  const todas = fracoes.data ?? [];
  const grupos = useMemo(() => agruparPorPiso(todas.filter((f) => !filtro || f.estado === filtro)), [todas, filtro]);
  const contagem = useMemo(() => Object.fromEntries(Object.keys(ESTADOS_FRACAO).map((e) => [e, todas.filter((f) => f.estado === e && f.natureza === 'HABITACIONAL').length])), [todas]);
  const taxa = taxaOcupacao(todas);
  const principais = todas.filter((f) => f.natureza === 'HABITACIONAL').length;

  const celula = (f: Fracao) => {
    const e = ESTADOS_FRACAO[f.estado];
    return (
      <button key={f.id} type="button" className="imo-celula" style={{ color: e.cor, background: e.fundo, borderColor: e.cor }} onClick={() => setAberta(f.id)}
        aria-label={`Fracção ${f.designacao}, ${e.rotulo}${f.area_bruta ? `, ${formatarArea(f.area_bruta)}` : ''}. Abrir a ficha.`}>
        <b>{f.designacao}</b>
        <span>{e.rotulo}{f.natureza !== 'HABITACIONAL' ? ' · acessório' : ''}</span>
      </button>
    );
  };

  return (
    <>
      <CabecalhoModulo titulo="Mapa de Ocupação" icone="th-large" subtitulo="As fracções de cada imóvel por piso, com o estado de cada uma e a taxa de ocupação." />
      <Card>
        <Space wrap style={{ marginBottom: 12 }}>
          <label htmlFor="imo-mapa-imovel">Imóvel</label>
          <Select id="imo-mapa-imovel" style={{ minWidth: 280 }} showSearch optionFilterProp="label" value={imovelId ?? undefined} loading={imoveis.isLoading} placeholder="— escolha o imóvel —"
            options={(imoveis.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} · ${i.nome}` }))} onChange={(v) => { setEscolhido(v); setFiltro(null); }} />
        </Space>
        {imovelId === null ? <Empty description="Ainda não há imóveis registados." /> : (
          <>
            <Row gutter={[12, 12]}>
              <Col xs={12} md={6}><Statistic title="Fracções" value={principais} /></Col>
              <Col xs={12} md={6}><Statistic title="Ocupadas (arrendadas ou vendidas)" value={todas.filter((f) => f.natureza === 'HABITACIONAL' && ESTADOS_FRACAO[f.estado].ocupa).length} /></Col>
              <Col xs={12} md={6}><Statistic title="Taxa de ocupação" value={formatarPercentagem(taxa)} /></Col>
              <Col xs={12} md={6}><Statistic title="Disponíveis" value={contagem.DISPONIVEL ?? 0} /></Col>
            </Row>
            <div className="imo-legenda" role="group" aria-label="Filtrar por estado">
              {(Object.keys(ESTADOS_FRACAO) as (keyof typeof ESTADOS_FRACAO)[]).map((e) => (
                <Tag.CheckableTag key={e} checked={filtro === e} onChange={(c) => setFiltro(c ? e : null)}>{ESTADOS_FRACAO[e].rotulo} ({contagem[e] ?? 0})</Tag.CheckableTag>
              ))}
            </div>
            {fracoes.isFetching && todas.length === 0 ? null : grupos.length === 0 ? (
              <Empty description={todas.length === 0 ? 'Este imóvel ainda não tem fracções. Crie-as em Imóveis e Fracções.' : 'Nenhuma fracção neste estado.'} />
            ) : grupos.map((g) => (
              <section key={g.andar} className="imo-mapa-piso" aria-label={`Piso ${g.andar}`}>
                <div className="imo-mapa-piso-cab">{g.andar}<small>{g.ocupadas}/{g.fracoes.length} ocupadas · {formatarArea(g.area)}</small></div>
                <div className="imo-mapa-celulas">{g.fracoes.map(celula)}</div>
              </section>
            ))}
          </>
        )}
      </Card>
      <FichaFracao id={aberta} listas={listas} podeMudarEstado={pode('imo_estado_mudar')} aoFechar={() => setAberta(null)} />
    </>
  );
}
