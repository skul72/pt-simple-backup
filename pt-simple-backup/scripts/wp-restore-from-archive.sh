#!/usr/bin/env bash
set -euo pipefail

log() {
  local ts
  ts=$(date '+%d-%m-%Y-%H:%M')
  echo "[${ts}] $*"
}

fail() {
  log "ERROR: $*"
  exit 1
}

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    fail "comando obrigatório ausente: $1"
  fi
}

REMOTE=${REMOTE:-}
FILE=${FILE:-}
WP_PATH=${WP_PATH:-}
DB_REMOTE_DIR=${DB_REMOTE_DIR:-}

[[ -z "$REMOTE" ]] && fail "REMOTE não definido."
[[ -z "$FILE" ]] && fail "FILE não definido."
[[ -z "$WP_PATH" ]] && fail "WP_PATH não definido."
[[ ! -d "$WP_PATH" ]] && fail "WP_PATH não existe: $WP_PATH"

need_cmd rclone
need_cmd tar
need_cmd gzip
need_cmd php
need_cmd rsync

TMP_DIR=$(mktemp -d "${TMPDIR:-/tmp}/ptsb-restore.XXXXXX")
trap 'rm -rf "$TMP_DIR"' EXIT

BUNDLE_LOCAL="$TMP_DIR/bundle.tar.gz"
log "Baixando bundle ${REMOTE}${FILE}..."
if ! rclone copyto "${REMOTE}${FILE}" "$BUNDLE_LOCAL" >/dev/null 2>&1; then
  fail "falha ao baixar o bundle do Drive."
fi

log "Inspecionando bundle..."
if ! tar -tzf "$BUNDLE_LOCAL" >"$TMP_DIR/bundle.list"; then
  fail "não foi possível ler o conteúdo do bundle."
fi

wp_files_entry=$(grep -E '^wp-files[^/]*\.tar\.gz$' "$TMP_DIR/bundle.list" | head -n1 || true)
db_entry=$(grep -E '(^|/)(db|wpb-db|wp-db)[^/]*\.sql\.gz$' "$TMP_DIR/bundle.list" | head -n1 || true)

if [[ -z "$wp_files_entry" ]]; then
  fail "bundle sem wp-files-*.tar.gz"
fi

DB_LOCAL=""
if [[ -n "$db_entry" ]]; then
  log "Encontrado dump do banco no bundle (${db_entry})."
  mkdir -p "$TMP_DIR/extract"
  if ! tar -xzf "$BUNDLE_LOCAL" -C "$TMP_DIR/extract" "$db_entry"; then
    fail "falha ao extrair dump do banco do bundle."
  fi
  DB_LOCAL="$TMP_DIR/extract/$db_entry"
else
  log "Dump do banco não está no bundle — buscando no remote."
  ts="$(echo "$FILE" | grep -oE '[0-9]{8}-[0-9]{6}' | tail -n1 || true)"
  if [[ -z "$ts" ]]; then
    fail "não foi possível identificar o timestamp do arquivo para localizar o dump do banco."
  fi

  if [[ -n "$DB_REMOTE_DIR" ]]; then
    [[ "$DB_REMOTE_DIR" == */ ]] || DB_REMOTE_DIR="${DB_REMOTE_DIR}/"
  fi
  db_remote_base="${REMOTE}${DB_REMOTE_DIR}"

  log "Procurando dump do banco com timestamp ${ts} em ${db_remote_base}..."
  db_candidate=$(rclone lsf "$db_remote_base" --files-only --format "p" --include "*${ts}*.sql.gz" 2>/dev/null | head -n1 || true)
  if [[ -z "$db_candidate" ]]; then
    fail "dump do banco não localizado no remote."
  fi

  db_candidate_base=$(basename "$db_candidate")
  DB_LOCAL="$TMP_DIR/$db_candidate_base"
  log "Baixando dump do banco ${db_candidate_base}..."
  if ! rclone copyto "${db_remote_base}${db_candidate}" "$DB_LOCAL" >/dev/null 2>&1; then
    fail "falha ao baixar o dump do banco (${db_candidate})."
  fi
fi

[[ -f "$DB_LOCAL" ]] || fail "dump do banco não encontrado."

log "Extraindo arquivos do WordPress (${wp_files_entry})..."
mkdir -p "$TMP_DIR/extract"
if ! tar -xzf "$BUNDLE_LOCAL" -C "$TMP_DIR/extract" "$wp_files_entry"; then
  fail "falha ao extrair wp-files do bundle."
fi

WP_FILES_TAR="$TMP_DIR/extract/$wp_files_entry"
[[ -f "$WP_FILES_TAR" ]] || fail "arquivo wp-files não foi extraído corretamente."

log "Preparando diretório temporário com os arquivos..."
WP_FILES_DIR="$TMP_DIR/wp-files"
mkdir -p "$WP_FILES_DIR"
if ! tar -xzf "$WP_FILES_TAR" -C "$WP_FILES_DIR"; then
  fail "falha ao extrair o conteúdo de wp-files."
fi

log "Sincronizando arquivos para ${WP_PATH}..."
if ! rsync -a --delete "$WP_FILES_DIR"/ "$WP_PATH"/; then
  fail "falha ao sincronizar arquivos do WordPress."
fi

log "Descompactando dump do banco..."
DB_SQL="$TMP_DIR/database.sql"
if ! gzip -dc "$DB_LOCAL" >"$DB_SQL"; then
  fail "falha ao descompactar o dump do banco."
fi

log "Lendo credenciais do wp-config.php..."
set +e
db_exports=$(WP_PATH="$WP_PATH" php -r '
  $base = getenv("WP_PATH");
  if ($base === false || $base === "") {
    fwrite(STDERR, "WP_PATH ausente\n");
    exit(2);
  }
  $config = rtrim($base, "/") . "/wp-config.php";
  if (!file_exists($config)) {
    fwrite(STDERR, "wp-config.php não encontrado em {$base}\n");
    exit(3);
  }
  define("SHORTINIT", true);
  require $config;
  $host = defined("DB_HOST") ? DB_HOST : "localhost";
  $port = "";
  $socket = "";
  if (preg_match("/^\\[(.+)\\]:(\\d+)$/", $host, $m)) {
    $host = $m[1];
    $port = $m[2];
  } elseif (preg_match("/^(.*):(\\d+)$/", $host, $m)) {
    $host = $m[1];
    $port = $m[2];
  } elseif (preg_match("/^(.*):(\/.*)$/", $host, $m)) {
    $host = $m[1];
    $socket = $m[2];
  }
  echo 'DB_NAME=' . escapeshellarg(DB_NAME) . "\n";
  echo 'DB_USER=' . escapeshellarg(DB_USER) . "\n";
  echo 'DB_PASSWORD=' . escapeshellarg(DB_PASSWORD) . "\n";
  echo 'DB_HOST=' . escapeshellarg($host) . "\n";
  if ($port !== "") {
    echo 'DB_PORT=' . escapeshellarg($port) . "\n";
  }
  if ($socket !== "") {
    echo 'DB_SOCKET=' . escapeshellarg($socket) . "\n";
  }
') 2>/dev/null
php_status=$?
set -e

if [[ $php_status -ne 0 || -z "$db_exports" ]]; then
  fail "não foi possível obter as credenciais do banco."
fi

eval "$db_exports"

need_cmd mysql
mysql_args=("--host=${DB_HOST}" "--user=${DB_USER}" "--default-character-set=utf8mb4")
[[ -n "${DB_PORT:-}" ]] && mysql_args+=("--port=${DB_PORT}")
[[ -n "${DB_SOCKET:-}" ]] && mysql_args+=("--socket=${DB_SOCKET}")

DB_RESET_DONE=0
if command -v wp >/dev/null 2>&1; then
  log "Resetando banco via WP-CLI..."
  if wp --path="$WP_PATH" db reset --yes >/dev/null 2>&1; then
    DB_RESET_DONE=1
  else
    log "Aviso: falha ao executar wp db reset; tentando via mysql."
  fi
fi

if [[ $DB_RESET_DONE -eq 0 ]]; then
  log "Recriando banco diretamente..."
  MYSQL_PWD="$DB_PASSWORD" mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >/dev/null 2>&1 || fail "falha ao recriar o banco."
fi

log "Importando dump do banco..."
DB_IMPORT_DONE=0
if command -v wp >/dev/null 2>&1; then
  if wp --path="$WP_PATH" db import "$DB_SQL" >/dev/null 2>&1; then
    DB_IMPORT_DONE=1
  else
    log "Aviso: falha ao importar via WP-CLI; tentando via mysql."
  fi
fi

if [[ $DB_IMPORT_DONE -eq 0 ]]; then
  MYSQL_PWD="$DB_PASSWORD" mysql "${mysql_args[@]}" "$DB_NAME" <"$DB_SQL" || fail "falha ao importar dump do banco pelo mysql."
fi

log "Restauração concluída com sucesso."
