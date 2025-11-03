#!/bin/bash
set -euo pipefail

log() {
    local ts
    ts=$(date '+%d-%m-%Y-%H:%M')
    echo "[$ts] $1"
}

REMOTE="${REMOTE:-}"
FILE="${FILE:-}"
WP_PATH="${WP_PATH:-}"
DOWNLOAD_DIR="${DOWNLOAD_DIR:-}"
PREFIX="${PREFIX:-}"
DB_REMOTE_DIR="${DB_REMOTE_DIR:-}"
DB_REMOTE_HINT="${DB_REMOTE_HINT:-}"
BUNDLE_PARTS="${BUNDLE_PARTS:-}"
DB_NAME="${DB_NAME:-}"
DB_USER="${DB_USER:-}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_HOSTNAME="${DB_HOSTNAME:-}"
DB_PORT="${DB_PORT:-}"
DB_SOCKET="${DB_SOCKET:-}"

normalize_parts_list() {
    local csv="$1"
    if [[ -z "$csv" ]]; then
        echo ""
        return
    fi

    local compact
    compact=$(echo "$csv" | tr '[:upper:]' '[:lower:]')
    compact=$(echo "$compact" | tr -d '[:space:]')
    echo "$compact"
}

cleanup() {
    if [[ -n "${TMP_DIR:-}" && -d "$TMP_DIR" ]]; then
        rm -rf "$TMP_DIR"
    fi
    if [[ -n "${BUNDLE_LOCAL:-}" && -f "$BUNDLE_LOCAL" ]]; then
        rm -f "$BUNDLE_LOCAL"
    fi
}

trap cleanup EXIT

if [[ -z "$REMOTE" || -z "$FILE" || -z "$WP_PATH" ]]; then
    log "ERRO: variáveis REMOTE, FILE e WP_PATH são obrigatórias."
    exit 1
fi

if [[ ! -d "$WP_PATH" ]]; then
    log "ERRO: diretório do WordPress não encontrado: $WP_PATH"
    exit 1
fi

WORK_ROOT="$DOWNLOAD_DIR"
if [[ -z "$WORK_ROOT" ]]; then
    WORK_ROOT="/tmp"
fi

if ! mkdir -p "$WORK_ROOT"; then
    log "ERRO: não foi possível criar diretório de trabalho: $WORK_ROOT"
    exit 1
fi

BUNDLE_LOCAL="$WORK_ROOT/$FILE"
TMP_DIR="${BUNDLE_LOCAL%.tar.gz}-restore"
rm -rf "$TMP_DIR"
if ! mkdir -p "$TMP_DIR"; then
    log "ERRO: não foi possível preparar diretório temporário: $TMP_DIR"
    exit 1
fi

log "Baixando bundle ${REMOTE}${FILE}..."
if ! rclone copyto "${REMOTE}${FILE}" "$BUNDLE_LOCAL"; then
    log "ERRO: falha ao baixar bundle do remote."
    exit 1
fi

if [[ ! -s "$BUNDLE_LOCAL" ]]; then
    log "ERRO: bundle baixado está vazio: $BUNDLE_LOCAL"
    exit 1
fi

log "Extraindo bundle para $TMP_DIR..."
if ! tar -xzf "$BUNDLE_LOCAL" -C "$TMP_DIR"; then
    log "ERRO: falha ao extrair bundle."
    exit 1
fi

MANIFEST_PATH="$TMP_DIR/manifest.json"
if [[ -f "$MANIFEST_PATH" ]]; then
    log "Manifesto detectado: $MANIFEST_PATH"
fi

find_wp_archive() {
    find "$TMP_DIR" -maxdepth 1 -type f -name 'wp-files-*.tar.gz' | head -n1
}

find_db_dump_local() {
    local dump
    dump=$(find "$TMP_DIR" -maxdepth 1 -type f -name 'db-*.sql*' | head -n1)
    if [[ -n "$dump" ]]; then
        echo "$dump"
        return
    fi
    dump=$(find "$TMP_DIR" -maxdepth 1 -type f -name '*.sql*' | head -n1)
    if [[ -n "$dump" ]]; then
        echo "$dump"
    fi
}

fetch_db_remote() {
    local hint="$DB_REMOTE_HINT"
    local target
    if [[ -n "$hint" ]]; then
        local base
        base=$(basename "$hint")
        target="$TMP_DIR/$base"
        log "Tentando baixar dump informado pelo manifest: ${REMOTE}${hint}"
        if rclone copyto "${REMOTE}${hint}" "$target" 2>/dev/null && [[ -s "$target" ]]; then
            echo "$target"
            return
        fi
        log "Aviso: dump informado no manifest não pôde ser baixado."
    fi

    local ts
    ts=$(echo "$FILE" | grep -oE '[0-9]{8}-[0-9]{6}' | tail -n1 || true)
    if [[ -z "$ts" ]]; then
        return
    fi

    local base="${FILE%.tar.gz}"
    local slug=""
    if [[ -n "$PREFIX" && "${base#$PREFIX}" != "$base" ]]; then
        local trimmed="${base#$PREFIX}"
        slug="${trimmed%-${ts}}"
        if [[ "$slug" == "$trimmed" ]]; then
            slug=""
        fi
    fi

    declare -a candidates=()
    if [[ -n "$slug" ]]; then
        candidates+=("${PREFIX}db-${slug}-${ts}.sql.gz" "${PREFIX}db-${slug}-${ts}.sql.zst" "${PREFIX}db-${slug}-${ts}.sql")
    fi
    if [[ -n "$PREFIX" ]]; then
        candidates+=("${PREFIX}db-${ts}.sql.gz" "${PREFIX}db-${ts}.sql.zst" "${PREFIX}db-${ts}.sql")
    fi
    candidates+=("db-${ts}.sql.gz" "db-${ts}.sql")

    local remoteDir="$DB_REMOTE_DIR"
    for cand in "${candidates[@]}"; do
        target="$TMP_DIR/$cand"
        local remotePath="${remoteDir}${cand}"
        log "Tentando baixar dump candidato: ${REMOTE}${remotePath}"
        if rclone copyto "${REMOTE}${remotePath}" "$target" 2>/dev/null && [[ -s "$target" ]]; then
            log "Dump remoto obtido: $(basename "$target")"
            echo "$target"
            return
        fi
        rm -f "$target"
    done
}

WP_ARCHIVE=$(find_wp_archive)
if [[ -z "$WP_ARCHIVE" ]]; then
    log "ERRO: arquivo wp-files-*.tar.gz não encontrado no bundle."
    exit 1
fi
log "Arquivo de arquivos WP detectado: $WP_ARCHIVE"

DB_DUMP=$(find_db_dump_local)
if [[ -z "$DB_DUMP" ]]; then
    DB_DUMP=$(fetch_db_remote || true)
fi

if [[ -n "$DB_DUMP" ]]; then
    log "Dump de banco localizado: $(basename "$DB_DUMP")"
fi

bundle_has_db=false
if [[ -n "$BUNDLE_PARTS" ]]; then
    bundle_parts_normalized=$(normalize_parts_list "$BUNDLE_PARTS")
    bundle_parts_normalized=",${bundle_parts_normalized},"
    if [[ "$bundle_parts_normalized" == *",db,"* ]]; then
        bundle_has_db=true
    fi
fi

restore_database() {
    local dump="$1"
    if [[ -z "$DB_NAME" ]]; then
        log "Aviso: DB_NAME não informado, pulando restauração do banco."
        return 0
    fi

    local reader=("cat")
    case "$dump" in
        *.sql.gz)
            if command -v pigz >/dev/null 2>&1; then
                reader=("pigz" "-dc")
            else
                reader=("gzip" "-dc")
            fi
            ;;
        *.sql.zst)
            reader=("zstd" "-dc")
            ;;
        *.sql.xz)
            reader=("xz" "-dc")
            ;;
        *.sql.bz2)
            reader=("bzip2" "-dc")
            ;;
        *)
            reader=("cat")
            ;;
    esac

    if ! command -v "${reader[0]}" >/dev/null 2>&1; then
        log "ERRO: comando ${reader[0]} não encontrado para descompactar o dump."
        return 1
    fi

    if ! command -v mysql >/dev/null 2>&1; then
        log "ERRO: comando mysql não encontrado."
        return 1
    fi

    local mysql_cmd=("mysql")
    if [[ -n "$DB_HOSTNAME" ]]; then
        mysql_cmd+=("--host=$DB_HOSTNAME")
    fi
    if [[ -n "$DB_PORT" ]]; then
        mysql_cmd+=("--port=$DB_PORT")
    fi
    if [[ -n "$DB_SOCKET" ]]; then
        mysql_cmd+=("--socket=$DB_SOCKET")
    fi
    if [[ -n "$DB_USER" ]]; then
        mysql_cmd+=("--user=$DB_USER")
    fi
    mysql_cmd+=("$DB_NAME")

    log "Restaurando banco de dados a partir de $(basename "$dump")..."
    export MYSQL_PWD="$DB_PASSWORD"
    if ! "${reader[@]}" "$dump" | "${mysql_cmd[@]}"; then
        log "ERRO: falha ao restaurar o banco de dados."
        return 1
    fi
    log "Banco de dados restaurado com sucesso."
    return 0
}

if [[ -n "$DB_DUMP" ]]; then
    if ! restore_database "$DB_DUMP"; then
        exit 1
    fi
else
    if [[ "$bundle_has_db" == "true" ]]; then
        log "ERRO: dump do banco não localizado, restauração abortada."
        exit 1
    else
        log "Nenhum dump de banco localizado e parte 'db' não solicitada; seguindo sem restaurar banco."
    fi
fi

detect_strip_components() {
    local archive="$1"
    local entries=()
    while IFS= read -r line; do
        entries+=("$line")
    done < <((tar -tzf "$archive" 2>/dev/null || true) | head -n400)

    for entry in "${entries[@]}"; do
        if [[ -z "$entry" ]]; then
            continue
        fi

        local clean="$entry"
        clean="${clean#./}"
        clean="${clean#./}"
        clean="${clean%/}"
        if [[ -z "$clean" ]]; then
            continue
        fi

        IFS='/' read -r -a parts <<< "$clean"
        local idx=0
        for part in "${parts[@]}"; do
            if [[ "$part" == "wp-config.php" ]]; then
                echo "$idx"
                return
            fi
            if [[ "$part" == "wp-admin" ]]; then
                echo "$idx"
                return
            fi
            idx=$((idx + 1))
        done
    done

    echo 0
}

strip_components=$(detect_strip_components "$WP_ARCHIVE")
if [[ -z "$strip_components" || ! "$strip_components" =~ ^[0-9]+$ ]]; then
    strip_components=0
fi

log "Restaurando arquivos do WordPress para $WP_PATH (strip-components=$strip_components)..."
tar_args=("-xzf" "$WP_ARCHIVE" "-C" "$WP_PATH" "--overwrite" "--no-same-owner" "--no-same-permissions")
if (( strip_components > 0 )); then
    tar_args+=("--strip-components=$strip_components")
fi

if ! tar "${tar_args[@]}"; then
    log "ERRO: falha ao extrair arquivos do WordPress."
    exit 1
fi

log "Arquivos restaurados com sucesso."
log "Restauração concluída."
