# Changelog

## [1.0.1] - 2025-10-31
- Agendamento dos backups manuais via WP-Cron para evitar processamento pesado na requisição do painel.

## [1.0.0] - 2025-10-30
- Estrutura refatorada para carregar o plugin através do loader `pt-simple-backup.php`.
- Distribuição do código legado entre módulos (`config`, `rclone`, `log`, `parts`, `schedule`, `actions`, `ajax`, `ui`).
- Adição de diretório de assets com arquivos base `admin.css` e `admin.js`.
