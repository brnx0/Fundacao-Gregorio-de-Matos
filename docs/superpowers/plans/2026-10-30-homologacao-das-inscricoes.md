# Homologação das inscrições — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A equipe confere cada inscrição finalizada item a item (documentos e respostas) dentro do período de homologação da chamada e decide homologar ou não; o proponente vê o resultado, entra com um recurso até o prazo e a equipe julga o recurso; o período "Validação" da chamada vira "Avaliação", depois da Homologação.

**Architecture:** A situação da homologação mora em `inscricoes.homologacao` (enum `SituacaoHomologacao`, nula sem decisão), as marcações em `inscricao_conferencias` e o recurso em `inscricao_recursos` + `inscricao_recurso_documentos` (arquivos separados dos originais até o julgamento). `ConferenciaDaInscricao` (leitura: itens conferidos, marcação que vale, tudo ok, motivo montado) é usada pela retaguarda e pelo portal; as transições (marcar, decidir, recorrer, julgar, reabrir) ficam nos controllers, dentro de transação, com a trava edital → inscrição de `Travas` e a situação conferida de novo na linha travada. Regras de dia (período de homologação, prazo do recurso) seguem o dia civil de `UsaDiaCivil` (America/Bahia).

**Tech Stack:** Laravel 13 (PHP 8.4), PHPUnit, Inertia v2, Vue 3 `<script setup lang="ts">` (vue-tsc no `npm run build`), Tailwind v4, Oracle (yajra oci8) e PostgreSQL.

**Spec:** `docs/superpowers/specs/2026-10-30-homologacao-das-inscricoes-design.md` (as "Decisões minhas" 1–12 são aprovadas e valem como requisito).

## Global Constraints

- Execução neste worktree: `W=C:/Users/Breno/AppData/Local/Temp/claude/E--Dev-Mapa-Cultura/e4d54214-7206-4ddb-aab8-4b4296549565/scratchpad/wt-homologacao`. Nunca tocar em `E:/Dev/Mapa_Cultura` (só leitura de `vendor/`). Abaixo, `app` abrevia `MSYS_NO_PATHCONV=1 docker run --rm -v "$W:/var/www/html" -v "E:/Dev/Mapa_Cultura/vendor:/var/www/html/vendor:ro" --network mapa_cultura_default -w /var/www/html mapa_cultura-app`.
- Testes: PHPUnit feature tests criados com `app php artisan make:test --phpunit <Nome> --no-interaction`, factories, métodos `test_` em snake_case e em português como os vizinhos. TDD em cada task. Banco Oracle compartilhado: **um arquivo por vez**, `app php artisan test --compact <arquivo>`; nunca a suíte inteira em paralelo com outra coisa.
- Pint depois de mexer em PHP: `app vendor/bin/pint --dirty --format agent`. Build e tipos: `npm run build` dentro de `$W` (roda vue-tsc). Nunca Prettier.
- Migrations com `app php artisan make:migration <nome> --no-interaction`, portáveis Oracle e PostgreSQL (só Schema builder). Oracle: `''` vira NULL, ids voltam como string (sempre `(int)`), VARCHAR2 conta bytes (texto livre em coluna string com `App\Rules\TamanhoEmBytes`), texto longo = `longText` (CLOB) fora de listagens (selecionar colunas sem CLOB em consultas de lista), identificadores ≤ 30 caracteres: nomes explícitos e curtos para índices, únicos e FKs. Nunca `migrate` no banco de dev, nunca `migrate:fresh`.
- Sem dependências novas. Textos de tela em pt-BR. Marcações liga/desliga usam `Interruptor` (`role="switch"`), nunca checkbox; obrigatório com `*` vermelho (`obrigatorio` de `CampoCadastro`) e o destaque de erro existente (`aria-invalid` / `campo-invalido`). Vue com `lang="ts"`, props tipadas em `resources/js/types/index.ts`. Sem helper de rota nomeada no front: caminhos fixos com comentário dizendo a rota, como os vizinhos.
- Trava: sempre edital e depois inscrição (`lockForUpdate`), e a situação conferida de novo dentro da transação.
- Nunca expor caminho de arquivo; CPF mascarado em lista (`App\Support\Cpf::mascarar`), CNPJ formatado (`Cnpj::formatar`); telas da retaguarda com dados pessoais em `inertia.encrypt`.
- Datas de período: dia civil em America/Bahia (`UsaDiaCivil::hoje()`, comparação por `toDateString()` como `EditalChamada::situacao()`). O prazo do recurso vale até 23:59 do último dia.
- O período continua `validacao_inicio/validacao_fim` no banco e no código (Decisão 10): só rótulos, mensagens, ordem e textos da IA mudam; nenhuma migration para isso.
- Um commit por task, em português, mensagem terminando com linha em branco e `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Nunca push, nunca merge.

## Review Focus

1. **Último dia do prazo do recurso no fuso de Salvador**: com o servidor em UTC, às 23:59 de Salvador do último dia o recurso ainda entra, e à 00:00 do dia seguinte não. Teste na Task 2 (`test_prazo_do_recurso_vai_ate_o_fim_do_ultimo_dia_em_salvador`) e na Task 12 (`test_recurso_no_ultimo_minuto_entra_e_no_dia_seguinte_e_recusado`).
2. **Envio forjado do recurso**: arquivo para um documento que não foi marcado com problema (ou de outro edital) é recusado e nenhum arquivo fica no disco. Teste na Task 12 (`test_arquivo_para_documento_sem_problema_e_recusado_sem_sobrar_arquivo`).
3. **Marcar item que não faz parte da conferência**: pergunta escondida pela condição, documento opcional não enviado ou item de outro edital não grava marcação (422 com mensagem). Teste na Task 8 (`test_item_fora_da_conferencia_e_recusado`).
4. **Inscrição sem chamada** (a chamada foi apagada, `edital_chamada_id` nulo) ou chamada sem as datas de homologação: a tela abre só para leitura com o aviso, e marcar/decidir são recusados. Testes nas Tasks 7, 8 e 9 (`test_sem_periodo_de_homologacao_abre_so_para_leitura_com_aviso`, `test_inscricao_sem_chamada_nao_marca`, `test_fora_do_periodo_e_sem_chamada_nao_decide`).
5. **Texto só com espaços** em observação, motivo, justificativa ou parecer (no Oracle `''` vira NULL): conta como vazio — problema sem observação, recurso sem justificativa nem arquivo e julgamento sem parecer são recusados. Testes nas Tasks 8, 9 e 12.

## Pontos do spec decididos neste plano

- **Únicos das marcações:** um único de três colunas com `nullsNotDistinct()` em vez dos dois do spec, que quebrariam no Oracle (Task 1).
- **Obrigatório não enviado "já vem como problema":** é uma marcação automática (`Não enviado.`) que a equipe pode trocar por ok; ao não homologar, as automáticas viram linhas gravadas; com decisão, só o gravado conta (Task 5, Task 9).
- **Motivo vazio ao não homologar** com itens com problema: grava o motivo montado (Task 9).
- **Recurso julgado** não muda `homologada_por`/`homologada_em`/`homologacao_motivo` (ficam os da decisão original; o julgamento fica no recurso). Aceito: o `InscricaoDocumento` passa a apontar para o arquivo do recurso, que continua também na linha do recurso (Task 10).
- **Decisão 11:** "remover documento exigido" = remover qualquer documento já salvo do edital (Task 4).
- **Lista da retaguarda:** CPF mascarado, CNPJ formatado (não é dado pessoal).
- **Controle ok / com problema:** dois botões com `aria-pressed`, porque há três estados (não conferido, ok, problema); não é `Interruptor` nem checkbox (Task 11).
- **Situação no portal:** finalizada sem decisão mostra "Em homologação" em qualquer momento, como a tabela do spec (Task 2).

---

## File Structure

Novos (back end):
- `app/Enums/SituacaoHomologacao.php`, `app/Enums/MarcacaoDoItem.php`, `app/Enums/DecisaoRecurso.php` — situações.
- 3 migrations: `add_homologacao_to_inscricoes_table`, `create_inscricao_conferencias_table`, `create_inscricao_recursos_tables`.
- `app/Models/InscricaoConferencia.php`, `app/Models/InscricaoRecurso.php`, `app/Models/InscricaoRecursoDocumento.php` + factories.
- `app/Support/Inscricao/Travas.php` — trava edital → inscrição para os controllers novos.
- `app/Support/Inscricao/ConferenciaDaInscricao.php` — o que se confere, marcação que vale, tudo ok, itens com problema, motivo montado.
- `app/Support/Inscricao/RetratoDoPerfil.php` — linhas legíveis do `inscricoes.perfil`.
- `app/Http/Controllers/Retaguarda/HomologacaoController.php` (lista, conferência, arquivo, marcar, decidir), `app/Http/Controllers/Retaguarda/RecursoDaHomologacaoController.php` (julgar, arquivo do recurso).
- `app/Http/Requests/Retaguarda/MarcarItemRequest.php`, `DecidirHomologacaoRequest.php`, `JulgarRecursoRequest.php`.
- `app/Http/Controllers/InscricaoRecursoController.php` (portal: tela e envio do recurso), `app/Http/Requests/EnviarRecursoRequest.php`.
- `tests/Support/CenarioDaHomologacao.php` — trait com o cenário comum dos testes.

Novos (front end):
- `resources/js/Pages/Retaguarda/Homologacao/Index.vue`, `Show.vue`; `resources/js/Components/Retaguarda/Homologacao/MarcacaoDoItem.vue`, `DecisaoDaHomologacao.vue`, `JulgamentoDoRecurso.vue`.
- `resources/js/Pages/Portal/MinhasInscricoes.vue`, `resources/js/Pages/Portal/RecursoDaInscricao.vue`, `resources/js/Components/Inscricao/ResultadoDaHomologacao.vue`.

Modificados: `app/Models/Inscricao.php`, `app/Models/EditalChamada.php`, `app/Models/Edital.php`, `app/Models/PessoaFisica.php`, `database/factories/InscricaoFactory.php`, `app/Http/Controllers/InscricaoController.php`, `app/Http/Controllers/Retaguarda/EditalController.php`, `app/Http/Controllers/Retaguarda/ProponenteController.php`, `app/Http/Requests/Retaguarda/SalvarEditalRequest.php`, `app/Support/Inscricao/PropsDaInscricao.php`, `app/Support/EditalIa/{EsquemaDaResposta,PromptModelo,EditalDaResposta}.php`, `app/Support/Edital/SincronizadorDoEdital.php` (docblock), `routes/web.php`, `routes/retaguarda.php`, `resources/js/types/index.ts`, `resources/js/Components/Retaguarda/Edital/{ChamadasEdital,DocumentosEdital,ListaEditavel}.vue`, `resources/js/Pages/Retaguarda/Editais/Form.vue`, `resources/js/Components/Retaguarda/MenuRetaguarda.vue`, `resources/js/Components/MenuPortal.vue`, `resources/js/Components/Inscricao/RevisaoDaInscricao.vue`, `resources/js/Pages/Portal/InscricaoPassos.vue`.

---

### Task 1: Dados da homologação (enums, tabelas, modelos, factories)

**Files:**
- Create: `app/Enums/SituacaoHomologacao.php`, `app/Enums/MarcacaoDoItem.php`, `app/Enums/DecisaoRecurso.php`; migrations `add_homologacao_to_inscricoes_table`, `create_inscricao_conferencias_table`, `create_inscricao_recursos_tables`; `app/Models/InscricaoConferencia.php`, `app/Models/InscricaoRecurso.php`, `app/Models/InscricaoRecursoDocumento.php`; `database/factories/InscricaoConferenciaFactory.php`, `InscricaoRecursoFactory.php`, `InscricaoRecursoDocumentoFactory.php`
- Modify: `app/Models/Inscricao.php` (fillable, casts, relações), `database/factories/InscricaoFactory.php`
- Test: `tests/Feature/Inscricao/DadosDaHomologacaoTest.php`

**Interfaces:**
- Produces:
  - `enum SituacaoHomologacao: string`: `Homologada='homologada'`, `NaoHomologada='nao_homologada'`, `EmRecurso='em_recurso'`, `RecursoNegado='recurso_negado'`; `rotulo()` (retaguarda): `Homologada`, `Não homologada`, `Em recurso`, `Recurso negado`; `rotuloNoPortal()`: `Homologada`, `Não homologada`, `Recurso em análise`, `Não homologada (recurso negado)`.
  - `enum MarcacaoDoItem: string`: `Ok='ok'` (`Ok`), `Problema='problema'` (`Com problema`); `rotulo()`.
  - `enum DecisaoRecurso: string`: `Aceito='aceito'` (`Aceito`), `Negado='negado'` (`Negado`); `rotulo()`.
  - `inscricoes` + `homologacao` string(20) null, `homologacao_motivo` longText null, `homologada_por` foreignId null → `users` `nullOnDelete` (FK `inscricoes_homolog_por_fk`), `homologada_em` dateTime null; índice `inscricoes_homolog_idx` (`homologacao`). `down()` larga a FK pelo nome e depois as colunas.
  - `inscricao_conferencias`: `id`; `inscricao_id` → `inscricoes` cascade (`insc_conf_inscricao_fk`); `edital_documento_id` null → `edital_documentos` cascade (`insc_conf_documento_fk`); `edital_pergunta_id` null → `edital_perguntas` cascade (`insc_conf_pergunta_fk`); `situacao` string(20); `observacao` string(1000) null; `user_id` → `users` (`insc_conf_user_fk`); timestamps; **um único** `unique(['inscricao_id','edital_documento_id','edital_pergunta_id'], 'insc_conf_item_unico')->nullsNotDistinct()` (ver nota).
  - `inscricao_recursos`: `id`; `inscricao_id` → `inscricoes` cascade (`insc_rec_inscricao_fk`), `unique('inscricao_id', 'insc_rec_inscricao_unico')`; `justificativa` longText null; `enviado_em` dateTime; `decisao` string(20) null; `parecer` longText null; `julgado_por` null → `users` `nullOnDelete` (`insc_rec_julgado_por_fk`); `julgado_em` dateTime null; timestamps.
  - `inscricao_recurso_documentos`: `id`; `inscricao_recurso_id` → `inscricao_recursos` cascade (`insc_rec_doc_recurso_fk`); `edital_documento_id` → `edital_documentos` cascade (`insc_rec_doc_documento_fk`); `arquivo` string(255); `arquivo_nome` string(255); timestamps; `unique(['inscricao_recurso_id','edital_documento_id'], 'insc_rec_doc_unico')`.
  - Nota do único das marcações: no Oracle, `(1, 5, NULL)` repetido já viola um único composto e `(1, NULL, 7)` com `(1, NULL, 8)` não; dois únicos separados (inscrição, documento) e (inscrição, pergunta), como o spec descreve, quebrariam no Oracle na segunda pergunta marcada (`(1, NULL)` repetido). `nullsNotDistinct()` dá o mesmo comportamento no PostgreSQL (≥ 15) e o grammar do Oracle o ignora. "Exatamente um preenchido" é garantido pelo código (Task 8), não por CHECK.
  - `Inscricao`: fillable + `homologacao`, `homologacao_motivo`, `homologada_por`, `homologada_em`; casts `homologacao` → `SituacaoHomologacao`, `homologada_em` → `datetime`; `conferencias(): HasMany<InscricaoConferencia>`, `recurso(): HasOne<InscricaoRecurso>`, `homologador(): BelongsTo<User>` (`homologada_por`).
  - `InscricaoConferencia`: fillable `inscricao_id, edital_documento_id, edital_pergunta_id, situacao, observacao, user_id`; cast `situacao` → `MarcacaoDoItem`; `inscricao()`, `documento()` (`edital_documento_id`), `pergunta()` (`edital_pergunta_id`), `user()`.
  - `InscricaoRecurso`: fillable `inscricao_id, justificativa, enviado_em, decisao, parecer, julgado_por, julgado_em`; casts `enviado_em`/`julgado_em` datetime, `decisao` → `DecisaoRecurso`; `inscricao()`, `documentos(): HasMany<InscricaoRecursoDocumento>`, `julgador()` (`julgado_por`).
  - `InscricaoRecursoDocumento`: fillable `inscricao_recurso_id, edital_documento_id, arquivo, arquivo_nome`; `recurso()` (`inscricao_recurso_id`), `documento()` (`edital_documento_id`).
  - `InscricaoFactory`: estados `homologada()` (finalizada + `homologacao` Homologada, `homologada_em` now, `homologada_por` `User::factory()->gestor()`), `naoHomologada(string $motivo = 'Documentos: RG — ilegível.')`, `emRecurso()`, `recursoNegado()`.
  - `InscricaoConferenciaFactory`: padrão ok para um `EditalDocumento::factory()`, `user_id` gestor; estados `problema(string $observacao = 'Ilegível.')`, `daPergunta(EditalPergunta $pergunta)` (zera `edital_documento_id`). `InscricaoRecursoFactory`: inscrição `emRecurso()`, `enviado_em` now. `InscricaoRecursoDocumentoFactory`: `arquivo` `inscricoes/{uuid}.pdf`, `arquivo_nome` `novo.pdf`.

- [ ] **Step 1: Testes** em `DadosDaHomologacaoTest`:
  - `test_decisao_e_gravada_com_quem_e_quando` — `Inscricao::factory()->naoHomologada()->create()`; `fresh()->homologacao === SituacaoHomologacao::NaoHomologada`, `homologacao_motivo` igual, `homologador` é User.
  - `test_marcacoes_de_documento_e_de_pergunta_convivem_e_a_repetida_e_recusada` — duas marcações de perguntas diferentes e uma de documento na mesma inscrição gravam; repetir a mesma pergunta lança `UniqueConstraintViolationException`.
  - `test_recurso_e_um_por_inscricao` — segundo `InscricaoRecurso` da mesma inscrição lança `UniqueConstraintViolationException`.
  - `test_apagar_a_inscricao_apaga_marcacoes_e_recurso` — `assertModelMissing` nas três tabelas.
  - `test_motivo_longo_vai_inteiro` — `homologacao_motivo` com 4000 "ç" volta igual (CLOB).
  - `test_rotulos_da_situacao` — `rotulo()` e `rotuloNoPortal()` exatamente como acima.
- [ ] **Step 2:** `app php artisan test --compact tests/Feature/Inscricao/DadosDaHomologacaoTest.php` — FAIL (classes inexistentes).
- [ ] **Step 3: Implementar** o acima (migrations pelo `make:migration`, modelos e factories pelo `make:model -f`/`make:factory`).
- [ ] **Step 4:** Rodar o arquivo e `tests/Feature/Inscricao/ModeloDaInscricaoTest.php` — PASS.
- [ ] **Step 5:** pint; commit `feat: dados da homologação das inscrições`.

---

### Task 2: Período de homologação e prazo do recurso

**Files:**
- Create: `tests/Support/CenarioDaHomologacao.php`, `app/Support/Inscricao/Travas.php`
- Modify: `app/Models/EditalChamada.php`, `app/Models/Inscricao.php`
- Test: `tests/Feature/Inscricao/PeriodoDaHomologacaoTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `EditalChamada::naHomologacao(?CarbonInterface $agora = null): bool` — `homologacao_inicio` e `homologacao_fim` preenchidos e `hoje` (UsaDiaCivil) entre os dois, inclusive.
  - `EditalChamada::temPeriodoDeHomologacao(): bool`.
  - `Inscricao` passa a `use UsaDiaCivil`. `Inscricao::naHomologacao(?CarbonInterface $agora = null): bool` — `$this->chamada?->naHomologacao($agora) === true`.
  - `Inscricao::prazoDoRecurso(): ?CarbonImmutable` — nulo sem `homologada_em`; senão o último dia (meia-noite, fuso da app) = maior entre `chamada->homologacao_fim` (se houver) e o dia de `homologada_em` em America/Bahia, mais 3 dias corridos.
  - `Inscricao::podeRecorrer(?CarbonInterface $agora = null): bool` — `homologacao === NaoHomologada`, sem `recurso` gravado e `hoje <= prazoDoRecurso` (por `toDateString()`).
  - `Inscricao::situacaoNoPortal(): array{chave: string, rotulo: string}` — rascunho → `['rascunho','Rascunho']`; finalizada sem decisão → `['em_homologacao','Em homologação']`; senão `[homologacao->value, homologacao->rotuloNoPortal()]`.
  - `Travas::edital(Edital $edital): void` e `Travas::inscricao(Inscricao $inscricao): Inscricao` (mesmo corpo dos privados de `InscricaoController`, relação `edital` repassada). Os controllers existentes ficam como estão.
  - Trait `Tests\Support\CenarioDaHomologacao`: `editalEmHomologacao(array $chamada = []): Edital` (publicado, com PDF, `slug` único, chamada `inicio 2026-11-01`, `fim 2026-11-30`, `homologacao_inicio 2026-12-01`, `homologacao_fim 2026-12-10`, sobrescrevível); `inscricaoFinalizada(Edital $edital, ?User $dono = null): Inscricao` (dono proponente com perfil, chamada da edital, `finalizada()`); `documentoEnviado(Inscricao $i, string $nome, bool $obrigatorio = true, string $arquivo = 'inscricoes/original.pdf'): EditalDocumento` (cria o `EditalDocumento` e o `InscricaoDocumento` Enviado); `documentoExigido(Edital $e, string $nome, bool $obrigatorio = true): EditalDocumento`; `perguntaRespondida(Inscricao $i, string $enunciado, string $texto = 'Resposta'): EditalPergunta` (seção + pergunta texto curto `todas_categorias` + `InscricaoResposta`); `marcar(Inscricao $i, EditalDocumento|EditalPergunta $item, MarcacaoDoItem $m, ?string $obs = null): InscricaoConferencia`.

- [ ] **Step 1: Testes** (`travelTo` em horário de America/Bahia, como `FinalizarInscricaoTest`):
  - `test_homologacao_aberta_so_entre_inicio_e_fim_em_dias_inteiros` — `2026-11-30 23:59` false; `2026-12-01 00:00` true; `2026-12-10 23:59` true; `2026-12-11 00:00` false.
  - `test_sem_periodo_ou_sem_chamada_nao_esta_na_homologacao` — chamada sem as datas → false; `edital_chamada_id` nulo → false.
  - `test_prazo_do_recurso_e_tres_dias_depois_do_fim_da_homologacao` — decidida em `2026-12-05` → `prazoDoRecurso()->toDateString() === '2026-12-13'`.
  - `test_prazo_conta_da_decisao_quando_o_periodo_termina_antes_dela` — `homologacao_fim` mudado para `2026-12-03` depois da decisão em `2026-12-05` → `'2026-12-08'`; chamada sem período → `'2026-12-08'`.
  - `test_prazo_do_recurso_vai_ate_o_fim_do_ultimo_dia_em_salvador` — `travelTo(Carbon::parse('2026-12-13 23:59', 'America/Bahia'))` → `podeRecorrer()` true; `'2026-12-14 00:00'` Bahia → false.
  - `test_so_nao_homologada_sem_recurso_pode_recorrer` — homologada, em recurso, recurso negado e não homologada com `InscricaoRecurso` → false.
  - `test_situacao_no_portal` — as quatro + rascunho e em homologação, chave e rótulo exatos.
- [ ] **Step 2:** Rodar `tests/Feature/Inscricao/PeriodoDaHomologacaoTest.php` — FAIL.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar o arquivo — PASS.
- [ ] **Step 5:** pint; commit `feat: período de homologação e prazo do recurso`.

---

### Task 3: Período "Validação" vira "Avaliação", depois da Homologação

**Files:**
- Modify: `resources/js/Components/Retaguarda/Edital/ChamadasEdital.vue:88-176`, `app/Http/Requests/Retaguarda/SalvarEditalRequest.php:249-254,279-282`, `app/Support/EditalIa/EsquemaDaResposta.php:65-68`, `app/Support/EditalIa/PromptModelo.php:27`, `app/Support/EditalIa/EditalDaResposta.php:267-277,286`, `app/Models/EditalChamada.php:15`, `app/Support/Edital/SincronizadorDoEdital.php:31`, `resources/js/types/index.ts` (comentários de `ChamadaFormulario`/`ChamadaEditavel`, se houver)
- Test: `tests/Feature/Retaguarda/EditalEstruturaSalvarTest.php`, `tests/Unit/EditalDaRespostaTest.php`

**Interfaces:**
- Produces (só textos e ordem; chaves e colunas `validacao_*` ficam):
  - Mensagens: `chamadas.*.validacao_inicio.required_with` → `Preencha também o início da avaliação.`; `chamadas.*.validacao_fim.required_with` → `Preencha também o fim da avaliação.`; atributos `início da avaliação`, `fim da avaliação`. Na lista de mensagens e atributos, as de homologação vêm antes das de avaliação.
  - `ChamadasEdital.vue`: fieldsets na ordem Inscrição, **Homologação**, **Avaliação** (legenda `Avaliação`, id de ajuda `avaliacao-ajuda`; ids dos campos continuam `validacao_inicio`/`validacao_fim` porque casam com as chaves de erro); `nova()` lista as chaves na ordem nova.
  - `EsquemaDaResposta`: em `chamadas[]` as chaves `homologacao_*` antes de `validacao_*`; descrições `Início da avaliação dos projetos; vazio se o edital só der prazo relativo.` / `Fim da avaliação dos projetos; vazio se o edital só der prazo relativo.`
  - `PromptModelo` regra 9: `…registre as fases de inscrição, homologação e avaliação…` (resto igual).
  - `EditalDaResposta::chamada()`: `fase($item, 'homologacao', 'homologação', …)` antes de `fase($item, 'validacao', 'avaliação', …)`; docblock de `fase` diz `'homologacao' ou 'validacao' (avaliação)`. Avisos passam a sair na ordem homologação, avaliação.
  - Docblocks de `EditalChamada` e `SincronizadorDoEdital`: "a Inscrição, a Homologação e a Avaliação (colunas `validacao_*`)".

- [ ] **Step 1: Atualizar e escrever testes:**
  - `EditalEstruturaSalvarTest::test_fase_com_so_uma_data_e_recusada` — mapa de mensagens com `Preencha também o início da avaliação.` / `…o fim da avaliação.`
  - Novo `test_periodos_mostram_homologacao_antes_da_avaliacao`:
    ```php
    $vue = file_get_contents(resource_path('js/Components/Retaguarda/Edital/ChamadasEdital.vue'));
    $this->assertStringNotContainsString('>Validação<', $vue);
    $this->assertLessThan(strpos($vue, '>Avaliação</legend>'), strpos($vue, '>Homologação</legend>'));
    ```
  - `EditalDaRespostaTest::test_fase_com_data_impossivel_ou_fim_antes_do_inicio_fica_vazia_com_ponto_de_atencao` — avisos esperados, nesta ordem: `A chamada "Chamada 1" trouxe o fim da homologação antes do início; a fase ficou vazia.`, `A chamada "Chamada 1" trouxe uma data impossível na avaliação; a fase ficou vazia.`
  - Novo `EditalDaRespostaTest::test_esquema_e_prompt_falam_em_avaliacao` — o JSON de `EsquemaDaResposta` contém `Início da avaliação dos projetos` e não contém `validação das inscrições`; `PromptModelo::editavel()` contém `inscrição, homologação e avaliação`.
- [ ] **Step 2:** Rodar `tests/Feature/Retaguarda/EditalEstruturaSalvarTest.php` e `tests/Unit/EditalDaRespostaTest.php` (um por vez) — FAIL nos novos/alterados.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar os dois arquivos e `tests/Feature/Retaguarda/ImportacaoEditalTest.php` — PASS; `npm run build` — sem erro.
- [ ] **Step 5:** pint; commit `refactor: período de validação vira avaliação, depois da homologação`.

---

### Task 4: Documentos do edital travados depois de uma decisão (Decisão 11)

**Files:**
- Modify: `app/Models/Edital.php`, `app/Http/Requests/Retaguarda/SalvarEditalRequest.php` (`after()`), `app/Http/Controllers/Retaguarda/EditalController.php` (`edit` props, transação de `salvar`), `resources/js/Components/Retaguarda/Edital/ListaEditavel.vue`, `resources/js/Components/Retaguarda/Edital/DocumentosEdital.vue`, `resources/js/Pages/Retaguarda/Editais/Form.vue`, `resources/js/types/index.ts`
- Test: `tests/Feature/Retaguarda/EditalEstruturaSalvarTest.php`

**Interfaces:**
- Consumes: Task 1 (`inscricoes.homologacao`).
- Produces:
  - `Edital::documentosTravados(): bool` — existe inscrição do edital com `homologacao` não nula.
  - `Edital::AVISO_DOCUMENTOS_TRAVADOS = 'Os documentos exigidos não podem ser removidos porque o edital já tem inscrição com resultado da homologação: a remoção apagaria os arquivos em que a decisão se apoiou.'`
  - `SalvarEditalRequest::removeDocumentoSalvo(Edital $edital): bool` — com `com_listas`, algum id de `$edital->documentos()` não veio em `documentos.*.id`. `after()`: com `documentosTravados()` e `removeDocumentoSalvo()` → erro `documentos` = aviso.
  - `EditalController::salvar`: com `comListas()`, trava o edital (`lockForUpdate`, como o questionário) e repete a conferência dentro da transação → `ValidationException(['documentos' => aviso])`.
  - Props de `edit`: `documentosTravados: bool`, `avisoDocumentosTravados: string` (`create`: false).
  - `ListaEditavel` ganha `removerSoNovos?: boolean` (padrão false): sem botão remover para item com `id !== null`; adicionar e mover continuam. `DocumentosEdital` recebe `travados: boolean`, repassa e mostra o aviso acima da lista quando true.

- [ ] **Step 1: Testes:**
  - `test_com_inscricao_decidida_nao_remove_documento_exigido` — edital com 2 documentos e `Inscricao::factory()->homologada()` do edital; salvar com só um → `assertSessionHasErrors(['documentos' => Edital::AVISO_DOCUMENTOS_TRAVADOS])`, os 2 continuam.
  - `test_com_inscricao_decidida_acrescenta_e_edita_documento` — manda os 2 (nome trocado) + 1 novo → 3 documentos, nome novo gravado.
  - `test_sem_decisao_remove_documento_normalmente` — só inscrição finalizada sem decisão: remoção passa.
  - `test_tela_de_edicao_avisa_a_trava_dos_documentos` — `assertInertia(where('documentosTravados', true))`.
- [ ] **Step 2:** Rodar `tests/Feature/Retaguarda/EditalEstruturaSalvarTest.php` — FAIL nos novos.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar o arquivo e `tests/Feature/Retaguarda/EditaisTest.php` — PASS; `npm run build` — sem erro.
- [ ] **Step 5:** pint; commit `feat: documentos do edital não são removidos depois de uma decisão`.

---

### Task 5: O que se confere (ConferenciaDaInscricao)

**Files:**
- Create: `app/Support/Inscricao/ConferenciaDaInscricao.php`
- Modify: `app/Support/Inscricao/PropsDaInscricao.php`, `app/Http/Controllers/InscricaoController.php` (usa o `pergunta()` extraído)
- Test: `tests/Feature/Inscricao/ConferenciaDaInscricaoTest.php`

**Interfaces:**
- Consumes: Tasks 1–2 (modelos, `CenarioDaHomologacao`), `QuestionarioDaInscricao::visiveis(respostasSalvas())`, `Inscricao::documentosExigidos()`.
- Produces:
  - `PropsDaInscricao::pergunta(EditalPergunta $pergunta): array` (o `perguntaParaATela` privado de `InscricaoController` movido sem mudar o formato) e `PropsDaInscricao::secoes(QuestionarioDaInscricao $q): list<array{id, titulo, descricao, perguntas}>` (o mapeamento de `show`); `InscricaoController::show` passa a usá-los.
  - `final class ConferenciaDaInscricao`, `__construct(private Inscricao $inscricao)`; `public const string NAO_ENVIADO = 'Não enviado.'`
  - `documentos(): Collection<int, EditalDocumento>` — exigidos que foram enviados + obrigatórios sem envio, na ordem do edital (opcional não enviado fica de fora).
  - `perguntas(): Collection<int, EditalPergunta>` — `visiveis(respostasSalvas())`.
  - `temDocumento(int $id): bool`, `temPergunta(int $id): bool`.
  - `marcacoes(): array{documentos: array<int, Marcacao>, perguntas: array<int, Marcacao>}` com `Marcacao = array{situacao: MarcacaoDoItem, observacao: ?string, automatica: bool, por: ?string, em: ?string}` (`em` em `d/m/Y H:i`), só para itens de `documentos()`/`perguntas()`. Sem decisão, obrigatório não enviado sem marcação gravada vale `Problema` com `NAO_ENVIADO`, `automatica: true`. Com decisão, só as gravadas contam.
  - `tudoOk(): bool` — todo item de `documentos()` e `perguntas()` com marcação `Ok`; zero itens → true.
  - `itensComProblema(): list<array{tipo: 'documento'|'pergunta', id: int, nome: string, observacao: ?string}>` — documentos e depois perguntas, na ordem.
  - `documentosComProblema(): Collection<int, EditalDocumento>`.
  - `motivoSugerido(): string` — grupos não vazios, separados por `"\n"`: `Documentos: <nome> — <observação>; <nome> — <observação>.` e `Questionário: <enunciado> — <observação>.`; de cada observação sai o ponto final (`rtrim($obs, '.')`) e o grupo termina com um ponto; item sem observação fica só `<nome>`; `''` sem problema.
  - `gravarProblemasAutomaticos(int $userId): void` — grava como linha as marcações automáticas (usado ao não homologar, Task 9).

- [ ] **Step 1: Testes:**
  - `test_confere_os_enviados_e_os_obrigatorios_que_faltam` — enviado obrigatório, enviado opcional, obrigatório não enviado (exigido depois), opcional não enviado → `documentos()` tem os 3 primeiros, na ordem.
  - `test_obrigatorio_nao_enviado_vem_como_problema_e_pode_ser_marcado_ok` — `marcacoes()['documentos'][$id]` = Problema, `Não enviado.`, `automatica` true; depois de `marcar(..., Ok)` → Ok, `automatica` false.
  - `test_pergunta_escondida_pela_condicao_nao_e_conferida` — condicionada cuja origem não tem a opção → fora de `perguntas()` e `temPergunta()` false.
  - `test_tudo_ok_so_com_todos_os_itens_marcados_ok` — um não marcado → false; todos ok → true.
  - `test_motivo_montado_dos_itens_com_problema` —
    ```php
    $this->assertSame("Documentos: RG — Ilegível; Certidão — Não enviado.\nQuestionário: Nome do projeto — Vazio.", $conferencia->motivoSugerido());
    ```
    (RG marcado `Ilegível.`, Certidão obrigatória não enviada, pergunta marcada `Vazio`.)
  - `test_com_decisao_vale_so_o_que_foi_gravado` — inscrição `naoHomologada` com obrigatório novo não enviado → sem marcação para ele; `itensComProblema()` só com as gravadas.
- [ ] **Step 2:** Rodar `tests/Feature/Inscricao/ConferenciaDaInscricaoTest.php` — FAIL.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar o arquivo, `tests/Feature/Portal/InscricaoTest.php` e `tests/Feature/Portal/QuestionarioDaInscricaoTest.php` (um por vez) — PASS.
- [ ] **Step 5:** pint; commit `feat: conferência da inscrição (itens, marcações e motivo)`.

---

### Task 6: Retaguarda — lista da homologação e menu

**Files:**
- Create: `app/Http/Controllers/Retaguarda/HomologacaoController.php` (`index`), `resources/js/Pages/Retaguarda/Homologacao/Index.vue`
- Modify: `routes/retaguarda.php`, `resources/js/Components/Retaguarda/MenuRetaguarda.vue`, `resources/js/types/index.ts`
- Test: `tests/Feature/Retaguarda/HomologacaoListaTest.php`

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces:
  - Rota `GET /retaguarda/homologacao` → `retaguarda.homologacao.index`, dentro de `['auth','gestor']` e `inertia.encrypt`.
  - `HomologacaoController::index(Request $request): Response` → `Retaguarda/Homologacao/Index` com:
    - `inscricoes: Paginado<LinhaDaHomologacao>` (15 por página, `withQueryString`), `LinhaDaHomologacao = {id: number; numero: string; proponente: string; documento: string; edital: string; categoria: string | null; finalizadaEm: string; homologacao: SituacaoHomologacao | null; situacaoRotulo: string}`; `documento` = CPF mascarado (11 dígitos) ou CNPJ formatado; `situacaoRotulo` = `Sem decisão` ou `rotulo()`; `proponente` = `PropsDaInscricao::nomeDoCadastro($inscricao, $inscricao->user)`.
    - `filtros: {edital?: string; situacao?: string; busca?: string}`, `editais: {valor: string; rotulo: string}[]` (publicados com inscrição finalizada; valor = id), `situacoes: {valor: string; rotulo: string}[]` = `sem_decisao` (`Sem decisão`) + os quatro de `SituacaoHomologacao`.
  - Consulta: `situacao = finalizada`, `whereHas('edital', publicados)`, sem colunas CLOB (`select` explícito, sem `perfil`/`homologacao_motivo`); `edital` filtra por id numérico; `situacao` whitelisted (`sem_decisao` → `whereNull`); `busca` = `numero LIKE` ou `LOWER(users.name)`/`LOWER(organizacoes.nome_fantasia|razao_social) LIKE ? ESCAPE '\'` (como `ProponenteController::buscar`). Ordem: `orderByRaw("CASE WHEN homologacao = 'em_recurso' THEN 0 WHEN homologacao IS NULL THEN 1 ELSE 2 END")`, `finalizada_em`, `id`.
  - TS: `type SituacaoHomologacao = 'homologada' | 'nao_homologada' | 'em_recurso' | 'recurso_negado'`, `LinhaDaHomologacao`, `RetaguardaHomologacaoIndexProps`.
  - `Index.vue`: espelha `Proponentes/Index.vue` (filtros com `router.get('/retaguarda/homologacao', …)` — comentário com a rota —, `TabelaPaginada`, selo da situação, ação `Conferir` → `/retaguarda/homologacao/{id}`).
  - Menu: grupo novo `{ id: 'menu-grupo-inscricoes', texto: 'Inscrições', itens: [{ href: '/retaguarda/homologacao', texto: 'Homologação' }] }` entre `portal` e `cadastro`.

- [ ] **Step 1: Testes** (gestor logado; `CenarioDaHomologacao`):
  - `test_lista_so_finalizadas_de_editais_publicados_com_cpf_mascarado` — rascunho e finalizada de edital não publicado ficam fora; `inscricoes.data.0.documento === '***.982.247-**'`.
  - `test_em_recurso_primeiro_depois_sem_decisao_mais_antigas_depois_decididas` — 4 inscrições → ordem dos ids esperada.
  - `test_filtra_por_edital_situacao_e_busca` — `?situacao=sem_decisao`, `?edital={id}`, `?busca=maria` (nome em maiúsculas no banco), `?busca={numero}`.
  - `test_situacao_desconhecida_e_ignorada` — `?situacao=xyz` → todas.
  - `test_quem_nao_e_gestor_vai_para_o_login_da_retaguarda` — visitante e proponente → redirect `/retaguarda/login`.
  - `test_menu_tem_o_grupo_inscricoes_com_homologacao` — lê `MenuRetaguarda.vue`: contém `route('retaguarda.homologacao.index', absolute: false)`, `'Inscrições'`, `'Homologação'`.
- [ ] **Step 2:** Rodar `tests/Feature/Retaguarda/HomologacaoListaTest.php` — FAIL.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar o arquivo — PASS; `npm run build` — sem erro.
- [ ] **Step 5:** pint; commit `feat: lista da homologação na retaguarda`.

---

### Task 7: Retaguarda — dados da conferência e arquivos

**Files:**
- Create: `app/Support/Inscricao/RetratoDoPerfil.php`, `app/Http/Controllers/Retaguarda/RecursoDaHomologacaoController.php` (`arquivo`), `resources/js/Pages/Retaguarda/Homologacao/Show.vue` (mínima: layout, props tipadas, título com o número — nos testes o Inertia exige que a página exista, `testing.ensure_pages_exist`; a Task 11 completa)
- Modify: `app/Http/Controllers/Retaguarda/HomologacaoController.php` (`show`, `arquivo`), `app/Models/PessoaFisica.php`, `app/Http/Controllers/Retaguarda/ProponenteController.php` (usa o método movido), `routes/retaguarda.php`, `resources/js/types/index.ts`
- Test: `tests/Feature/Retaguarda/HomologacaoConferenciaTest.php`

**Interfaces:**
- Consumes: Tasks 2, 5.
- Produces:
  - Rotas (gestor): `GET /retaguarda/homologacao/{inscricao}` → `retaguarda.homologacao.show` (`inertia.encrypt`, `whereNumber`); `GET /retaguarda/homologacao/{inscricao}/documentos/{documento}/arquivo` → `retaguarda.homologacao.documentos.arquivo`; `GET /retaguarda/homologacao/{inscricao}/recurso/documentos/{documento}/arquivo` → `retaguarda.homologacao.recurso.arquivo` (as duas fora do encrypt, `whereNumber`).
  - `HomologacaoController::show(Inscricao $inscricao): Response` — 404 se não finalizada; `Retaguarda/Homologacao/Show` com `RetaguardaHomologacaoShowProps`:
    - `inscricao: {id, numero, edital: string, chamada: string | null, proponente, documento, cadastro: 'Pessoa física' | 'Pessoa jurídica', categoria, cotas: string[] (rótulos), finalizadaEm, homologacao: SituacaoHomologacao | null, situacaoRotulo, motivo: string | null, decididaPor: string | null, decididaEm: string | null, prazoDoRecurso: string | null}`
    - `periodo: {inicio: string | null; fim: string | null}`, `podeConferir: boolean` (= sem decisão e `naHomologacao()`), `aviso: string | null` (`HomologacaoController::AVISO_SEM_PERIODO` sem chamada ou sem as datas; `AVISO_FORA_DO_PERIODO` fora delas; nulo dentro ou com decisão)
    - `perfil: {rotulo: string; valor: string | null}[]` (de `RetratoDoPerfil::linhas($inscricao->perfil)`)
    - `documentos: {id, nome, etapaRotulo, obrigatorio, enviado: {nome, url} | null, ia: {situacao, rotulo, motivo} | null, marcacao: MarcacaoConferida | null}[]` (`url` da rota de arquivo da retaguarda; `MarcacaoConferida = {situacao: 'ok' | 'problema'; observacao: string | null; automatica: boolean; por: string | null; em: string | null}`)
    - `secoes` (formato de `PropsDaInscricao::secoes`), `respostas` (objeto pelo id), `perguntasConferidas: number[]`, `marcacoesDasPerguntas: Record<number, MarcacaoConferida>`
    - `motivoSugerido: string`, `podeHomologar: boolean` (= `podeConferir && tudoOk()`)
    - `recurso: {justificativa, enviadoEm, decisao, decisaoRotulo, parecer, julgadoPor, julgadoEm, documentos: {id, nome, original: {nome, url} | null, novo: {nome, url}}[]} | null`, `podeJulgar: boolean` (= `homologacao === EmRecurso`)
  - Constantes: `AVISO_SEM_PERIODO = 'A chamada desta inscrição não tem período de homologação. Cadastre o período na aba Períodos do edital para conferir e decidir.'`; `AVISO_FORA_DO_PERIODO = 'Fora do período de homologação da chamada (:inicio a :fim): a inscrição fica só para consulta.'` (datas `d/m/Y`).
  - `HomologacaoController::arquivo(Inscricao $inscricao, EditalDocumento $documento): StreamedResponse` e `RecursoDaHomologacaoController::arquivo(Inscricao $inscricao, EditalDocumento $documento): StreamedResponse` — `Storage::disk(Inscricao::DISCO)->response($arquivo, $arquivoNome)` (inline); 404 sem linha ou sem arquivo no disco.
  - `PessoaFisica::rotuloDoTipoDeDeficiencia(): ?string` — o `ProponenteController::tipoDeDeficiencia` privado movido para o modelo.
  - `RetratoDoPerfil::linhas(?array $perfil): list<array{rotulo: string, valor: ?string}>` — `(new PessoaFisica)->forceFill($perfil)` para valer os casts; rótulos iguais à seção "Identidade e perfil" de `ProponenteController::secoes`; `[]` com perfil nulo.

- [ ] **Step 1: Testes** (`Storage::fake('local')`, gestor, `travelTo('2026-12-05 10:00' Bahia)`):
  - `test_conferencia_traz_documentos_com_ia_respostas_e_marcacoes` — `assertInertia`: `documentos.0.ia.situacao`, `documentos.0.enviado.url` = rota da retaguarda, `perguntasConferidas`, `marcacoesDasPerguntas.{id}.situacao === 'problema'`, `podeConferir` true, `aviso` null; nenhum valor de prop contém `inscricoes/` (sem caminho).
  - `test_sem_periodo_de_homologacao_abre_so_para_leitura_com_aviso` — chamada sem as datas → `podeConferir` false, `aviso === AVISO_SEM_PERIODO`; `edital_chamada_id` nulo → idem.
  - `test_fora_do_periodo_abre_so_para_leitura` — `travelTo('2026-12-11 00:00')` → `podeConferir` false, aviso com `01/12/2026 a 10/12/2026`.
  - `test_rascunho_da_404` — inscrição reaberta → 404.
  - `test_retrato_do_perfil_aparece_com_rotulos` — `perfil` com `escolaridade` → linha `Escolaridade` com o rótulo do enum.
  - `test_gestor_abre_o_arquivo_da_inscricao_e_do_recurso` — `Content-Disposition` começa com `inline; filename=`.
  - `test_arquivo_de_documento_nao_enviado_ou_fora_do_disco_da_404`.
  - `test_quem_nao_e_gestor_vai_para_o_login` — dono da inscrição nas três rotas → redirect `/retaguarda/login`.
  - `ProponentesTest` continua passando (método movido).
- [ ] **Step 2:** Rodar `tests/Feature/Retaguarda/HomologacaoConferenciaTest.php` — FAIL.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar o arquivo e `tests/Feature/Retaguarda/ProponentesTest.php` — PASS; `npm run build` — sem erro.
- [ ] **Step 5:** pint; commit `feat: dados da conferência da inscrição e arquivos na retaguarda`.

---

### Task 8: Retaguarda — marcar item

**Files:**
- Create: `app/Http/Requests/Retaguarda/MarcarItemRequest.php`
- Modify: `app/Http/Controllers/Retaguarda/HomologacaoController.php` (`marcar`), `routes/retaguarda.php`
- Test: `tests/Feature/Retaguarda/HomologacaoMarcarTest.php`

**Interfaces:**
- Consumes: Tasks 2 (`Travas`, `naHomologacao`), 5 (`temDocumento`, `temPergunta`).
- Produces:
  - Rota `PUT /retaguarda/homologacao/{inscricao}/conferencias` → `retaguarda.homologacao.conferencias.update`.
  - `MarcarItemRequest`: `tipo` `required|in:documento,pergunta`; `item` `required|integer`; `situacao` `required` + `Rule::enum(MarcacaoDoItem::class)`; `observacao` `nullable|string|max:1000` + `TamanhoEmBytes(1000)` + `required_if:situacao,problema` (mensagem `Descreva o problema.`).
  - `HomologacaoController::marcar(MarcarItemRequest $request, Inscricao $inscricao): RedirectResponse` — transação: `Travas::edital`, `Travas::inscricao`; recusa (`back()->with('erro', …)`) se não finalizada, com decisão (`ERRO_JA_DECIDIDA`) ou fora de `naHomologacao()` (`ERRO_FORA_DO_PERIODO = 'Fora do período de homologação: a inscrição fica só para consulta.'`); item fora de `ConferenciaDaInscricao` → `ValidationException(['item' => ERRO_ITEM])`; `updateOrCreate` pela chave (inscrição + só uma das colunas preenchida, a outra nula) com `situacao`, `observacao`, `user_id`. Volta `back()` sem flash de sucesso (a tela atualiza a marcação).
  - `ERRO_JA_DECIDIDA = 'Esta inscrição já tem decisão registrada; as marcações ficam só para consulta.'`, `ERRO_ITEM = 'Este item não faz parte da conferência desta inscrição.'`

- [ ] **Step 1: Testes:**
  - `test_marca_documento_e_pergunta_com_quem_e_quando` — grava `user_id` do gestor; segunda marcação do mesmo item atualiza (1 linha).
  - `test_problema_exige_observacao_e_espacos_nao_contam` — `observacao: '   '` → erro `observacao` = `Descreva o problema.`
  - `test_observacao_acima_de_1000_bytes_e_recusada` — 501 "ç".
  - `test_item_fora_da_conferencia_e_recusado` — pergunta escondida pela condição, opcional não enviado e documento de outro edital → erro `item`; nenhuma linha.
  - `test_fora_do_periodo_nao_marca` — `2026-11-30` e `2026-12-11` → erro flash, nenhuma linha.
  - `test_inscricao_sem_chamada_nao_marca` — `edital_chamada_id` nulo → recusada, nenhuma linha.
  - `test_com_decisao_nao_marca` — `naoHomologada` → `ERRO_JA_DECIDIDA`.
  - `test_rascunho_nao_marca` — reaberta → recusada.
- [ ] **Step 2:** Rodar `tests/Feature/Retaguarda/HomologacaoMarcarTest.php` — FAIL.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar o arquivo — PASS.
- [ ] **Step 5:** pint; commit `feat: marcar documento e resposta na homologação`.

---

### Task 9: Retaguarda — decidir

**Files:**
- Create: `app/Http/Requests/Retaguarda/DecidirHomologacaoRequest.php`
- Modify: `app/Http/Controllers/Retaguarda/HomologacaoController.php` (`decidir`), `routes/retaguarda.php`
- Test: `tests/Feature/Retaguarda/HomologacaoDecisaoTest.php`

**Interfaces:**
- Consumes: Tasks 2, 5 (`tudoOk`, `itensComProblema`, `motivoSugerido`, `gravarProblemasAutomaticos`), 8 (mensagens).
- Produces:
  - Rota `POST /retaguarda/homologacao/{inscricao}/decisao` → `retaguarda.homologacao.decisao`.
  - `DecidirHomologacaoRequest`: `decisao` `required|in:homologada,nao_homologada`; `motivo` `nullable|string|max:4000`.
  - `HomologacaoController::decidir(DecidirHomologacaoRequest $request, Inscricao $inscricao): RedirectResponse` — transação com `Travas::edital` → `Travas::inscricao`; recusa como `marcar` (não finalizada, já decidida, fora do período); `homologada` exige `tudoOk()` (`ERRO_HOMOLOGAR_COM_PENDENCIA = 'Para homologar, marque todos os itens como ok.'`); `nao_homologada` exige `itensComProblema() !== []` ou motivo não vazio (`ERRO_NAO_HOMOLOGAR_SEM_MOTIVO = 'Marque ao menos um item com problema ou escreva o motivo.'`), chama `gravarProblemasAutomaticos($userId)` e grava `homologacao_motivo` = motivo enviado ou, vazio, `motivoSugerido()`; ambos gravam `homologada_por`, `homologada_em = now()`. Sucesso: redirect `retaguarda.homologacao.show` com `sucesso` `Inscrição homologada.` / `Inscrição não homologada.`

- [ ] **Step 1: Testes:**
  - `test_homologa_com_tudo_ok_e_grava_quem_e_quando`.
  - `test_nao_homologa_com_item_pendente_ou_problema_quando_pede_homologar` — erro `ERRO_HOMOLOGAR_COM_PENDENCIA`, `homologacao` continua nula.
  - `test_nao_homologar_exige_problema_ou_motivo` — tudo ok e motivo `'  '` → erro; tudo ok e motivo `'Fora do objeto.'` → grava `Fora do objeto.`
  - `test_nao_homologar_sem_texto_grava_o_motivo_montado_e_os_problemas_automaticos` — obrigatório não enviado sem marcação → linha `Problema`/`Não enviado.` gravada; `homologacao_motivo === "Documentos: Certidão — Não enviado."`.
  - `test_segunda_decisao_e_recusada` — decide duas vezes → a segunda volta `ERRO_JA_DECIDIDA` e não muda `homologada_em` nem `homologacao`.
  - `test_fora_do_periodo_e_sem_chamada_nao_decide`.
- [ ] **Step 2:** Rodar `tests/Feature/Retaguarda/HomologacaoDecisaoTest.php` — FAIL.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar o arquivo — PASS.
- [ ] **Step 5:** pint; commit `feat: decidir a homologação`.

---

### Task 10: Retaguarda — julgar recurso

**Files:**
- Create: `app/Http/Requests/Retaguarda/JulgarRecursoRequest.php`
- Modify: `app/Http/Controllers/Retaguarda/RecursoDaHomologacaoController.php` (`julgar`), `routes/retaguarda.php`
- Test: `tests/Feature/Retaguarda/HomologacaoRecursoTest.php`

**Interfaces:**
- Consumes: Tasks 1, 2.
- Produces:
  - Rota `POST /retaguarda/homologacao/{inscricao}/recurso` → `retaguarda.homologacao.recurso.julgar`.
  - `JulgarRecursoRequest`: `decisao` `required` + `Rule::enum(DecisaoRecurso::class)`; `parecer` `required|string|max:4000` (mensagem `Escreva o parecer.`).
  - `RecursoDaHomologacaoController::julgar(JulgarRecursoRequest $request, Inscricao $inscricao): RedirectResponse` — **sem** conferir período (Decisão 2). Transação: `Travas::edital` → `Travas::inscricao`, recurso relido com `lockForUpdate`; exige `homologacao === EmRecurso` e `recurso->decisao === null` (`ERRO_SEM_RECURSO = 'Esta inscrição não tem recurso aguardando julgamento.'`). Aceito: para cada documento do recurso, `InscricaoDocumento::firstOrNew([inscricao, edital_documento])` recebe `arquivo`, `arquivo_nome`, `situacao = Enviado` e zera `motivo, erro, provedor, modelo, execucao, iniciada_em, conferido_em` (sem IA — Decisão 7); guarda os arquivos antigos; `homologacao = Homologada`. Negado: `homologacao = RecursoNegado`, documentos intocados. Ambos: `decisao`, `parecer`, `julgado_por`, `julgado_em`. Depois do commit, `ArquivoArmazenado::apagarTodos` nos originais trocados. `homologada_por`/`homologada_em`/`homologacao_motivo` ficam os da decisão original. Sucesso: redirect `show` com `Recurso aceito: inscrição homologada.` / `Recurso negado.`

- [ ] **Step 1: Testes** (`Storage::fake('local')`, arquivos reais no disco falso; `travelTo('2026-12-20')`, depois do período):
  - `test_aceito_troca_os_arquivos_e_homologa` — documento do recurso vira o `arquivo` da inscrição, situação `Enviado`, original apagado do disco (`assertMissing`), `homologacao === Homologada`, `recurso.decisao === Aceito`.
  - `test_aceito_cria_o_documento_que_nao_tinha_sido_enviado` — obrigatório não enviado + arquivo no recurso → `InscricaoDocumento` novo.
  - `test_negado_mantem_os_originais_e_guarda_os_novos` — original e novo continuam no disco; `homologacao === RecursoNegado`.
  - `test_julga_depois_do_periodo_de_homologacao`.
  - `test_parecer_e_obrigatorio_e_espacos_nao_contam`.
  - `test_sem_recurso_pendente_nao_julga` — não homologada sem recurso e recurso já julgado → `ERRO_SEM_RECURSO`; julgar duas vezes → a segunda recusada.
- [ ] **Step 2:** Rodar `tests/Feature/Retaguarda/HomologacaoRecursoTest.php` — FAIL.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar o arquivo — PASS.
- [ ] **Step 5:** pint; commit `feat: julgar o recurso da homologação`.

---

### Task 11: Retaguarda — tela de conferência

**Files:**
- Create: `resources/js/Pages/Retaguarda/Homologacao/Show.vue`, `resources/js/Components/Retaguarda/Homologacao/MarcacaoDoItem.vue`, `DecisaoDaHomologacao.vue`, `JulgamentoDoRecurso.vue`
- Modify: `resources/js/types/index.ts` (`RetaguardaHomologacaoShowProps`, `MarcacaoConferida`, `RecursoNaRetaguarda`)
- Test: build (`npm run build`) + conferência manual descrita no Step 3

**Interfaces:**
- Consumes: props da Task 7; rotas das Tasks 8–10; `textoDaResposta` de `@/Components/Inscricao/questionario`; `SecaoCadastro`, `CampoCadastro`, `ConfirmarExclusao` (padrão de diálogo) e o `<dialog>`/`showModal` de `RevisaoDaInscricao.vue`.
- Produces:
  - `Show.vue` (layout `RetaguardaLayout`): voltar para a lista; cabeçalho (número, edital, chamada, proponente, documento, situação, prazo do recurso); aviso (`aviso`) no estilo `bg-aviso-fundo`; `SecaoCadastro` "Escolhas e perfil" só leitura; "Documentos" (nome, etapa, `Abrir` em nova aba, parecer da IA, `MarcacaoDoItem`); "Questionário" (por seção, só `perguntasConferidas`, resposta por `textoDaResposta`, `MarcacaoDoItem`); `DecisaoDaHomologacao` sem decisão; com decisão, o resultado (situação, motivo, por, quando); `JulgamentoDoRecurso` quando há `recurso`.
  - `MarcacaoDoItem.vue` — props `{ inscricaoId: number; tipo: 'documento' | 'pergunta'; item: number; marcacao: MarcacaoConferida | null; desabilitado: boolean }`. Dois botões `Ok` / `Com problema` com `aria-pressed` (três estados — não conferido, ok, problema — não é liga/desliga, então não é `Interruptor` nem checkbox). `Ok` grava na hora; `Com problema` abre a observação (obrigatória, `*` vermelho, erro destacado) com botão `Salvar`. `router.put('/retaguarda/homologacao/{id}/conferencias', …, { preserveScroll: true, preserveState: true })` — comentário com `retaguarda.homologacao.conferencias.update`. Mostra "por <nome> em <data>" e "automático" quando `automatica`.
  - `DecisaoDaHomologacao.vue` — props `{ inscricaoId: number; podeConferir: boolean; podeHomologar: boolean; motivoSugerido: string }`. `Homologar` habilitado só com `podeHomologar` (com texto dizendo por quê quando desabilitado); `Não homologar` abre diálogo com `textarea` do motivo pré-preenchida com `motivoSugerido` (até 4000, contador) e confirmação; `Homologar` também confirma em diálogo. POST para `/retaguarda/homologacao/{id}/decisao`.
  - `JulgamentoDoRecurso.vue` — props `{ inscricaoId: number; recurso: RecursoNaRetaguarda; podeJulgar: boolean }`. Justificativa; por documento trocado, original e novo lado a lado (`grid @lg:grid-cols-2`, links `Abrir` em nova aba); parecer obrigatório; `Aceitar recurso` / `Negar recurso` com confirmação; julgado: decisão, parecer, por, quando.

- [ ] **Step 1:** Escrever os tipos e os componentes acima.
- [ ] **Step 2:** `npm run build` — sem erro de tipos.
- [ ] **Step 3:** Rodar `tests/Feature/Retaguarda/HomologacaoConferenciaTest.php` — PASS (continua). Conferir no navegador (`composer run dev` pelo usuário, se pedido): marcar ok/problema grava sem recarregar a página toda; Homologar só habilita com tudo ok; motivo vem montado.
- [ ] **Step 4:** Commit `feat: tela de conferência da homologação`.

---

### Task 12: Portal — resultado, reabrir travado, Minhas inscrições e recurso (back end)

**Files:**
- Create: `app/Http/Controllers/InscricaoRecursoController.php`, `app/Http/Requests/EnviarRecursoRequest.php`, `resources/js/Pages/Portal/MinhasInscricoes.vue` e `resources/js/Pages/Portal/RecursoDaInscricao.vue` (mínimas, com props tipadas — os testes do Inertia exigem que a página exista; a Task 13 completa)
- Modify: `app/Http/Controllers/InscricaoController.php` (`index`, `show`, `reabrir`), `routes/web.php`
- Test: `tests/Feature/Portal/ResultadoDaHomologacaoTest.php`, `tests/Feature/Portal/RecursoDaInscricaoTest.php`

**Interfaces:**
- Consumes: Tasks 1, 2 (`podeRecorrer`, `prazoDoRecurso`, `situacaoNoPortal`, `Travas`), 5 (`itensComProblema`, `documentosComProblema`).
- Produces:
  - Rotas no grupo de `InscricaoController` (mesmos middlewares): `GET /portal/inscricoes` → `portal.inscricoes.index`; `GET /portal/inscricoes/{inscricao}/recurso` → `portal.inscricoes.recurso.create`; `POST /portal/inscricoes/{inscricao}/recurso` → `portal.inscricoes.recurso.store` (`throttle:10,1`). `whereNumber` nas duas.
  - `InscricaoController::index(Request $request): Response` → `Portal/MinhasInscricoes` com `inscricoes: {id, numero: string | null, edital: string, cadastro: string, categoria: string | null, situacao: string (chave de situacaoNoPortal), situacaoRotulo: string, finalizadaEm: string | null}[]` — do usuário (`user_id`), mais novas primeiro, em qualquer fase.
  - `InscricaoController::show` acrescenta `resultado: ResultadoDaHomologacao | null` (nulo no rascunho): `{situacao: string; rotulo: string; motivo: string | null; itens: {nome: string; observacao: string | null}[]; prazoDoRecurso: string | null (d/m/Y); podeRecorrer: boolean; parecer: string | null}`; `motivo`/`itens` só em não homologada, em recurso e recurso negado; `parecer` quando o recurso foi julgado. `podeReabrir` passa a exigir `homologacao === null`.
  - `InscricaoController::reabrir`: com a linha travada, `homologacao !== null` → redirect `show` com `erro` `ERRO_REABRIR_COM_DECISAO = 'A inscrição já tem resultado da homologação e não pode mais ser reaberta.'`; ao reabrir, apaga `$travada->conferencias()` (Decisão 2).
  - `EnviarRecursoRequest`: `justificativa` `nullable|string|max:4000`; `arquivos` `nullable|array`; `arquivos.*` com as mesmas regras e mensagem (`InscricaoDocumentoController::ERRO_ARQUIVO`) do envio normal (`file|mimes:pdf,jpg,jpeg,png|mimetypes:…TIPOS_DE_ARQUIVO|max:10240`); `after()`: sem justificativa e sem arquivo → `justificativa` = `ERRO_VAZIO = 'Escreva a justificativa ou envie ao menos um documento.'`; chave de `arquivos` que não é id de `documentosComProblema()` → `arquivos` = `ERRO_DOCUMENTO = 'Só os documentos marcados com problema podem ser trocados.'`
  - `InscricaoRecursoController::create(Inscricao $inscricao): Response|RedirectResponse` — `Gate::authorize('gerenciar')`; sem `podeRecorrer()` → redirect `show` com `aviso` (`ERRO_FORA_DO_PRAZO = 'O prazo para entrar com recurso terminou.'` quando passou o prazo; `ERRO_SEM_RECURSO = 'Esta inscrição não aceita recurso.'` nos demais). Render `Portal/RecursoDaInscricao` com `{edital: {titulo, slug}, inscricao: {id, numero}, prazo: string, motivo: string | null, itens: {nome, observacao}[], documentos: {id, nome, orientacao: string | null, observacao: string | null}[]}`.
  - `InscricaoRecursoController::store(EnviarRecursoRequest $request, Inscricao $inscricao): RedirectResponse` — guarda os arquivos antes (`ArquivoArmazenado::guardar(…, Inscricao::DISCO, Inscricao::PASTA."/{$id}/recurso")`); transação `Travas::edital` → `Travas::inscricao`, repete `podeRecorrer()` na linha travada; cria `InscricaoRecurso` (`enviado_em = now()`) e um `InscricaoRecursoDocumento` por arquivo; `homologacao = EmRecurso`. Qualquer falha apaga os arquivos guardados. Sucesso: redirect `portal.inscricoes.show` com `sucesso` `Recurso enviado.`
  - Trocar escolhas, respostas e documentos com decisão já são recusados porque a inscrição decidida é finalizada e não reabre; o teste abaixo fixa isso.

- [ ] **Step 1: Testes em `ResultadoDaHomologacaoTest`:**
  - `test_minhas_inscricoes_lista_as_do_usuario_em_qualquer_fase` — rascunho de edital encerrado, finalizada, não homologada; de outro usuário fica fora; `situacaoRotulo` `Rascunho`, `Em homologação`, `Não homologada`.
  - `test_resultado_nao_homologada_traz_motivo_itens_e_prazo` — `resultado.itens.0 = {nome: 'RG', observacao: 'Ilegível.'}`, `prazoDoRecurso === '13/12/2026'`, `podeRecorrer` true.
  - `test_resultado_homologada_e_em_homologacao` — itens vazios, `motivo` null; rascunho → `resultado` null.
  - `test_recurso_negado_mostra_o_parecer`.
  - `test_com_decisao_nao_reabre` — `POST reabrir` → `ERRO_REABRIR_COM_DECISAO`, continua finalizada; `podeReabrir` false na tela.
  - `test_reabrir_apaga_as_marcacoes` — no período de inscrição, com marcações → reabre, `conferencias()->count() === 0`.
  - `test_com_decisao_nao_troca_escolhas_respostas_nem_documentos` — `PUT update`, `PUT secoes`, `POST documentos` recusados; nada muda.
- [ ] **Step 2: Testes em `RecursoDaInscricaoTest`** (`Storage::fake('local')`, `travelTo('2026-12-12 10:00' Bahia)`, inscrição `naoHomologada` em `2026-12-05` com RG marcado com problema):
  - `test_envia_justificativa_e_documento_marcado` — recurso gravado, arquivo no disco em `inscricoes/{id}/recurso/`, original intacto, `homologacao === EmRecurso`.
  - `test_so_justificativa_ou_so_arquivo_basta`.
  - `test_sem_justificativa_nem_arquivo_e_recusado_e_espacos_nao_contam` — `justificativa: '   '` → `ERRO_VAZIO`.
  - `test_arquivo_para_documento_sem_problema_e_recusado_sem_sobrar_arquivo` — chave de documento ok e de outro edital → `ERRO_DOCUMENTO`; `Storage::disk('local')->allFiles()` sem nada de `recurso/`; nenhum recurso.
  - `test_recurso_no_ultimo_minuto_entra_e_no_dia_seguinte_e_recusado` — `2026-12-13 23:59` Bahia passa; `2026-12-14 00:00` → `ERRO_FORA_DO_PRAZO`.
  - `test_sem_fim_da_homologacao_o_prazo_conta_da_decisao` — chamada sem período, decisão `2026-12-05` → `2026-12-08` passa, `2026-12-09` recusa.
  - `test_so_um_recurso` — segundo envio → `ERRO_SEM_RECURSO`.
  - `test_tipo_e_tamanho_do_arquivo_como_no_envio_normal` — `.docx` e 11 MB → `ERRO_ARQUIVO`.
  - `test_outro_usuario_recebe_404` — `GET`/`POST` de inscrição alheia → 404.
- [ ] **Step 3:** Rodar os dois arquivos (um por vez) — FAIL.
- [ ] **Step 4: Implementar** o acima.
- [ ] **Step 5:** Rodar os dois arquivos, `tests/Feature/Portal/FinalizarInscricaoTest.php`, `tests/Feature/Portal/InscricaoTest.php` e `tests/Feature/Portal/InscricaoDocumentosTest.php` (um por vez) — PASS; `npm run build` — sem erro.
- [ ] **Step 6:** pint; commit `feat: resultado da homologação e recurso no portal`.

---

### Task 13: Portal — telas (Minhas inscrições, resultado, recurso, menu)

**Files:**
- Create: `resources/js/Components/Inscricao/ResultadoDaHomologacao.vue`
- Modify: `resources/js/Pages/Portal/MinhasInscricoes.vue`, `resources/js/Pages/Portal/RecursoDaInscricao.vue` (completar as mínimas da Task 12), `resources/js/Components/MenuPortal.vue`, `resources/js/Components/Inscricao/RevisaoDaInscricao.vue:167-179`, `resources/js/Pages/Portal/InscricaoPassos.vue` (repassa `resultado`), `resources/js/types/index.ts` (`ResultadoDaHomologacao`, `PortalMinhasInscricoesProps`, `PortalRecursoProps`, `PortalInscricaoPassosProps.resultado`)
- Test: `tests/Feature/MenuDoPortalTest.php`

**Interfaces:**
- Consumes: props e rotas da Task 12; `PortalProponenteLayout`, `FaixaPortal`, `CampoArquivo`, `CampoCadastro`, o `<dialog>` de confirmação de `RevisaoDaInscricao`.
- Produces:
  - `MenuPortal.vue`: item `{ href: '/portal/inscricoes', texto: 'Minhas inscrições', ativoEm: ['/portal/inscricoes'] }` só para proponente, depois de Editais (comentário com `portal.inscricoes.index`).
  - `MinhasInscricoes.vue`: lista (edital, número, cadastro, categoria, selo da situação, enviada em) com link `Abrir` → `/portal/inscricoes/{id}`; vazio: `Você ainda não tem inscrições.` com link para os editais.
  - `ResultadoDaHomologacao.vue` — prop `{ resultado: ResultadoDaHomologacao; inscricaoId: number }`: `em_homologacao` → `A equipe vai conferir a inscrição na homologação. O resultado aparece aqui.`; `nao_homologada` → motivo, lista de itens (nome — observação), `Prazo para recurso: até <prazo>, às 23:59.` e `Link` `Entrar com recurso` → `/portal/inscricoes/{id}/recurso` quando `podeRecorrer` (senão `O prazo para recurso terminou.`); `em_recurso` → `Recebemos o seu recurso. A equipe vai analisá-lo.`; `recurso_negado` → `Não homologada (recurso negado).` + parecer; `homologada` → `Inscrição homologada.` (+ parecer quando veio de recurso).
  - `RevisaoDaInscricao`: no ramo finalizada, mostra `ResultadoDaHomologacao` abaixo do número; o texto "Fora do período…" só quando `resultado.situacao === 'em_homologacao'`.
  - `RecursoDaInscricao.vue`: motivo e itens; `textarea` da justificativa (até 4000, contador); para cada documento um `CampoArquivo` (mesmos `accept`, dica e checagem de tipo/tamanho do `DocumentoExigido.vue`); erro `Escreva a justificativa ou envie ao menos um documento.` também no cliente; diálogo `Enviar o recurso? Depois do envio, nada mais pode ser alterado.`; `useForm` com `forceFormData` POST `/portal/inscricoes/{id}/recurso` (comentário com `portal.inscricoes.recurso.store`).

- [ ] **Step 1: Teste** `MenuDoPortalTest::test_menu_tem_minhas_inscricoes` — lê `MenuPortal.vue`: contém `route('portal.inscricoes.index', absolute: false)` e `'Minhas inscrições'`.
- [ ] **Step 2:** Rodar `tests/Feature/MenuDoPortalTest.php` — FAIL.
- [ ] **Step 3: Implementar** o acima.
- [ ] **Step 4:** Rodar `tests/Feature/MenuDoPortalTest.php`, `tests/Feature/Portal/ResultadoDaHomologacaoTest.php` e `tests/Feature/Portal/RecursoDaInscricaoTest.php` (um por vez) — PASS; `npm run build` — sem erro.
- [ ] **Step 5:** Commit `feat: telas de resultado, recurso e minhas inscrições no portal`.

---

## Self-Review (feito)

- **Cobertura do spec:** situações/colunas (T1); conferências e recursos (T1); período e prazo, Decisões 2 e 4 (T2, T8, T9, T12); renomear período sem migration, Decisão 10 (T3); edital mexido depois das decisões, Decisão 11 (T4); o que se confere, Decisão 12, e motivo montado (T5); lista, filtros, ordem, encrypt e menu, Decisão 9 (T6); conferência, perfil, IA ao lado, arquivos da retaguarda (T7); marcar na hora (T8); homologar só com tudo ok, Decisão 1 (T9); recurso aceito troca, negado mantém, Decisão 6, sem IA, Decisão 7 (T10); tela da retaguarda (T11); resultado na hora, Decisão 3, um recurso, Decisão 5, Minhas inscrições, Decisão 8, reabrir apaga marcações e é bloqueado com decisão (T12, T13).
- **Testes que importam do spec:** todos têm teste nomeado (T2, T6–T10, T12, T3).
- **Tipos:** `SituacaoHomologacao`, `MarcacaoDoItem`, `DecisaoRecurso`, `ConferenciaDaInscricao::*`, `Travas::*`, `Inscricao::{naHomologacao, prazoDoRecurso, podeRecorrer, situacaoNoPortal}` usados com os mesmos nomes nas tasks seguintes.
