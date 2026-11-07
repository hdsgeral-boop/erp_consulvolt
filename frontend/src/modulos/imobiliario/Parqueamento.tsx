import { LinkOutlined, PlusOutlined, ThunderboltOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Col, Empty, Input, Row, Select, Space, Statistic } from 'antd';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { BotoesExportar, esc, tabelaHtml, type PedidoImpressao } from '@/componentes/impressao';
import { notificarErro } from '@/utilitarios/erros';
import { useSessao } from '@/sessao/SessaoContexto';
import { CHAVES, listarImoveis } from './api';
import { CHAVES_PK, obterListasParque, obterMapaParque, type VagaMapa } from './apiParque';
import { ModalAfectar, ModalGerarVagas, ModalVaga, ModalVeiculos } from './ModaisParque';
import { aparenciaDaVaga, rotuloAfectacao } from './logicaParque';
import { formatarPercentagem } from './logica';
import './imobiliario.css';

/**
 * Gestão Imobiliária › Parqueamento (ecrã imo_parque): os lugares de um imóvel por piso — quem os ocupa, com que matrícula e por que contrato. Uma vaga
 * é uma fracção com natureza «estacionamento». Ver: imo_parque_view · criar, editar e gerar vagas: imo_vaga_editar · afectar e libertar: imo_vaga_afectar ·
 * veículos do contrato: imo_vaga_veiculos (também recusadas no servidor).
 */
export default function Parqueamento() {
  const { pode } = useSessao();
  const [escolhido, setEscolhido] = useState<number | null>(null);
  const [texto, setTexto] = useState('');
  const [vaga, setVaga] = useState<VagaMapa | null | undefined>(undefined);
  const [gerar, setGerar] = useState(false);
  const [afectar, setAfectar] = useState(false);
  const [veiculos, setVeiculos] = useState<{ contrato: number; numero: string } | null>(null);
  const p = { editar: pode('imo_vaga_editar'), afectar: pode('imo_vaga_afectar'), veiculos: pode('imo_vaga_veiculos') };
  const imoveis = useQuery({ queryKey: CHAVES.imoveis, queryFn: listarImoveis });
  const listas = useQuery({ queryKey: CHAVES_PK.listas, queryFn: obterListasParque, staleTime: 5 * 60_000 });
  const imovel = (imoveis.data ?? []).find((i) => i.id === (escolhido ?? imoveis.data?.[0]?.id)) ?? null;
  const mapa = useQuery({ queryKey: CHAVES_PK.mapa(imovel?.id ?? 0, texto), queryFn: () => obterMapaParque(imovel?.id as number, texto), enabled: !!imovel, placeholderData: keepPreviousData });
  useEffect(() => { if (imoveis.error) notificarErro(imoveis.error, 'Não foi possível carregar os imóveis'); }, [imoveis.error]);
  useEffect(() => { if (mapa.error) notificarErro(mapa.error, 'Não foi possível carregar o mapa de parqueamento'); }, [mapa.error]);
  useEffect(() => { if (listas.error) notificarErro(listas.error, 'Não foi possível carregar as listas'); }, [listas.error]);

  const completo = useQuery({ queryKey: CHAVES_PK.mapa(imovel?.id ?? 0, ''), queryFn: () => obterMapaParque(imovel?.id as number, ''), enabled: !!imovel && texto !== '' });   // o diálogo de afectar vê todas, mesmo com a pesquisa activa
  const r = mapa.data?.resumo ?? null;
  const pisos = mapa.data?.pisos ?? [];
  const todas = useMemo(() => pisos.flatMap((x) => x.vagas), [pisos]);
  const todasSemFiltro = useMemo(() => (texto === '' ? todas : (completo.data?.pisos ?? []).flatMap((x) => x.vagas)), [texto, todas, completo.data]);
  const semVagas = !!mapa.data && mapa.data.total === 0;
  const afectacoes = listas.data?.park_afectacoes;
  const tipoPorte = (v: VagaMapa) => [listas.data?.park_tipos.find((t) => t.valor === v.tipo)?.rotulo, listas.data?.park_portes.find((t) => t.valor === v.porte)?.rotulo].filter(Boolean).join(' · ');

  const pedido = (): PedidoImpressao => ({
    titulo: `Mapa de parqueamento — ${imovel?.nome ?? ''}`,
    subtitulo: r ? `${r.total} lugar(es), ${r.ocupadas} ocupado(s), ${r.livres} livre(s), taxa ${r.taxa ?? '—'}%` : null,
    orientacao: 'retrato',
    conteudo: pisos.map((x) => `<h3>${esc(x.andar)}</h3>${tabelaHtml<VagaMapa>({
      colunas: [
        { titulo: 'Lugar', valor: (v) => v.designacao }, { titulo: 'Dístico', valor: (v) => v.distico ?? '' }, { titulo: 'Tipo / porte', valor: (v) => tipoPorte(v) },
        { titulo: 'Afectação', valor: (v) => rotuloAfectacao(v.afectacao, afectacoes) + (v.afecta_a ? ` · ${v.afecta_a}` : '') }, { titulo: 'Ocupante', valor: (v) => v.ocupante ?? '' },
        { titulo: 'Contrato', valor: (v) => v.contrato ?? '' }, { titulo: 'Matrículas', valor: (v) => v.veiculos.map((x) => x.matricula).join(' / '), quebrar: true },
      ],
      linhas: x.vagas,
    })}`).join(''),
  });

  const celula = (v: VagaMapa) => {
    const a = aparenciaDaVaga(v, afectacoes);
    const mats = v.veiculos.map((x) => x.matricula);
    const estado = v.estado !== 'DISPONIVEL' && v.estado !== 'ARRENDADA' ? a.rotulo : v.ocupada ? `${a.rotulo} · ${v.ocupante ?? ''}` : a.rotulo;
    return (
      <button key={v.id} type="button" className="imo-celula imo-vaga" style={{ color: a.cor, background: a.fundo, borderColor: a.cor }} onClick={() => setVaga(v)}
        aria-label={`Lugar ${v.designacao}, ${a.rotulo}${v.ocupante ? `, ocupado por ${v.ocupante}` : ''}${v.afecta_a ? `, afecto a ${v.afecta_a}` : ''}${v.conflito_porte.length ? ', veículo maior do que o lugar' : ''}. Abrir.`}>
        <b>{v.designacao}</b>
        <span>{tipoPorte(v)}{v.tomada && <ThunderboltOutlined aria-label="Com tomada eléctrica" title="Com tomada eléctrica" style={{ marginInlineStart: 4 }} />}{v.altura_max ? ` · ↕${v.altura_max} m` : ''}</span>
        {v.afecta_a && <span><LinkOutlined aria-hidden /> {v.afecta_a}</span>}
        <span>{estado}</span>
        {mats.length > 0 && <span>{mats.slice(0, 2).join(' / ')}{mats.length > 2 ? ` +${mats.length - 2}` : ''}</span>}
        {v.conflito_porte.length > 0 && <span title="O veículo é maior do que o porte do lugar"><b>porte</b> — veículo maior</span>}
      </button>
    );
  };

  return (
    <>
      <CabecalhoModulo titulo="Parqueamento" icone="warehouse" subtitulo="Os lugares do imóvel, por piso: quem os ocupa, com que matrícula e por que contrato."
        accoes={<Space wrap>
          {p.editar && <Button disabled={!imovel} onClick={() => setGerar(true)}>Gerar vagas</Button>}
          {p.editar && <Button type="primary" icon={<PlusOutlined />} disabled={!imovel} onClick={() => setVaga(null)}>Vaga</Button>}
          {p.afectar && <Button icon={<LinkOutlined />} disabled={!imovel || semVagas} onClick={() => setAfectar(true)}>Afectar a fracção</Button>}
          <BotoesExportar desactivado={!pisos.length} obterPedido={pedido} />
        </Space>} />
      <Card>
        <Space wrap style={{ marginBottom: 12 }}>
          <label htmlFor="imo-parque-imovel">Imóvel</label>
          <Select id="imo-parque-imovel" style={{ minWidth: 280 }} showSearch optionFilterProp="label" value={imovel?.id} loading={imoveis.isLoading} placeholder="— escolha o imóvel —"
            options={(imoveis.data ?? []).map((i) => ({ value: i.id, label: `${i.codigo} · ${i.nome}` }))} onChange={(v) => { setEscolhido(v); setTexto(''); }} />
          <Input.Search allowClear aria-label="Procurar lugares" placeholder="lugar, dístico, matrícula, fracção ou ocupante" value={texto} onChange={(e) => setTexto(e.target.value)} style={{ width: 340 }} />
        </Space>
        {!imovel ? <Empty description={imoveis.isLoading ? 'A carregar…' : 'Ainda não há imóveis. Comece pelo cadastro.'} /> : (
          <>
            {r && (
              <Row gutter={[12, 12]}>
                <Col xs={12} md={5}><Statistic title="Lugares" value={r.total} /></Col>
                <Col xs={12} md={5}><Statistic title="Livres" value={r.livres} /></Col>
                <Col xs={12} md={5}><Statistic title="Ocupados" value={r.ocupadas} /></Col>
                <Col xs={12} md={5}><Statistic title="Visitas e cargas" value={r.visita} /></Col>
                <Col xs={12} md={4}><Statistic title="Taxa do parque" value={formatarPercentagem(r.taxa)} /></Col>
              </Row>
            )}
            {(mapa.data?.avisos ?? []).length > 0 && (
              <Alert type="warning" showIcon style={{ marginTop: 12 }} title={`${mapa.data?.avisos.length} ponto(s) a tratar`} description={<ul className="imo-lista">{mapa.data?.avisos.map((a) => <li key={a}>{a}</li>)}</ul>} />
            )}
            {semVagas ? (
              <Empty style={{ marginTop: 16 }} description={`${imovel.nome} ainda não tem lugares de estacionamento. Uma vaga é uma fracção com natureza «estacionamento»: entra nos contratos, no mapa de ocupação e na rentabilidade como qualquer outra. Para uma cave inteira use «Gerar vagas».`} />
            ) : todas.length === 0 && !mapa.isFetching ? (
              <Empty style={{ marginTop: 16 }} description={`Nenhum lugar corresponde a «${texto}».`} />
            ) : pisos.map((x) => (
              <section key={x.andar} className="imo-mapa-piso" aria-label={`Piso ${x.andar}`}>
                <div className="imo-mapa-piso-cab">{x.andar}<small>{x.vagas.length} lugar(es) · {x.ocupadas} ocupado(s)</small></div>
                <div className="imo-mapa-celulas">{x.vagas.map(celula)}</div>
              </section>
            ))}
            <p className="imo-legenda-texto">Pique o lugar para o editar, afectar a uma fracção ou registar os veículos. Verde é livre, azul é ocupado, roxo é de uso comum (visitas e cargas) — e esses nunca entram em contratos. O estado vem sempre escrito, além da cor.</p>
          </>
        )}
      </Card>
      {imovel && <ModalGerarVagas imovel={imovel.id} nome={imovel.nome} aberto={gerar} listas={listas.data} aoFechar={() => setGerar(false)} />}
      {imovel && <ModalVaga imovel={imovel.id} vaga={vaga} listas={listas.data} podeEditar={p.editar} podeVeiculos={p.veiculos} aoFechar={() => setVaga(undefined)}
        aoVeiculos={(contrato, numero) => { setVaga(undefined); setVeiculos({ contrato, numero }); }} />}
      {imovel && <ModalAfectar imovel={imovel.id} vagas={todasSemFiltro} aberto={afectar} listas={listas.data} aoFechar={() => setAfectar(false)} />}
      <ModalVeiculos contrato={veiculos?.contrato ?? null} numero={veiculos?.numero ?? ''} listas={listas.data} aoFechar={() => setVeiculos(null)} />
    </>
  );
}
