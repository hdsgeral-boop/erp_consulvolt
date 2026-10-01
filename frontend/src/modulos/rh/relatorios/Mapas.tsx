import { Alert, Button, Card, Col, Empty, Row, Select, Tag, Typography } from 'antd';
import { DownloadOutlined, FileTextOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { GRUPOS_PAGAMENTO, type OrdemPagamento, type ResultadoSalarial } from '../api';
import { AreaImpressao, CabecalhoMapa, descarregar, SeletorColaborador } from '../comum/componentes';
import { useAvisarErro, useCargos } from '../comum/consultas';
import { ReciboSalario } from '../comum/ReciboSalario';
import { colunasRubricas, escalaoIrt, formatarIban, gerarCsv, kzCsv, somar, valoresRubricas } from '../comum/regras';
import { AvisoNaoValidado, MolduraMapa, useDadosColaborador, usePeriodoMapa } from './comum';

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
  const res = useMemo(() => [...(mapa.detalhe.data?.resultados ?? [])].sort(ordenarPorNome(dados)), [mapa.detalhe.data, dados]);
  const colunas = colunasRubricas(res);
  const valores = res.map(valoresRubricas);
  const exportar = () => {
    const cab = ['Colaborador', 'NIF', 'N.º INSS', 'Dias contr.', 'Dias trab.', ...colunas.map((c) => c.nome), 'Bruto', 'INSS trab.', 'IRT', 'Descontos', 'Líquido', 'INSS empresa'];
    const linhas = res.map((r, i) => {
      const d = dados(r.colaborador_id, r);
      return [d.nome, d.nif, d.inss, r.dias_contrato, r.dias_trabalhados, ...colunas.map((c) => kzCsv(valores[i][c.chave])), kzCsv(r.bruto), kzCsv(r.inss_trabalhador), kzCsv(r.irt), kzCsv(r.descontos), kzCsv(r.liquido), kzCsv(r.inss_patronal)];
    });
    descarregar(`mapa-remuneracoes-${mapa.detalhe.data?.mes_ano.replace('/', '-')}.csv`, gerarCsv(cab, linhas));
  };
  const total = (k: keyof ResultadoSalarial) => formatarKz(somar(res.map((r) => r[k] as string)));
  return (
    <MolduraMapa titulo="Mapa de remunerações" subtitulo="Remunerações do mês por colaborador e rubrica" mapa={mapa} aviso={<AvisoNaoValidado periodo={mapa.periodo} />}
      accoes={<Button icon={<DownloadOutlined />} disabled={!res.length} onClick={exportar}>Exportar CSV</Button>}>
      <AreaImpressao paisagem>
        <CabecalhoMapa empresa={empresa?.nome} titulo="Mapa de remunerações" mesAno={mapa.detalhe.data?.mes_ano} />
        <div style={{ overflowX: 'auto' }}>
          <table className="rh-tabela-mapa">
            <thead>
              <tr>
                <th>#</th><th style={{ textAlign: 'left' }}>Colaborador</th><th>NIF</th><th>N.º INSS</th><th>Dias</th>
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
                <td colSpan={5}>Totais ({res.length})</td>
                {colunas.map((c) => <td key={c.chave} className="num">{formatarKz(somar(valores.map((v) => v[c.chave])))}</td>)}
                <td className="num">{total('bruto')}</td><td className="num">{total('inss_trabalhador')}</td><td className="num">{total('irt')}</td>
                <td className="num">{total('descontos')}</td><td className="num">{total('liquido')}</td><td className="num">{total('inss_patronal')}</td>
              </tr>
            </tfoot>
          </table>
        </div>
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
      const e = escalaoIrt(r.base_irt);
      return [d.nif, d.inss, d.nome, d.provincia, d.municipio, kzCsv(r.bruto), kzCsv(r.inss_trabalhador), kzCsv(r.isencoes), kzCsv(r.base_irt), kzCsv(e.fixo), String(e.taxa).replace('.', ','), kzCsv(e.excesso), kzCsv(e.devido), kzCsv(r.irt)];
    });
    descarregar(`mapa-irt-${mapa.detalhe.data?.mes_ano.replace('/', '-')}.csv`, gerarCsv(cab, linhas));
  };
  return (
    <MolduraMapa titulo="Mapa de IRT" subtitulo="Retenções de IRT do mês (modelo oficial)" mapa={mapa} aviso={<AvisoNaoValidado periodo={mapa.periodo} />}
      accoes={<Button icon={<DownloadOutlined />} disabled={!grupoA.length} onClick={exportar}>Exportar CSV</Button>}>
      <AreaImpressao paisagem>
        <CabecalhoMapa empresa={empresa?.nome} titulo="Mapa de IRT — Grupo A (trabalhadores por conta de outrem)" mesAno={mapa.detalhe.data?.mes_ano} extra={empresa?.nif ? <div>NIF {String(empresa.nif)}</div> : null} />
        <div style={{ overflowX: 'auto' }}>
          <table className="rh-tabela-mapa">
            <thead>
              <tr><th>Ord.</th><th>NIF</th><th>N.º SS</th><th style={{ textAlign: 'left' }}>Nome</th><th>Província</th><th>Município</th><th>Bruto</th><th>S. Social (3%)</th>
                <th>Isenções</th><th>Matéria colectável</th><th>Parcela fixa</th><th>Taxa</th><th>Excesso</th><th>Imposto devido</th><th>Imposto retido</th></tr>
            </thead>
            <tbody>
              {grupoA.map((r, i) => {
                const d = dados(r.colaborador_id, r);
                const e = escalaoIrt(r.base_irt);
                return (
                  <tr key={r.colaborador_id}>
                    <td className="num">{i + 1}</td><td>{d.nif}</td><td>{d.inss}</td><td>{d.nome}</td><td>{d.provincia}</td><td>{d.municipio}</td>
                    <td className="num">{formatarKz(r.bruto)}</td><td className="num">{formatarKz(r.inss_trabalhador)}</td><td className="num">{formatarKz(r.isencoes)}</td>
                    <td className="num">{formatarKz(r.base_irt)}</td><td className="num">{formatarKz(e.fixo)}</td><td className="num">{formatarNumero(e.taxa)}%</td>
                    <td className="num">{formatarKz(e.excesso)}</td><td className="num">{formatarKz(e.devido)}</td><td className="num"><strong>{formatarKz(r.irt)}</strong></td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr>
                <td colSpan={6}>Totais ({grupoA.length})</td>
                <td className="num">{formatarKz(somar(grupoA.map((r) => r.bruto)))}</td><td className="num">{formatarKz(somar(grupoA.map((r) => r.inss_trabalhador)))}</td>
                <td className="num">{formatarKz(somar(grupoA.map((r) => r.isencoes)))}</td><td className="num">{formatarKz(somar(grupoA.map((r) => r.base_irt)))}</td>
                <td colSpan={3} /><td className="num">{formatarKz(somar(grupoA.map((r) => escalaoIrt(r.base_irt).devido)))}</td>
                <td className="num">{formatarKz(somar(grupoA.map((r) => r.irt)))}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <Typography.Paragraph type="secondary" className="rh-nao-imprimir" style={{ marginTop: 8 }}>
          O escalão (parcela fixa, taxa e excesso) e o imposto devido são mostrados pela tabela de IRT em vigor; o imposto retido é o calculado pelo servidor no processamento (nos períodos migrados, pelo modo do legado).
        </Typography.Paragraph>
        {grupoB.length > 0 && (
          <>
            <CabecalhoMapa titulo="Retenção de IRT — Grupo B (prestadores de serviço / avençados, 6,5 %)" />
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
      </AreaImpressao>
    </MolduraMapa>
  );
}

/** Salários a pagar: líquido por colaborador. */
export function MapaPagamentos() {
  const mapa = usePeriodoMapa();
  const { empresa } = useSessao();
  const dados = useDadosColaborador();
  const res = useMemo(() => [...(mapa.detalhe.data?.resultados ?? [])].filter((r) => Number(r.liquido) > 0).sort(ordenarPorNome(dados)), [mapa.detalhe.data, dados]);
  return (
    <MolduraMapa titulo="Salários a pagar" subtitulo="Líquido a receber por colaborador" mapa={mapa} aviso={<AvisoNaoValidado periodo={mapa.periodo} />}>
      <AreaImpressao>
        <CabecalhoMapa empresa={empresa?.nome} titulo="Lista de salários a pagar" mesAno={mapa.detalhe.data?.mes_ano} />
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
      {ordem.isLoading ? <Card loading bordered={false} /> : o && (
        <AreaImpressao>
          <CabecalhoMapa empresa={empresa?.nome} titulo="Ordem de pagamento bancária" mesAno={mapa.periodo?.mes_ano} extra={<div>Grupo: {GRUPOS_PAGAMENTO.find((g) => g.value === grupo)?.label}</div>} />
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
        </AreaImpressao>
      )}
    </MolduraMapa>
  );
}

/** Recibos de salário: individual ou todos (impressão em massa, um por página). Só períodos validados. */
export function MapaRecibos() {
  const mapa = usePeriodoMapa(['VALIDADO']);
  const { empresa } = useSessao();
  const dados = useDadosColaborador();
  const cargos = useCargos();
  const [colaborador, setColaborador] = useState<number>();
  const res = useMemo(() => [...(mapa.detalhe.data?.resultados ?? [])].sort(ordenarPorNome(dados)), [mapa.detalhe.data, dados]);
  const visiveis = colaborador ? res.filter((r) => r.colaborador_id === colaborador) : res;
  const mesAno = mapa.detalhe.data?.mes_ano ?? '';
  return (
    <MolduraMapa titulo="Recibos de salário" subtitulo="Recibos de vencimento da fotografia do período validado" mapa={mapa} apenas={['VALIDADO']}
      filtros={<SeletorColaborador value={colaborador} onChange={setColaborador} ids={res.map((r) => r.colaborador_id)} placeholder="Todos os colaboradores" />}
      aviso={mapa.periodo && mapa.periodo.estado !== 'VALIDADO' ? <Alert type="warning" showIcon style={{ marginBottom: 16 }} message="Os recibos só se emitem de processamentos validados." /> : null}>
      {mapa.periodo?.estado === 'VALIDADO' && (
        <AreaImpressao>
          {visiveis.length === 0 && <Empty />}
          {visiveis.map((r, i) => {
            const d = dados(r.colaborador_id, r);
            return <ReciboSalario key={r.colaborador_id} resultado={r} mesAno={mesAno} empresa={{ nome: empresa?.nome, nif: empresa?.nif ?? null }}
              colaborador={{ nome: d.nome, nif: d.nif, numero_inss: d.inss, funcao: d.cargo ? cargos.nome(d.cargo) : null }} quebra={i < visiveis.length - 1} />;
          })}
        </AreaImpressao>
      )}
    </MolduraMapa>
  );
}
