#!/usr/bin/env bash
set -euo pipefail

log() {
    local now
    now="$(date '+%d-%m-%Y-%H:%M')"
    printf '[%s] %s\n' "$now" "$1"
}

fail() {
    log "ERROR: $1"
    exit 1
}

REMOTE="${REMOTE:-}"
FILE="${FILE:-}"
WP_PATH="${WP_PATH:-}"
DOWNLOAD_DIR="${DOWNLOAD_DIR:-}"

if [[ -z "$REMOTE" || -z "$FILE" || -z "$WP_PATH" ]]; then
    fail "variáveis REMOTE, FILE ou WP_PATH ausentes"
fi

if ! command -v rclone >/dev/null 2>&1; then
    fail "rclone não encontrado no PATH"
fi

if ! command -v tar >/dev/null 2>&1; then
    fail "tar não encontrado no PATH"
fi

if [[ -z "$DOWNLOAD_DIR" ]]; then
    base_dir="$(dirname "${WP_PATH%/}")"
    DOWNLOAD_DIR="$base_dir/Backups/downloads"
fi

if [[ ! -d "$DOWNLOAD_DIR" ]]; then
    if ! mkdir -p "$DOWNLOAD_DIR"; then
        fail "não foi possível criar diretório de download: $DOWNLOAD_DIR"
    fi
fi

bundle_path="$DOWNLOAD_DIR/$FILE"
remote_path="$REMOTE$FILE"

log "Restoring from $remote_path to $WP_PATH (somente arquivos)"
log "Downloading bundle from Drive..."
if ! rclone copyto "$remote_path" "$bundle_path"; then
    fail "falha no download via rclone"
fi

log "Peeking bundle content..."
if ! tar -tzf "$bundle_path" >"$DOWNLOAD_DIR/.bundle.list"; then
    fail "não foi possível inspecionar o bundle"
fi
cat "$DOWNLOAD_DIR/.bundle.list"

wp_files_tar="$(grep -E '^wp-files-.*\\.tar\\.gz$' "$DOWNLOAD_DIR/.bundle.list" | head -n1 || true)"
db_dump="$(grep -E '^db-.*\\.sql(\\.gz)?$' "$DOWNLOAD_DIR/.bundle.list" | head -n1 || true)"
rm -f "$DOWNLOAD_DIR/.bundle.list"

if [[ -z "$wp_files_tar" ]]; then
    fail "bundle sem wp-files-*.tar.gz"
fi

if [[ -n "$db_dump" ]]; then
    log "Aviso: bundle contém dump do banco, mas rota de restauração parcial foi acionada."
fi

base_backups_dir="$(dirname "$DOWNLOAD_DIR")"
peek_dir="$base_backups_dir/peek"

log "Extracting bundle to $peek_dir..."
rm -rf "$peek_dir"
if ! mkdir -p "$peek_dir"; then
    fail "não foi possível preparar diretório de staging"
fi
if ! tar -xzf "$bundle_path" -C "$peek_dir"; then
    fail "falha ao extrair bundle"
fi

wp_files_path="$peek_dir/$wp_files_tar"
if [[ ! -f "$wp_files_path" ]]; then
    fail "wp-files tar não encontrado após extração"
fi

log "Extraindo arquivos do WordPress..."
files_tmp="$(mktemp -d "$peek_dir/wpfiles.XXXXXX")"
trap 'rm -rf "$files_tmp"' EXIT
if ! tar -xzf "$wp_files_path" -C "$files_tmp"; then
    fail "falha ao extrair wp-files"
fi

# Verifica se há caminhos suspeitos
if tar -tzf "$wp_files_path" | grep -qE '^(/|\.\.)'; then
    fail "tar de arquivos contém caminhos absolutos ou inseguros"
fi

log "Aplicando arquivos no diretório alvo..."
if command -v rsync >/dev/null 2>&1; then
    if ! rsync -a "$files_tmp"/ "$WP_PATH"/; then
        fail "rsync falhou ao copiar arquivos"
    fi
else
    if ! (cd "$files_tmp" && tar -cf - .) | (cd "$WP_PATH" && tar -xf -); then
        fail "fallback de cópia falhou"
    fi
fi

log "Limpeza de temporários..."
rm -rf "$files_tmp"
trap - EXIT

log "Restauração de arquivos concluída com sucesso."
