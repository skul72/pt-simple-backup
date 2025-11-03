#!/usr/bin/env php
<?php
declare(strict_types=1);

function ptsb_log(string $message): void {
    fwrite(STDOUT, sprintf('[%s] %s%s', date('d-m-Y-H:i'), $message, PHP_EOL));
}

function ptsb_fail(string $message, int $code = 1): void {
    ptsb_log('ERROR: ' . $message);
    exit($code);
}

function ptsb_env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function ptsb_ensure_dir(string $path): void {
    if ($path === '') {
        return;
    }
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Falha ao criar diretório: ' . $path);
    }
}

function ptsb_run(string $command, bool $throw = true): array {
    $output = [];
    $code   = 0;
    exec($command, $output, $code);
    if ($code !== 0 && $throw) {
        throw new RuntimeException(sprintf('Comando falhou (%d): %s%s%s', $code, $command, PHP_EOL, implode(PHP_EOL, $output)));
    }
    return [$output, $code];
}

function ptsb_find_binary(string $binary): string {
    $command = 'command -v ' . escapeshellarg($binary) . ' 2>/dev/null';
    $path = trim((string) shell_exec($command));
    if ($path === '') {
        throw new RuntimeException('Binário não encontrado: ' . $binary);
    }
    return $path;
}

function ptsb_parse_timestamp(string $filename): ?string {
    if (preg_match('/(\d{8}-\d{6})/', $filename, $m)) {
        return $m[1];
    }
    return null;
}

function ptsb_parse_db_host(string $host): array {
    $host = trim($host);
    $out = [
        'host'   => $host !== '' ? $host : 'localhost',
        'port'   => null,
        'socket' => null,
    ];

    if ($host === '') {
        $out['host'] = 'localhost';
        return $out;
    }

    if ($host[0] === '[' && ($pos = strpos($host, ']')) !== false) {
        $out['host'] = substr($host, 1, $pos - 1);
        $rest = substr($host, $pos + 1);
        if (strpos($rest, ':') === 0) {
            $maybe = substr($rest, 1);
            if ($maybe !== '' && ctype_digit($maybe)) {
                $out['port'] = (int) $maybe;
            }
        }
        return $out;
    }

    $parts = explode(':', $host, 2);
    if (count($parts) < 2) {
        $out['host'] = $host;
        return $out;
    }

    $out['host'] = $parts[0] === '' ? 'localhost' : $parts[0];
    $rest = $parts[1];
    if ($rest === '') {
        return $out;
    }

    if (ctype_digit($rest)) {
        $out['port'] = (int) $rest;
        return $out;
    }

    if (strpos($rest, '/') !== false) {
        $out['socket'] = $rest;
        return $out;
    }

    if (ctype_digit($rest)) {
        $out['port'] = (int) $rest;
    }

    return $out;
}

function ptsb_decompress_gzip(string $source, string $target): void {
    $in = @gzopen($source, 'rb');
    if ($in === false) {
        throw new RuntimeException('Falha ao abrir gzip: ' . $source);
    }
    $out = @fopen($target, 'wb');
    if ($out === false) {
        @gzclose($in);
        throw new RuntimeException('Falha ao criar arquivo temporário: ' . $target);
    }

    while (!gzeof($in)) {
        $chunk = gzread($in, 8192);
        if ($chunk === false) {
            @fclose($out);
            @gzclose($in);
            throw new RuntimeException('Erro ao ler gzip: ' . $source);
        }
        if (fwrite($out, $chunk) === false) {
            @fclose($out);
            @gzclose($in);
            throw new RuntimeException('Erro ao gravar SQL temporário: ' . $target);
        }
    }

    @fclose($out);
    @gzclose($in);
}

function ptsb_import_database(string $mysqlBin, array $creds, string $sqlFile): void {
    $cmd = [
        $mysqlBin,
        '--user=' . $creds['user'],
    ];

    if ($creds['pass'] !== '') {
        $cmd[] = '--password=' . $creds['pass'];
    }

    if ($creds['socket'] !== null && $creds['socket'] !== '') {
        $cmd[] = '--socket=' . $creds['socket'];
    } else {
        $cmd[] = '--host=' . $creds['host'];
        if ($creds['port'] !== null) {
            $cmd[] = '--port=' . (string) $creds['port'];
        }
    }

    $cmd[] = '--default-character-set=utf8mb4';
    $cmd[] = $creds['name'];

    $descriptors = [
        0 => ['file', $sqlFile, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Falha ao executar mysql.');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $msg = trim($stderr !== '' ? $stderr : $stdout);
        throw new RuntimeException('mysql retornou código ' . $exitCode . ($msg !== '' ? ': ' . $msg : ''));
    }
}

$remote = ptsb_env('REMOTE');
$file   = ptsb_env('FILE');
$wpPath = ptsb_env('WP_PATH');
$downloadDirEnv = ptsb_env('DOWNLOAD_DIR');
$dbDumpDir = ptsb_env('DB_DUMP_REMOTE_DIR', 'db-dumps');

if ($remote === null || $remote === '') {
    ptsb_fail('REMOTE não informado.');
}
if ($file === null || $file === '') {
    ptsb_fail('FILE não informado.');
}
if ($wpPath === null || $wpPath === '') {
    ptsb_fail('WP_PATH não informado.');
}

$realWpPath = realpath($wpPath);
if ($realWpPath === false) {
    ptsb_fail('WP_PATH inválido: ' . $wpPath);
}

$baseDir = dirname($realWpPath);
$backupsDir = $baseDir . DIRECTORY_SEPARATOR . 'Backups';
$downloadDir = $downloadDirEnv && $downloadDirEnv !== '' ? $downloadDirEnv : ($backupsDir . DIRECTORY_SEPARATOR . 'downloads');
$tmpRoot = $backupsDir . DIRECTORY_SEPARATOR . 'restore-' . date('Ymd-His') . '-' . getmypid();

$exitCode = 0;

try {
    ptsb_ensure_dir($backupsDir);
    ptsb_ensure_dir($downloadDir);
    ptsb_ensure_dir($tmpRoot);

    $rclone = ptsb_find_binary('rclone');
    $tar    = ptsb_find_binary('tar');
    $mysql  = ptsb_find_binary('mysql');

    $bundleLocal = $tmpRoot . DIRECTORY_SEPARATOR . basename($file);

    ptsb_log(sprintf('Restaurando de %s%s para %s', $remote, $file, $realWpPath));
    ptsb_log('Baixando pacote do Drive...');
    ptsb_run(sprintf('%s copyto %s %s', escapeshellarg($rclone), escapeshellarg($remote . $file), escapeshellarg($bundleLocal)));

    ptsb_log('Inspecionando conteúdo do pacote...');
    [$listOutput, $listCode] = ptsb_run(sprintf('%s -tzf %s', escapeshellarg($tar), escapeshellarg($bundleLocal)));
    if ($listCode !== 0) {
        throw new RuntimeException('Falha ao inspecionar o pacote: ' . $bundleLocal);
    }

    $filesArchive = null;
    $dbArchive    = null;
    foreach ($listOutput as $entry) {
        $entry = trim($entry);
        if ($entry === '' || substr($entry, -1) === '/') {
            continue;
        }
        if ($filesArchive === null && preg_match('/wp-files-.*\.tar\.gz$/', $entry)) {
            $filesArchive = $entry;
        }
        if ($dbArchive === null && preg_match('/\.sql\.gz$/', $entry)) {
            $dbArchive = $entry;
        }
    }

    if ($filesArchive === null) {
        throw new RuntimeException('Pacote sem wp-files-*.tar.gz');
    }

    ptsb_log('Extraindo pacote temporariamente...');
    ptsb_run(sprintf('%s -xzf %s -C %s', escapeshellarg($tar), escapeshellarg($bundleLocal), escapeshellarg($tmpRoot)));

    $filesArchivePath = $tmpRoot . DIRECTORY_SEPARATOR . $filesArchive;
    if (!is_file($filesArchivePath)) {
        throw new RuntimeException('Arquivo de arquivos não encontrado após extração: ' . $filesArchive);
    }

    $dbArchivePath = null;
    if ($dbArchive !== null) {
        $maybe = $tmpRoot . DIRECTORY_SEPARATOR . $dbArchive;
        if (is_file($maybe)) {
            $dbArchivePath = $maybe;
        }
    }

    if ($dbArchivePath === null) {
        $stamp = ptsb_parse_timestamp($file);
        if ($stamp !== null) {
            $trimmedDir = trim((string) $dbDumpDir, '/');
            $dbRemoteDir = $trimmedDir !== '' ? ($trimmedDir . '/') : '';
            $searchPath = $remote . $dbRemoteDir;
            ptsb_log('Dump SQL não encontrado no pacote. Buscando em ' . $searchPath . '...');
            [$lsOutput, $lsCode] = ptsb_run(sprintf('%s lsf %s --files-only --format "p" --include %s', escapeshellarg($rclone), escapeshellarg($searchPath), escapeshellarg('*' . $stamp . '*.sql.gz')), false);
            if ($lsCode === 0 && !empty($lsOutput)) {
                $candidate = trim($lsOutput[0]);
                if ($candidate !== '') {
                    $remoteDbFile = $searchPath . $candidate;
                    $localDbFile  = $tmpRoot . DIRECTORY_SEPARATOR . basename($candidate);
                    ptsb_log('Baixando dump correspondente: ' . $candidate);
                    ptsb_run(sprintf('%s copyto %s %s', escapeshellarg($rclone), escapeshellarg($remoteDbFile), escapeshellarg($localDbFile)));
                    if (is_file($localDbFile)) {
                        $dbArchivePath = $localDbFile;
                    }
                }
            }
        }
    }

    if ($dbArchivePath === null) {
        throw new RuntimeException('bundle sem arquivo .sql.gz correspondente.');
    }

    $sqlPath = $tmpRoot . DIRECTORY_SEPARATOR . 'restore.sql';
    ptsb_log('Descompactando dump do banco...');
    ptsb_decompress_gzip($dbArchivePath, $sqlPath);

    ptsb_log('Carregando credenciais do WordPress...');
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    define('WP_USE_THEMES', false);
    require $realWpPath . '/wp-load.php';
    global $wpdb;
    if (!isset($wpdb)) {
        throw new RuntimeException('wpdb não disponível após carregar o WordPress.');
    }

    $dbInfo = [
        'name'   => (string) $wpdb->dbname,
        'user'   => (string) $wpdb->dbuser,
        'pass'   => (string) $wpdb->dbpassword,
        'host'   => (string) $wpdb->dbhost,
    ];

    $parsed = ptsb_parse_db_host($dbInfo['host']);
    $creds = [
        'name'   => $dbInfo['name'],
        'user'   => $dbInfo['user'],
        'pass'   => $dbInfo['pass'],
        'host'   => $parsed['host'],
        'port'   => $parsed['port'],
        'socket' => $parsed['socket'],
    ];

    ptsb_log('Restaurando arquivos do WordPress...');
    ptsb_run(sprintf('%s -xzf %s -C %s', escapeshellarg($tar), escapeshellarg($filesArchivePath), escapeshellarg($realWpPath)));

    ptsb_log('Importando banco de dados...');
    ptsb_import_database($mysql, $creds, $sqlPath);

    ptsb_log('Restauração concluída com sucesso.');
    $exitCode = 0;
} catch (Throwable $e) {
    ptsb_log('ERROR: ' . $e->getMessage());
    $exitCode = 1;
}

if (is_dir($tmpRoot)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $path) {
        /** @var SplFileInfo $path */
        if ($path->isDir()) {
            @rmdir($path->getPathname());
        } else {
            @unlink($path->getPathname());
        }
    }
    @rmdir($tmpRoot);
}

exit($exitCode);
