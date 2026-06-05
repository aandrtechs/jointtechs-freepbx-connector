<?php

namespace FreePBX\modules;

class Jointtechsconnector extends \FreePBX_Helpers implements \BMO
{
    private const CONFIG_KEY = 'JOINTTECHS_CONNECTOR_CONFIG';
    private const MODULE_VERSION = '0.2.1';

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        $this->setConnectorConfig([]);
        return true;
    }

    public function backup()
    {
        return [
            'config' => $this->getConnectorConfig(),
        ];
    }

    public function restore($backup)
    {
        if (isset($backup['config']) && is_array($backup['config'])) {
            $this->setConnectorConfig($backup['config']);
        }
        return true;
    }

    public function doConfigPageInit($page)
    {
        return true;
    }

    public function ajaxRequest($req, &$setting)
    {
        return in_array($req, ['pair'], true);
    }

    public function ajaxHandler()
    {
        try {
            $command = $_REQUEST['command'] ?? '';
            if ($command !== 'pair') {
                return ['status' => false, 'message' => _('Unknown command')];
            }

            return $this->pairWithPortal($_POST['portalUrl'] ?? '', $_POST['pairingCode'] ?? '', $_POST['connectorUrl'] ?? '', $_POST['recordingsPath'] ?? '');
        } catch (\Throwable $exception) {
            return [
                'status' => false,
                'message' => _('Pairing failed. Check the portal URL, pairing code, and outbound HTTPS access, then try again.'),
            ];
        }
    }

    public function runHeartbeatCli()
    {
        return $this->sendHeartbeat();
    }

    public function runCallSyncCli()
    {
        return $this->syncCalls();
    }

    public function runRecordingSyncCli()
    {
        return $this->syncRecordings();
    }

    public function getConnectorConfig()
    {
        $raw = $this->getConfig(self::CONFIG_KEY);
        if (!$raw) {
            return [
                'portalUrl' => '',
                'pbxId' => '',
                'token' => '',
                'actionSecret' => '',
                'connectorUrl' => '',
                'recordingsPath' => '/var/spool/asterisk/monitor',
                'actionNonces' => [],
                'pairedAt' => null,
                'lastHeartbeatAt' => null,
                'lastCallSyncAt' => null,
                'lastRecordingSyncAt' => null,
            ];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setConnectorConfig(array $config)
    {
        $this->setConfig(self::CONFIG_KEY, json_encode($config));
    }

    private function pairWithPortal($portalUrl, $pairingCode, $connectorUrl, $recordingsPath)
    {
        $portalUrl = rtrim(trim((string) $portalUrl), '/');
        $connectorUrl = rtrim(trim((string) $connectorUrl), '/');
        $pairingCode = trim((string) $pairingCode);
        $recordingsPath = rtrim(trim((string) $recordingsPath), '/') ?: '/var/spool/asterisk/monitor';

        if ($portalUrl === '' || !filter_var($portalUrl, FILTER_VALIDATE_URL)) {
            return ['status' => false, 'message' => _('Enter a valid portal URL.')];
        }

        if (!preg_match('/^https:\/\//i', $portalUrl)) {
            return ['status' => false, 'message' => _('Portal URL must use HTTPS.')];
        }

        if ($pairingCode === '') {
            return ['status' => false, 'message' => _('Enter a pairing code.')];
        }

        $payload = [
            'pairingCode' => $pairingCode,
            'name' => php_uname('n') ?: 'FreePBX Box',
            'hostname' => php_uname('n') ?: null,
            'connectorUrl' => $connectorUrl ?: null,
            'recordingsPath' => $recordingsPath,
            'localIp' => $this->getLocalIp(),
            'freepbxVersion' => $this->getFreePbxVersion(),
            'asteriskVersion' => $this->getAsteriskVersion(),
            'moduleVersion' => self::MODULE_VERSION,
            'timezone' => date_default_timezone_get(),
        ];

        $response = $this->postJson($portalUrl . '/api/pbx/pair', $payload);
        if (!$response['ok']) {
            return ['status' => false, 'message' => $this->sanitizeMessage($response['message'])];
        }

        $body = json_decode($response['body'], true);
        if (!is_array($body) || empty($body['pbxId']) || empty($body['token']) || empty($body['actionSecret'])) {
            return ['status' => false, 'message' => _('Portal returned an invalid pairing response.')];
        }

        $config = $this->getConnectorConfig();
        $config['portalUrl'] = $portalUrl;
        $config['pbxId'] = (string) $body['pbxId'];
        $config['token'] = (string) $body['token'];
        $config['actionSecret'] = (string) $body['actionSecret'];
        $config['connectorUrl'] = $connectorUrl;
        $config['recordingsPath'] = $recordingsPath;
        $config['pairedAt'] = gmdate('c');
        $config['moduleVersion'] = self::MODULE_VERSION;
        $this->setConnectorConfig($config);

        return [
            'status' => true,
            'message' => _('PBX paired successfully.'),
            'pbxId' => $config['pbxId'],
        ];
    }

    public function handleInboundAction($body, array $headers)
    {
        try {
            $config = $this->getConnectorConfig();
            if (empty($config['actionSecret']) || !$this->verifyActionSignature($body, $headers, $config)) {
                return $this->jsonResponse(['error' => 'Unauthorized action request.'], 401);
            }
            $decoded = json_decode($body, true);
            if (!is_array($decoded) || empty($decoded['command'])) {
                return $this->jsonResponse(['error' => 'Invalid action request.'], 400);
            }
            $payload = isset($decoded['payload']) && is_array($decoded['payload']) ? $decoded['payload'] : [];
            if ($decoded['command'] === 'heartbeat') return $this->jsonResponse($this->sendHeartbeat());
            if ($decoded['command'] === 'sync_calls') return $this->jsonResponse($this->syncCalls());
            if ($decoded['command'] === 'sync_recordings') return $this->jsonResponse($this->syncRecordings());
            if ($decoded['command'] === 'refresh_recording') return $this->jsonResponse($this->refreshRecording($payload));
            if ($decoded['command'] === 'stream_recording') return $this->streamRecording($payload);
            return $this->jsonResponse(['error' => 'Unsupported action command.'], 400);
        } catch (\Throwable $exception) {
            return $this->jsonResponse(['error' => 'Action failed.'], 500);
        }
    }

    private function verifyActionSignature($body, array $headers, array $config)
    {
        $timestamp = $headers['x-jointtechs-timestamp'] ?? '';
        $nonce = $headers['x-jointtechs-nonce'] ?? '';
        $signature = $headers['x-jointtechs-signature'] ?? '';
        $pbxId = $headers['x-jointtechs-pbx-id'] ?? '';
        if (!$timestamp || !$nonce || !$signature || !$pbxId || $pbxId !== ($config['pbxId'] ?? '')) return false;
        if (abs(time() - (int) $timestamp) > 300) return false;
        $nonces = isset($config['actionNonces']) && is_array($config['actionNonces']) ? $config['actionNonces'] : [];
        $nonces = array_filter($nonces, function ($seenAt) { return (time() - (int) $seenAt) < 600; });
        if (isset($nonces[$nonce])) return false;
        $expected = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $config['actionSecret']);
        if (!hash_equals($expected, $signature)) return false;
        $nonces[$nonce] = time();
        $config['actionNonces'] = $nonces;
        $this->setConnectorConfig($config);
        return true;
    }

    private function sendHeartbeat()
    {
        $config = $this->getConnectorConfig();
        $payload = [
            'hostname' => php_uname('n') ?: null,
            'localIp' => $this->getLocalIp(),
            'freepbxVersion' => $this->getFreePbxVersion(),
            'asteriskVersion' => $this->getAsteriskVersion(),
            'moduleVersion' => self::MODULE_VERSION,
            'connectorUrl' => $config['connectorUrl'] ?? null,
            'recordingsPath' => $config['recordingsPath'] ?? '/var/spool/asterisk/monitor',
            'timezone' => date_default_timezone_get(),
            'diskUsagePercent' => $this->diskUsagePercent('/'),
            'recordingDiskUsagePercent' => $this->diskUsagePercent($config['recordingsPath'] ?? '/var/spool/asterisk/monitor'),
        ];
        $this->postPortal('/api/pbx/heartbeat', $payload);
        $config['lastHeartbeatAt'] = gmdate('c');
        $this->setConnectorConfig($config);
        return ['ok' => true, 'heartbeat' => $payload];
    }

    private function syncCalls()
    {
        $config = $this->getConnectorConfig();
        $calls = $this->readRecentCdrRows($config);
        $this->postPortal('/api/pbx/sync/calls', ['calls' => $calls]);
        $config['lastCallSyncAt'] = gmdate('c');
        $this->setConnectorConfig($config);
        return ['ok' => true, 'count' => count($calls), 'message' => 'CDR rows synced.'];
    }

    private function syncRecordings()
    {
        $config = $this->getConnectorConfig();
        $path = $config['recordingsPath'] ?? '/var/spool/asterisk/monitor';
        $recordings = [];
        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (count($recordings) >= 200) break;
                if ($file->isFile() && preg_match('/\.(wav|mp3|gsm)$/i', $file->getFilename())) {
                    $recordings[] = ['filePath' => $file->getPathname(), 'fileName' => $file->getFilename(), 'fileSizeBytes' => $file->getSize(), 'format' => strtolower($file->getExtension()), 'recordingStartedAt' => gmdate('c', $file->getMTime())];
                }
            }
        }
        $this->postPortal('/api/pbx/sync/recordings', ['recordings' => $recordings]);
        $config['lastRecordingSyncAt'] = gmdate('c');
        $this->setConnectorConfig($config);
        return ['ok' => true, 'count' => count($recordings)];
    }

    private function refreshRecording(array $payload)
    {
        $path = $this->safeRecordingPath($payload['filePath'] ?? '');
        return ['ok' => (bool) ($path && is_file($path)), 'available' => (bool) ($path && is_file($path)), 'fileSizeBytes' => $path && is_file($path) ? filesize($path) : null, 'lastVerifiedAt' => gmdate('c')];
    }

    private function streamRecording(array $payload)
    {
        $path = $this->safeRecordingPath($payload['filePath'] ?? '');
        if (!$path || !is_file($path) || !is_readable($path)) return $this->jsonResponse(['error' => 'Recording not available.'], 404);
        header('Content-Type: ' . $this->recordingContentType($path));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        return true;
    }

    private function safeRecordingPath($path)
    {
        $config = $this->getConnectorConfig();
        $base = realpath($config['recordingsPath'] ?? '/var/spool/asterisk/monitor');
        $real = realpath((string) $path);
        if (!$base || !$real || strpos($real, $base) !== 0) return null;
        return $real;
    }

    private function readRecentCdrRows(array $config)
    {
        $pdo = $this->getCdrPdo();
        $columns = $this->getCdrColumns($pdo);
        if (empty($columns) || !in_array('calldate', $columns, true)) {
            return [];
        }

        $wanted = ['calldate', 'clid', 'src', 'dst', 'dcontext', 'channel', 'dstchannel', 'lastapp', 'lastdata', 'duration', 'billsec', 'disposition', 'amaflags', 'accountcode', 'uniqueid', 'userfield', 'recordingfile', 'cnum', 'cnam', 'outbound_cnum', 'outbound_cnam', 'did', 'linkedid', 'peeraccount', 'sequence'];
        $selected = array_values(array_intersect($wanted, $columns));
        $selectSql = implode(', ', array_map(function ($column) { return '`' . str_replace('`', '', $column) . '`'; }, $selected));
        $since = $this->cdrSince($config);
        $stmt = $pdo->prepare("SELECT {$selectSql} FROM cdr WHERE calldate >= :since ORDER BY calldate DESC LIMIT 250");
        $stmt->execute(['since' => $since]);

        $calls = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $calls[] = $this->mapCdrRow($row, $config);
        }
        return $calls;
    }

    private function getCdrPdo()
    {
        global $amp_conf;
        $host = $amp_conf['AMPDBHOST'] ?? 'localhost';
        $user = $amp_conf['AMPDBUSER'] ?? 'freepbxuser';
        $pass = $amp_conf['AMPDBPASS'] ?? '';
        $database = $amp_conf['CDRDBNAME'] ?? 'asteriskcdrdb';
        $dsn = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4';
        return new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    }

    private function getCdrColumns(\PDO $pdo)
    {
        $stmt = $pdo->query('SHOW COLUMNS FROM cdr');
        $columns = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (!empty($row['Field'])) $columns[] = $row['Field'];
        }
        return $columns;
    }

    private function cdrSince(array $config)
    {
        if (!empty($config['lastCallSyncAt'])) {
            $time = strtotime((string) $config['lastCallSyncAt']);
            if ($time) return gmdate('Y-m-d H:i:s', $time - 3600);
        }
        return gmdate('Y-m-d H:i:s', time() - 86400 * 7);
    }

    private function mapCdrRow(array $row, array $config)
    {
        $recordingFile = $row['recordingfile'] ?? null;
        $recordingPath = $recordingFile ? $this->findRecordingPath($recordingFile, $row['calldate'] ?? null, $config) : null;
        return [
            'asteriskUniqueId' => $row['uniqueid'] ?? null,
            'linkedId' => $row['linkedid'] ?? null,
            'calldate' => $row['calldate'] ?? null,
            'clid' => $row['clid'] ?? null,
            'src' => $row['src'] ?? null,
            'dst' => $row['dst'] ?? null,
            'did' => $row['did'] ?? null,
            'dcontext' => $row['dcontext'] ?? null,
            'channel' => $row['channel'] ?? null,
            'dstchannel' => $row['dstchannel'] ?? null,
            'lastapp' => $row['lastapp'] ?? null,
            'lastdata' => $row['lastdata'] ?? null,
            'duration' => isset($row['duration']) ? (int) $row['duration'] : null,
            'billsec' => isset($row['billsec']) ? (int) $row['billsec'] : null,
            'disposition' => $row['disposition'] ?? null,
            'cnum' => $row['cnum'] ?? null,
            'cnam' => $row['cnam'] ?? null,
            'outbound_cnum' => $row['outbound_cnum'] ?? null,
            'outbound_cnam' => $row['outbound_cnam'] ?? null,
            'recordingAvailable' => (bool) ($recordingFile || $recordingPath),
            'recordingFile' => $recordingFile,
            'recordingFilePath' => $recordingPath,
            'rawCdr' => $row,
        ];
    }

    private function findRecordingPath($recordingFile, $calldate, array $config)
    {
        $recordingFile = trim((string) $recordingFile);
        if ($recordingFile === '') return null;
        if ($recordingFile[0] === '/' && is_file($recordingFile)) return $recordingFile;
        $base = rtrim($config['recordingsPath'] ?? '/var/spool/asterisk/monitor', '/');
        $candidates = [$base . '/' . $recordingFile];
        $timestamp = $calldate ? strtotime((string) $calldate) : false;
        if ($timestamp) {
            $candidates[] = $base . '/' . gmdate('Y/m/d', $timestamp) . '/' . $recordingFile;
            $candidates[] = $base . '/' . gmdate('Y/m', $timestamp) . '/' . $recordingFile;
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) return $candidate;
        }
        return $candidates[0];
    }

    private function getLocalIp()
    {
        $hostname = php_uname('n');
        $ip = $hostname ? gethostbyname($hostname) : null;
        return $ip && $ip !== $hostname ? $ip : null;
    }

    private function postPortal($path, array $payload)
    {
        $config = $this->getConnectorConfig();
        if (empty($config['portalUrl']) || empty($config['pbxId']) || empty($config['token'])) throw new \RuntimeException('Connector is not paired.');
        return $this->postJsonWithHeaders(rtrim($config['portalUrl'], '/') . $path, $payload, ['Authorization: Bearer ' . $config['token'], 'X-PBX-ID: ' . $config['pbxId']]);
    }

    private function postJsonWithHeaders($url, array $payload, array $headers)
    {
        $json = json_encode($payload);
        $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $body = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $statusCode >= 400) throw new \RuntimeException('Portal request failed.');
        return $body;
    }

    private function jsonResponse(array $payload, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload);
        return true;
    }

    private function diskUsagePercent($path)
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        return $total ? (int) round((($total - $free) / $total) * 100) : null;
    }

    private function recordingContentType($path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'mp3') return 'audio/mpeg';
        if ($ext === 'gsm') return 'audio/gsm';
        return 'audio/wav';
    }

    private function postJson($url, array $payload)
    {
        $json = json_encode($payload);
        if ($json === false) {
            return ['ok' => false, 'message' => _('Could not encode pairing request.'), 'body' => ''];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $error = curl_error($ch);
            $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($body === false) {
                return ['ok' => false, 'message' => sprintf(_('Pairing request failed: %s'), $this->sanitizeMessage($error ?: _('unknown error'))), 'body' => ''];
            }

            return $this->formatPortalResponse($statusCode, $body);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $json,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $statusCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $statusCode = (int) $matches[1];
        }
        if ($body === false) {
            return ['ok' => false, 'message' => _('Pairing request failed.'), 'body' => ''];
        }

        return $this->formatPortalResponse($statusCode, $body);
    }

    private function formatPortalResponse($statusCode, $body)
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return ['ok' => true, 'message' => '', 'body' => $body];
        }

        $decoded = json_decode($body, true);
        $message = is_array($decoded) && !empty($decoded['error'])
            ? $this->sanitizeMessage((string) $decoded['error'])
            : sprintf(_('Portal pairing failed with HTTP %d.'), $statusCode);

        return ['ok' => false, 'message' => $message, 'body' => $body];
    }

    private function sanitizeMessage($message)
    {
        $message = (string) $message;
        $message = preg_replace('/(token|authorization|pairingCode|pairing_code)(["\']?\s*[:=]\s*["\']?)[^"\'\s,}]+/i', '$1$2[redacted]', $message);
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $message);
        return $message ?: _('Pairing failed.');
    }

    private function getFreePbxVersion()
    {
        if (isset($this->FreePBX->Config)) {
            $version = $this->FreePBX->Config->get('AMPVERSION');
            if (!empty($version)) {
                return (string) $version;
            }
        }
        return null;
    }

    private function getAsteriskVersion()
    {
        $version = null;
        if (function_exists('exec')) {
            @exec('asterisk -rx "core show version" 2>/dev/null', $output, $code);
            if ($code === 0 && !empty($output[0]) && preg_match('/Asterisk\s+([^\s]+)/', $output[0], $matches)) {
                $version = $matches[1];
            }
        }
        return $version;
    }
}
