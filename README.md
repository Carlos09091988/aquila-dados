# aquila-dados

Índice de dados abertos usado pelo [Raio-X do fornecedor](https://aquilaadvocacia.com.br/raio-x-do-fornecedor/) da Aquila Advocacia.

Uma vez por mês, o robô (`build.py`, executado pelo GitHub Actions) baixa as bases públicas da
**PGFN** (dívida ativa da União e do FGTS) e da **Receita Federal** (quadro de sócios e empresas)
e publica no GitHub Pages um índice dividido em arquivos pequenos, consultado pelo site.

Fontes: dadosabertos.pgfn.gov.br e arquivos.receitafederal.gov.br (dados abertos).
