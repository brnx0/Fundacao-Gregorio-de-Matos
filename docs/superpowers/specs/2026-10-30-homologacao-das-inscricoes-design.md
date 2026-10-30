# Homologação das inscrições — desenho

## Por que

Depois de encerrado o envio, a FGM confere se cada inscrição atende ao que o edital pede antes de levá-la à
avaliação. Hoje a conferência dos documentos é só automática (IA) e não há conferência das respostas do
questionário, nem registro do resultado, nem caminho para o proponente contestar. Só as inscrições homologadas
seguem para a avaliação (que fica para depois).

Ordem das fases de uma chamada: **Inscrição → Homologação → Avaliação**.

## Objetivos

- Uma pessoa da equipe abre uma inscrição finalizada, confere cada documento (com o parecer da IA ao lado) e cada
  resposta do questionário, marca cada item como **ok** ou **com problema** (com observação) e decide:
  **homologar** ou **não homologar**.
- Na não homologação, o motivo vem montado a partir dos itens marcados, e a pessoa pode completar o texto.
- O proponente vê o resultado e, se não homologada, pode entrar com **um** recurso até 3 dias depois do fim da
  homologação: troca os documentos marcados e/ou escreve uma justificativa.
- Alguém da equipe julga o recurso: **aceito** → homologada; **negado** → não homologada em definitivo.
- O período "Validação" das chamadas passa a se chamar **Avaliação** e fica depois da Homologação.

## Fora do escopo

- A avaliação dos projetos (critérios, notas, avaliadores).
- Avisos por e-mail ao proponente (a configuração de e-mail existe só na branch `feat/provedor-email`).
- Divisão de inscrições entre pessoas da equipe, papéis específicos para homologar, dupla conferência.
- Publicação de resultado em lote, lista pública de homologados, exportações.

## Anti-objetivos (seria um fracasso mesmo funcionando)

- O proponente perder o arquivo que a equipe conferiu: o arquivo enviado no recurso **não** sobrescreve o original
  enquanto o recurso não é julgado.
- O proponente mexer na inscrição depois que a equipe decidiu (reabrir, trocar escolhas, respostas ou documentos fora
  do recurso).
- Duas pessoas decidirem a mesma inscrição ao mesmo tempo e uma decisão sumir.
- Expor caminho de arquivo, CPF completo ou dados de outra inscrição.

## Decisões do usuário

- Qualquer pessoa da equipe (gestor da retaguarda) pode homologar qualquer inscrição; regras mais complexas virão.
- Documentos e respostas são conferidos por humano, além da IA nos documentos.
- Marcar item a item o que está errado; o sistema monta o motivo.
- Recurso: o proponente pode trocar os documentos marcados e/ou enviar uma justificativa; alguém julga.
- Prazo do recurso: **3 dias depois do fim da homologação** da chamada; se a decisão sair depois disso, 3 dias depois
  da decisão.
- Homologação vem antes da avaliação; só inscrição homologada é avaliada.
- O período "Validação" vira "Avaliação" e não tem relação com a homologação.

## Decisões minhas (o usuário aprovou o resumo; confira estas)

1. **Só homologa com tudo ok.** "Homologar" só fica disponível com todos os itens marcados como ok. "Não homologar"
   exige ao menos um item com problema **ou** um texto de motivo.
2. **Quando conferir e decidir** (o usuário definiu): **marcar os itens e homologar ou não homologar só dentro do
   período de homologação da chamada** (`homologacao_inicio` a `homologacao_fim`, dias inteiros no horário de
   Salvador). Fora dele a equipe só visualiza a inscrição, as marcações e a decisão. Chamada sem período de
   homologação fica só para visualizar, e a tela avisa para cadastrar o período no edital. **Julgar recurso** é a
   exceção (minha decisão): o recurso chega depois do fim da homologação, então pode ser julgado a partir do envio,
   sem depender de período. Enquanto a inscrição não tem decisão, o proponente ainda pode reabrir (dentro do período
   de inscrição); **reabrir apaga as marcações já feitas** (o proponente pode trocar arquivos, respostas e escolhas,
   e um "ok" antigo não pode valer para conteúdo que ninguém viu), e a inscrição volta para a lista quando for
   finalizada outra vez. **Com decisão, reabrir fica bloqueado.**
3. **Resultado visível na hora.** O proponente vê o resultado assim que a decisão é registrada.
4. **Prazo do recurso**: 3 dias corridos depois do mais tardio entre o fim da homologação da chamada e o dia da
   decisão (o usuário escolheu contar da decisão quando ela sai tarde). Como a decisão só acontece dentro do período,
   na prática é o fim da homologação + 3 dias; se o período for mudado depois da decisão para terminar antes dela,
   vale o dia da decisão + 3, e o proponente sempre tem ao menos 3 dias. O prazo vai até 23:59 do último dia, horário
   de Salvador.
5. **Um recurso por inscrição**, enviado uma vez (sem rascunho no servidor).
6. **Arquivos do recurso ficam separados** dos originais. Recurso aceito: cada arquivo novo passa a ser o documento
   da inscrição (o original é apagado). Recurso negado: os originais continuam e os novos ficam guardados no recurso.
7. **Sem IA nos arquivos do recurso** nesta versão: a equipe confere à mão.
8. **Página "Minhas inscrições" no portal**: hoje, encerrado o envio, nenhuma página leva à inscrição. Novo item no
   menu do portal lista as inscrições do usuário com a situação, em qualquer fase.
9. **Menu da retaguarda**: novo grupo **Inscrições** com o item **Homologação** (a avaliação entra no mesmo grupo
   depois).
10. **Renomear o período sem migration nova**: as colunas `validacao_inicio/fim` continuam no banco; muda o rótulo
    na tela (Períodos), nas mensagens de validação, no prompt e no esquema da importação por IA, e a ordem passa a
    ser Inscrição, Homologação, Avaliação. Nome interno e coluna ficam como estão.
11. **Edital mexido depois das decisões.** Com alguma inscrição do edital já decidida, o formulário do edital não
    deixa remover documentos exigidos (a remoção apagaria em cascata os arquivos, as marcações e os arquivos de
    recurso em que a decisão se apoiou), como o questionário já fica travado depois da primeira inscrição
    finalizada. Acrescentar documento continua possível; numa inscrição já decidida ele aparece só para leitura, sem
    mudar a decisão.
12. **O que se confere**: documentos exigidos (os enviados e os obrigatórios que faltarem) e as respostas das
    perguntas visíveis. Escolhas (cadastro, categoria, cotas) e o retrato do perfil aparecem só para leitura.

## Situações

Nova coluna `inscricoes.homologacao` (enum `SituacaoHomologacao`, nula enquanto não há decisão):

| Valor | Quando | Proponente vê |
|---|---|---|
| nula | finalizada, sem decisão | "Em homologação" |
| `homologada` | decisão homologar, ou recurso aceito | "Homologada" |
| `nao_homologada` | decisão não homologar, sem recurso | "Não homologada" + motivo + itens + prazo/botão de recurso |
| `em_recurso` | recurso enviado, sem julgamento | "Recurso em análise" |
| `recurso_negado` | recurso julgado e negado | "Não homologada (recurso negado)" + parecer |

`situacao` (rascunho/finalizada) continua como está.

## Dados

- `inscricoes`: `homologacao` (string 20, nula), `homologacao_motivo` (texto longo, nulo), `homologada_por`
  (FK users, nula), `homologada_em` (data e hora, nula).
- `inscricao_conferencias` — uma marcação por item conferido:
  `inscricao_id` (cascade), `edital_documento_id` (nulo) **ou** `edital_pergunta_id` (nulo) — exatamente um
  preenchido —, `situacao` (`ok` | `problema`), `observacao` (string 1000, nula; obrigatória com problema),
  `user_id`, timestamps. Únicos: (inscrição, documento) e (inscrição, pergunta).
- `inscricao_recursos` — no máximo um por inscrição: `inscricao_id` (único), `justificativa` (texto longo, nula),
  `enviado_em`, `decisao` (`aceito` | `negado`, nula), `parecer` (texto longo, nulo), `julgado_por`, `julgado_em`,
  timestamps.
- `inscricao_recurso_documentos`: `inscricao_recurso_id` (cascade), `edital_documento_id`, `arquivo`,
  `arquivo_nome`, timestamps; único (recurso, documento).

Texto livre com limite em bytes (`TamanhoEmBytes`): observação 1000; motivo, justificativa e parecer até 4000
caracteres (CLOB, sem limite de bytes do VARCHAR2). Tudo portátil Oracle/PostgreSQL.

## Retaguarda

### Lista (`/retaguarda/homologacao`)
- Inscrições finalizadas, de editais publicados, paginadas.
- Filtros: edital, situação da homologação (sem decisão, homologada, não homologada, em recurso, recurso negado) e
  busca por número ou nome do proponente.
- Colunas: número, proponente (nome; CPF/CNPJ mascarado), edital, categoria, finalizada em, situação; ação "Conferir".
- Ordem: em recurso primeiro, depois sem decisão (mais antigas primeiro), depois as decididas.
- Telas cifradas no histórico (`inertia.encrypt`), como as de proponentes.

### Conferência (`/retaguarda/homologacao/{inscricao}`)
- Cabeçalho: número, edital, chamada, proponente, situação, prazo do recurso (quando houver).
- Seção somente leitura: escolhas e retrato do perfil.
- **Documentos**: para cada documento exigido, nome, etapa, arquivo (abrir em nova aba pela retaguarda), parecer da
  IA (situação e motivo) e o controle **ok / com problema** com observação. Obrigatório não enviado aparece como
  "não enviado" e já vem como problema.
- **Questionário**: por seção, cada pergunta visível com a resposta formatada (mesma formatação da revisão do
  portal) e o mesmo controle.
- Cada marcação é gravada na hora (sem salvar a página inteira), com quem e quando.
- **Decisão**: "Homologar" (habilitado com tudo ok) e "Não homologar" (abre o motivo pré-montado:
  "Documentos: <nome> — <observação>; …  Questionário: <enunciado> — <observação>; …", editável). Confirmação antes
  de gravar. Com decisão registrada, as marcações ficam só para leitura.
- **Recurso** (quando `em_recurso`): justificativa, e para cada documento trocado o original e o novo lado a lado;
  parecer obrigatório; botões "Aceitar recurso" e "Negar recurso".

### Arquivos
- Nova rota da retaguarda que serve o arquivo da inscrição e o arquivo do recurso (inline), só para gestor, sem
  expor o caminho.

### Períodos do edital
- "Validação" vira "Avaliação" e passa para depois de "Homologação" na aba Períodos; mensagens e importação por IA
  acompanham.

## Portal

- **Minhas inscrições** (`/portal/inscricoes`): item no menu do portal; lista edital, número, cadastro, categoria,
  situação (rascunho, finalizada/em homologação, homologada…), link para abrir.
- **Página da inscrição**: no bloco de envio da revisão, o resultado:
  - em homologação: aviso simples;
  - não homologada: motivo, lista de itens com problema e observação, prazo; botão "Entrar com recurso" até o prazo;
  - em recurso / recurso negado / homologada: texto correspondente (parecer quando negado).
- **Recurso** (`/portal/inscricoes/{id}/recurso`): justificativa (até 4000) e, para cada documento marcado com
  problema, um campo para enviar o novo arquivo (mesmos tipos e tamanho do envio normal). Ao menos uma das duas
  coisas. Confirmação antes de enviar. Depois do envio, nada mais muda.
- Reabrir, trocar escolhas, respostas e documentos ficam bloqueados quando há decisão.

## Concorrência e travas

- Ordem de trava: edital → inscrição (a mesma do portal).
- Decidir, enviar recurso e julgar recurso travam a inscrição (`lockForUpdate`) e conferem a situação de novo dentro
  da transação. Reabrir passa a recusar inscrição com decisão, conferindo dentro da trava.

## Testes que importam

- Homologar só com tudo ok; não homologar exige item com problema ou texto; decisão grava quem e quando.
- Marcar e decidir só dentro do período de homologação (antes, depois e sem período: recusado, tela só leitura);
  julgar recurso depois do período funciona.
- Duas decisões seguidas: a segunda é recusada.
- Proponente: vê o resultado, não reabre com decisão, entra com recurso só até o prazo (com e sem
  `homologacao_fim`, limite do último dia), só uma vez, só documentos marcados, ao menos justificativa ou arquivo.
- Recurso aceito troca os arquivos e homologa; negado mantém os originais.
- Gestor vê arquivo da inscrição e do recurso; proponente de outra inscrição recebe 404; quem não é gestor vai ao
  login.
- Períodos: rótulos "Avaliação", ordem, mensagens.

## O que fica para quem constrói

Nomes de classes, componentes e rotas; formato exato das props; textos de ajuda além dos citados; layout fino das
telas seguindo os padrões existentes (retaguarda: `TabelaPaginada`, `SecaoCadastro`, `Interruptor`; portal:
`RevisaoDaInscricao`, `CampoArquivo`).
