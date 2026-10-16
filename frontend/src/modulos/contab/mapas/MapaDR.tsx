import { Alert, Card, Checkbox, Form, Select } from 'antd';
import { useState } from 'react';
import { TabelaPorUnidade, useMapaPorUnidade } from '@/componentes/reparticao';
import { TituloMapa } from './TituloMapa';
import { BotaoPorUN } from './MapaBalanco';
import type { DemonstracaoResultados } from '../api';
import { TabelaDemonstracao } from '../comum/Demonstracao';
import { linhasDR } from '../comum/demonstracoes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { filtrosDosParametros, periodoDosParametros } from '../comum/impressao';
import { useMapa } from '../comum/useMapa';

/** Mapas › Demonstração de resultados (ecrã contab_mapa_dr), por natureza, com comparativo. */
export default function MapaDR() {
  const mapa = useMapa<DemonstracaoResultados>('dr', '/contabilidade/relatorios/demonstracao-resultados');
  const d = mapa.data;
  const comparativo = !!mapa.parametros?.comparativo;
  const homologo = mapa.parametros?.modo_comparativo === 'homologo';
  const [porUN, setPorUN] = useState(false);
  const porUnidade = useMapaPorUnidade<DemonstracaoResultados>('/contabilidade/relatorios/demonstracao-resultados', mapa.parametros, porUN);

  return (
    <>
      {/* legado: <h3>Demonstração de resultados comparativa</h3> (js/ui_reports.js:1305-1318) */}
      <TituloMapa titulo="Demonstração de resultados comparativa" subtitulo={periodoDosParametros(mapa.parametros) ?? 'Por natureza, com notas DEMO'} accoes={<BotaoPorUN ligado={porUN} aoMudar={setPorUN} />} />
      <Card style={{ marginBottom: 16 }}>
        <FiltrosMapa
          modo="periodo"
          aCalcular={mapa.isFetching}
          aoCalcular={mapa.calcular}
          avancados={{ unidade: true, centro: true, diario: true, classe9: true, apuramento: true }}
          valoresIniciais={{ comparativo: true, modo_comparativo: 'ano_anterior' }}
          extra={
            <>
              <Form.Item name="comparativo" valuePropName="checked" style={{ marginBottom: 8 }}>
                <Checkbox>Comparativo</Checkbox>
              </Form.Item>
              <Form.Item name="modo_comparativo" label="Comparar com" style={{ marginBottom: 8 }}>
                <Select style={{ width: 200 }} options={[{ value: 'ano_anterior', label: 'Ano anterior (completo)' }, { value: 'homologo', label: 'Período homólogo' }]} />
              </Form.Item>
            </>
          }
        />
      </Card>
      {porUN && mapa.parametros && (
        <Card loading={porUnidade.isFetching}>
          {porUnidade.data && <TabelaPorUnidade dados={porUnidade.data} linhas={linhasDR} titulo="Demonstração de resultados" periodo={periodoDosParametros(mapa.parametros)} />}
        </Card>
      )}
      {!porUN && d && (
        <>
          {comparativo && !d.historico_anterior && (
            <Alert type="info" showIcon style={{ marginBottom: 16 }} message="O comparativo é calculado a partir dos lançamentos (sem saldos históricos importados)." />
          )}
          <Card>
            <TabelaDemonstracao
              linhas={linhasDR(d)}
              comparativo={comparativo}
              rotuloAtual={`Ano ${d.ano}`}
              rotuloAnterior={homologo ? `Ano ${d.ano - 1} (homólogo)` : `Ano ${d.ano - 1}`}
              tipoNota="demonstracao"
              parametros={mapa.parametros ?? {}}
              nomeCsv={`demonstracao_resultados_${d.ano}`}
              impressao={{
                titulo: 'Demonstração de resultados por natureza',
                periodo: periodoDosParametros(mapa.parametros),
                filtros: [...filtrosDosParametros(mapa.parametros), comparativo ? (homologo ? 'Comparativo: período homólogo' : 'Comparativo: ano anterior') : null],
              }}
            />
          </Card>
        </>
      )}
    </>
  );
}
