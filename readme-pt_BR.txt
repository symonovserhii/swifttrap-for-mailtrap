=== SwiftTrap for Mailtrap ===
Contributors: simmotorlp
Tags: mailtrap, transactional-email, email-api, wp-mail, email-log
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 3.0.1
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Envie e-mails do WordPress pela API de E-mail do Mailtrap (não SMTP). Streams em massa e transacionais, categorias, lista de supressão, registro de e-mails.

== Description ==

**SwiftTrap** é um substituto drop-in do `wp_mail()` que roteia o e-mail do WordPress pela **API de Envio de E-mail do Mailtrap** em vez de SMTP. Foi criado especificamente para o Mailtrap — não é um plugin SMTP genérico com um preset do Mailtrap — por isso expõe recursos nativos do Mailtrap que o SMTP não oferece: roteamento de stream em massa vs. transacional, categorias de e-mail, variáveis personalizadas para rastreamento, listas de supressão e status de verificação de domínio.

= Por que API HTTP em vez de SMTP? =

* **Menor latência** — uma chamada HTTPS por mensagem, sem idas e vindas de MAIL FROM / RCPT TO / DATA.
* **Melhor entregabilidade** — o Mailtrap roteia mensagens da API por seus streams dedicados transacional e em massa; o SMTP não expõe seleção de stream.
* **Categorias nativas** — cada e-mail é categorizado automaticamente (boas-vindas, redefinição de senha, notificação, marketing, etc.), permitindo filtrar e gerar relatórios no Mailtrap.
* **Sem dores de cabeça com firewall** — porta 587/465 bloqueada? A API funciona pela HTTPS padrão, porta 443.

= Por que SwiftTrap e não WP Mail SMTP / Post SMTP =

* Plugins SMTP genéricos usam as credenciais SMTP do Mailtrap e perdem todos os recursos exclusivos do Mailtrap.
* O SwiftTrap chama `send.api.mailtrap.io` para e-mail transacional e `bulk.api.mailtrap.io` para e-mail em massa — automaticamente, com base na categoria ou via filtro.
* Não requer o SDK PHP do Mailtrap. O plugin tem **cerca de 30 KB no total** e usa apenas a API HTTP do WordPress (`wp_remote_post`).
* A página de estatísticas mostra o status de verificação do seu domínio de envio e a lista de supressão ao vivo (rejeições, reclamações, cancelamentos de inscrição).

= Recursos =

* Substituto drop-in do `wp_mail()` — funciona com WooCommerce, Contact Form 7, Gravity Forms e qualquer plugin que use o sistema de e-mail do WordPress.
* Categorização automática de e-mails e substituições de roteamento de stream via grade de configurações.
* Rastreamento de Entrega e Webhooks — Rastreamento de eventos em tempo real via rota REST personalizada `swifttrap/v1/webhook`.
* Gerenciamento de Supressões — Painel CRUD para as listas de supressão do Mailtrap com verificações de supressão do destinatário antes do envio.
* Fallback de Confiabilidade — Failover automático para o `wp_mail()` nativo do WordPress caso a chamada à API do Mailtrap falhe.
* Integração com o Site Health — Teste de verificação que checa o status do token do Mailtrap e a verificação do domínio de envio.
* Registro de E-mails ao Vivo — Navegue e filtre dados de entrega obtidos diretamente da API do Mailtrap; pesquise por endereço do destinatário, status ou intervalo de datas com paginação automática.
* Comandos WP-CLI — Gerenciamento via linha de comando com `wp swifttrap` (test, stats, prune-logs, send-suppression-sync).
* Proteção de tamanho de anexo — Limites configuráveis para evitar que arquivos grandes demais sejam rejeitados no gateway da API.
* Botão de e-mail de teste na página de configurações.
* Suporte a templates do Mailtrap via `template_uuid`.
* Recorre ao manipulador de e-mail padrão do WordPress quando desativado ou quando o token está vazio.

= Extensível via filtros =

* `swifttrap_mailtrap_email_category` — substitui a categoria de e-mail detectada automaticamente.
* `swifttrap_mailtrap_use_bulk_stream` — força uma mensagem para o stream em massa ou transacional.
* `swifttrap_mailtrap_template` — envia via um template do Mailtrap usando `template_uuid`.
* `swifttrap_mailtrap_custom_variables` — anexa metadados de rastreamento aos e-mails enviados.

= Privacidade =

Este plugin envia os payloads de e-mail (destinatários, assunto, corpo, anexos) para a API do Mailtrap em `send.api.mailtrap.io` e `bulk.api.mailtrap.io`. Estatísticas da conta e registros de e-mail são obtidos de `mailtrap.io/api/accounts` e `mailtrap.io/api/email_logs`. Consulte a [Política de Privacidade do Mailtrap](https://mailtrap.io/privacy-policy). Nenhum dado é enviado para qualquer outro destino.

== Installation ==

1. Instale via **Plugins → Adicionar novo** e procure por *SwiftTrap for Mailtrap*, ou faça upload da pasta `swifttrap-for-mailtrap` para `/wp-content/plugins/`.
2. Ative o plugin.
3. Acesse **Mailtrap → Settings**.
4. Cole seu **token de API de envio** do Mailtrap (painel do Mailtrap → Sending Domains → API Tokens).
5. Defina seu e-mail e nome de remetente verificados.
6. Clique em **Send test email** para verificar a entrega.

== Frequently Asked Questions ==

= Por que usar o SwiftTrap em vez do WP Mail SMTP ou Post SMTP com credenciais do Mailtrap? =

O WP Mail SMTP e o Post SMTP roteiam pelo gateway SMTP do Mailtrap e tratam o Mailtrap como apenas mais um host SMTP. O SwiftTrap usa a API de Envio HTTP do Mailtrap, que expõe recursos que o SMTP não pode: roteamento de stream em massa vs. transacional, categorias, variáveis personalizadas de rastreamento, UUIDs de template e visibilidade da lista de supressão ao vivo. Use o SwiftTrap se você quiser o comportamento nativo do Mailtrap; use um plugin SMTP genérico se quiser uma configuração única que sirva para todos os provedores.

= Ele suporta templates de e-mail do Mailtrap? =

Sim — use o filtro `swifttrap_mailtrap_template` para enviar via um `template_uuid`. As variáveis do template podem ser passadas pelo payload padrão de variáveis de template do Mailtrap.

= Como funciona o roteamento de stream em massa? =

Por padrão, categorias de marketing/promocionais são roteadas para `bulk.api.mailtrap.io` e todo o restante para `send.api.mailtrap.io`. Substitua por mensagem com o filtro `swifttrap_mailtrap_use_bulk_stream` — útil para newsletters em lote de um plugin personalizado.

= Onde consigo meu token de API? =

Faça login em [mailtrap.io](https://mailtrap.io), abra seu domínio de envio, vá em **API Tokens** e crie um token com permissões de envio.

= O que acontece se eu desativar o plugin ou remover o token? =

O WordPress volta a usar seu manipulador `wp_mail()` padrão. Nenhum e-mail é descartado silenciosamente.

= O plugin requer o SDK PHP do Mailtrap? =

Não. O SwiftTrap chama a API REST do Mailtrap diretamente pela API HTTP do WordPress. O tamanho total do plugin é de cerca de 30 KB.

= Quais dados são enviados externamente? =

Os dados de e-mail (destinatários, assunto, corpo, anexos) vão para `send.api.mailtrap.io` e `bulk.api.mailtrap.io`. As estatísticas da conta são obtidas de `mailtrap.io/api/accounts`. Consulte a [Política de Privacidade do Mailtrap](https://mailtrap.io/privacy-policy).

= Existe um limite de tamanho de anexo? =

Sim — 25 MB por e-mail (corresponde ao limite da API do Mailtrap).

== Screenshots ==

1. Página de configurações — token de API, remetente verificado, roteamento de stream.
2. Página de estatísticas — status de verificação do domínio de envio e lista de supressão (rejeições, reclamações, cancelamentos de inscrição).
3. Registro de e-mails — dados ao vivo da API do Mailtrap com filtros e paginação.
4. Widget do painel mostrando status da integração, remetente e links rápidos para Stats e Settings.
5. Confirmação de e-mail de teste.

== Changelog ==

= 3.0.1 =
* Corrigido: O receptor de webhook agora verifica o cabeçalho `Mailtrap-Signature` HMAC-SHA256 real do Mailtrap, em vez de um cabeçalho que o Mailtrap nunca envia. Toda chamada real de webhook de rastreamento de entrega estava sendo rejeitada desde que o recurso foi lançado na versão 2.4.0.
* Corrigido: O parsing do payload do webhook agora desembrulha corretamente o envelope `{"events": [...]}` do Mailtrap, para que os eventos verificados cheguem a `do_action('swifttrap_mailtrap_webhook_event', ...)`.
* Corrigido: O card de Uso na página de estatísticas agora chama o endpoint atual `/api/billing/usage` do Mailtrap, em vez de um caminho antigo com escopo de conta que não retornava dados.
* Corrigido: Desinstalar o plugin agora limpa os transients realmente armazenados em cache, em vez de nomes de chave anteriores à versão 2.3.0 que não correspondem mais.
* Melhorado: A busca de destinatário nos Registros de E-mail e as chamadas à API de conta agora usam de forma consistente a sintaxe de filtro com colchetes e autenticação por token Bearer.

= 3.0.0 =
* Alteração incompatível: Removido todo o registro de e-mails baseado em arquivo local. Nenhum arquivo de log é mais gravado em disco — elimina o risco de OOM/disco cheio em sites de alto volume.
* Novo: O painel de Registros de E-mail na página de estatísticas obtém dados ao vivo diretamente da API do Mailtrap (`GET /api/email_logs`).
* Novo: Os Registros de E-mail suportam filtragem por endereço de e-mail do destinatário, status de entrega e intervalo de datas.
* Novo: Paginação no lado do cliente — armazena em buffer até 1.000 registros do Mailtrap por chamada de API, exibindo 20 linhas por vez com navegação Anterior/Próximo. Busca automaticamente o próximo lote quando o buffer se esgota.
* Novo: O manipulador de webhook agora dispara `do_action('swifttrap_mailtrap_webhook_event', $event)` para cada evento de entrega, permitindo integrações de terceiros sem modificar o plugin.
* Removido: Exportação CSV, limpeza de arquivo de log, modal de detalhes do log, reenvio de log, configuração de itens por página nos registros e limpeza de log baseada em cron. Tudo substituído pela visualização de API ao vivo.
* Corrigido: A página de estatísticas não cria mais um atributo nonce redundante no elemento wrapper.

= 2.4.2 =
* Corrigido: O registro de e-mails perdia a maioria das entradas durante envios de alto volume ou concorrentes. Cada gravação relia e reescrevia todo o arquivo de log, então processos paralelos sobrescreviam as linhas uns dos outros. As gravações agora usam um append atômico e com bloqueio exclusivo, para que o painel de Estatísticas (envios por dia, categorias, totais) reflita o número real de e-mails enviados.
* Melhorado: O registro não deixa mais os envios em massa mais lentos — os appends agora são O(1) em vez de reler e reescrever o arquivo inteiro a cada e-mail.

= 2.4.1 =
* Corrigido: A lista de supressão agora lê o campo `type` do Mailtrap, para que o painel mostre contagens reais de BOUNCE / COMPLAINT / UNSUBSCRIBE / MANUAL em vez de marcar todo registro como manual.
* Novo: As linhas de supressão exibem a categoria de rejeição da mensagem (quando fornecida) para detalhes de hard bounce.
* Corrigido: As datas de supressão agora são formatadas no lado do servidor usando o formato de data do site, em vez do idioma do navegador.
* Novo: Link "Ver tudo no Mailtrap" no card de Supressões.
* Novo: Seletor de itens por página (10/25/50/100) na tela de Registros de E-mail.
* Melhorado: As ações do cabeçalho dos Registros de E-mail foram alinhadas à direita; o campo de filtro de data foi reestilizado para combinar com os demais campos.

= 2.4.0 =
* Novo: Endpoint REST de Webhook (`swifttrap/v1/webhook`) para rastrear status de entregue, rejeitado, aberto e clicado.
* Novo: CRUD de Gerenciamento de Supressões nas Estatísticas do Admin e verificações do destinatário antes do envio para ignorar e-mails suprimidos.
* Novo: Mecanismo de fallback retornando `null` em `pre_wp_mail` em caso de falha da API, para que o `wp_mail` nativo envie o e-mail em vez disso.
* Novo: Teste de conexão do Site Health e status de verificação de domínio.
* Novo: Interface de registros do Admin atualizada com busca, filtragem, exportação CSV, modais de pré-visualização de payload em iframe e ações de reenvio.
* Novo: Grade de configurações de categoria para regras de mapeamento de stream por categoria e substituições de remetente.
* Novo: Namespace WP-CLI `wp swifttrap` (test, stats, prune-logs, send-suppression-sync).
* Novo: Configuração de proteção de tamanho de anexo.
* Refatorado: Extraída a formatação de linha CSV para uma função auxiliar para testes unitários. Totalmente coberta e verificada pela suíte de testes.

= 2.3.0 =
* O PHP 8.0 agora é o mínimo exigido; testado até o WordPress 7.0.
* Confiabilidade: nova tentativa automática com backoff em erros transitórios da API do Mailtrap (429/5xx, respeitando Retry-After).
* Retenção determinística de logs via um evento cron diário (substitui a limpeza probabilística anterior).
* Os caches de conta/estatísticas/domínio/supressão agora são indexados por token de API, então trocar de token não serve mais dados desatualizados.
* Tratamento robusto de JSON para todas as respostas da API do Mailtrap; cache de configurações seguro para multisite.
* Novo: Botão "Verify token" na tela de configurações.
* Código modernizado para idiomas do PHP 8; primeira suíte de testes unitários adicionada.

= 2.2.2 =
* Plugin URI: agora aponta para a página de destino dedicada em https://plugins.symonov.com/swifttrap-for-mailtrap/
* Nenhuma mudança de código ou comportamento

= 2.2.1 =
* Readme: reescrita com foco em USP, enfatizando a API de E-mail do Mailtrap (vs. SMTP) e o roteamento de stream em massa/transacional
* Tags: substituídas `email`/`mail`/`smtp` por `mailtrap`, `transactional-email`, `email-api`, `wp-mail`, `email-log` mais direcionadas
* FAQ: adicionada comparação com WP Mail SMTP / Post SMTP, suporte a templates do Mailtrap e roteamento de stream em massa
* Testado até o WordPress 7.0

= 2.2.0 =
* Substituídos todos os file_get_contents/file_put_contents pela API WP_Filesystem
* Corrigida a sanitização de $_GET com wp_unslash() adequado e anotações phpcs
* Melhorados os cabeçalhos PHPDoc em todos os arquivos
* Melhor conformidade com os WordPress Coding Standards

= 2.1.0 =
* Adicionado status de verificação do domínio de envio na página de Estatísticas
* Adicionada lista de supressão (rejeições, reclamações, cancelamentos de inscrição) na página de Estatísticas
* Adicionado filtro `swifttrap_mailtrap_template` para suporte a templates do Mailtrap
* Adicionado filtro `swifttrap_mailtrap_custom_variables` para metadados de rastreamento de e-mail
* Extraída a função reutilizável `swifttrap_mailtrap_get_account_id()` com cache em transient

= 2.0.0 =
* Removida a dependência do SDK do Mailtrap — usa a API HTTP do WordPress diretamente
* Zero dependências externas, ~30 KB de tamanho total do plugin
* Melhorada a conformidade com o WP.org

= 1.3.0 =
* Segurança: diretório de log protegido contra acesso direto pela web
* Adicionada validação de tamanho de anexo (limite de 25 MB)
* Adicionada validação de destinatário vazio
* Corrigido o tratamento de fuso horário na exibição do log
* Otimizado o cálculo de categoria de e-mail
* Melhorado o bloqueio de arquivo de log

== Upgrade Notice ==

= 3.0.1 =
Correção importante: eventos de webhook de rastreamento de entrega do Mailtrap estavam sendo rejeitados devido a uma incompatibilidade na verificação de assinatura e nunca foram processados desde a versão 2.4.0. Atualize se você usa a integração de webhook.

= 2.4.0 =
Atualiza o plugin do WordPress para a 2.4.0, introduzindo webhooks de rastreamento de entrega, gerenciamento de supressões, fallback nativo automático, interface de registros aprimorada com exportação CSV, comandos WP-CLI e uma verificação do Site Health do WordPress.

= 2.3.0 =
Versão de confiabilidade menor: novas tentativas automáticas de envio em erros transitórios da API, limpeza de log baseada em cron e atualizações modernas do PHP 8.

= 2.2.2 =
O Plugin URI agora aponta para a página de destino dedicada em plugins.symonov.com. Nenhuma mudança de código.

= 2.2.1 =
Versão somente de documentação. Readme atualizado e compatibilidade confirmada com o WordPress 7.0.

= 2.2.0 =
Conformidade com os WordPress Coding Standards — API WP_Filesystem, sanitização de entrada reforçada e PHPDoc aprimorado. Nenhuma alteração de configuração necessária.
