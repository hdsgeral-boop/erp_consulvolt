import { Alert, Button, Card, Checkbox, Form } from 'antd';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useState } from 'react';
import { TituloMapa } from './TituloMapa';
import { DemonstracaoPorEmpresa, InterruptorPorEmpresa, usePorEmpresa } from './PorEmpresa';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Balanco } from '../api';
import { MovimentosSemNota, TabelaDemonstracao } from '../comum/Demonstracao';
import { linhasBalanco } from '../comum/demonstracoes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { filtrosDosParametros, periodoDosParametros } from '../comum/impressao';
import { useMapa } from '../comum/useMapa';
import { TabelaPorUnidade, useMapaPorUnidade } from '@/componentes/reparticao';

/** Mapas › Balanço (ecrã contab_mapa_balanco), por notas DEMO, com comparativo e controlo de equilíbrio. */
export default function MapaBalanco() {
  const mapa = useMapa<Balanco>('balanco', '/contabilidade/relatorios/balanco');
  const [semNota, setSemNota] = useState(false);
  const [porUN, setPorUN] = useState(false);
  // holding: uma coluna por empresa do grupo + consolidado (legado renderPorEmpresa)
  const [porEmpresa, setPorEmpresa] = useState(false);
  const pe = usePorEmpresa('balanco', mapa.parametros, porEmpresa);
  const porUnidade = useMapaPorUnidade<Balanco>('/contabilidade/relatorios/balanco', mapa.parametros, porUN);
  const d = mapa.data;
  const comparativo = !!mapa.parametros?.comparativo;
  const controlo = d?.controlo.atual;

  return (
    <>
      {/* legado: <h3>Balanço patrimonial comparativo</h3> com «Por unidade de negócio: ON/OFF» à direita (js/ui_reports.js:1137-1150) */}
      <TituloMapa titulo="Balanço patrimonial comparativo" subtitulo={periodoDosParametros(mapa.parametros) ?? 'Demonstração da posição financeira por notas'} accoes={<><InterruptorPorEmpresa ligado={porEmpresa} aoMudar={setPorEmpresa} /><BotaoPorUN ligado={porUN} aoMudar={setPorUN} /></>} />
      <Card style={{ marginBottom: 16 }}>
        <FiltrosMapa
          modo="ate"
          aCalcular={mapa.isFetching}
          aoCalcular={mapa.calcular}
          avancados={{ unidade: true, centro: true, classe9: true, apuramento: true }}
          valoresIniciais={{ comparativo: true }}
          extra={
            <Form.Item name="comparativo" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Comparativo com o ano anterior</Checkbox>
            </Form.Item>
          }
        />
      </Card>
      {porUN && mapa.parametros && (
        <Card loading={porUnidade.isFetching}>
          {porUnidade.data && <TabelaPorUnidade dados={porUnidade.data} linhas={linhasBalanco} titulo="Balanço" periodo={periodoDosParametros(mapa.parametros)} />}
        </Card>
      )}
      {porEmpresa && !porUN && d && <DemonstracaoPorEmpresa linhas={linhasBalanco(d)} dados={pe.data} titulo="Balanço" periodo={periodoDosParametros(mapa.parametros)} carregando={pe.isFetching} />}
      {!porUN && !porEmpresa && d && controlo && (
        <>
          {!controlo.equilibrado && (
            <Alert
              type="warning"
              showIcon
              style={{ marginBottom: 16 }}
              message={`O Balanço não está equilibrado: diferença de ${formatarKz(controlo.diferenca, true)} entre o Activo e o Capital Próprio + Passivo.`}
              description={
                controlo.sem_nota.linhas > 0
                  ? `Há ${controlo.sem_nota.linhas} linha(s) sem nota DEMO (débitos ${formatarKz(controlo.sem_nota.debito)} · créditos ${formatarKz(controlo.sem_nota.credito)}).`
                  : undefined
              }
              action={controlo.sem_nota.linhas > 0 ? <Button size="small" onClick={() => setSemNota(true)}>Movimentos por mapear</Button> : undefined}
            />
          )}
          {comparativo && !d.historico_anterior && d.ano_anterior && (
            <Alert type="info" showIcon style={{ marginBottom: 16 }} message={`O comparativo de ${d.ano_anterior} é calculado a partir dos lançamentos (sem saldos históricos importados).`} />
          )}
          <Card>
            <TabelaDemonstracao
              demonstracao="balanco"
              linhas={linhasBalanco(d)}
              comparativo={comparativo}
              rotuloAtual={`Ano ${d.ano}`}
              rotuloAnterior={`Ano ${d.ano_anterior}`}
              tipoNota="demonstracao"
              parametros={mapa.parametros ?? {}}
              nomeCsv={`balanco_${d.data_fim}`}
              impressao={{ titulo: 'Balanço', periodo: periodoDosParametros(mapa.parametros), filtros: [...filtrosDosParametros(mapa.parametros), comparativo ? `Comparativo com ${d.ano_anterior}` : null] }}
            />
          </Card>
        </>
      )}
      {semNota && mapa.parametros && <MovimentosSemNota parametros={{ data_fim: mapa.parametros.data_fim, data_inicio: d?.data_inicio }} aoFechar={() => setSemNota(false)} />}
    </>
  );
}

/**
 * «Por unidade de negócio: ON/OFF» (botaoPorUN, js/ui_reports.js:402): uma coluna por unidade de negócio, «Sem unidade» e
 * o Total (Fase C, ui_reports.js:376-406).
 */
export function BotaoPorUN({ ligado, aoMudar }: { ligado: boolean; aoMudar: (v: boolean) => void }) {
  return (
    <Button
      size="small"
      type={ligado ? 'primary' : 'default'}
      icon={<IconeFa nome="sitemap" />}
      aria-pressed={ligado}
      title="Mostrar uma coluna com os valores de cada unidade de negócio"
      onClick={() => aoMudar(!ligado)}
    >
      Por unidade de negócio: {ligado ? 'ON' : 'OFF'}
    </Button>
  );
}
