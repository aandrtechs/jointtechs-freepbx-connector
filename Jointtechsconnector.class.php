<?php

namespace FreePBX\modules;

class Jointtechsconnector extends \FreePBX_Helpers implements \BMO
{
    private const CONFIG_KEY = 'JOINTTECHS_CONNECTOR_CONFIG';
    private const MODULE_VERSION = '0.1.2';

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

            return $this->pairWithPortal($_POST['portalUrl'] ?? '', $_POST['pairingCode'] ?? '');
        } catch (\Throwable $exception) {
            return [
                'status' => false,
                'message' => _('Pairing failed. Check the portal URL, pairing code, and outbound HTTPS access, then try again.'),
            ];
        }
    }

    public function getConnectorConfig()
    {
        $raw = $this->getConfig(self::CONFIG_KEY);
        if (!$raw) {
            return [
                'portalUrl' => '',
                'pbxId' => '',
                'token' => '',
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

    private function pairWithPortal($portalUrl, $pairingCode)
    {
        $portalUrl = rtrim(trim((string) $portalUrl), '/');
        $pairingCode = trim((string) $pairingCode);

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
        if (!is_array($body) || empty($body['pbxId']) || empty($body['token'])) {
            return ['status' => false, 'message' => _('Portal returned an invalid pairing response.')];
        }

        $config = $this->getConnectorConfig();
        $config['portalUrl'] = $portalUrl;
        $config['pbxId'] = (string) $body['pbxId'];
        $config['token'] = (string) $body['token'];
        $config['pairedAt'] = gmdate('c');
        $config['moduleVersion'] = self::MODULE_VERSION;
        $this->setConnectorConfig($config);

        return [
            'status' => true,
            'message' => _('PBX paired successfully.'),
            'pbxId' => $config['pbxId'],
        ];
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
