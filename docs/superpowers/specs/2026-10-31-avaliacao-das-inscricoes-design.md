# Avaliação das inscrições — desenho

## Por que

Depois da homologação, as inscrições homologadas de um edital precisam receber nota de mérito. A FGM convida
avaliadores externos (já cadastrados em **Cadastro → Avaliadores**), divide as inscrições entre eles, e cada um dá
as notas pelos critérios que o edital já traz na aba **Avaliações**. Hoje não existe nenhuma dessas etapas.

Ordem das fases de uma chamada: **Inscrição → Homologação → Avaliação**. Esta entrega cobre a Avaliação até as notas
estarem registradas e visíveis para a equipe.

## Objetivos

1. A equipe indica **quais avaliadores trabalham em um edital**. Cada um recebe um **convite por e-mail** com um link
   pessoal.
2. A equipe **distribui as inscrições homologadas** do edital entre esses avaliadores. **Cada inscrição tem um único
   avaliador.** Três modos:
   - **Igual**: divide em partes iguais, sem critério;
   - **Manual**: a equipe escolhe o avaliador de cada inscrição;
   - **Por categoria**: cada avaliador cobre algumas categorias do edital; as inscrições de cada categoria são
     divididas igualmente entre os avaliadores dela.
3. O avaliador entra pelo link do e-mail, confirma o **CPF** e vê só as inscrições atribuídas a ele. Abre a inscrição
   completa (dados do proponente, escolhas, documentos, respostas do questionário) e dá uma **nota por critério**,
   mais uma **observação opcional**. Vai salvando e, no fim, **conclui**; concluída, nada mais muda.
4. A equipe vê as notas: por inscrição, a nota de cada critério, a nota final e a observação.

## Fora do escopo

- Classificação, ranking, resultado final, publicação de resultado.
- O proponente ver a nota no portal.
- Senha do avaliador (virá depois; o acesso foi pensado para receber a senha como mais uma etapa).
- Mais de um avaliador por inscrição, média de notas, desempate.
- Conflito de interesse, aceite do convite, recusa pelo avaliador.
- Reabrir uma avaliação concluída.
- Recurso contra a nota.

## Anti-objetivos (seria um fracasso mesmo funcionando)

- Alguém entrar na área do avaliador **só com o CPF** de um avaliador (sem o link pessoal).
- Um avaliador ver ou baixar algo de uma inscrição que não é dele (documento, resposta, dados do proponente).
- Uma nota ou a avaliação mudar depois de concluída, ou ser apagada por uma transferência.
- A distribuição repetida mexer em inscrições que já têm avaliador.
- A nota final não bater com as notas por critério porque o critério do edital mudou depois.

## Decisões do usuário

- O avaliador **não tem conta nem senha** agora; o CPF o identifica. Senha virá no futuro.
- O convite **não precisa ser aceito**: entregar o link basta.
- O avaliador dá as notas nesta entrega, usando os critérios da aba Avaliações do edital, e vê a inscrição inteira.
- A nota de **cada critério fica guardada**, além da final.
- **Um único avaliador por inscrição**, definido pela distribuição.
- Distribuição: igual, manual ou por categoria (avaliador ↔ categorias; cada categoria dividida igualmente entre os
  seus avaliadores).
- Notas só **dentro do período de avaliação** da chamada; fora dele, só visualizar.
- O administrador pode **transferir** inscrições de um avaliador para outro.
- **Concluiu, não muda mais nada.**
- Além das notas, um **campo opcional** de observação.
- A distribuição pode rodar **mais de uma vez**: cada rodada pega só as homologadas **sem avaliador** e equilibra
  pelo que cada avaliador já tem (para as que viram homologadas depois, por recurso aceito).
- Depois de concluídas, a equipe **só vê as notas**.
- A equipe vê as notas **só das avaliações concluídas**. Antes de concluir, a aba Notas mostra apenas o avaliador e a
  situação (**não iniciada** ou **em andamento**), sem nota parcial nem observação.

## Decisões minhas (confira estas)

1. **Acesso = link pessoal + CPF.** O link do e-mail traz um código aleatório e longo, **um por avaliador** (o mesmo
   para todos os editais em que ele trabalha). Abrir o link pede o CPF; só o par link + CPF do mesmo avaliador
   entra. Sem o link, o CPF sozinho não entra em nada. Tentativas de CPF limitadas (5 por minuto por link e IP).
   O acesso fica na sessão do navegador e expira por inatividade como o resto do portal. O avaliador **desativado**
   em Cadastro perde o acesso na hora. Um botão **"Gerar novo link"** na retaguarda invalida o link antigo, **encerra
   as sessões já abertas** com ele e envia um e-mail com o link novo listando **todos os editais** em que ele trabalha.
   *Custo se errado: se a FGM quiser um link por edital, muda a tabela do acesso.*
2. **A área do avaliador fica separada** do portal do proponente e da retaguarda (endereço próprio, por exemplo
   `/avaliacao`), com o visual do portal. Mostra os editais em que ele tem inscrição atribuída e, em cada um, as
   inscrições com a situação: **não iniciada, em andamento, concluída**. Um avaliador que também é proponente usa as
   duas áreas separadamente.
3. **Nota**: número de **0 até a pontuação máxima** do critério, com até **2 casas decimais**. **Nota final = soma de
   (nota × peso)**; critério com peso vazio conta peso **1**. Os critérios que valem são os do edital marcados para
   todas as categorias mais os ligados à categoria da inscrição; inscrição sem categoria usa só os de todas as
   categorias.
4. **Concluir exige nota em todos os critérios** que valem para a inscrição. O rascunho aceita notas pela metade.
   A observação é opcional (até 4000 caracteres) e também trava ao concluir.
5. **Critérios travam** quando o edital tem a primeira nota salva (rascunho ou concluída): a aba Avaliações do edital
   não deixa mais adicionar, remover, mudar peso ou pontuação máxima, **nem mudar a quais categorias o critério vale**;
   e o edital **não deixa excluir categoria**. Tudo com um aviso — o mesmo padrão dos documentos travados depois da
   homologação. Nome e descrição continuam editáveis. Além da trava, cada nota guarda a **cópia do peso e da
   pontuação máxima** do critério no momento em que foi dada, e a **nota final é gravada ao concluir**: o que foi
   concluído nunca muda por causa do edital.
6. **Pré-condições da distribuição** (a tela explica o que falta): o edital precisa ter ao menos um avaliador, ao menos
   um critério, e **todo critério com pontuação máxima** preenchida. Sem isso, não distribui.
7. **Período**: salvar nota e concluir só **dentro do período de avaliação** (`validacao_inicio`/`validacao_fim` da
   chamada da inscrição, dias inteiros no horário de Salvador — o mesmo `UsaDiaCivil` da homologação). Fora dele, ou
   na chamada sem o período, o avaliador só vê, com o aviso. **Indicar avaliadores, enviar convite, distribuir e
   transferir** podem ser feitos **a qualquer momento**, para a equipe se preparar antes do período.
8. **Transferência**: só de inscrição **não concluída**. As notas de rascunho do avaliador anterior são **apagadas**;
   o novo começa do zero. Transferir para alguém que não está no edital não é possível. Também é por aqui que se
   corrige a distribuição manual.
9. **Tirar um avaliador do edital** só quando ele não tem nenhuma inscrição do edital (transferir antes). Avaliador
   inativo não aparece para ser indicado.
10. **Convite**: sai quando o avaliador é incluído no edital, com botão **"Reenviar convite"** por avaliador. Traz o
    nome dele, o título do edital, o período de avaliação, o link e o aviso de que vai precisar do CPF. O envio
    usa o mesmo caminho dos outros e-mails do sistema (a configuração da retaguarda, ou o servidor do `.env` quando
    ela está desligada) e sai **em segundo plano**, para a tela não esperar o servidor de e-mail. A lista de
    avaliadores do edital mostra, por avaliador, a situação do convite: **enviado em …**, **na fila** ou **falhou**
    (com o motivo, sem dados da conta de e-mail), com o botão de reenviar.
11. **Distribuição igual e por categoria**: as inscrições sem avaliador são embaralhadas e cada uma vai para o
    avaliador (do edital, ou da categoria dela) com **menos inscrições no edital** naquele momento; empate é sorteado.
    Assim a segunda rodada continua equilibrando. Por categoria: inscrição cuja categoria não tem avaliador **fica
    sem avaliador** e a tela lista quantas sobraram e de quais categorias. O modo por categoria só aparece em edital
    com categorias. Antes de confirmar, a tela mostra **uma estimativa** de quantas inscrições vão para cada avaliador; ao
    confirmar, a conta é refeita com o edital travado (pode ter entrado inscrição nova) e a tela mostra o que foi de
    fato gravado.
12. **Só inscrições homologadas** entram (situação `homologada`, incluída a que virou homologada por recurso aceito).
13. **Retaguarda**: novo item **Inscrições → Avaliação**, organizado por edital como a Homologação: lista dos editais
    com inscrição homologada (com contagens: homologadas, sem avaliador, concluídas) → página do edital com três abas:
    **Avaliadores** (incluir, categorias de cada um, convite), **Distribuição** (os três modos, a tabela inscrição →
    avaliador e a transferência), **Notas** (cada inscrição com avaliador e situação; a nota final só nas concluídas, e
    abrir uma concluída mostra a nota por critério e a observação).
14. **Quem na equipe**: qualquer gestor da retaguarda, como na homologação.
15. **Avaliador desativado em Cadastro e ainda no edital**: perde o acesso na hora, **não recebe** inscrições nas
    rodadas seguintes da distribuição, **não aparece** como destino na distribuição manual nem na transferência, e as
    abas Avaliadores e Distribuição avisam quantas inscrições não concluídas estão com ele, para transferir.

## Deixado para quem constrói

- Nomes de tabelas, colunas e rotas (lembrando o limite de 30 caracteres de identificador do Oracle e PostgreSQL ≥ 15).
- Como o código do link é guardado (hash para achar, e o que for preciso para reenviar o mesmo link), desde que o
  código nunca vá para log, sessão de erro ou URL da retaguarda.
- O desenho exato das telas, seguindo os componentes do rebrand (PaginaRetaguarda, PainelLista, BarraAcoes).
- Reaproveitar a montagem da inscrição completa da conferência da homologação (perfil, documentos, respostas) na
  tela do avaliador, com rotas de arquivo próprias que conferem a atribuição.
- Travas de concorrência no padrão já usado (`Travas`): edital, depois inscrição.
