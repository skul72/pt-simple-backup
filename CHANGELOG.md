# Changelog

## [1.2.11] - 2025-11-16
- Remove função `ptsb_cycles_global_save` obsoleta do agendamento para evitar usos indevidos.

## [1.2.10] - 2025-11-15
- Remove `ptsb_telemetry_history` e ajusta chamadas para usar apenas a opção armazenada diretamente.

## [1.2.9] - 2025-11-14
- Corrige o controle "Exibindo" da lista do Drive para exibir totais e paginação corretos.
- Reaplica o espaçamento do filtro para alinhar com o restante das tabelas.

## [1.2.8] - 2025-11-13
- Aplica `nice` e `ionice` aos disparos de backup e restauração quando disponíveis, evitando que os processos disputem prioridade em horários de pico.
- Limita o uso de CPU com `cpulimit` (70%) sempre que o binário existir, expondo as restrições via variáveis de ambiente para o script externo.
- Exporta metadados de contexto e limites ativos (`PTS_JOB_*`) para permitir ajustes adicionais em wrappers personalizados.

## [1.2.7] - 2025-11-12
- Remove o disparo manual do WP-Cron via painel, esperando apenas o agendamento padrão.
- Orienta no painel como configurar um cron do sistema chamando `wp cron event run --due-now`.

## [1.2.6] - 2025-11-11
- Cria arquivo de estado por plano com progresso dos chunks, mantendo tentativas, agendamentos e token de corrida por parte.
- Expõe o caminho e a versão do estado via variáveis de ambiente para o script externo retomar apenas os chunks pendentes.
- Disponibiliza helpers PHP para marcar chunks em execução, concluídos, com erro temporário ou exceção permanente com backoff.
- Limpa automaticamente estados antigos ao remover planos expirados para evitar acúmulo.

## [1.2.5] - 2025-11-10
- Reserva arquivo de telemetria por execução, expondo caminho via `PTS_TELEMETRY_FILE` e guardando contexto da execução.
- Consolida resumos leves de métricas (duração por etapa, bytes, I/O wait, memória pico) com expiração automática do histórico.
- Limpa arquivos de telemetria antigos e descarta pendências obsoletas para evitar acúmulo.

## [1.2.4] - 2025-11-09
- Move planos de chunks e payloads volumosos para arquivos JSON em `/wp-content/uploads/pt-simple-backup/`, mantendo apenas metadados leves nas opções.
- Marca opções com arrays grandes como `autoload=no`, evitando leitura automática no carregamento de cada página.
- Garante limpeza periódica dos blobs persistidos e preserva o diretório dedicado de armazenamento.

## [1.2.3] - 2025-11-08
- Carrega "Próximas Execuções" e "Últimas execuções" via AJAX, limitando a 20 itens por requisição e evitando carregamento pesado na renderização inicial.
- Implementa paginação dinâmica no painel, mantendo filtros e formulários responsivos sem recarregar a página.
- Reaproveita o endpoint de detalhes para preencher manifestos sob demanda após cada carregamento.

## [1.2.2] - 2025-11-07
- Agenda dumps do banco em job assíncrono com `mysqldump --single-transaction --quick`, compressão via `pigz`/`gzip` e upload por `rclone`.
- Adiciona botão no painel para disparar o dump SQL sem bloquear a requisição administrativa.
- Permite configurar diretório remoto dedicado (`db-dumps`) para armazenar os arquivos `.sql.gz` gerados.

## [1.2.1] - 2025-11-06
- Define parâmetros padrão de delta para o rclone (max-age/update) nos chunks de uploads e exporta tuning via `RCLONE_TUNING`.
- Limita concorrência e políticas de retry do rclone no plano, permitindo ajuste fino sem alterar o script externo.
- Torna o uso de `--fast-list` opcional conforme suporte/memória do remote.

## [1.2.0] - 2025-11-05
- Planeja chunks de backup antes de disparar o script externo, organizando partes em jobs sequenciais sem multiplicar entradas no painel.
- Gera manifestos de chunk em JSON acessíveis pelo script (`PTS_CHUNK_PLAN_*`) e higieniza arquivos antigos automaticamente.
- Segmenta uploads por ano/mês quando disponíveis, preservando fallback único caso não haja estrutura padrão.

## [1.1.1] - 2025-11-04
- Reverte o enfileiramento de chunks garantindo que cada agendamento gere apenas um arquivo de backup.
- Restaura o disparo único do script externo mantendo o registro correto das partes utilizadas.

## [1.0.4] - 2025-11-03
- Cria manifest incremental em disco para a lista de backups, reutilizando a varredura do Drive por até 6 horas.
- Invalida automaticamente o manifest local ao iniciar novos backups para garantir atualização pontual.

## [1.0.3] - 2025-11-02
- Implementa lock otimista com TTL para evitar disparos concorrentes de backups.
- Atualiza verificações de status liberando o lock assim que o processo encerra.

## [1.0.2] - 2025-11-01
- Renomear, marcar "Sempre manter" e remover arquivos agora são agendados e executados via WP-Cron (sem `rclone` direto no painel).
- Adição de fila e reprocessamento básico para jobs administrativos assíncronos.
- Ajuste das mensagens do painel para indicar operações agendadas.

## [1.0.1] - 2025-10-31
- Agendamento dos backups manuais via WP-Cron para evitar processamento pesado na requisição do painel.

## [1.0.0] - 2025-10-30
- Estrutura refatorada para carregar o plugin através do loader `pt-simple-backup.php`.
- Distribuição do código legado entre módulos (`config`, `rclone`, `log`, `parts`, `schedule`, `actions`, `ajax`, `ui`).
- Adição de diretório de assets com arquivos base `admin.css` e `admin.js`.
