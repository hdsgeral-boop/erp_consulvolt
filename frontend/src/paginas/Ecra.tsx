import { Result, message } from 'antd';
import { useEffect } from 'react';
import { Navigate, useParams } from 'react-router';
import { decidirEcra } from './guardaEcra';
import { ECRAS } from '@/modulos/registo';
import { EmConstrucao } from '@/componentes/EmConstrucao';
import { useSessao } from '@/sessao/SessaoContexto';

/** /m/:modulo/:ecra/* — mostra o ecrã registado, se o utilizador o puder ver (o servidor volta a validar cada pedido). */
export function Ecra() {
  const { modulo, ecra } = useParams();
  const { menu, pode } = useSessao();
  const decisao = decidirEcra(menu, modulo, ecra, pode);

  if (decisao === 'sem_rota') return <SemRota />;
  if (decisao === 'negado') {
    return <Result status="403" title="Sem acesso" subTitle="Não tem permissão para este ecrã nesta empresa." />;
  }
  const definicao = menu.find((m) => m.id === modulo)?.ecras.find((e) => e.id === ecra);
  const Componente = ecra ? ECRAS[ecra] : undefined;
  return Componente ? <Componente /> : <EmConstrucao nome={definicao?.nome ?? ecra ?? 'Ecrã'} />;
}

/** URL de um ecrã que não existe como página (ex.: o chat do assistente é um botão flutuante): volta ao Início com aviso. */
function SemRota() {
  useEffect(() => { message.warning('Esse endereço não corresponde a nenhum ecrã. Foi reencaminhado para o Início.'); }, []);
  return <Navigate to="/inicio" replace />;
}
