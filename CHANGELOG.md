# Changelog

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
