import { Button } from 'antd';
import { useState, type ComponentType } from 'react';
import { useLocation } from 'react-router-dom';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { EstadoVazio } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { ControloDesequilibrios } from './mapas/ControloDesequilibrios';
import MapaBalancete, { MapaBalanceteRazao, MapaIvaSeparador } from './mapas/MapaBalancete';
import MapaBalanco from './mapas/MapaBalanco';
import MapaDR from './mapas/MapaDR';
import MapaEvolucao from './mapas/MapaEvolucao';
import MapaExtrato from './mapas/MapaExtrato';
import MapaFluxo from './mapas/MapaFluxo';
import { destinoSeparador, separadorActivo, SEPARADORES_RELATORIOS, type ChaveSeparadorRelatorio } from './mapas/separadoresRelatorios';

const CONTEUDO: Record<ChaveSeparadorRelatorio, ComponentType> = {
  extrato: MapaExtrato,
  balancete: MapaBalancete,
  razao: MapaBalanceteRazao,
  balanco: MapaBalanco,
  dr: MapaDR,
  fluxo: MapaFluxo,
  evolucao: MapaEvolucao,
  iva: MapaIvaSeparador,
};

/**
 * Contabilidade › «Relatórios e mapas contabilísticos» (renderRelatoriosContabeis, js/ui_reports.js:80-1700): um só ecrã
 * com os separadores-botão do legado — Extrato, Balancete, Balancete do Razão, Balanço, Demonstração de resultados,
 * Fluxo de Caixa, Evolução Mensal e Mapa IVA. Cada separador tem a sua rota (contab_mapa_*); a entrada «Mapas e
 * relatórios» (relatorios_contabeis) abre o primeiro permitido. O controlo de desequilíbrios (ferramenta do sistema
 * novo) fica no botão do cabeçalho.
 */
export function EcraRelatorios({ separador }: { separador: ChaveSeparadorRelatorio | null }) {
  const { pode } = useSessao();
  const { search } = useLocation();
  const [desequilibrios, setDesequilibrios] = useState(false);
  const activo = separadorActivo(separador, search, pode);
  const Conteudo = activo ? CONTEUDO[activo.chave] : null;

  return (
    <>
      <CabecalhoModulo
        titulo="Relatórios e mapas contabilísticos"
        icone="chart-line"
        ecraCheio
        minimoSeparadores={1}
        activo={activo?.chave}
        accoes={
          pode('relatorios_contabeis_view') && (
            <Button icon={<IconeFa nome="balance-scale" />} onClick={() => setDesequilibrios(true)}>
              Controlo de desequilíbrios
            </Button>
          )
        }
        separadores={SEPARADORES_RELATORIOS.map((s) => ({
          chave: s.chave,
          // Mapa IVA com o realce verde do legado quando não está activo (js/ui_reports.js:124)
          rotulo: s.chave === 'iva' && activo?.chave !== 'iva' ? <span style={{ color: '#059669' }} title={s.dica}>{s.rotulo}</span> : <span title={s.dica}>{s.rotulo}</span>,
          destino: destinoSeparador(s),
          icone: s.icone,
          permissao: `${s.ecra}_view`,
        }))}
      />
      {Conteudo ? (
        <Conteudo key={activo?.chave} />
      ) : (
        <EstadoVazio icone="lock" titulo="Sem acesso" texto="Não tem permissão para visualizar nenhum mapa contabilístico." />
      )}
      <ControloDesequilibrios aberto={desequilibrios} aoFechar={() => setDesequilibrios(false)} />
    </>
  );
}

/** Entrada «Mapas e relatórios» (ecrã relatorios_contabeis): o mesmo ecrã, no primeiro separador permitido. */
export default function RelatoriosContabeis() {
  return <EcraRelatorios separador={null} />;
}
