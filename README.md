# Perfil — jogo de adivinhação web

Jogo de adivinhação no estilo do tabuleiro Perfil, feito para vários jogadores
passarem **um único celular** de mão em mão.

- **Back-end:** PHP puro em MVC, API REST, sem framework. Composer só para o autoload.
- **Front-end:** React + Vite, componentes funcionais e hooks, mobile-first e PWA.
- **Cartas:** geradas por lógica em PHP a partir do Wikidata e da Wikipédia. **Nenhuma IA.**

---

## 1. Estrutura de pastas

```
perfil/
├── database.sql                  script completo do banco (utf8mb4 + FKs)
├── cartas.sql                    baralho pronto: cartas aprovadas com as 20 dicas
├── README.md                     este arquivo
├── .htaccess                     serve o jogo em /perfil/ no XAMPP local
│
├── Dockerfile                    imagem: front compilado + API no mesmo Apache
├── .dockerignore                 o que fica fora do contexto de build
├── stack.yml                     stack do Swarm (Traefik + MySQL) para o Portainer
├── docker/
│   ├── apache-perfil.conf        VirtualHost do container
│   └── entrada.sh                espera o MySQL e prepara o banco na subida
├── .github/workflows/
│   └── publicar.yml              compila e publica a imagem no GHCR
│
├── api/                          back-end
│   ├── composer.json             autoload PSR-4 (App\ → app/)
│   │
│   ├── public/                   ÚNICA pasta que o Apache enxerga
│   │   ├── index.php             front controller
│   │   └── .htaccess             manda tudo para o index.php
│   │
│   ├── app/
│   │   ├── Core/
│   │   │   ├── Router.php        rotas com parâmetros {id}
│   │   │   ├── Request.php       método, caminho, query, corpo JSON
│   │   │   ├── Response.php      status, cabeçalhos, envio, CORS
│   │   │   ├── Database.php      conexão PDO única (singleton)
│   │   │   ├── Configuracao.php  leitura de config/config.php
│   │   │   ├── Controller.php    base dos controllers (validação)
│   │   │   ├── Model.php         base dos models (prepared statements)
│   │   │   ├── ExcecaoHttp.php   erro que vira resposta amigável
│   │   │   └── TratadorErros.php lugar único de tratamento de erro
│   │   │
│   │   ├── Controllers/
│   │   │   ├── PartidaController.php
│   │   │   ├── JogadorController.php
│   │   │   ├── CartaController.php
│   │   │   ├── AdminController.php
│   │   │   └── AuthController.php
│   │   │
│   │   ├── Models/               um por tabela
│   │   │   ├── Categoria.php
│   │   │   ├── Carta.php
│   │   │   ├── Dica.php
│   │   │   ├── RespostaAlternativa.php
│   │   │   ├── Partida.php
│   │   │   ├── Jogador.php
│   │   │   ├── CartaUsada.php
│   │   │   └── AcaoPartida.php   pilha do botão "Desfazer"
│   │   │
│   │   ├── Views/
│   │   │   ├── VisaoJson.php     formato padrão de toda resposta
│   │   │   ├── VisaoPartida.php  estado da partida para o React
│   │   │   └── VisaoCarta.php    cartas para o painel
│   │   │
│   │   ├── Services/             toda a lógica mora aqui
│   │   │   ├── RegrasPartida.php      pontuação, turnos, vitória, desfazer
│   │   │   ├── VerificadorPalpite.php confere palpite e bloqueia vazamento
│   │   │   ├── GeradorCartas.php      orquestra a criação de cartas
│   │   │   ├── MontadorDicas.php      fatos → 20 dicas
│   │   │   ├── WikidataClient.php     SPARQL
│   │   │   ├── WikipediaClient.php    resumo do artigo
│   │   │   ├── ClienteHttp.php        User-Agent, pausa, cache, retentativa
│   │   │   └── Normalizador.php       acento, caixa, espaço, semelhança
│   │   │
│   │   └── Middlewares/
│   │       └── AuthAdmin.php     protege as rotas /admin
│   │
│   ├── config/
│   │   ├── config.exemplo.php    modelo para copiar
│   │   ├── config.php            o seu (fora do Git)
│   │   └── routes.php            mapa de rotas
│   │
│   ├── templates_dicas/          fácil de editar, é aqui que se melhora o jogo
│   │   ├── pessoa.json           modelos de frase + focos do Wikidata
│   │   ├── lugar.json
│   │   ├── coisa.json
│   │   ├── ano.json
│   │   └── sparql/
│   │       ├── pessoa_lista.rq   pessoa_fatos.rq
│   │       ├── lugar_lista.rq    lugar_fatos.rq
│   │       ├── coisa_lista.rq    coisa_fatos.rq
│   │       ├── ano_eventos.rq    ano_nascimentos.rq
│   │       ├── ano_mortes.rq     ano_lancamentos.rq
│   │       └── alt_labels.rq
│   │
│   ├── bin/
│   │   └── gerar-cartas.php      script do Agendador de Tarefas
│   │
│   └── cache/                    cache HTTP e log de erros
│
└── frontend/
    ├── package.json
    ├── vite.config.js            proxy /api + --host para a rede local
    ├── index.html
    ├── public/
    │   ├── manifest.webmanifest  PWA
    │   ├── sw.js                 service worker
    │   ├── icone-192.png  icone-512.png  icone-180.png
    │   ├── icone-mascara-512.png  icone.svg
    │   └── .htaccess             rotas do React em produção
    └── src/
        ├── main.jsx   App.jsx
        ├── pages/     TelaInicio, TelaCadastro, TelaPartida, TelaFim, TelaAdmin
        ├── components/ Basicos, Placar, GradeDicas, TelaTransicao
        ├── hooks/     usePartida, useDispositivo
        ├── services/  api.js  (todas as chamadas à API)
        └── styles/    global.css
```

---

## 2. Extensões do PHP

O jogo precisa destas extensões ativas:

| Extensão    | Para quê |
|-------------|----------|
| `pdo_mysql` | falar com o MySQL |
| `curl`      | consultar o Wikidata e a Wikipédia |
| `mbstring`  | tratar acentos e emojis |
| `intl`      | tirar acento com precisão (tem um plano B se faltar) |
| `json`      | já vem ligada por padrão |

**Como conferir:**

```bash
php -m
```

Ou, com o Apache já rodando, abra no navegador:

```
http://localhost/api/saude
```

A resposta lista cada extensão com `true` ou `false`:

```json
{
  "sucesso": true,
  "dados": {
    "api": "ok",
    "php": "8.3.32",
    "banco": "ok",
    "extensoes": {
      "pdo_mysql": true, "curl": true, "mbstring": true, "intl": true, "json": true
    }
  },
  "erro": null
}
```

**Se alguma estiver faltando**, abra o `php.ini` (o caminho aparece em `php --ini`),
tire o `;` da frente da linha e reinicie o Apache:

```ini
extension=pdo_mysql
extension=curl
extension=mbstring
extension=intl
```

> No XAMPP o `php.ini` fica em `C:\xampp\php\php.ini`.
> Atenção: o PHP do terminal pode ser **outro** PHP, diferente do que o Apache usa.
> Confira com `php --ini` e compare com o que aparece no `phpinfo()`.

---

## 3. Instalação, passo a passo

### 3.1 Criar o banco e importar o SQL

```bash
# Windows, XAMPP
C:\xampp\mysql\bin\mysql.exe -u root -p < database.sql
```

Ou pelo phpMyAdmin: aba **Importar** → escolha o `database.sql` → **Executar**.

Isso cria o banco `perfil`, todas as tabelas e já preenche as quatro categorias.

### 3.2 Configurar o `config.php`

```bash
cd api/config
copy config.exemplo.php config.php     # Windows
# cp config.exemplo.php config.php     # Linux/Mac
```

Abra o `config.php` e ajuste:

1. **Banco** — `usuario` e `senha` do seu MySQL.
2. **User-Agent** — coloque um contato real seu. O Wikidata **exige** isso e
   bloqueia quem não manda.
3. **Senha do painel** — gere um hash novo e troque:

```bash
php -r "echo password_hash('a-sua-senha', PASSWORD_DEFAULT), PHP_EOL;"
```

> A senha que vem de fábrica é `admin` / `perfil123`. **Troque.**

### 3.3 Gerar o autoload

```bash
cd api
composer dump-autoload -o
```

### 3.4 Apontar o Apache para `api/public/`

O Apache tem que enxergar **só** a pasta `public/`. As pastas `app/`, `config/`,
`bin/` e `templates_dicas/` ficam fora do alcance do navegador.

**Opção A — VirtualHost (recomendada).** Em `httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName perfil.local
    DocumentRoot "C:/caminho/para/perfil/frontend/dist"

    <Directory "C:/caminho/para/perfil/frontend/dist">
        AllowOverride All
        Require all granted
    </Directory>

    # A API responde em /api
    Alias /api "C:/caminho/para/perfil/api/public"

    <Directory "C:/caminho/para/perfil/api/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

E no `C:\Windows\System32\drivers\etc\hosts`:

```
127.0.0.1    perfil.local
```

**Opção B — sem VirtualHost.** Copie o conteúdo de `frontend/dist/` para
`C:\xampp\htdocs\` e crie um alias para a API no `httpd.conf`:

```apache
Alias /api "C:/caminho/para/perfil/api/public"
<Directory "C:/caminho/para/perfil/api/public">
    AllowOverride All
    Require all granted
</Directory>
```

**Opção C — XAMPP puro, sem mexer no Apache (é a que está em uso aqui).**
Basta a pasta do projeto estar em `C:\xampp\htdocs\perfil`. O `.htaccess` da
raiz do projeto manda os pedidos para `frontend/dist/` e deixa `api/` em paz,
então nada precisa ser copiado nem aliasado.

Em `frontend/.env` (veja `.env.exemplo`):

```
VITE_BASE=/perfil/
VITE_API_BASE=/perfil/api/public
```

Depois `npm run build`. Endereços:

| O quê | Endereço |
| --- | --- |
| Jogo | `http://localhost/perfil/` |
| Painel | `http://localhost/perfil/admin` |
| API | `http://localhost/perfil/api/public/saude` |

Se o nome da pasta em `htdocs` não for `perfil`, troque nos dois valores do
`.env` e na linha `RewriteBase` do `.htaccess` da raiz.

Em qualquer caso, confirme que o `mod_rewrite` está ligado no `httpd.conf`:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

Teste: `http://localhost/api/saude` tem que responder JSON.

### 3.5 Subir o front-end em modo desenvolvimento

```bash
cd frontend
npm install
npm run dev
```

O Vite mostra dois endereços:

```
  ➜  Local:   http://localhost:5173/
  ➜  Network: http://192.168.0.10:5173/     ← este é o do celular
```

O script `dev` já sobe com `--host`, então o celular enxerga o computador.

**Se o seu Apache não estiver na porta 80**, crie `frontend/.env`:

```
VITE_API_ALVO=http://localhost:8080
```

### 3.6 Abrir no celular

1. Celular e computador na **mesma rede Wi-Fi**.
2. Abra o endereço `Network` que o Vite mostrou (ex.: `http://192.168.0.10:5173`).
3. Se não abrir, libere a porta 5173 no Firewall do Windows:

```powershell
New-NetFirewallRule -DisplayName "Vite Perfil" -Direction Inbound -LocalPort 5173 -Protocol TCP -Action Allow
```

### 3.7 Gerar as primeiras cartas

Pelo painel: abra `/admin`, entre e clique em **Gerar 10 cartas**.

Ou pelo terminal:

```bash
cd api
php bin/gerar-cartas.php 10
php bin/gerar-cartas.php 20 --aprovar    # já aprova o que ficou completo
php bin/gerar-cartas.php 5 pessoa        # só de uma categoria
```

> A geração **é lenta de propósito**: há uma pausa de 1,2 s entre as consultas
> para respeitar o limite de uso do Wikidata. Conte com 1 a 2 minutos por carta
> na primeira vez. Depois o cache em disco acelera bastante.

Só **carta aprovada** entra no jogo. No painel, revise as pendentes e clique em
**Aprovar**.

### 3.8 Jogar

Abra o endereço do Vite no celular, cadastre os jogadores e bom jogo.

---

## 4. Produção

```bash
cd frontend
npm run build
```

Isso gera `frontend/dist/` já com o `.htaccess` dentro. Copie a pasta inteira
para onde o Apache serve os arquivos.

O `.htaccess` do build faz três coisas:

1. **Não mexe em `/api`** — a API continua com dono próprio.
2. Serve arquivo que existe de verdade (JS, CSS, ícones).
3. Manda qualquer outro caminho para o `index.html`, para `/partida/12`,
   `/admin` e `/fim/3` funcionarem ao recarregar a página.

Quando publicar uma versão nova, mude a linha `const VERSAO = 'perfil-v1'` no
`frontend/public/sw.js`. Sem isso o celular pode ficar preso na versão antiga.

No `config.php` de produção, troque `'ambiente' => 'desenvolvimento'` por
`'producao'`. Aí os erros param de mostrar detalhe técnico no JSON e vão só
para `api/cache/erros.log`.

### 4.1 Publicar em container (GitHub Actions → Swarm → Portainer)

O repositório já vem com tudo montado para esse caminho:

| Arquivo | Para que serve |
| --- | --- |
| `Dockerfile` | Compila o front, resolve o autoload do PHP e junta os dois num Apache |
| `docker/apache-perfil.conf` | VirtualHost do container: `/` é o React, `/api` é o PHP |
| `docker/entrada.sh` | Roda antes do Apache: espera o MySQL e cria as tabelas se faltarem |
| `api/bin/preparar-banco.php` | Cria as tabelas e importa o baralho, com trava dupla contra sobrescrever dado |
| `cartas.sql` | O baralho versionado, importado na primeira subida |
| `api/config/config.ambiente.php` | Configuração lida de variáveis de ambiente, sem senha na imagem |
| `stack.yml` | Stack do Swarm com Traefik e MySQL, no formato que o Portainer espera |
| `.github/workflows/publicar.yml` | Compila e publica a imagem no Docker Hub: PR, push na `main` e tags |

**Onde fica o gatilho**

Não se liga gatilho pela interface do GitHub. Ele mora dentro do próprio
arquivo do workflow, no bloco `on:` de
[.github/workflows/publicar.yml](.github/workflows/publicar.yml):

```yaml
on:
  pull_request:
    branches: [main]      # abriu ou atualizou PR para a main
  push:
    branches: [main]      # mergeou na main
    tags: ['v*']          # criou uma tag de versão
  workflow_dispatch:      # botão "Run workflow" na aba Actions
```

Na interface você cadastra apenas os segredos, em
**Settings → Secrets and variables → Actions**:

| Segredo | Para que serve |
| --- | --- |
| `DOCKERHUB_USERNAME` | Usuário do Docker Hub. Também vira o namespace da imagem |
| `DOCKERHUB_TOKEN` | Token de acesso, em Docker Hub → Account Settings → Security |
| `PORTAINER_WEBHOOK` | Opcional. URL do webhook do serviço, para o Swarm puxar a imagem nova |

**O que cada evento publica**

| Evento | Tag publicada | Avisa o servidor? |
| --- | --- | --- |
| Pull request para a `main` | `usuario/perfil:pr-12` | Não |
| Push na `main` | `usuario/perfil:latest` e `:sha-abc1234` | Sim, se houver webhook |
| Tag `v1.2.3` | `usuario/perfil:v1.2.3` | Não |

A imagem do PR sai numa tag própria de propósito: dá para subir `:pr-12` num
ambiente de teste sem tocar no `:latest` que o servidor está rodando. Quem entra
em produção é o merge.

**Como fica dentro do container**

```
/var/www/html/public       build do React   -> servido em /
/var/www/html/api/public   front controller -> servido em /api
/var/www/html/api/app      código, fora do alcance do navegador
```

**Passo a passo**

1. Cadastre `DOCKERHUB_USERNAME` e `DOCKERHUB_TOKEN` nos segredos do
   repositório. O workflow publica em `<usuario>/perfil` no Docker Hub.
2. Abra um pull request ou faça um push na `main`. A aba **Actions** mostra a
   imagem publicada no resumo da execução.
3. Copie o `stack.yml` para o Portainer e troque tudo que está marcado com
   `TROQUE`: domínio, nome da imagem, senhas do MySQL e o hash do admin.
4. Gere o hash da senha do painel com:

   ```bash
   php -r "echo password_hash('suasenha', PASSWORD_DEFAULT);"
   ```

   Sem `PERFIL_ADMIN_SENHA_HASH` o login é recusado sempre, de propósito.
5. Suba a stack. Na primeira vez o container cria as tabelas sozinho; da
   segunda em diante ele vê que o banco já existe e não toca em nada.
6. No Portainer, pegue o **webhook** do serviço `perfil` e salve como segredo
   `PORTAINER_WEBHOOK` no GitHub. A partir daí, cada push na `main` compila a
   imagem e o Swarm puxa a versão nova sozinho.

**Variáveis que o container aceita**

| Variável | Padrão | Para que serve |
| --- | --- | --- |
| `PERFIL_DB_HOST` | `perfilmysql` | Host do MySQL |
| `PERFIL_DB_NOME` / `PERFIL_DB_USUARIO` / `PERFIL_DB_SENHA` | `perfil` / `perfil` / vazio | Acesso ao banco |
| `PERFIL_AMBIENTE` | `producao` | `desenvolvimento` mostra o erro técnico no JSON |
| `PERFIL_ADMIN_USUARIO` | `admin` | Usuário do painel |
| `PERFIL_ADMIN_SENHA_HASH` | vazio | Hash bcrypt da senha do painel |
| `PERFIL_MINIMO_SITELINKS` | `120` | Quanto maior, mais famoso o tema sorteado |
| `PERFIL_DESLOCAMENTO` | `0` | Linhas puladas na lista ordenada por fama |
| `PERFIL_MIX_DIFICIL` / `_MEDIA` / `_FACIL` | `7` / `7` / `6` | Mistura de dificuldade por carta |
| `PERFIL_APROVAR_AUTO` | `false` | `true` aprova a carta gerada na hora |
| `PERFIL_PREPARAR_BANCO` | `true` | `false` desliga a criação das tabelas e a importação do baralho |

---

## 5. Geração de cartas sem IA

### Como funciona

1. **Sorteia um foco.** Cada categoria tem uma lista de tipos do Wikidata
   (`focos` no JSON): futebolista, país, filme, montanha. Amarrar a consulta a
   um tipo deixa o SPARQL rápido e ainda dá variedade.
2. **Busca temas famosos.** O SPARQL filtra por `wikibase:sitelinks` (quantas
   Wikipédias têm artigo sobre o tema) e exige artigo em **português**. O mínimo
   é configurável em `gerador.minimo_sitelinks`.
3. **Descarta repetido.** Se o QID ou a resposta normalizada já existem, pula.
   As duas colunas têm índice `UNIQUE`, então nem por acidente entra duas vezes.
4. **Busca os fatos.** Uma segunda consulta traz as propriedades da categoria já
   com rótulo em português.
5. **Pega os apelidos.** `skos:altLabel` em português vira resposta alternativa
   aceita.
6. **Monta as frases.** Cada propriedade tem vários modelos em primeira pessoa e
   o gerador sorteia entre eles, para as cartas não saírem todas iguais.
7. **Completa com a Wikipédia.** Se faltar, frases do resumo do artigo entram com
   o nome do tema trocado por `...`.
8. **Filtra.** Qualquer dica que contenha a resposta, um pedaço dela ou um
   apelido é jogada fora. Dicas iguais ou muito parecidas também.
9. **Escolhe 20 com mistura de dificuldade** e embaralha as posições de 1 a 20.
10. **Se não fechar 20 dicas boas, o tema é descartado** e o gerador tenta outro.

### O baralho que vai junto no repositório

`cartas.sql` é o baralho pronto: 21 cartas aprovadas, com as 20 dicas e as
respostas aceitas de cada uma. O container importa esse arquivo na primeira
subida, logo depois do `database.sql`, então **o servidor já nasce com cartas**
sem depender do Wikidata no boot.

A importação tem trava: se já existir qualquer carta aprovada no banco, o
arquivo não é tocado. Ninguém perde o que gerou no painel.

Para ampliar o baralho:

1. Gere cartas no painel `/admin` (ou por `api/bin/gerar-cartas.php`).
2. Revise e **aprove** as boas, rejeite as que ninguém da mesa cravaria.
3. Rode `php api/bin/exportar-cartas.php`. Ele reescreve o `cartas.sql` com
   tudo que está aprovado.
4. Suba o commit. A imagem nova já sai com o baralho novo.

No XAMPP o PHP da linha de comando costuma vir sem `pdo_mysql`. Se der
"could not find driver", chame o PHP do próprio XAMPP:
`C:\xampp\php\php.exe api\bin\exportar-cartas.php`.

### Como o gerador escolhe temas conhecidos

O que separa "Serge Haroche" de "Diego Maradona" são quatro ajustes:

| Ajuste | Onde | Valor |
| --- | --- | --- |
| Mínimo de sitelinks | `gerador.minimo_sitelinks` | `120` (era 60) |
| Deslocamento na lista de fama | `gerador.deslocamento_maximo` | `0` (era um salto aleatório de até 60 linhas) |
| Mistura de dificuldade | `gerador.mistura_dificuldade` | `7` / `7` / `6` (era 11 / 6 / 3) |
| Focos de nicho | `templates_dicas/*.json` | podados: saíram matemático, filósofo, maestro, sítio arqueológico, península, ferramenta e outros |

O deslocamento era o pior deles: a consulta vem ordenada do mais famoso para o
menos, e pular linhas jogava o sorteio na cauda. Com zero, o gerador escolhe
entre os 120 primeiros do tipo sorteado.

Três filtros novos limpam o texto das dicas:

1. **Nada de rótulo estrangeiro.** Quando o Wikidata não tem o nome em
   português, o SPARQL cai no inglês e saía "Director of the Collège de France"
   no meio da frase. Agora o valor é descartado.
2. **Um valor, uma dica.** O Minecraft ganhava "quem me projetou", "quem me
   desenvolveu" e "meu criador", os três com Markus Persson. Agora o mesmo nome
   próprio vale uma dica só.
3. **Sem idioma de trabalho.** A propriedade `P1412` do Wikidata é ruidosa e
   rendia "Maradona trabalha em coreano". Saiu. A língua materna (`P103`) ficou.

### Números viram faixa

Nada de "tenho 125.416.877 habitantes". O gerador arredonda para baixo até o
número redondo mais próximo:

| Fato bruto        | Vira na dica                          |
|-------------------|---------------------------------------|
| 125.416.877 hab.  | "Tenho mais de 100 milhões de habitantes." |
| 377.975 km²       | "Ocupo mais de 200 mil km²."          |
| nascido em 1960   | "Nasci nos anos 1960."                |
| nascido em 1960   | "Nasci no século XX."                 |
| 1,74 m            | "Minha altura fica entre 1,70 m e 1,80 m." |

### Dificuldade

| Nível     | O que é                                   | Cota padrão |
|-----------|-------------------------------------------|-------------|
| `dificil` | dica vaga, dá pouca coisa                 | 11 de 20    |
| `media`   | ajuda, mas ainda exige raciocínio         | 6 de 20     |
| `facil`   | quase entrega a resposta                  | 3 de 20     |

A cota fica em `gerador.mistura_dificuldade`. Se o tema não tiver fatos suficientes
de um nível, o gerador completa com outro, sempre começando pelos difíceis.

### Editando os modelos

Tudo que faz a dica sair boa ou ruim está em `api/templates_dicas/`. Para
acrescentar uma frase nova, basta abrir o JSON da categoria:

```json
"P106": {
  "chave": "profissao",
  "max_dicas": 2,
  "modelos": [
    { "texto": "Minha profissão é {valor}.", "dificuldade": "media", "formato": "texto" },
    { "texto": "Ganhei a vida assim: {valor}.", "dificuldade": "media", "formato": "texto" }
  ]
}
```

- `{valor}` é onde o fato entra.
- `formato`: `texto`, `ano`, `decada`, `seculo`, `idade_aproximada`,
  `faixa_populacao`, `faixa_area`, `faixa_altitude`, `faixa_comprimento`,
  `faixa_altura`.
- `max_dicas`: quantos valores diferentes daquela propriedade podem virar dica
  na mesma carta.

Há também:

- **`derivadas`** — dicas que nascem da ausência ou da quantidade de um fato
  ("Eu já não estou mais neste mundo", quando existe data de morte).
- **`genericas`** — frases vagas da categoria, usadas **só de reserva**, quando o
  tema não rendeu 20 dicas próprias.

As consultas SPARQL ficam em `templates_dicas/sparql/*.rq`, com os marcadores
`%FOCO%`, `%QID%`, `%MIN_SITELINKS%`, `%LIMITE%` e `%DESLOCAMENTO%`.

### Agendador de Tarefas do Windows

```
Programa:   C:\xampp\php\php.exe
Argumentos: "C:\caminho\para\perfil\api\bin\gerar-cartas.php" 10
Iniciar em: C:\caminho\para\perfil\api
```

O script usa exatamente os mesmos Services da API, sem duplicar nenhuma regra.

---

## 6. Regras do jogo

1. De **2 a 6** jogadores ou equipes.
2. Cada carta esconde uma resposta de uma categoria: **pessoa**, **lugar**,
   **ano** ou **coisa**. A categoria aparece; a resposta, não.
3. Cada carta tem **20 dicas**, numeradas de 1 a 20.
4. Na sua vez, o jogador escolhe um número ainda fechado. A dica aparece e ele
   pode chutar.
5. **Acertou:** ganha 1 ponto para cada dica que continuou fechada. Abriu 2
   dicas e acertou? 18 pontos.
6. **Não quer chutar?** Toque em **Passar a vez**. Não custa nada além da dica
   que já foi gasta: o jogador continua vivo na carta e pode chutar quando a
   roda voltar nele.
7. **Errou o chute:** está fora desta carta. Não chuta mais nela, nem quando a
   roda voltar. O celular passa para o próximo que ainda está vivo.
8. **A carta acaba sem acerto** quando todo mundo já errou o chute nela, ou
   quando as 20 dicas terminam. Ninguém pontua e entra uma carta nova.
9. Vence quem chegar primeiro na pontuação combinada (30, 50, 70 ou 100).
   Em caso de empate, ganha quem acertou a última carta.
10. Uma carta **nunca** se repete na mesma partida. Entre partidas, as menos
   jogadas entram primeiro.

### Modo "passa o celular"

- No fim de cada vez, uma tela cheia mostra o nome, a cor e o avatar do próximo:
  **"Vez de Ana! Toque para jogar"**.
- Cada jogador escolhe cor e avatar no cadastro, e essa identidade aparece no
  placar e nas transições.
- **Desfazer** volta a última ação, para quando alguém toca errado.
- Sair da partida pede confirmação.

### O palpite

O palpite é sempre **digitado**: o jogador escreve a resposta e o **servidor**
confere. A resposta nunca sai do servidor antes do acerto, e ninguém precisa
julgar ninguém. Quem chuta e erra está eliminado da carta na hora, mesmo que
jure que sabia. A comparação ignora maiúsculas, acentos, espaços e pontuação,
aceita os apelidos do Wikidata e perdoa erro de digitação:

| Palpite              | Resposta       | Resultado |
|----------------------|----------------|-----------|
| `ayrton senna`       | Ayrton Senna   | acerta    |
| `AYRTON SENNA`       | Ayrton Senna   | acerta    |
| `Ayrton Sena`        | Ayrton Senna   | acerta    |
| `Senna`              | Ayrton Senna   | acerta    |
| `Japao`              | Japão          | acerta    |
| `o senhor dos aneis` | O Senhor dos Anéis | acerta |
| `Nelson Piquet`      | Ayrton Senna   | erra      |

Quem abriu a dica e prefere não arriscar usa **Passar a vez**: gasta só a dica e
segue vivo na carta, exatamente como na mesa de verdade, em que cada um vai
puxando dica até se sentir seguro para cravar. Quando o último jogador vivo erra
o chute, a carta acaba ali mesmo, sem pontos para ninguém. No placar, quem já
errou aparece apagado e com um ✗ no lugar do avatar, até a carta virar.

**Ver resposta** é outra coisa: mostra a resposta e o link do artigo para todo
mundo, não pontua para ninguém e traz uma carta nova na hora.

---

## 7. Endpoints da API

Toda resposta sai no mesmo formato:

```json
{ "sucesso": true,  "dados": { ... }, "erro": null }
{ "sucesso": false, "dados": null, "erro": { "mensagem": "...", "detalhes": { ... } } }
```

Códigos usados: `200` ok, `201` criado, `400` entrada inválida, `401` sem login,
`404` não existe, `405` método errado, `409` ação fora de hora, `503` sem cartas.

O prefixo `/api` é opcional: `/api/partidas` e `/partidas` chegam no mesmo lugar.

### 7.1 Público

#### `GET /api/saude`
Checagem rápida de banco e extensões. Sem parâmetros.

```json
{ "sucesso": true, "dados": { "api": "ok", "php": "8.3.32", "banco": "ok",
  "extensoes": { "pdo_mysql": true, "curl": true, "mbstring": true, "intl": true, "json": true } }, "erro": null }
```

#### `GET /api/categorias`
As quatro categorias com cor, ícone e quantas cartas aprovadas cada uma tem.

```json
{ "sucesso": true, "dados": { "categorias": [
  { "chave": "pessoa", "nome": "Pessoa", "cor": "#FF4D6D", "icone": "🧑", "aprovadas": 13 },
  { "chave": "lugar",  "nome": "Lugar",  "cor": "#20C997", "icone": "🌍", "aprovadas": 7 }
] }, "erro": null }
```

#### `GET /api/acervo`
Usado pela tela inicial para avisar quando não há carta aprovada.

```json
{ "sucesso": true, "dados": { "total_aprovadas": 20, "pronto_para_jogar": true }, "erro": null }
```

### 7.2 Partida

#### `POST /api/partidas`
Cria a partida e já puxa a primeira carta.

| Parâmetro | Tipo | Obrigatório | Descrição |
|---|---|---|---|
| `jogadores` | array | sim | 2 a 6 itens, cada um `{ nome, cor, avatar }` |
| `jogadores[].nome` | string | sim | 1 a 40 caracteres, sem repetir na partida |
| `jogadores[].cor` | string | não | `#RRGGBB` (padrão `#FF4D6D`) |
| `jogadores[].avatar` | string | não | 1 emoji (padrão `🙂`) |
| `pontuacao_vitoria` | int | não | 10 a 200 (padrão 50) |

```json
POST /api/partidas
{
  "jogadores": [
    { "nome": "Ana",   "cor": "#FF4D6D", "avatar": "🦊" },
    { "nome": "Bruno", "cor": "#20C997", "avatar": "🐸" }
  ],
  "pontuacao_vitoria": 50
}
```

```json
201 Created
{
  "sucesso": true,
  "dados": {
    "partida": {
      "id": 7, "codigo": "KQ3ZV8TP",
      "pontuacao_vitoria": 50, "status": "em_andamento", "numero_carta": 1,
      "jogador_vez_id": 14, "ultimo_acertador_id": null, "vencedor_id": null,
      "carta_revelada": false, "dica_da_vez": null, "eliminados_carta": [],
      "pode_desfazer": false, "fim_de_carta": false
    },
    "jogadores": [
      { "id": 14, "nome": "Ana", "cor": "#FF4D6D", "avatar": "🦊", "pontos": 0, "ordem": 1 },
      { "id": 15, "nome": "Bruno", "cor": "#20C997", "avatar": "🐸", "pontos": 0, "ordem": 2 }
    ],
    "carta": {
      "id": 31,
      "categoria": { "chave": "pessoa", "nome": "Pessoa", "cor": "#FF4D6D", "icone": "🧑" },
      "dicas": []
    },
    "progresso": { "dicas_reveladas": 0, "dicas_restantes": 20, "valor_do_acerto": 19 }
  },
  "erro": null
}
```

> `valor_do_acerto` é quanto o acerto vale **agora**. Com a dica da vez ainda
> fechada, ele já desconta a dica que o jogador precisa abrir.

#### `GET /api/partidas/{id}`
Estado completo da partida. Use ao recarregar a página ou ao voltar do bloqueio
de tela. Mesmo corpo do `POST /api/partidas`.

#### `GET /api/partidas/codigo/{codigo}`
Igual ao anterior, mas pelo código público de 8 letras.

#### `POST /api/partidas/{id}/dicas/{numero}`
Abre a dica escolhida. `numero` de 1 a 20. Sem corpo.

```json
{
  "sucesso": true,
  "dados": {
    "dica": { "numero": 7, "texto": "Nasci nos anos 1960.", "dificuldade": "dificil" },
    "estado": { "...": "estado completo da partida" }
  },
  "erro": null
}
```

Erros: `409` se o número já foi usado, se o jogador já abriu uma dica nesta vez
ou se a resposta já foi mostrada.

#### `POST /api/partidas/{id}/palpite`
O jogador digita a resposta e o servidor confere.

| Parâmetro | Tipo | Obrigatório |
|---|---|---|
| `palpite` | string | sim (1 a 120 caracteres) |

```json
POST /api/partidas/7/palpite
{ "palpite": "ayrton sena" }
```

```json
{
  "sucesso": true,
  "dados": {
    "acertou": true,
    "pontos_ganhos": 18,
    "resposta": "Ayrton Senna",
    "url_fonte": "https://pt.wikipedia.org/wiki/Ayrton_Senna",
    "estado": { "...": "estado completo da partida" }
  },
  "erro": null
}
```

`resposta` e `url_fonte` **só aparecem quando a carta encerra** (acerto ou 20
dicas gastas). Errou no meio do caminho? Vem `"acertou": false` e nada mais.

`eliminado` diz se a jogada tirou o jogador da carta: `true` no chute errado,
`false` em `/passar`.

#### `POST /api/partidas/{id}/passar`
O jogador abriu a dica mas não quer chutar. Não elimina ninguém: a vez passa
para o próximo que ainda está vivo na carta e ele volta a jogar na próxima
rodada. Sem corpo.

#### `POST /api/partidas/{id}/revelar`
Mostra a resposta sem pontuar para ninguém.

```json
{
  "sucesso": true,
  "dados": {
    "resposta": "Ayrton Senna",
    "url_fonte": "https://pt.wikipedia.org/wiki/Ayrton_Senna",
    "estado": { "...": "estado completo" }
  },
  "erro": null
}
```

#### `POST /api/partidas/{id}/proxima-carta`
Puxa a carta seguinte e passa a vez para o próximo jogador. Sem corpo.
Devolve o estado completo.

Erro `503` quando acabaram as cartas aprovadas ainda não usadas nesta partida.

#### `POST /api/partidas/{id}/desfazer`
Volta a última ação (abrir dica, acerto, erro, revelar, carta nova). Sem corpo.
Devolve o estado completo.

Erro `409` se não há nada para desfazer.

#### `POST /api/partidas/{id}/encerrar`
Encerra a partida na marra. O placar de agora vale como final. Sem corpo.

### 7.3 Jogadores

#### `GET /api/partidas/{id}/jogadores`
Placar já ordenado do maior para o menor.

```json
{ "sucesso": true, "dados": { "jogadores": [
  { "id": 15, "nome": "Bruno", "cor": "#20C997", "avatar": "🐸", "pontos": 32, "ordem": 2 },
  { "id": 14, "nome": "Ana",   "cor": "#FF4D6D", "avatar": "🦊", "pontos": 18, "ordem": 1 }
] }, "erro": null }
```

#### `PUT /api/partidas/{id}/jogadores/{jogadorId}`
Troca nome, cor ou avatar no meio da partida.

| Parâmetro | Tipo | Obrigatório |
|---|---|---|
| `nome` | string | não (1 a 40) |
| `cor` | string | não (`#RRGGBB`) |
| `avatar` | string | não (1 emoji) |

### 7.4 Painel administrativo

Todas exigem sessão, menos `login`, `logout` e `sessao`.
Mande os cookies (`credentials: 'same-origin'` no fetch).

#### `POST /api/admin/login`

| Parâmetro | Tipo | Obrigatório |
|---|---|---|
| `usuario` | string | sim |
| `senha` | string | sim |

```json
{ "sucesso": true, "dados": { "usuario": "admin", "logado": true }, "erro": null }
```

Erro `401` com `"Usuario ou senha incorretos."` — a mensagem é a mesma para
usuário errado e senha errada, de propósito.

#### `POST /api/admin/logout`
Sem parâmetros. `{ "logado": false }`.

#### `GET /api/admin/sessao`
`{ "logado": true, "usuario": "admin" }`.

#### `GET /api/admin/resumo`

```json
{ "sucesso": true, "dados": {
  "cartas": { "total": 42, "aprovadas": 30, "pendentes": 10, "rejeitadas": 2 },
  "categorias": [
    { "chave": "pessoa", "nome": "Pessoa", "cor": "#FF4D6D", "icone": "🧑",
      "aprovadas": 13, "pendentes": 4, "total": 17 }
  ]
}, "erro": null }
```

#### `GET /api/admin/cartas`

| Query | Valores |
|---|---|
| `status` | `pendente`, `aprovada`, `rejeitada` |
| `categoria` | `pessoa`, `lugar`, `ano`, `coisa` |
| `busca` | trecho da resposta |
| `pagina` | padrão 1 |
| `por_pagina` | 5 a 100, padrão 20 |

```json
{ "sucesso": true, "dados": {
  "cartas": [{
    "id": 31, "qid": "Q13003", "resposta": "Ayrton Senna", "status": "pendente",
    "vezes_jogada": 0, "url_fonte": "https://pt.wikipedia.org/wiki/Ayrton_Senna",
    "criado_em": "2026-09-25 22:14:03", "total_dicas": 20,
    "categoria": { "chave": "pessoa", "nome": "Pessoa", "cor": "#FF4D6D", "icone": "🧑" }
  }],
  "paginacao": { "total": 42, "pagina": 1, "por_pagina": 20, "paginas": 3 }
}, "erro": null }
```

#### `GET /api/admin/cartas/{id}`
Carta completa, com as 20 dicas e as respostas alternativas.

```json
{ "sucesso": true, "dados": {
  "id": 31, "resposta": "Ayrton Senna", "status": "pendente", "total_dicas": 20,
  "dicas": [
    { "id": 620, "numero": 1, "texto": "Nasci nos anos 1960.",
      "dificuldade": "dificil", "propriedade_origem": "P569" }
  ],
  "respostas_alternativas": [ { "id": 88, "texto": "Senna" } ]
}, "erro": null }
```

#### `PUT /api/admin/cartas/{id}`

| Parâmetro | Tipo |
|---|---|
| `resposta` | string (1 a 180) |
| `url_fonte` | string (URL) ou `""` para limpar |
| `categoria` | `pessoa`, `lugar`, `ano`, `coisa` |

Devolve a carta completa. Erro `409` se outra carta já tem a mesma resposta.

#### `POST /api/admin/cartas/{id}/aprovar`
Sem corpo. Erro `409` se a carta não tiver as 20 dicas.

```json
{ "sucesso": true, "dados": { "id": 31, "status": "aprovada" }, "erro": null }
```

#### `POST /api/admin/cartas/{id}/rejeitar`
Sem corpo.

#### `DELETE /api/admin/cartas/{id}`
Apaga a carta, as dicas e as alternativas.

#### `PUT /api/admin/dicas/{id}`

| Parâmetro | Tipo |
|---|---|
| `texto` | string (5 a 255) |
| `dificuldade` | `dificil`, `media`, `facil` |

#### `DELETE /api/admin/dicas/{id}`
Se a carta ficar com menos de 20 dicas, ela volta para `pendente` sozinha.

#### `POST /api/admin/alternativas`

| Parâmetro | Tipo | Obrigatório |
|---|---|---|
| `carta_id` | int | sim |
| `texto` | string | sim |

#### `DELETE /api/admin/alternativas/{id}`

#### `POST /api/admin/gerar`

| Parâmetro | Tipo | Obrigatório |
|---|---|---|
| `quantidade` | int | não (1 a 50, padrão 10) |
| `categoria` | string | não (padrão: reveza entre as quatro) |

```json
{ "sucesso": true, "dados": {
  "geradas": 8,
  "pedidas": 10,
  "por_categoria": { "pessoa": 2, "lugar": 2, "coisa": 2, "ano": 2 },
  "registro": [
    "[pessoa] \"Ayrton Senna\" criada com 20 dicas (pendente).",
    "[lugar] \"Malta\" descartado: so sobraram 15 dicas aproveitaveis."
  ]
}, "erro": null }
```

> Demora. Se o seu Apache tiver `max_execution_time` curto, aumente ou use o
> `bin/gerar-cartas.php` para lotes grandes.

---

## 8. Banco de dados

| Tabela | Para quê |
|---|---|
| `categorias` | pessoa, lugar, ano, coisa — com cor e ícone |
| `cartas` | resposta, `qid` (UNIQUE), `resposta_normalizada` (UNIQUE), fonte, status, quantas vezes já foi jogada |
| `dicas` | 20 por carta, com dificuldade e a propriedade que deu origem |
| `respostas_alternativas` | apelidos aceitos como acerto |
| `partidas` | meta de pontos, carta atual, de quem é a vez, dicas já abertas, quem saiu da carta |
| `jogadores` | nome, cor, avatar, pontos, ordem |
| `cartas_usadas` | garante que a carta não se repete na partida |
| `acoes_partida` | fotografia do estado antes de cada ação, para o "Desfazer" |

Chaves que protegem os dados:

- `cartas.qid` **UNIQUE** — o mesmo tema do Wikidata nunca entra duas vezes.
- `cartas.resposta_normalizada` **UNIQUE** — "Japão" e "japao" são a mesma carta.
- `dicas (carta_id, numero)` **UNIQUE** — sem buraco nem número repetido.
- `dicas (carta_id, texto_normalizado)` **UNIQUE** — sem dica repetida na carta.
- `cartas_usadas (partida_id, carta_id)` **UNIQUE** — sem carta repetida na partida.

Todas as chaves estrangeiras usam `ON DELETE CASCADE`, menos
`cartas.categoria_id`, que é `RESTRICT` para ninguém apagar uma categoria em uso.

---

## 9. Identidade visual: papel e carimbo

O jogo não se parece com um app: ele se parece com um fichário. A regra que
segura tudo é simples, e está inteira em `frontend/src/styles/global.css`.

| Elemento | Como é aqui | O que nunca aparece |
| --- | --- | --- |
| Fundo | Papel creme com grão gerado em SVG | Gradiente colorido |
| Sombra | Deslocamento sólido de 3px na cor da tinta | Blur, glow, sombra colorida |
| Canto | 2px, praticamente reto | Cantos de 16px |
| Cor | Tinta chapada de carimbo | Degradê, transparência |
| Título | Oswald condensada, caixa alta, tracking largo | Fonte arredondada |
| Texto | Courier Prime, como máquina de escrever | Fonte de interface genérica |

A paleta tem quatro tintas de carimbo, uma por categoria: carmim para pessoa,
verde-garrafa para lugar, ocre para ano e azul-tinta para coisa. As mesmas
quatro servem de cor de jogador, junto com roxo, preto, sépia e oliva.

### No desktop: capa de verdade, partida emoldurada

O jogo nasceu mobile-first e, numa tela de 1920px, virava uma coluna de 720px
boiando no vazio. O padrão que as landing pages de jogo usam hoje resolve isso
em três movimentos, e é o que está implementado:

1. **Hero em duas colunas** a partir de 900px: o nome, a proposta em uma frase
   e os botões à esquerda; o baralho aberto à direita, com a carta de mostruário
   desenhada na mesma linguagem da tela de jogo. CTA acima da dobra.
2. **Faixa "como se joga"** logo abaixo, em três passos numerados. Antes isso
   estava escondido dentro de um `<details>`, que ninguém abre.
3. **Prova de acervo** na própria dobra: quantas cartas e quantas dicas existem.

A tela de partida **não** vira largura cheia, e isso é proposital: o jogo é
passado de mão em mão num aparelho só, então a coluna continua com largura de
celular. O que muda é o entorno. A partir de 900px o fundo escurece para o tom
de mesa (`--mesa`) e a coluna ganha borda e sombra lateral: deixa de ser um
vazio e passa a ser uma folha apoiada.

A ficha da dica é papel pautado, com a entrelinha do texto casada com a pauta de
28px. Era o que faltava para a sobra de espaço da carta parecer papel em vez de
caixa vazia.

Detalhes que fazem o conjunto fechar:

1. O botão afunda no papel ao ser tocado: a sombra some e ele anda 3px.
2. A dica aparece numa folha com furos de fichário desenhados na margem.
3. Número de dica já usado fica riscado a caneta, não apagado.
4. O acerto e o erro entram como carimbo grande e torto, com moldura dupla.
5. O avatar do jogador vira retrato 3x4 preso com fita adesiva na tela de
   passar o celular. Foi a parte que mais agradou, então ela ficou no centro.

---

## 10. O que foi pensado para o celular

| Item | Como foi feito |
|---|---|
| Layout base | 360px de largura, em pé. Telas maiores só ganham respiro. |
| Sem rolagem na partida | `100dvh` com flex; placar, dica, grade e ações dividem a altura. |
| Altura da tela | `100dvh` em vez de `100vh`, com `100vh` de reserva. |
| iPhone | `env(safe-area-inset-*)` no topo, na base e nas laterais; `viewport-fit=cover`. |
| Toque | Todo botão e todo número com no mínimo 48px. Nada depende de hover. |
| Teclado virtual | `visualViewport` mede o teclado e empurra o campo para cima. Fonte de 16px para o iPhone não dar zoom. |
| Tela ligada | Screen Wake Lock API, e volta a pedir quando a aba retorna. Sem a API, segue sem reclamar. |
| Vibração | `navigator.vibrate` no acerto, no erro e na troca de jogador. |
| PWA | Manifest, ícones (inclusive maskable) e service worker. A API nunca entra em cache. |
| Animações | `transform` e `opacity`, todas abaixo de 0,5 s. Respeitam `prefers-reduced-motion`. |
| Voltou do bloqueio | O estado vem do banco: recarrega sozinho quando a aba volta a aparecer. |

Testado no Chrome do Android e no Safari do iPhone.

---

## 11. Problemas comuns

**"Autoload do Composer nao encontrado"**
Rode `composer dump-autoload -o` dentro de `api/`.

**"Arquivo de configuracao nao encontrado"**
Copie `api/config/config.exemplo.php` para `api/config/config.php`.

**"Nao foi possivel conectar ao MySQL"**
Confira usuário e senha no `config.php` e se o MySQL está de pé.
Se a mensagem for `could not find driver`, falta `extension=pdo_mysql` no `php.ini`
**do PHP que o Apache usa**.

**`/api/saude` devolve 404**
O `mod_rewrite` está desligado, ou falta `AllowOverride All` no bloco
`<Directory>` do Apache.

**Recarregar `/partida/12` dá 404 em produção**
Falta o `.htaccess` na pasta do build, ou o `AllowOverride All`.

**O celular não abre o endereço do Vite**
Firewall do Windows bloqueando a porta 5173, ou os aparelhos estão em redes
diferentes.

**A geração não cria nenhuma carta**
- Sem internet ou o Wikidata fora do ar. A mensagem aparece no `registro`.
- `user_agent` ainda com o valor de exemplo: o Wikidata bloqueia.
- `minimo_sitelinks` alto demais para a categoria. Baixe para 40 e tente.

**"Acabaram as cartas aprovadas"**
A partida já usou todas. Gere mais no painel e aprove.

**O celular abre uma versão antiga depois do build**
Mude o `VERSAO` no `frontend/public/sw.js` e recarregue segurando o botão.

---

## 12. O que já está pronto na sua máquina

Ao entregar, deixei o ambiente funcionando:

- Banco `perfil` criado e com as tabelas.
- `api/config/config.php` criado a partir do exemplo, apontando para
  `root` sem senha (o padrão do XAMPP). **Ajuste se o seu for diferente.**
- `composer dump-autoload -o` já rodado.
- `npm install` já rodado e um `npm run build` já gerado em `frontend/dist/`.
- **10 cartas reais já geradas e aprovadas** (3 pessoa, 3 lugar, 2 ano, 2 coisa),
  com as 200 dicas. Dá para jogar agora mesmo.

Falta só: apontar o Apache para as pastas (passo 3.4) e trocar a senha do
painel (passo 3.2).

### Sobre a qualidade das cartas geradas

O gerador acerta a maior parte, mas nem sempre. O filtro de fama usa a
quantidade de Wikipédias que têm artigo sobre o tema, e isso às vezes deixa
passar alguém conhecido lá fora e desconhecido aqui, do tipo "Serge Haroche".
Por isso a carta nasce **pendente**: a revisão no painel é parte do processo,
não um detalhe.

Três botões para ajustar a mira:

1. **`gerador.minimo_sitelinks`** no `config.php`. Subir para 80 ou 100 traz só
   nome muito famoso, mas diminui bastante quantas cartas saem por lote.
2. **Lista de `focos`** nos arquivos de `templates_dicas/`. Tirar um foco
   (por exemplo `cientista`) tira toda uma família de temas.
3. **Rejeitar no painel.** Carta rejeitada não volta a aparecer no jogo.
