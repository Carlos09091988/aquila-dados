# Diários municipais — cobertura (05/11/2026)

Módulo: `diarios.php` (snippet 1607). Função central: `dv_dm_buscar( $texto, $desde, $ufs, $ate, $paginas, $frase )`.

## Fontes ligadas
- SIGPub (diariomunicipal.com.br) — 22 associações: amm-mg, famurs, amp, amupe, femurn, amm-mt, famup, agm, fgm, aprece, famep, ama, aam, ms, arom, appm, amr, amurc, sergipe, aemerj, apm, bahia.
  Busca GET `/{ent}/pesquisar?busca_avancada[texto]=...&dataInicio&dataFim&ordenacao=recente&pagina=N`; 25 por página; cartões `.materia-card`.
  Busca palavra por palavra (aspas ignoradas) e NÃO indexa números (CNPJ não funciona) → para nome, conferir frase no texto integral (`/materia/CODIGO`, `.documento__corpo`).
- DOM/SC (diariomunicipal.sc.gov.br) — 295 municípios. GET `?q=&dataInicial=dd/mm/aaaa&dataFinal=`; aceita "frase exata"; tem `&view=rss`. Ato: `/atos/ID`.
- Cobertura medida: 2.598 municípios (MG 498, SC 295, RS 271, PR 182, PE 177, GO 175, RN 160, MT 141, PB 121, PA 114, CE 109, AL 95, AM 61, MS 57, RO 52, PI 32, RR 15, BA 15, SE 11, RJ 10, SP 7).

## Ligado em
alertas (editais, com texto integral e filtro por título), buscador (/buscador/diarios), captador (Radar, frase exata), dossiê (24 meses), contratos (ficha), monitor (sigpub, domsc).

## Lacunas e o que já foi testado
- Querido Diário: fora do ar (TLS handshake falha do servidor e do navegador).
- SP: DiOE/DOSP (imprensaoficialmunicipal.com.br / dosp.com.br) — busca por cidade (`pesquisar.php?c=cidade`, POST termo) carregada por JS; sem busca nacional.
- BA: doem.org.br bloqueia robôs; diariooficialba.com.br (422 entidades) dá 403 no POST.
- MA: diariooficial.famem.org.br (Siganet) — POST `todasPublicacoes` com caxPesquisa/caxDtInicio/caxDtFim; resultado vem por JS.
- PI: diarioficialdosmunicipios.org (app JS). ES: ioes.dio.es.gov.br (pede login). TO: não achado.
