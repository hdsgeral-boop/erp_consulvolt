import { Alert, Button, Card, Col, Empty, Row, Select, Space, Tag, Typography } from 'antd';
import { DownloadOutlined, FilePdfOutlined, FileTextOutlined, FileZipOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { descarregar as descarregarApi, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useOperacoes } from '@/componentes/operacoes/Operacoes';
import { DeslocamentoHorizontal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { GRUPOS_PAGAMENTO, type OrdemPagamento, type ResultadoSalarial } from '../api';
import { AreaImpressao, CabecalhoMapa, descarregar, SeletorColaborador } from '../comum/componentes';
import { useAvisarErro, useCargos } from '../comum/consultas';
import { ReciboSalario, useEmpresaRecibo } from '../comum/ReciboSalario';
import { colunasRubricas, formatarIban, gerarCsv, kzCsv, somar, valoresRubricas } from '../comum/regras';
import { AvisoNaoValidado, MolduraMapa, useDadosColaborador, usePeriodoMapa } from './comum';

/** M-13: filtro Colaboradores / Avençados nos mapas (o legado tinha folhas separadas, folha_salarios.js:262-298). */
const filtroGrupo = (grupo: string) => (r: ResultadoSalarial) => grupo === 'TODOS' || (grupo === 'AVENCADOS' ? r.avencado : !r.avencado);
const rotuloGrupo = (grupo: string) => GRUPOS_PAGAMENTO.find((g) => g.value === grupo)?.label ?? grupo;

const ordenarPorNome = (dados: (id: number, r?: ResultadoSalarial) => { nome: string }) => (a: ResultadoSalarial, b: ResultadoSalarial) =>
  dados(a.colaborador_id, a).nome.localeCompare(dados(b.colaborador_id, b).nome, 'pt');

/** RH › Relatórios e recibos (ecrã relatorios): entrada para os mapas que o utilizador pode ver. */
export function Relatorios() {
  const { menu } = useSessao();
  const navegar = useNavigate();
  const filhos = menu.find((m) => m.id === 'rh')?.ecras.filter((e) => e.pai === 'relatorios') ?? [];
  return (
    <>
      <CabecalhoPagina titulo="Relatórios e recibos" subtitulo="Mapas mensais do processamento salarial (impressão no navegador)" />
      {filhos.length === 0 ? <Empty description="Sem acesso a nenhum mapa nesta empresa." /> : (
        <Row gutter={[16, 16]}>
          {filhos.map((e) => (
            <Col key={e.id} xs={24} sm={12} lg={8}>
              <Card hoverable onClick={() => navegar(`/m/rh/${e.id}`)}>
                <Card.Meta avatar={<FileTextOutlined style={{ fontSize: 24 }} />} title={e.nome} />
              </Card>
            </Col>
          ))}
        </Row>
      )}
    </>
  );
}

/** Mapa de remunerações: uma coluna por rubrica, encargos e líquido. */
export function MapaRemuneracoes() {
  const mapa = usePeriodoMapa();
  const { empresa } = useSessao();
  const dados = useDadosColaborador();
  const [grupo, setGrupo] = useState('TODOS');
  const res = useMemo(() => [...(mapa.detalhe.data?.resultados ?? [])].filter(filtroGrupo(grupo)).sort(ordenarPorNome(dados)), [mapa.detalhe.data, dados, grupo]);
  const colunas = colunasRubricas(res);
  const valores = res.map(valoresRubricas);
  const exportar = () => {
    const cab = ['Colaborador', 'NIF', 'N.º INSS', 'UN', 'CC', 'Dias contr.', 'Dias trab.', ...colunas.map((c) => c.nome), 'Bruto', 'INSS trab.', 'IRT', 'Descontos', 'Líquido', 'INSS empresa'];
    const linhas = res.map((r, i) => {
      const d = dados(r.colaborador_id, r);
      return [d.nome, d.nif, d.inss, r.unidade_negocio ?? '', r.centro_custo ?? '', r.dias_contrato, r.dias_trabalhados, ...colunas.map((c) => kzCsv(valores[i][c.chave])), kzCsv(r.bruto), kzCsv(r.inss_trabalhador), kzCsv(r.irt), kzCsv(r.descontos), kzCsv(r.liquido), kzCsv(r.inss_patronal)];
    });
    descarregar(`mapa-remuneracoes-${mapa.detalhe.data?.mes_ano.replace('/', '-')}-${grupo.toLowerCase()}.csv`, gerarCsv(cab, linhas));
  };
  const total = (k: keyof ResultadoSalarial) => formatarKz(somar(res.map((r) => r[k] as string)));
  return (
    <MolduraMapa titulo="Mapa de remunerações" subtitulo="Remunerações do mês por colaborador e rubrica" mapa={mapa} aviso={<AvisoNaoValidado periodo={mapa.periodo} />}
      filtros={<Select value={grupo} onChange={setGrupo} options={GRUPOS_PAGAMENTO} style={{ width: 180 }} aria-label="Grupo" />}
      accoes={<Button icon={<DownloadOutlined />} disabled={!res.length} onClick={exportar}>Exportar CSV</Button>}>
      <AreaImpressao paisagem>
        <CabecalhoMapa empresa={empresa?.nome} titulo="Mapa de remunerações" mesAno={mapa.detalhe.data?.mes_ano} extra={grupo !== 'TODOS' ? <div>Grupo: {rotuloGrupo(grupo)}</div> : null} />
        <DeslocamentoHorizontal>
          <table className="rh-tabela-mapa">
            <thead>
              <tr>
                <th>#</th><th style={{ textAlign: 'left' }}>Colaborador</th><th>NIF</th><th>N.º INSS</th><th>UN</th><th>CC</th><th>Dias</th>
                {colunas.map((c) => <th key={c.chave} style={{ color: c.tipo === 'DESCONTO' ? '#a8071a' : undefined }}>{c.nome}</th>)}
                <th>Bruto</th><th>INSS (trab.)</th><th>IRT</th><th>Descontos</th><th>Líquido</th><th>INSS (empresa)</th>
              </tr>
            </thead>
            <tbody>
              {res.map((r, i) => {
                const d = dados(r.colaborador_id, r);
                return (
                  <tr key={r.colaborador_id}>
                    <td className="num">{i + 1}</td><td>{d.nome}{r.avencado ? ' (avençado)' : ''}</td><td>{d.nif}</td><td>{d.inss}</td>
                    <td>{r.unidade_negocio ?? ''}</td><td>{r.centro_custo ?? ''}</td>
                    <td className="num">{formatarNumero(r.dias_trabalhados)}/{formatarNumero(r.dias_contrato)}</td>
                    {colunas.map((c) => <td key={c.chave} className="num">{valores[i][c.chave] ? formatarKz(valores[i][c.chave]) : ''}</td>)}
                    <td className="num">{formatarKz(r.bruto)}</td><td className="num">{formatarKz(r.inss_trabalhador)}</td><td className="num">{formatarKz(r.irt)}</td>
                    <td className="num">{formatarKz(r.descontos)}</td><td className="num"><strong>{formatarKz(r.liquido)}</strong></td><td className="num">{formatarKz(r.inss_patronal)}</td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={7}>Totais ({res.length})</td>
                {colunas.map((c) => <td key={c.chave} className="num">{formatarKz(somar(valores.map((v) => v[c.chave])))}</td>)}
                <td className="num">{total('bruto')}</td><td className="num">{total('inss_trabalhador')}</td><td className="num">{total('irt')}</td>
                <td className="num">{total('descontos')}</td><td className="num">{total('liquido')}</td><td className="num">{total('inss_patronal')}</td>
              </tr>
            </tfoot>
          </table>
        </DeslocamentoHorizontal>
      </AreaImpressao>
    </MolduraMapa>
  );
}

/** Mapa de IRT: Grupo A (trabalhadores, tabela progressiva) e Grupo B (avençados, 6,5 %). */
export function MapaIrt() {
  const mapa = usePeriodoMapa();
  const { empresa } = useSessao();
  const dados = useDadosColaborador();
  const res = useMemo(() => [...(mapa.detalhe.data?.resultados ?? [])].sort(ordenarPorNome(dados)), [mapa.detalhe.data, dados]);
  const grupoA = res.filter((r) => !r.avencado);
  const grupoB = res.filter((r) => r.avencado);
  const exportar = () => {
    const cab = ['NIF', 'N.º SS', 'Nome', 'Província', 'Município', 'Bruto', 'INSS 3%', 'Isenções', 'Matéria colectável', 'Parcela fixa', 'Taxa %', 'Excesso', 'Imposto devido', 'Imposto retido'];
    const linhas = grupoA.map((r) => {
      const d = dados(r.colaborador_id, r);
      const e = r.irt_escalao;
      return [d.nif, d.inss, d.nome, d.provincia, d.municipio, kzCsv(r.bruto), kzCsv(r.inss_trabalhador), kzCsv(r.isencoes), kzCsv(r.base_irt), e ? kzCsv(e.fixo) : '', e ? String(e.taxa).replace('.', ',') : '', e ? kzCsv(e.excesso) : '', e ? kzCsv(e.devido) : '', kzCsv(r.irt)];
    });
    descarregar(`mapa-irt-${mapa.detalhe.data?.mes_ano.replace('/', '-')}.csv`, gerarCsv(cab, linhas));
  };
  // M-13: o CSV do Grupo B (avençados, 6,5 %) também se exporta
  const exportarB = () => descarregar(`mapa-irt-grupo-b-${mapa.detalhe.data?.mes_ano.replace('/', '-')}.csv`, gerarCsv(['NIF', 'Nome', 'Valor bruto', 'Matéria colectável', 'IRT retido'],
    grupoB.map((r) => { const d = dados(r.colaborador_id, r); return [d.nif, d.nome, kzCsv(r.bruto), kzCsv(r.base_irt), kzCsv(r.irt)]; })));
  return (
    <MolduraMapa titulo="Mapa de IRT" subtitulo="Retenções de IRT do mês (modelo oficial)" mapa={mapa} aviso={<AvisoNaoValidado periodo={mapa.periodo} />}
      accoes={<>
        <Button icon={<DownloadOutlined />} disabled={!grupoA.length} onClick={exportar}>Exportar CSV (Grupo A)</Button>
        <Button icon={<DownloadOutlined />} disabled={!grupoB.length} onClick={exportarB}>Exportar CSV (Grupo B)</Button>
      </>}>
      <AreaImpressao paisagem>
        <CabecalhoMapa empresa={empresa?.nome} titulo="Mapa de IRT — Grupo A (trabalhadores por conta de outrem)" mesAno={mapa.detalhe.data?.mes_ano} extra={empresa?.nif ? <div>NIF {String(empresa.nif)}</div> : null} />
        <DeslocamentoHorizontal>
          <table className="rh-tabela-mapa">
            <thead>
              <tr><th>Ord.</th><th>NIF</th><th>N.º SS</th><th style={{ textAlign: 'left' }}>Nome</th><th>Província</th><th>Município</th><th>Bruto</th><th>S. Social (3%)</th>
                <th>Isenções</th><th>Matéria colectável</th><th>Parcela fixa</th><th>Taxa</th><th>Excesso</th><th>Imposto devido</th><th>Imposto retido</th></tr>
            </thead>
            <tbody>
              {grupoA.map((r, i) => {
                const d = dados(r.colaborador_id, r);
                const e = r.irt_escalao;
                return (
                  <tr key={r.colaborador_id}>
                    <td className="num">{i + 1}</td><td>{d.nif}</td><td>{d.inss}</td><td>{d.nome}</td><td>{d.provincia}</td><td>{d.municipio}</td>
                    <td className="num">{formatarKz(r.bruto)}</td><td className="num">{formatarKz(r.inss_trabalhador)}</td><td className="num">{formatarKz(r.isencoes)}</td>
                    <td className="num">{formatarKz(r.base_irt)}</td><td className="num">{e ? formatarKz(e.fixo) : '—'}</td><td className="num">{e ? `${formatarNumero(e.taxa)}%` : '—'}</td>
                    <td className="num">{e ? formatarKz(e.excesso) : '—'}</td><td className="num">{e ? formatarKz(e.devido) : '—'}</td><td className="num"><strong>{formatarKz(r.irt)}</strong></td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={6}>Totais ({grupoA.length})</td>
                <td className="num">{formatarKz(somar(grupoA.map((r) => r.bruto)))}</td><td className="num">{formatarKz(somar(grupoA.map((r) => r.inss_trabalhador)))}</td>
                <td className="num">{formatarKz(somar(grupoA.map((r) => r.isencoes)))}</td><td className="num">{formatarKz(somar(grupoA.map((r) => r.base_irt)))}</td>
                <td colSpan={3} /><td className="num">{formatarKz(mapa.detalhe.data?.totais.irt_devido ?? somar(grupoA.map((r) => r.irt_escalao?.devido ?? '0')))}</td>
                <td className="num">{formatarKz(somar(grupoA.map((r) => r.irt)))}</td>
              </tr>
            </tfoot>
          </table>
        </DeslocamentoHorizontal>
        <Typography.Paragraph type="secondary" className="rh-nao-imprimir" style={{ marginTop: 8 }}>
          O escalão (parcela fixa, taxa e excesso) e o imposto devido vêm do cálculo do servidor (MotorSalarial); o imposto retido é o calculado pelo servidor no processamento (nos períodos migrados, pelo modo do legado).
        </Typography.Paragraph>
        {grupoB.length > 0 && (
          <>
            <CabecalhoMapa titulo="Retenção de IRT — Grupo B (prestadores de serviço / avençados, 6,5 %)" />
            <DeslocamentoHorizontal>
              <table className="rh-tabela-mapa">
                <thead><tr><th>Ord.</th><th>NIF</th><th style={{ textAlign: 'left' }}>Nome</th><th>Valor bruto</th><th>Matéria colectável</th><th>IRT retido</th></tr></thead>
                <tbody>
                  {grupoB.map((r, i) => {
                    const d = dados(r.colaborador_id, r);
                    return <tr key={r.colaborador_id}><td className="num">{i + 1}</td><td>{d.nif}</td><td>{d.nome}</td><td className="num">{formatarKz(r.bruto)}</td><td className="num">{formatarKz(r.base_irt)}</td><td className="num">{formatarKz(r.irt)}</td></tr>;
                  })}
                </tbody>
                <tfoot><tr><td colSpan={3}>Totais</td><td className="num">{formatarKz(somar(grupoB.map((r) => r.bruto)))}</td><td className="num">{formatarKz(somar(grupoB.map((r) => r.base_irt)))}</td><td className="num">{formatarKz(somar(grupoB.map((r) => r.irt)))}</td></tr></tfoot>
              </table>
            </DeslocamentoHorizontal>
          </>
        )}
      </AreaImpressao>
    </MolduraMapa>
  );
}

/** Mapa de Segurança Social: base de incidência e contribuições (trabalhador e empresa). */
export function MapaInss() {
  const mapa = usePeriodoMapa();
  const { empresa } = useSessao();
  const dados = useDadosColaborador();
  const res = useMemo(() => [...(mapa.detalhe.data?.resultados ?? [])].filter((r) => !r.avencado).sort(ordenarPorNome(dados)), [mapa.detalhe.data, dados]);
  const exportar = () => descarregar(`mapa-inss-${mapa.detalhe.data?.mes_ano.replace('/', '-')}.csv`, gerarCsv(
    ['Colaborador', 'NIF', 'N.º INSS', 'Base', 'Trabalhador', 'Empresa', 'Total'],
    res.map((r) => { const d = dados(r.colaborador_id, r); return [d.nome, d.nif, d.inss, kzCsv(r.base_inss), kzCsv(r.inss_trabalhador), kzCsv(r.inss_patronal), kzCsv(somar([r.inss_trabalhador, r.inss_patronal]))]; }),
  ));
  return (
    <MolduraMapa titulo="Mapa de Segurança Social" subtitulo="Contribuições para o INSS do mês" mapa={mapa} aviso={<AvisoNaoValidado periodo={mapa.periodo} />}
      accoes={<Button icon={<DownloadOutlined />} disabled={!res.length} onClick={exportar}>Exportar CSV</Button>}>
      <AreaImpressao>
        <CabecalhoMapa empresa={empresa?.nome} titulo="Mapa de Segurança Social (INSS)" mesAno={mapa.detalhe.data?.mes_ano} />
        <DeslocamentoHorizontal>
          <table className="rh-tabela-mapa">
            <thead><tr><th>#</th><th style={{ textAlign: 'left' }}>Colaborador</th><th>N.º INSS</th><th>Base de incidência</th><th>Trabalhador</th><th>Empresa</th><th>Total</th></tr></thead>
            <tbody>
              {res.map((r, i) => {
                const d = dados(r.colaborador_id, r);
                return (
                  <tr key={r.colaborador_id}>
                    <td className="num">{i + 1}</td><td>{d.nome}{r.reformado ? ' (reformado)' : ''}</td><td>{d.inss || '—'}</td><td className="num">{formatarKz(r.base_inss)}</td>
                    <td className="num">{formatarKz(r.inss_trabalhador)}</td><td className="num">{formatarKz(r.inss_patronal)}</td><td className="num">{formatarKz(somar([r.inss_trabalhador, r.inss_patronal]))}</td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={3}>Totais ({res.length})</td><td className="num">{formatarKz(somar(res.map((r) => r.base_inss)))}</td>
                <td className="num">{formatarKz(somar(res.map((r) => r.inss_trabalhador)))}</td><td className="num">{formatarKz(somar(res.map((r) => r.inss_patronal)))}</td>
                <td className="num">{formatarKz(somar(res.flatMap((r) => [r.inss_trabalhador, r.inss_patronal])))}</td>
              </tr>
            </tfoot>
          </table>
        </DeslocamentoHorizontal>
      </AreaImpressao>
    </MolduraMapa>
  );
}

/** Salários a pagar: líquido por colaborador. */
export function MapaPagamentos() {
  const mapa = usePeriodoMapa();
  const { empresa } = useSessao();
  const dados = useDadosColaborador();
  const [grupo, setGrupo] = useState('TODOS');
  const res = useMemo(() => [...(mapa.detalhe.data?.resultados ?? [])].filter((r) => Number(r.liquido) > 0).filter(filtroGrupo(grupo)).sort(ordenarPorNome(dados)), [mapa.detalhe.data, dados, grupo]);
  const exportar = () => descarregar(`salarios-a-pagar-${mapa.detalhe.data?.mes_ano.replace('/', '-')}-${grupo.toLowerCase()}.csv`, gerarCsv(['Colaborador', 'NIF', 'Banco', 'IBAN', 'Líquido'],
    res.map((r) => { const d = dados(r.colaborador_id, r); return [d.nome, d.nif, r.banco ?? '', r.iban ?? '', kzCsv(r.liquido)]; })));
  return (
    <MolduraMapa titulo="Salários a pagar" subtitulo="Líquido a receber por colaborador" mapa={mapa} aviso={<AvisoNaoValidado periodo={mapa.periodo} />}
      filtros={<Select value={grupo} onChange={setGrupo} options={GRUPOS_PAGAMENTO} style={{ width: 180 }} aria-label="Grupo" />}
      accoes={<Button icon={<DownloadOutlined />} disabled={!res.length} onClick={exportar}>Exportar CSV</Button>}>
      <AreaImpressao>
        <CabecalhoMapa empresa={empresa?.nome} titulo="Lista de salários a pagar" mesAno={mapa.detalhe.data?.mes_ano} extra={grupo !== 'TODOS' ? <div>Grupo: {rotuloGrupo(grupo)}</div> : null} />
        <DeslocamentoHorizontal>
          <table className="rh-tabela-mapa">
            <thead><tr><th>#</th><th style={{ textAlign: 'left' }}>Colaborador</th><th>NIF</th><th>Líquido a receber (Kz)</th><th>Assinatura</th></tr></thead>
            <tbody>
              {res.map((r, i) => {
                const d = dados(r.colaborador_id, r);
                return <tr key={r.colaborador_id}><td className="num">{i + 1}</td><td>{d.nome}{r.avencado ? ' (avençado)' : ''}</td><td>{d.nif}</td><td className="num">{formatarKz(r.liquido)}</td><td style={{ width: 180 }} /></tr>;
              })}
            </tbody>
            <tfoot><tr><td colSpan={3}>Total ({res.length})</td><td className="num">{formatarKz(somar(res.map((r) => r.liquido)))}</td><td /></tr></tfoot>
          </table>
        </DeslocamentoHorizontal>
      </AreaImpressao>
    </MolduraMapa>
  );
}

/** Ordem de pagamento bancária (só períodos validados; IBAN e líquido). */
export function MapaBanco() {
  const mapa = usePeriodoMapa(['VALIDADO']);
  const { empresa } = useSessao();
  const [grupo, setGrupo] = useState('TODOS');
  const ordem = useQuery({
    queryKey: ['rh', 'salarios', 'ordem', mapa.id, grupo],
    queryFn: () => obter<OrdemPagamento>(`/rh/salarios/periodos/${mapa.id}/ordem-pagamento`, { grupo }),
    enabled: mapa.id !== undefined && mapa.periodo?.estado === 'VALIDADO',
  });
  useAvisarErro(ordem.error, 'Erro ao carregar a ordem de pagamento');
  const o = ordem.data;
  const exportar = () => o && descarregar(`ordem-pagamento-${mapa.periodo?.mes_ano.replace('/', '-')}-${grupo.toLowerCase()}.csv`, gerarCsv(
    ['Beneficiário', 'Banco', 'IBAN', 'Valor'], o.linhas.map((l) => [l.nome ?? `#${l.colaborador_id}`, l.banco ?? '', l.iban ?? '', kzCsv(l.liquido)]),
  ));
  return (
    <MolduraMapa titulo="Ordem de pagamento bancária" subtitulo="Transferências dos salários líquidos (contém IBAN)" mapa={mapa} apenas={['VALIDADO']}
      filtros={<Select value={grupo} onChange={setGrupo} options={GRUPOS_PAGAMENTO} style={{ width: 180 }} />}
      accoes={<Button icon={<DownloadOutlined />} disabled={!o?.linhas.length} onClick={exportar}>Exportar CSV</Button>}
      aviso={o && o.sem_iban > 0 ? <Alert className="rh-nao-imprimir" type="warning" showIcon style={{ marginBottom: 16 }} message={`${o.sem_iban} colaborador(es) sem IBAN: registe-os em Coordenadas bancárias antes de emitir a carta.`} /> : null}>
      {ordem.isLoading ? <Card loading variant="borderless" /> : o && (
        <AreaImpressao>
          <CabecalhoMapa empresa={empresa?.nome} titulo="Ordem de pagamento bancária" mesAno={mapa.periodo?.mes_ano} extra={<div>Grupo: {GRUPOS_PAGAMENTO.find((g) => g.value === grupo)?.label}</div>} />
          <DeslocamentoHorizontal>
            <table className="rh-tabela-mapa">
              <thead><tr><th>#</th><th style={{ textAlign: 'left' }}>Beneficiário</th><th>Banco</th><th>IBAN</th><th>Valor (Kz)</th><th className="rh-nao-imprimir">Carta</th></tr></thead>
              <tbody>
                {o.linhas.map((l, i) => (
                  <tr key={l.colaborador_id}>
                    <td className="num">{i + 1}</td><td>{l.nome ?? `#${l.colaborador_id}`}</td><td>{l.banco ?? '—'}</td>
                    <td>{l.iban ? <code>{formatarIban(l.iban)}</code> : <Tag color="volcano">Sem IBAN</Tag>}</td>
                    <td className="num">{formatarKz(l.liquido)}</td><td className="rh-nao-imprimir">{l.carta_pagamento_id ? `#${l.carta_pagamento_id}` : '—'}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot><tr><td colSpan={4}>Total ({o.linhas.length})</td><td className="num">{formatarKz(o.total)}</td><td className="rh-nao-imprimir" /></tr></tfoot>
            </table>
          </DeslocamentoHorizontal>
        </AreaImpressao>
      )}
    </MolduraMapa>
  );
}

/**
 * Recibos de salário (generatePDFRecibo/generateSelectedPDFRecibos/generateAllPDFRecibos): individual, seleccionados ou
 * todos — impressão no navegador (2 vias por página) e, no servidor, PDF individual e ZIP com um PDF por colaborador (A-10).
 * Só períodos validados.
 */
export function MapaRecibos() {
  const mapa = usePeriodoMapa(['VALIDADO']);
  const { pode } = useSessao();
  const dadosEmpresa = useEmpresaRecibo();
  const dados = useDadosColaborador();
  const cargos = useCargos();
  const [colaboradores, setColaboradores] = useState<number[]>([]);
  const [aDescarregar, setADescarregar] = useState(false);
  const operacoes = useOperacoes();
  const res = useMemo(() => [...(mapa.detalhe.data?.resultados ?? [])].sort(ordenarPorNome(dados)), [mapa.detalhe.data, dados]);
  const visiveis = colaboradores.length ? res.filter((r) => colaboradores.includes(r.colaborador_id)) : res;
  const mesAno = mapa.detalhe.data?.mes_ano ?? '';
  const id = mapa.periodo?.id;
  const zip = async () => {
    if (!id) return;
    setADescarregar(true);
    try {
      // M-05: acompanhado no gestor de operações (o utilizador pode navegar enquanto o servidor gera os PDF); o pedido é
      // síncrono — o ZIP é gerado e devolvido na mesma resposta (sem trabalho na fila nem ficheiro guardado no servidor)
      const n = visiveis.length;
      await operacoes.executar(`Recibos ${mesAno} (ZIP)`, async (progresso) => {
        progresso(5, `A gerar ${n} recibo(s) em PDF no servidor…`);
        await descarregarApi(`/rh/salarios/periodos/${id}/recibos-zip`, colaboradores.length ? { colaboradores: colaboradores.join(',') } : undefined, `Recibos_${mesAno.replace('/', '_')}.zip`);
      });
    } catch (e) {
      notificarErro(e);
    } finally {
      setADescarregar(false);
    }
  };
  const pdf = () => id && colaboradores.length === 1 && void descarregarApi(`/rh/salarios/periodos/${id}/recibos/${colaboradores[0]}/pdf`, undefined, 'Recibo.pdf').catch(notificarErro);
  return (
    <MolduraMapa titulo="Recibos de salário" subtitulo="Recibos de vencimento (2 vias) da fotografia do período validado" mapa={mapa} apenas={['VALIDADO']}
      filtros={<SeletorColaborador mode="multiple" maxTagCount="responsive" value={colaboradores as unknown as number} onChange={(v) => setColaboradores((v as unknown as number[]) ?? [])}
        ids={res.map((r) => r.colaborador_id)} placeholder="Todos os colaboradores" style={{ minWidth: 280 }} />}
      accoes={mapa.periodo?.estado === 'VALIDADO' && pode('rh_recibos_emitir') ? (
        <Space wrap>
          <Button icon={<FilePdfOutlined />} disabled={colaboradores.length !== 1} onClick={pdf}>PDF do recibo</Button>
          <Button icon={<FileZipOutlined />} loading={aDescarregar} disabled={!res.length} onClick={() => void zip()}>
            {colaboradores.length ? `ZIP dos seleccionados (${colaboradores.length})` : 'ZIP (um PDF por colaborador)'}
          </Button>
        </Space>
      ) : undefined}
      aviso={mapa.periodo && mapa.periodo.estado !== 'VALIDADO' ? <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="Os recibos só se emitem de processamentos validados." /> : null}>
      {mapa.periodo?.estado === 'VALIDADO' && (
        <AreaImpressao>
          {visiveis.length === 0 && <Empty />}
          {visiveis.map((r, i) => {
            const d = dados(r.colaborador_id, r);
            return <ReciboSalario key={r.colaborador_id} resultado={r} mesAno={mesAno} empresa={dadosEmpresa}
              colaborador={{ nome: d.nome, nif: d.nif, numero_inss: d.inss, funcao: d.cargo ? cargos.nome(d.cargo) : r.funcao ?? null }} quebra={i < visiveis.length - 1} />;
          })}
        </AreaImpressao>
      )}
    </MolduraMapa>
  );
}
