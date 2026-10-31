import json,re
d=json.load(open('aquila/jur/tcu-licitacoes.json'))['selecionada']
def acha(n,a,pal):
    for x in d:
        if x['n']==n and x['a']==a and re.search(pal,x['e'],re.I): return x
    raise SystemExit('nao achei %s/%s %s'%(n,a,pal))
COL={'Plenário':'Plenário','Primeira Câmara':'1ª Câmara','Segunda Câmara':'2ª Câmara'}
def dec(n,a,pal,txt):
    x=acha(n,a,pal)
    return {'ac':f"Acórdão {n}/{a} – {COL.get(x['c'],x['c'])}",'data':x['d'],'rel':x['rel'].title(),'txt':txt,
            'enunciado':re.sub('<[^>]+>','',x['e']),'url':'https://pesquisa.apps.tcu.gov.br/documento/jurisprudencia-selecionada/*/KEY%253A'+x['k']+'/score%2520desc/0'}
S=[
{'slug':'erro-na-planilha','titulo':'Fui desclassificado por erro na planilha','tema':'Proposta e planilha de custos',
 'resumo':'Erro de preenchimento ou item esquecido na planilha de custos, por si só, não deveria tirar a sua empresa da disputa. O TCU entende que o órgão precisa abrir diligência e dar a chance de corrigir, desde que o valor total da proposta não aumente. E o pregoeiro tem que dizer com clareza o que está errado.',
 'pratica':['Peça por escrito a diligência para corrigir a planilha.','Corrija sem aumentar o preço global da proposta.','Se já foi desclassificado, manifeste a intenção de recorrer logo após o julgamento, na própria sessão.'],
 'prazo':'Recurso: 3 dias úteis (art. 165 da Lei 14.133/2021), com a intenção de recorrer manifestada imediatamente.',
 'dec':[dec('4668','2026','omissão sanável','Erro material ou omissão sanável na planilha não gera desclassificação sumária: o órgão deve fazer diligência para a correção, sem aumento do valor global.'),
        dec('4370','2023','pregoeiro','O pregoeiro precisa apontar exatamente o que está errado na planilha, e não só o módulo ou o item.'),
        dec('1784','2024','convenção coletiva','Terceirizada não pode ser desclassificada por deixar de incluir benefício da convenção coletiva que vale só para quem trabalha em contrato público.'),
        dec('906','2020','lucro zero','Diferenças entre a sua planilha e a do órgão, inclusive lucro zero ou negativo, não desclassificam por si só: o que importa é se a proposta é exequível.'),
        dec('898','2019','preços unitários','Erro na planilha que pode ser ajustado sem aumentar o preço global não é motivo para desclassificar.')]},
{'slug':'preco-inexequivel','titulo':'Disseram que o meu preço é inexequível','tema':'Inexequibilidade',
 'resumo':'Preço baixo, sozinho, não prova que a proposta é inviável. Em obras e serviços de engenharia, a Lei 14.133 presume inexequível o que fica abaixo de 75% do orçado, mas o TCU diz que essa presunção é relativa: a empresa tem que ter a chance de provar que consegue executar. Em bens e serviços em geral, o TCU trata valores abaixo de 50% do orçado como indício, que exige diligência antes de qualquer decisão.',
 'pratica':['Peça a diligência e demonstre a exequibilidade com planilha, notas fiscais e contratos parecidos.','Explique o que torna o seu preço possível: estrutura já instalada, ganho de escala, custo de oportunidade.','A análise deve olhar a proposta inteira, não um item isolado.'],
 'prazo':'Se a desclassificação acontecer, recurso em 3 dias úteis (art. 165 da Lei 14.133/2021).',
 'dec':[dec('214','2025','presunção relativa','O critério de 75% do art. 59, § 4º, é presunção relativa: o órgão deve dar à empresa a oportunidade de demonstrar a exequibilidade.'),
        dec('963','2024','50%','Em bens e serviços em geral, abaixo de 50% do orçado há indício de inexequibilidade, e o órgão deve fazer diligência antes de concluir.'),
        dec('379','2024','análise ampla','A conclusão pela inexequibilidade exige análise de todos os itens da proposta, não de itens isolados.'),
        dec('2705','2021','jornada','Salário proporcional à jornada prevista no edital, ainda que abaixo do piso fixado para jornada maior, não torna a proposta inexequível.'),
        dec('948','2024','manifestamente inexequível','Atenção: lance manifestamente inexequível pode ser excluído durante a própria disputa.')]},
{'slug':'atestado-exigido','titulo':'Pediram um atestado que eu não tenho (ou que parece exagerado)','tema':'Qualificação técnica e atestados',
 'resumo':'O órgão só pode pedir atestado das parcelas de maior relevância ou valor significativo do objeto, com quantidade mínima de até 50% dessas parcelas (art. 67, §§ 1º e 2º, da Lei 14.133/2021). Exigências como 100% dos postos ao mesmo tempo, tempo mínimo de experiência sem justificativa ou proibição de somar atestados precisam de justificativa técnica forte; sem ela, são irregulares e podem ser impugnadas.',
 'pratica':['Compare a exigência com o art. 67 e veja se o edital justifica a parcela pedida.','Se for exagerada, impugne o edital antes da sessão.','Cuidado: atestado de outra empresa do grupo não vale automaticamente, e o órgão pode pedir as notas fiscais do período atestado.'],
 'prazo':'Impugnação: até 3 dias úteis antes da abertura (art. 164 da Lei 14.133/2021).',
 'dec':[dec('3388','2026','maior relevância','É irregular exigir atestado sem demonstrar que o serviço é parcela de maior relevância técnica ou de valor significativo.'),
        dec('1604','2025','100%','Em serviços por postos de trabalho, é irregular exigir atestado de execução simultânea de 100% dos postos; o limite legal é de até 50%.'),
        dec('2585','2024','três anos','É irregular exigir, sem justificativa, 3 anos de experiência em terceirização para contrato de até 12 meses.'),
        dec('2839','2025','somatório','Proibir a soma de atestados só é válido com justificativa técnica robusta, registrada na fase preparatória.'),
        dec('2418','2026','pessoa jurídica diversa','Atestado em nome de outra empresa só vale se ficar demonstrada a transferência da capacidade técnica; sócios em comum não bastam.'),
        dec('519','2025','nota fiscal','Quando o órgão pede comprovação do atestado, ela é feita por nota fiscal, cobrindo todo o período atestado.')]},
{'slug':'repactuacao-negada','titulo':'O órgão não quer conceder a repactuação','tema':'Repactuação e reajuste',
 'resumo':'A repactuação vale para serviços contínuos com dedicação exclusiva de mão de obra e acompanha a variação dos custos do contrato, principalmente a convenção coletiva, respeitado o intervalo de 1 ano. O cuidado mais importante é o prazo: peça antes de assinar a prorrogação ou antes de o contrato terminar. Para o TCU, quem assina o aditivo sem pedir perde o direito (preclusão).',
 'pratica':['Protocole o pedido assim que a nova convenção coletiva for registrada, com a planilha demonstrando a variação.','Nunca assine a prorrogação sem pedir a repactuação ou, no mínimo, ressalvar o direito por escrito.','Guarde o protocolo e cobre resposta formal.'],
 'prazo':'Sem prazo fixo na lei, mas o direito se perde na prorrogação ou no fim do contrato se não for pedido antes. A Lei 14.133/2021 trata da repactuação no art. 135.',
 'nota':'As decisões abaixo foram tomadas na vigência da Lei 8.666/1993. A Lei 14.133/2021 manteve a repactuação (art. 135).',
 'dec':[dec('1574','2015','repactuação de preços aplica-se','A repactuação se aplica a serviços contínuos com dedicação exclusiva de mão de obra, respeitado 1 ano da data do orçamento, e deve ser demonstrada na planilha de custos.'),
        dec('1488','2016','repactuação','A repactuação só cabe em contratos de serviços contínuos com dedicação exclusiva de mão de obra.'),
        dec('2094','2010','prazo dentro do qual','O pedido pode ser feito do evento que gera a repactuação até a prorrogação seguinte ou o fim do contrato; depois disso, o direito se perde.'),
        dec('1601','2014','preclusão lógica','Assinar a prorrogação sem pedir os novos valores da convenção coletiva faz perder o direito à repactuação.')]},
{'slug':'multa-ou-penalidade','titulo':'Recebi uma notificação de multa ou penalidade','tema':'Sanções e defesa',
 'resumo':'Nenhuma penalidade pode ser aplicada sem processo e direito de defesa. Na Lei 14.133/2021, a multa vai de 0,5% a 30% do valor do contrato (art. 156, § 3º), e o impedimento de licitar vale no ente federativo que aplicou a sanção, enquanto a declaração de inidoneidade vale em todos (art. 156, §§ 4º e 5º). A penalidade não atinge automaticamente os contratos que você já tem.',
 'pratica':['Anote o prazo da defesa no dia em que receber a notificação.','Reúna e-mails, ofícios, medições e tudo que mostre a sua versão dos fatos.','Questione a proporcionalidade: a sanção tem que ser compatível com a gravidade da falha.'],
 'prazo':'Defesa: 15 dias úteis (arts. 157 e 158 da Lei 14.133/2021).',
 'nota':'Parte das decisões abaixo foi tomada na vigência da Lei 8.666/1993 e da Lei 10.520/2002.',
 'dec':[dec('2183','2019','ex nunc','A sanção vale dali para frente e não alcança automaticamente contratos assinados antes dela.'),
        dec('1757','2020','medida preventiva','É irregular desclassificar uma empresa só porque ela foi suspensa por outro órgão.'),
        dec('269','2019','ente federativo','O impedimento de licitar se limita ao ente federativo que aplicou a sanção (União, estado ou município).'),
        dec('2274','2020','10%','Multa por inexecução total limitada a 10% do valor do contrato. Note que o TCU já decidiu de forma diferente (ver o acórdão seguinte).'),
        dec('715','2021','20%','Multa de 20% sobre a parcela não executada foi considerada lícita.')]},
]
json.dump(S,open('aquila/jur/situacoes.json','w'),ensure_ascii=False,indent=1)
print('ok',sum(len(s['dec']) for s in S))
