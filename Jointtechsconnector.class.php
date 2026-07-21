<?php

namespace FreePBX\modules;

class Jointtechsconnector extends \FreePBX_Helpers implements \BMO
{
    private const CONFIG_KEY = 'JOINTTECHS_CONNECTOR_CONFIG';
    private const MODULE_VERSION = '1.1.0';
    private const DEFAULT_PORTAL_URL = 'https://portal.joint.tech';
    private const FORWARD_MARKER_FAMILY = 'JOINTTECHS_CF_EXPIRY';

    public function install()
    {
        try {
            $this->autoRegisterWithPortal();
            $this->ensureSyncCron();
        } catch (\Throwable $exception) {
            // Installation should not fail if outbound HTTPS is temporarily unavailable.
        }
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
        try {
            $this->autoRegisterWithPortal();
            $this->ensureSyncCron();
        } catch (\Throwable $exception) {
            // The config page can still render if registration is not available yet.
        }
        return true;
    }

    public function ajaxRequest($req, &$setting)
    {
        return in_array($req, ['pair', 'action'], true);
    }

    public function ajaxHandler()
    {
        try {
            $command = $_REQUEST['command'] ?? '';
            if ($command === 'action') {
                return $this->handleAjaxAction(file_get_contents('php://input'), $this->requestHeaders());
            }

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

    private function handleAjaxAction($body, array $headers)
    {
        $result = $this->executeInboundAction((string) $body, $headers, false);
        return is_array($result) ? $result : ['status' => false, 'message' => _('Action failed.')];
    }

    public function runHeartbeatCli()
    {
        $this->autoRegisterWithPortal();
        $this->ensureSyncCron();
        $this->reconcileTemporaryCallForwards();
        return $this->sendHeartbeat();
    }

    public function runCallSyncCli()
    {
        $this->autoRegisterWithPortal();
        $this->reconcileTemporaryCallForwards();
        return $this->syncCalls();
    }

    public function runTemporaryForwardExpiryCli($extension, $generation)
    {
        return $this->expireTemporaryCallForward($extension, $generation);
    }

    public function runRecordingSyncCli()
    {
        $this->autoRegisterWithPortal();
        return $this->syncRecordings();
    }

    public function runInventorySyncCli()
    {
        $this->autoRegisterWithPortal();
        return $this->syncInventory();
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
        $portalUrl = rtrim(trim((string) $portalUrl), '/') ?: self::DEFAULT_PORTAL_URL;
        $connectorUrl = rtrim(trim((string) $connectorUrl), '/') ?: $this->inferConnectorUrl();
        $pairingCode = trim((string) $pairingCode);
        $recordingsPath = rtrim(trim((string) $recordingsPath), '/') ?: '/var/spool/asterisk/monitor';

        if ($portalUrl === '' || !filter_var($portalUrl, FILTER_VALIDATE_URL)) {
            return ['status' => false, 'message' => _('Enter a valid portal URL.')];
        }

        if (!preg_match('/^https:\/\//i', $portalUrl)) {
            return ['status' => false, 'message' => _('Portal URL must use HTTPS.')];
        }

        if ($connectorUrl && rtrim($connectorUrl, '/') === rtrim($portalUrl, '/')) {
            return ['status' => false, 'message' => _('Connector URL must be the PBX URL, not the portal URL.')];
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

    private function autoRegisterWithPortal()
    {
        $config = $this->getConnectorConfig();
        if (!empty($config['pbxId']) && !empty($config['token']) && !empty($config['actionSecret'])) {
            return $config;
        }

        $portalUrl = rtrim($config['portalUrl'] ?? self::DEFAULT_PORTAL_URL, '/') ?: self::DEFAULT_PORTAL_URL;
        $recordingsPath = $this->detectRecordingsPath($config);
        $connectorUrl = rtrim($config['connectorUrl'] ?? '', '/') ?: $this->inferConnectorUrl();
        $payload = $this->systemPayload($connectorUrl, $recordingsPath);
        $response = $this->postJson($portalUrl . '/api/pbx/register', $payload);
        if (!$response['ok']) {
            throw new \RuntimeException('Auto-registration failed.');
        }
        $body = json_decode($response['body'], true);
        if (!is_array($body) || empty($body['pbxId']) || empty($body['token']) || empty($body['actionSecret'])) {
            throw new \RuntimeException('Portal returned an invalid registration response.');
        }

        $config['portalUrl'] = $portalUrl;
        $config['pbxId'] = (string) $body['pbxId'];
        $config['token'] = (string) $body['token'];
        $config['actionSecret'] = (string) $body['actionSecret'];
        $config['connectorUrl'] = $connectorUrl;
        $config['recordingsPath'] = $recordingsPath;
        $config['registeredAt'] = gmdate('c');
        $config['moduleVersion'] = self::MODULE_VERSION;
        $this->setConnectorConfig($config);
        $this->ensureSyncCron();
        return $config;
    }

    private function ensureSyncCron()
    {
        $moduleDir = __DIR__;
        $calls = $moduleDir . '/bin/sync-calls.php';
        $recordings = $moduleDir . '/bin/sync-recordings.php';
        $inventory = $moduleDir . '/bin/sync-inventory.php';
        $heartbeat = $moduleDir . '/bin/heartbeat.php';
        $forwardExpiry = $moduleDir . '/bin/expire-forward.php';
        @chmod($calls, 0755);
        @chmod($recordings, 0755);
        @chmod($inventory, 0755);
        @chmod($heartbeat, 0755);
        @chmod($forwardExpiry, 0755);
        $cron = "* * * * * asterisk php {$calls} >/dev/null 2>&1\n"
            . "* * * * * asterisk php {$recordings} >/dev/null 2>&1\n"
            . "13 2 * * * asterisk php {$inventory} >/dev/null 2>&1\n"
            . "17 * * * * asterisk php {$heartbeat} >/dev/null 2>&1\n";
        @file_put_contents('/etc/cron.d/jointtechsconnector', $cron);
        @chmod('/etc/cron.d/jointtechsconnector', 0644);
    }

    private function systemPayload($connectorUrl, $recordingsPath)
    {
        return [
            'name' => php_uname('n') ?: 'FreePBX Box',
            'hostname' => php_uname('n') ?: null,
            'connectorUrl' => $connectorUrl ?: null,
            'recordingsPath' => $recordingsPath,
            'localIp' => $this->getLocalIp(),
            'freepbxVersion' => $this->getFreePbxVersion(),
            'asteriskVersion' => $this->getAsteriskVersion(),
            'moduleVersion' => self::MODULE_VERSION,
            'timezone' => date_default_timezone_get(),
            'cdrColumns' => implode(',', $this->safeCdrColumns()),
        ];
    }

    public function handleInboundAction($body, array $headers)
    {
        $result = $this->executeInboundAction((string) $body, $headers, true);
        if ($result === true) return true;
        $payload = is_array($result) ? $result : ['status' => false, 'error' => 'Action failed.'];
        return $this->jsonResponse($payload, !empty($payload['status']) ? 200 : 400);
    }

    private function executeInboundAction($body, array $headers, $allowStreaming)
    {
        try {
            $config = $this->getConnectorConfig();
            if (empty($config['actionSecret']) || !$this->verifyActionSignature($body, $headers, $config)) {
                return ['status' => false, 'error' => 'Unauthorized action request.'];
            }
            $decoded = json_decode($body, true);
            if (!is_array($decoded) || empty($decoded['command'])) {
                return ['status' => false, 'error' => 'Invalid action request.'];
            }
            $payload = isset($decoded['payload']) && is_array($decoded['payload']) ? $decoded['payload'] : [];
            if ($decoded['command'] === 'heartbeat') return $this->actionSuccess($this->sendHeartbeat());
            if ($decoded['command'] === 'run_task') return $this->actionSuccess($this->runAgentTask($payload));
            if ($decoded['command'] === 'self_update') return $this->actionSuccess($this->selfUpdate($payload));
            if ($decoded['command'] === 'sync_calls') return $this->actionSuccess($this->syncCalls());
            if ($decoded['command'] === 'sync_recordings') return $this->actionSuccess($this->syncRecordings());
            if ($decoded['command'] === 'refresh_recording') return $this->actionSuccess($this->refreshRecording($payload));
            if ($decoded['command'] === 'fetch_recording') return $this->actionSuccess($this->fetchRecording($payload));
            if ($decoded['command'] === 'stream_recording' && $allowStreaming) return $this->streamRecording($payload);
            return ['status' => false, 'error' => 'Unsupported action command.'];
        } catch (\Throwable $exception) {
            return ['status' => false, 'error' => $this->sanitizeMessage($exception->getMessage()) ?: 'Action failed.'];
        }
    }

    private function actionSuccess(array $payload)
    {
        if (!array_key_exists('status', $payload)) {
            $payload['status'] = true;
        }
        return $payload;
    }

    private function requestHeaders()
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        return $headers;
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
        $recordingsPath = $this->detectRecordingsPath($config);
        $payload = [
            'hostname' => php_uname('n') ?: null,
            'localIp' => $this->getLocalIp(),
            'freepbxVersion' => $this->getFreePbxVersion(),
            'asteriskVersion' => $this->getAsteriskVersion(),
            'moduleVersion' => self::MODULE_VERSION,
            'connectorUrl' => $config['connectorUrl'] ?? null,
            'recordingsPath' => $recordingsPath,
            'timezone' => date_default_timezone_get(),
            'diskUsagePercent' => $this->diskUsagePercent('/'),
            'recordingDiskUsagePercent' => $this->diskUsagePercent($recordingsPath),
            'cdrColumns' => $this->safeCdrColumns(),
            'capabilities' => ['faxTier' => $this->detectFaxTier(), 'webrtc' => $this->detectWebRtcCapability()],
        ];
        $this->postPortal('/api/pbx/heartbeat', $payload);
        $config['lastHeartbeatAt'] = gmdate('c');
        $config['recordingsPath'] = $recordingsPath;
        $this->setConnectorConfig($config);
        return ['ok' => true, 'heartbeat' => $payload];
    }

    private function runAgentTask(array $payload)
    {
        $command = $payload['command'] ?? '';
        $permission = $payload['permission'] ?? 'read';
        $input = isset($payload['input']) && is_array($payload['input']) ? $payload['input'] : [];
        if (!in_array($permission, ['read', 'approved_fix', 'self_update'], true)) {
            return ['status' => false, 'errorCode' => 'permission_invalid', 'error' => 'Invalid task permission.'];
        }
        if (in_array($command, ['set_temp_call_forward', 'clear_temp_call_forward', 'supervise_call', 'update_extension_settings', 'update_fax_route', 'send_fax', 'originate_call'], true) && $permission !== 'approved_fix') {
            return ['status' => false, 'errorCode' => 'permission_denied', 'error' => 'This task requires approved fix permission.'];
        }
        if ($permission !== 'read' && $command !== 'self_update' && !in_array($command, ['set_temp_call_forward', 'clear_temp_call_forward', 'supervise_call', 'update_extension_settings', 'update_fax_route', 'send_fax', 'originate_call'], true)) {
            return ['status' => false, 'errorCode' => 'write_denied', 'error' => 'Only allowlisted approved fixes are supported.'];
        }
        if ($command === 'discover_system') return ['ok' => true, 'discovery' => $this->discoverSystem()];
        if ($command === 'sync_inventory') return $this->syncInventory();
        if ($command === 'sync_calls_dynamic') return $this->syncCallsDynamic($input);
        if ($command === 'sync_recordings_deep') return $this->syncRecordingsDeep($input);
        if ($command === 'tail_log') return $this->tailApprovedLog($input);
        if ($command === 'list_path') return $this->listApprovedPath($input);
        if ($command === 'fetch_file') return $this->fetchApprovedFile($input);
        if ($command === 'command_probe') return $this->runCommandProbe($input);
        if ($command === 'get_extension_settings') return $this->getExtensionSettings($input);
        if ($command === 'update_extension_settings') return $this->updateExtensionSettings($input);
        if ($command === 'update_fax_route') return $this->updateFaxRoute($input);
        if ($command === 'send_fax') return $this->sendFax($input);
        if ($command === 'originate_call') return $this->originateCall($input);
        if ($command === 'set_temp_call_forward') return $this->setTempCallForward($input);
        if ($command === 'clear_temp_call_forward') return $this->clearTempCallForward($input);
        if ($command === 'supervise_call') return $this->superviseCall($input);
        if ($command === 'self_update') return $this->selfUpdate($input);
        return ['status' => false, 'errorCode' => 'task_unknown', 'error' => 'Unsupported agent task.'];
    }

    private function getExtensionSettings(array $input)
    {
        $extension = preg_replace('/[^0-9*#]/', '', (string)($input['extension'] ?? ''));
        if ($extension === '') return ['status' => false, 'error' => 'Extension is required.'];
        $pdo = $this->getAmpPdo();
        $user = null;
        foreach ($this->queryRows($pdo, 'users', ['extension', 'name', 'outboundcid']) as $row) {
            if ((string)($row['extension'] ?? '') === $extension) { $user = $row; break; }
        }
        if (!$user) return ['status' => false, 'error' => 'Extension was not found.'];
        $voicemail = $this->getVoicemailSettings($pdo, $extension);
        return [
            'status' => true, 'name' => (string)($user['name'] ?? $extension),
            'outboundCid' => (string)($user['outboundcid'] ?? ''),
            'voicemailEnabled' => $voicemail !== null,
            'voicemailPassword' => (string)($voicemail['password'] ?? ''),
            'voicemailEmail' => (string)($voicemail['email'] ?? ''),
            'emailAttachment' => $this->booleanValue($voicemail['emailAttachment'] ?? $voicemail['attach'] ?? true),
            'deleteAfterEmail' => $this->booleanValue($voicemail['deleteAfterEmail'] ?? $voicemail['delete'] ?? false),
        ];
    }

    private function updateExtensionSettings(array $input)
    {
        $extension = preg_replace('/[^0-9*#]/', '', (string)($input['extension'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $password = preg_replace('/\D/', '', (string)($input['voicemailPassword'] ?? ''));
        $email = trim((string)($input['voicemailEmail'] ?? ''));
        $enabled = $this->booleanValue($input['voicemailEnabled'] ?? false);
        if ($extension === '' || $name === '') return ['status' => false, 'error' => 'Extension and name are required.'];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['status' => false, 'error' => 'Voicemail email is invalid.'];
        if ($password !== '' && (strlen($password) < 3 || strlen($password) > 20)) return ['status' => false, 'error' => 'Voicemail password must contain 3 to 20 digits.'];
        $pdo = $this->getAmpPdo();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('UPDATE users SET name = :name WHERE extension = :extension');
            $statement->execute(['name' => $name, 'extension' => $extension]);
            $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE extension = :extension');
            $check->execute(['extension' => $extension]);
            if (!(int)$check->fetchColumn()) throw new \RuntimeException('Extension was not found.');
            $this->saveVoicemailSettings(
                $pdo,
                $extension,
                $name,
                $password,
                $email,
                $enabled,
                $this->booleanValue($input['emailAttachment'] ?? true),
                $this->booleanValue($input['deleteAfterEmail'] ?? false)
            );
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['status' => false, 'error' => $this->sanitizeMessage($exception->getMessage())];
        }
        $this->reloadFreePbx();
        return ['status' => true, 'message' => 'Extension and voicemail settings updated.', 'extension' => $extension];
    }

    private function voicemailModule()
    {
        try {
            if (!class_exists('\FreePBX')) return null;
            $module = \FreePBX::Voicemail();
            return is_object($module) && method_exists($module, 'getMailbox') ? $module : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }

    private function normalizeVoicemailMailbox(array $mailbox)
    {
        $options = $mailbox['options'] ?? [];
        if (is_string($options)) {
            $parsed = [];
            foreach (explode('|', $options) as $option) {
                $parts = explode('=', $option, 2);
                if (count($parts) === 2) $parsed[trim($parts[0])] = trim($parts[1]);
            }
            $options = $parsed;
        }
        if (!is_array($options)) $options = [];
        return [
            'name' => (string)($mailbox['name'] ?? ''),
            'password' => (string)($mailbox['pwd'] ?? $mailbox['password'] ?? ''),
            'email' => (string)($mailbox['email'] ?? ''),
            'pager' => (string)($mailbox['pager'] ?? ''),
            'emailAttachment' => $this->booleanValue($options['attach'] ?? $mailbox['attach'] ?? true),
            'deleteAfterEmail' => $this->booleanValue($options['delete'] ?? $mailbox['delete'] ?? false),
            'vmcontext' => (string)($mailbox['vmcontext'] ?? $mailbox['context'] ?? 'default'),
            'options' => $options,
        ];
    }

    private function getVoicemailSettings(\PDO $pdo, $extension)
    {
        $module = $this->voicemailModule();
        if ($module) {
            $mailbox = $module->getMailbox($extension, false);
            if (is_array($mailbox) && !empty($mailbox)) return $this->normalizeVoicemailMailbox($mailbox);
        }
        foreach ($this->queryRows($pdo, 'voicemail_users', ['extension', 'name', 'password', 'email', 'attach', 'delete', 'context', 'options', 'pager']) as $row) {
            if ((string)($row['extension'] ?? '') === (string)$extension) return $this->normalizeVoicemailMailbox($row);
        }
        return null;
    }

    private function saveVoicemailSettings(\PDO $pdo, $extension, $name, $password, $email, $enabled, $emailAttachment, $deleteAfterEmail)
    {
        $module = $this->voicemailModule();
        if ($module) {
            $existing = $module->getMailbox($extension, false);
            if (!$enabled) {
                if (is_array($existing) && !empty($existing)) $module->delMailbox($extension, false);
                return;
            }
            if (is_array($existing) && !empty($existing)) {
                $settings = $existing;
                $settings['pwd'] = $password !== '' ? $password : (string)($existing['pwd'] ?? $extension);
                $settings['name'] = $name;
                $settings['email'] = $email;
                $settings['pager'] = (string)($existing['pager'] ?? '');
                $settings['vmcontext'] = (string)($existing['vmcontext'] ?? 'default');
                $options = isset($existing['options']) && is_array($existing['options']) ? $existing['options'] : [];
                $options['attach'] = $emailAttachment ? 'yes' : 'no';
                $options['delete'] = $deleteAfterEmail ? 'yes' : 'no';
                $settings['options'] = $options;
                $module->updateMailbox($extension, $settings, false);
            } else {
                $module->addMailbox($extension, [
                    'vm' => 'enabled',
                    'vmcontext' => 'default',
                    'vmpwd' => $password !== '' ? $password : $extension,
                    'name' => $name,
                    'email' => $email,
                    'pager' => '',
                    'options' => '',
                    'attach' => $emailAttachment ? 'attach=yes' : 'no',
                    'vmdelete' => $deleteAfterEmail ? 'delete=yes' : 'no',
                ], false);
            }
            return;
        }

        if (!$enabled) {
            $statement = $pdo->prepare('DELETE FROM voicemail_users WHERE extension = :extension');
            $statement->execute(['extension' => $extension]);
            return;
        }
        $columns = $this->tableColumns($pdo, 'voicemail_users');
        $values = ['context' => 'default', 'extension' => $extension, 'name' => $name, 'password' => $password ?: $extension, 'email' => $email, 'attach' => $emailAttachment ? 'yes' : 'no', 'delete' => $deleteAfterEmail ? 'yes' : 'no'];
        $values = array_intersect_key($values, array_flip($columns));
        $names = array_keys($values);
        $sqlColumns = implode(', ', $names);
        $placeholders = implode(', ', array_map(function ($column) { return ':' . $column; }, $names));
        $updates = implode(', ', array_map(function ($column) { return $column . ' = VALUES(' . $column . ')'; }, array_diff($names, ['extension', 'context'])));
        $statement = $pdo->prepare("INSERT INTO voicemail_users ({$sqlColumns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updates}");
        $statement->execute($values);
    }
    private function emailRecipientsFromValue($value)
    {
        $emails = preg_split('/[,;|]+/', (string)$value);
        return array_values(array_unique(array_filter(array_map(function ($email) {
            $email = strtolower(trim((string)$email));
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }, $emails))));
    }

    private function faxUsers(\PDO $pdo)
    {
        $users = [];
        foreach ($this->queryRows($pdo, 'fax_users', ['user', 'faxenabled', 'faxemail', 'faxattachformat']) as $row) {
            $user = trim((string)($row['user'] ?? ''));
            if ($user !== '') $users[$user] = $row;
        }
        return $users;
    }

    private function faxDestinationUser($value, array $users)
    {
        foreach (preg_split('/[^0-9*#]+/', (string)$value) as $candidate) {
            if ($candidate !== '' && isset($users[$candidate])) return $candidate;
        }
        return null;
    }

    private function faxRecipientsFromRow(array $row, array $users)
    {
        $recipients = [];
        foreach (['faxemail', 'legacy_email'] as $key) {
            if (!empty($row[$key])) $recipients = array_merge($recipients, $this->emailRecipientsFromValue($row[$key]));
        }
        foreach (['faxexten', 'destination'] as $key) {
            $user = $this->faxDestinationUser($row[$key] ?? '', $users);
            if ($user && !empty($users[$user]['faxemail'])) $recipients = array_merge($recipients, $this->emailRecipientsFromValue($users[$user]['faxemail']));
        }
        return array_values(array_unique($recipients));
    }

    private function discoverFaxRoutes(\PDO $pdo, array $incomingRows, $tier)
    {
        $routes = [];
        $users = $this->faxUsers($pdo);
        $faxIncoming = $this->queryRows($pdo, 'fax_incoming', ['cidnum', 'extension', 'detection', 'detectionwait', 'destination', 'legacy_email', 'ring']);

        foreach ($faxIncoming as $row) {
            $number = preg_replace('/\D/', '', (string)($row['extension'] ?? ''));
            if (strlen($number) < 10 || strlen($number) > 11) continue;
            $recipients = $this->faxRecipientsFromRow($row, $users);
            $routes[$number] = array_merge($row, ['tier' => $tier, 'recipients' => $tier === 'regular' ? array_slice($recipients, 0, 1) : $recipients]);
        }

        foreach ($incomingRows as $row) {
            $number = preg_replace('/\D/', '', (string)($row['extension'] ?? ''));
            if (strlen($number) < 10 || strlen($number) > 11) continue;
            $matched = null;
            foreach ($faxIncoming as $faxRow) {
                if ((string)($faxRow['extension'] ?? '') === (string)($row['extension'] ?? '') && (string)($faxRow['cidnum'] ?? '') === (string)($row['cidnum'] ?? '')) {
                    $matched = $faxRow;
                    break;
                }
            }
            $combined = array_merge($row, is_array($matched) ? $matched : []);
            $hasFaxDestination = stripos((string)($combined['destination'] ?? ''), 'fax') !== false
                || trim((string)($combined['faxexten'] ?? '')) !== ''
                || trim((string)($combined['faxemail'] ?? '')) !== ''
                || trim((string)($combined['legacy_email'] ?? '')) !== '';
            if (!$hasFaxDestination && !isset($routes[$number])) continue;
            $recipients = array_merge($routes[$number]['recipients'] ?? [], $this->faxRecipientsFromRow($combined, $users));
            $recipients = array_values(array_unique($recipients));
            $routes[$number] = array_merge($combined, ['tier' => $tier, 'recipients' => $tier === 'regular' ? array_slice($recipients, 0, 1) : $recipients]);
        }
        return $routes;
    }

    private function updateFaxRoute(array $input)
    {
        $number = preg_replace('/\D/', '', (string)($input['number'] ?? ''));
        $recipients = isset($input['recipients']) && is_array($input['recipients']) ? array_values(array_unique(array_filter(array_map('trim', $input['recipients'])))) : [];
        $tier = $this->detectFaxTier();
        if (strlen($number) < 10 || strlen($number) > 11 || empty($recipients)) return ['status' => false, 'error' => 'Fax number and recipient are required.'];
        foreach ($recipients as $email) if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['status' => false, 'error' => 'A fax recipient email is invalid.'];
        if ($tier !== 'pro' && count($recipients) !== 1) return ['status' => false, 'error' => 'Fax Regular supports exactly one recipient.'];

        $pdo = $this->getAmpPdo();
        $emailValue = implode(',', $recipients);
        $updated = false;
        $users = $this->faxUsers($pdo);
        $faxIncoming = $this->queryRows($pdo, 'fax_incoming', ['cidnum', 'extension', 'destination', 'legacy_email']);
        foreach ($faxIncoming as $row) {
            if (preg_replace('/\D/', '', (string)($row['extension'] ?? '')) !== $number) continue;
            $user = $this->faxDestinationUser($row['destination'] ?? '', $users);
            if ($user && $this->tableExists($pdo, 'fax_users') && in_array('faxemail', $this->tableColumns($pdo, 'fax_users'), true)) {
                $statement = $pdo->prepare('UPDATE fax_users SET faxemail = :email WHERE user = :user');
                $statement->execute(['email' => $emailValue, 'user' => $user]);
                $updated = $statement->rowCount() > 0 || isset($users[$user]);
            }
            if (in_array('legacy_email', $this->tableColumns($pdo, 'fax_incoming'), true)) {
                $statement = $pdo->prepare('UPDATE fax_incoming SET legacy_email = :email WHERE extension = :number AND cidnum = :cidnum');
                $statement->execute(['email' => $emailValue, 'number' => $row['extension'], 'cidnum' => (string)($row['cidnum'] ?? '')]);
                $updated = true;
            }
        }

        foreach ($this->queryRows($pdo, 'incoming', ['cidnum', 'extension', 'destination', 'faxexten']) as $row) {
            if (preg_replace('/\D/', '', (string)($row['extension'] ?? '')) !== $number) continue;
            foreach ([$row['faxexten'] ?? '', $row['destination'] ?? ''] as $destination) {
                $user = $this->faxDestinationUser($destination, $users);
                if (!$user || !$this->tableExists($pdo, 'fax_users') || !in_array('faxemail', $this->tableColumns($pdo, 'fax_users'), true)) continue;
                $statement = $pdo->prepare('UPDATE fax_users SET faxemail = :email WHERE user = :user');
                $statement->execute(['email' => $emailValue, 'user' => $user]);
                $updated = true;
            }
        }

        if (!$updated && $this->tableExists($pdo, 'incoming')) {
            $columns = $this->tableColumns($pdo, 'incoming');
            if (in_array('faxemail', $columns, true) && in_array('extension', $columns, true)) {
                $statement = $pdo->prepare('UPDATE incoming SET faxemail = :email WHERE extension = :number');
                $statement->execute(['email' => $emailValue, 'number' => $number]);
                $updated = $statement->rowCount() > 0;
            }
        }
        if (!$updated) return ['status' => false, 'error' => 'Fax route was not found.'];

        $this->reloadFreePbx();
        return ['status' => true, 'message' => 'Incoming fax recipients updated.', 'tier' => $tier, 'recipientCount' => count($recipients)];
    }
    private function sendFax(array $input)
    {
        if ($this->detectFaxTier() !== 'pro') return ['status' => false, 'error' => 'Sending requires Fax Pro.'];
        $from = preg_replace('/\D/', '', (string)($input['from'] ?? ''));
        $to = preg_replace('/\D/', '', (string)($input['to'] ?? ''));
        $content = base64_decode((string)($input['contentBase64'] ?? ''), true);
        $extension = strtolower(pathinfo((string)($input['fileName'] ?? 'fax.pdf'), PATHINFO_EXTENSION));
        if (!in_array($extension, ['pdf', 'tif', 'tiff', 'jpg', 'jpeg', 'png'], true)) return ['status' => false, 'error' => 'Fax document type is not supported.'];
        if (!$content || strlen($content) > 25 * 1024 * 1024 || strlen($from) < 10 || strlen($to) < 10) return ['status' => false, 'error' => 'Fax payload is invalid.'];
        $directory = '/var/spool/asterisk/fax/jointtechs';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true)) return ['status' => false, 'error' => 'Fax spool directory could not be created.'];
        $document = $directory . '/' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        if (@file_put_contents($document, $content, LOCK_EX) === false) return ['status' => false, 'error' => 'Fax document could not be written.'];
        $result = $this->queueCallFile(['Channel: Local/' . $to . '@from-internal/n', 'CallerID: <' . $from . '>', 'MaxRetries: 2', 'RetryTime: 60', 'WaitTime: 60', 'Application: SendFAX', 'Data: ' . $document, 'Archive: yes'], 'fax');
        if (!$result['status']) @unlink($document);
        $result['jobId'] = basename($document);
        return $result;
    }

    private function originateCall(array $input)
    {
        $source = preg_replace('/[^0-9*#]/', '', (string)($input['sourceExtension'] ?? ''));
        $destination = preg_replace('/\D/', '', (string)($input['destination'] ?? ''));
        $callerId = preg_replace('/\D/', '', (string)($input['callerId'] ?? ''));
        if ($source === '' || $destination === '' || strlen($destination) > 11) return ['status' => false, 'error' => 'Call source or destination is invalid.'];
        return $this->queueCallFile(['Channel: Local/' . $source . '@from-internal/n', 'CallerID: <' . ($callerId ?: $source) . '>', 'MaxRetries: 0', 'RetryTime: 30', 'WaitTime: 45', 'Context: from-internal', 'Extension: ' . $destination, 'Priority: 1', 'Setvar: JOINTTECHS_PORTAL_CALL=1', 'Archive: yes'], 'call');
    }

    private function queueCallFile(array $lines, $prefix)
    {
        $temporary = tempnam('/tmp', 'jtc_' . preg_replace('/[^a-z]/', '', (string)$prefix) . '_');
        if (!$temporary || @file_put_contents($temporary, implode("\n", $lines) . "\n", LOCK_EX) === false) return ['status' => false, 'error' => 'Could not create Asterisk call file.'];
        @chmod($temporary, 0640);
        $target = '/var/spool/asterisk/outgoing/' . basename($temporary) . '.call';
        if (!@rename($temporary, $target)) { @unlink($temporary); return ['status' => false, 'error' => 'Could not queue the Asterisk call file.']; }
        return ['status' => true, 'message' => 'Call queued.', 'callId' => basename($target)];
    }

    private function detectFaxTier()
    {
        if (!function_exists('exec')) return 'regular';
        $output = [];
        @exec('fwconsole ma list 2>/dev/null', $output, $code);
        return $code === 0 && preg_match('/^\s*faxpro\s+.*Enabled/im', implode("\n", $output)) ? 'pro' : 'regular';
    }

    private function detectWebRtcCapability()
    {
        if (!function_exists('exec')) return false;
        $output = [];
        @exec('asterisk -rx "http show status" 2>/dev/null', $output, $code);
        return $code === 0 && stripos(implode("\n", $output), 'Enabled and Bound') !== false;
    }

    private function booleanValue($value)
    {
        if (is_bool($value)) return $value;
        return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'true', 'on', 'enabled'], true);
    }

    private function reloadFreePbx()
    {
        if (!function_exists('exec')) return;
        @exec('fwconsole reload >/dev/null 2>&1', $output, $code);
    }

    private function setTempCallForward(array $input)
    {
        if (!function_exists('exec')) {
            return ['status' => false, 'errorCode' => 'exec_unavailable', 'error' => 'Asterisk command execution is not available.'];
        }

        $extension = preg_replace('/[^0-9*#]/', '', (string)($input['extension'] ?? ''));
        $destination = preg_replace('/[^0-9+*#]/', '', (string)($input['destination'] ?? ''));
        $untilRemoved = filter_var($input['untilRemoved'] ?? ($input['permanent'] ?? false), FILTER_VALIDATE_BOOLEAN);
        $ttlSeconds = (int)($input['ttlSeconds'] ?? 3600);
        $ttlSeconds = $untilRemoved ? 0 : max(60, min(31536000, $ttlSeconds));

        if (strlen($extension) < 2 || strlen($extension) > 24) {
            return ['status' => false, 'errorCode' => 'extension_invalid', 'error' => 'Extension is not valid.'];
        }
        if (strlen($destination) < 2 || strlen($destination) > 24 || (strpos($destination, '+') !== false && strpos($destination, '+') !== 0)) {
            return ['status' => false, 'errorCode' => 'destination_invalid', 'error' => 'Forward destination is not valid.'];
        }

        $lock = $this->acquireTemporaryForwardLock($extension);
        if (!$lock) {
            return ['status' => false, 'errorCode' => 'forward_lock_failed', 'error' => 'Could not lock call forwarding for this extension.'];
        }

        try {
            $generation = $this->newTemporaryForwardGeneration();
            $expiresAtEpoch = $untilRemoved ? 0 : time() + $ttlSeconds;
            $markerState = $untilRemoved ? 'permanent' : 'timed';
            $putCommand = 'asterisk -rx ' . escapeshellarg('database put CF ' . $extension . ' ' . $destination);
            $output = [];
            @exec($putCommand . ' 2>&1', $output, $code);
            if ($code !== 0) {
                return ['status' => false, 'errorCode' => 'asterisk_failed', 'error' => 'Could not set call forwarding.', 'stdout' => implode("\n", $output)];
            }

            if (!$this->writeTemporaryForwardMarker($extension, $generation, $expiresAtEpoch, $destination, $markerState)) {
                $rolledBack = $this->deleteAsteriskDatabaseValue('CF', $extension);
                return [
                    'status' => false,
                    'errorCode' => 'forward_marker_failed',
                    'error' => $rolledBack
                        ? 'Could not persist the call forwarding expiry marker.'
                        : 'Could not persist the call forwarding expiry marker or roll back call forwarding.',
                ];
            }

            $expiresAt = null;
            if (!$untilRemoved) {
                $expiryScript = __DIR__ . '/bin/expire-forward.php';
                $expiryCommand = 'sleep ' . (int)$ttlSeconds
                    . '; php ' . escapeshellarg($expiryScript)
                    . ' ' . escapeshellarg($extension)
                    . ' ' . escapeshellarg($generation);
                @exec('sh -c ' . escapeshellarg($expiryCommand) . ' >/dev/null 2>&1 &');
                $expiresAt = gmdate('c', $expiresAtEpoch);
            }

            return [
                'ok' => true,
                'message' => ($untilRemoved ? 'Call forwarding' : 'Temporary call forwarding') . ' set for extension ' . $extension . '.',
                'extension' => $extension,
                'destination' => $destination,
                'ttlSeconds' => $ttlSeconds,
                'expiresAt' => $expiresAt,
                'untilRemoved' => $untilRemoved,
                'permanent' => $untilRemoved,
            ];
        } finally {
            $this->releaseTemporaryForwardLock($lock);
        }
    }

    private function clearTempCallForward(array $input)
    {
        if (!function_exists('exec')) {
            return ['status' => false, 'errorCode' => 'exec_unavailable', 'error' => 'Asterisk command execution is not available.'];
        }

        $extension = preg_replace('/[^0-9*#]/', '', (string)($input['extension'] ?? ''));
        if (strlen($extension) < 2 || strlen($extension) > 24) {
            return ['status' => false, 'errorCode' => 'extension_invalid', 'error' => 'Extension is not valid.'];
        }

        $lock = $this->acquireTemporaryForwardLock($extension);
        if (!$lock) {
            return ['status' => false, 'errorCode' => 'forward_lock_failed', 'error' => 'Could not lock call forwarding for this extension.'];
        }

        try {
            $generation = $this->newTemporaryForwardGeneration();
            if (!$this->writeTemporaryForwardMarker($extension, $generation, 0, '', 'cleared')) {
                return ['status' => false, 'errorCode' => 'forward_marker_failed', 'error' => 'Could not invalidate the call forwarding expiry marker.'];
            }

            $command = 'asterisk -rx ' . escapeshellarg('database del CF ' . $extension);
            $output = [];
            @exec($command . ' 2>&1', $output, $code);
            $stdout = implode("\n", $output);
            $notFound = stripos($stdout, 'Database entry not found') !== false || stripos($stdout, 'not found') !== false;
            if ($code !== 0 && !$notFound) {
                return ['status' => false, 'errorCode' => 'asterisk_failed', 'error' => 'Could not remove call forwarding.', 'stdout' => $stdout];
            }

            return [
                'ok' => true,
                'message' => 'Call forwarding removed for extension ' . $extension . '.',
                'extension' => $extension,
                'cleared' => true,
            ];
        } finally {
            $this->releaseTemporaryForwardLock($lock);
        }
    }

    private function reconcileTemporaryCallForwards()
    {
        if (!function_exists('exec')) return ['checked' => 0, 'expired' => 0];
        $markers = $this->listTemporaryForwardMarkers();
        $expired = 0;
        foreach ($markers as $extension => $marker) {
            if (($marker['state'] ?? '') !== 'timed' || (int)($marker['expiresAt'] ?? 0) <= 0 || (int)$marker['expiresAt'] > time()) {
                continue;
            }
            $result = $this->expireTemporaryCallForward($extension, (string)($marker['generation'] ?? ''));
            if (!empty($result['expired'])) $expired++;
        }
        return ['checked' => count($markers), 'expired' => $expired];
    }

    private function expireTemporaryCallForward($extension, $generation)
    {
        if (!function_exists('exec')) return ['ok' => false, 'expired' => false, 'reason' => 'exec_unavailable'];
        $extension = preg_replace('/[^0-9*#]/', '', (string)$extension);
        $generation = strtolower(trim((string)$generation));
        if (strlen($extension) < 2 || strlen($extension) > 24 || !preg_match('/^[a-f0-9]{64}$/', $generation)) {
            return ['ok' => false, 'expired' => false, 'reason' => 'invalid_marker'];
        }

        $lock = $this->acquireTemporaryForwardLock($extension);
        if (!$lock) return ['ok' => false, 'expired' => false, 'reason' => 'lock_failed'];

        try {
            $marker = $this->readTemporaryForwardMarker($extension);
            if (!$marker || !hash_equals((string)$marker['generation'], $generation)) {
                return ['ok' => true, 'expired' => false, 'reason' => 'stale_generation'];
            }
            if (($marker['state'] ?? '') !== 'timed' || (int)$marker['expiresAt'] <= 0) {
                return ['ok' => true, 'expired' => false, 'reason' => 'not_timed'];
            }
            if ((int)$marker['expiresAt'] > time()) {
                return ['ok' => true, 'expired' => false, 'reason' => 'not_due'];
            }

            $currentDestination = $this->readAsteriskDatabaseValue('CF', $extension);
            if ($currentDestination !== null && $currentDestination === (string)$marker['destination']) {
                if (!$this->deleteAsteriskDatabaseValue('CF', $extension)) {
                    return ['ok' => false, 'expired' => false, 'reason' => 'forward_clear_failed'];
                }
            }

            $completedGeneration = $this->newTemporaryForwardGeneration();
            if (!$this->writeTemporaryForwardMarker($extension, $completedGeneration, 0, '', 'expired')) {
                return ['ok' => false, 'expired' => false, 'reason' => 'marker_finalize_failed'];
            }

            return ['ok' => true, 'expired' => true, 'extension' => $extension];
        } finally {
            $this->releaseTemporaryForwardLock($lock);
        }
    }

    private function newTemporaryForwardGeneration()
    {
        try {
            return hash('sha256', random_bytes(32));
        } catch (\Throwable $exception) {
            return hash('sha256', uniqid((string)mt_rand(), true));
        }
    }

    private function writeTemporaryForwardMarker($extension, $generation, $expiresAt, $destination, $state)
    {
        $value = strtolower((string)$generation)
            . '|' . (int)$expiresAt
            . '|' . rawurlencode((string)$destination)
            . '|' . strtolower((string)$state);
        $command = 'asterisk -rx ' . escapeshellarg('database put ' . self::FORWARD_MARKER_FAMILY . ' ' . $extension . ' ' . $value);
        $output = [];
        @exec($command . ' 2>&1', $output, $code);
        return $code === 0;
    }

    private function readTemporaryForwardMarker($extension)
    {
        $value = $this->readAsteriskDatabaseValue(self::FORWARD_MARKER_FAMILY, $extension);
        return $value === null ? null : $this->parseTemporaryForwardMarker($value);
    }

    private function listTemporaryForwardMarkers()
    {
        $command = 'asterisk -rx ' . escapeshellarg('database show ' . self::FORWARD_MARKER_FAMILY);
        $output = [];
        @exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) return [];

        $markers = [];
        $prefix = '/' . self::FORWARD_MARKER_FAMILY . '/';
        foreach ($output as $line) {
            $line = trim((string)$line);
            if (strpos($line, $prefix) !== 0 || strpos($line, ':') === false) continue;
            list($key, $value) = array_map('trim', explode(':', $line, 2));
            $extension = substr($key, strlen($prefix));
            if ($extension === '' || preg_match('/[^0-9*#]/', $extension)) continue;
            $marker = $this->parseTemporaryForwardMarker($value);
            if ($marker) $markers[$extension] = $marker;
        }
        return $markers;
    }

    private function parseTemporaryForwardMarker($value)
    {
        $parts = explode('|', trim((string)$value), 4);
        if (count($parts) !== 4 || !preg_match('/^[a-f0-9]{64}$/', $parts[0]) || !preg_match('/^[0-9]+$/', $parts[1])) return null;
        $state = strtolower($parts[3]);
        if (!in_array($state, ['timed', 'permanent', 'cleared', 'expired'], true)) return null;
        return [
            'generation' => $parts[0],
            'expiresAt' => (int)$parts[1],
            'destination' => rawurldecode($parts[2]),
            'state' => $state,
        ];
    }

    private function readAsteriskDatabaseValue($family, $key)
    {
        $command = 'asterisk -rx ' . escapeshellarg('database get ' . $family . ' ' . $key);
        $output = [];
        @exec($command . ' 2>&1', $output, $code);
        if ($code !== 0) return null;
        foreach ($output as $line) {
            if (preg_match('/^\s*Value:\s*(.*?)\s*$/i', (string)$line, $matches)) {
                return (string)$matches[1];
            }
        }
        return null;
    }

    private function deleteAsteriskDatabaseValue($family, $key)
    {
        $command = 'asterisk -rx ' . escapeshellarg('database del ' . $family . ' ' . $key);
        $output = [];
        @exec($command . ' 2>&1', $output, $code);
        $stdout = implode("\n", $output);
        $notFound = stripos($stdout, 'Database entry not found') !== false || stripos($stdout, 'not found') !== false;
        return $code === 0 || $notFound;
    }

    private function acquireTemporaryForwardLock($extension)
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'jointtechsconnector-forward-' . hash('sha256', (string)$extension) . '.lock';
        $handle = @fopen($path, 'c');
        if (!$handle) return null;
        if (!@flock($handle, LOCK_EX)) {
            @fclose($handle);
            return null;
        }
        return $handle;
    }

    private function releaseTemporaryForwardLock($handle)
    {
        if (!is_resource($handle)) return;
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function superviseCall(array $input)
    {
        if (!function_exists('exec')) {
            return ['status' => false, 'errorCode' => 'exec_unavailable', 'error' => 'Asterisk command execution is not available.'];
        }

        $mode = strtolower((string)($input['mode'] ?? 'listen'));
        if (!in_array($mode, ['listen', 'whisper', 'barge'], true)) {
            return ['status' => false, 'errorCode' => 'mode_invalid', 'error' => 'Supervision mode is not supported.'];
        }

        $supervisor = preg_replace('/[^0-9*#]/', '', (string)($input['supervisorExtension'] ?? ''));
        $target = preg_replace('/[^0-9*#]/', '', (string)($input['targetExtension'] ?? ''));
        if (!$supervisor || !$target) {
            return ['status' => false, 'errorCode' => 'extension_invalid', 'error' => 'Supervisor or target extension is invalid.'];
        }

        $options = ['listen' => 'qsE', 'whisper' => 'qwsE', 'barge' => 'qBsE'][$mode];
        $exactTarget = $this->findActiveSpyTarget($target);
        $spyBase = $exactTarget && !empty($exactTarget['channel']) ? $this->channelSpyBase($exactTarget['channel']) : null;
        $attempts = $this->supervisionAttempts($supervisor, $target, $spyBase, $options);
        $stdout = '';
        $code = 1;
        $accepted = false;
        $spyChannel = 'Local/' . $supervisor . '@from-internal/n';
        $spyTarget = $target . '@from-internal';
        $spyOptions = $options;

        foreach ($attempts as $attempt) {
            $spyChannel = $attempt['channel'];
            $spyTarget = $attempt['target'];
            $spyOptions = $attempt['options'];
            $originate = 'channel originate ' . $spyChannel . ' application ChanSpy ' . $spyTarget . ',' . $spyOptions;
            $command = 'asterisk -rx ' . escapeshellarg($originate);
            $output = [];
            @exec($command . ' 2>&1', $output, $code);
            $stdout = implode("\n", $output);
            $accepted = $this->supervisionCommandAccepted($code, $stdout);
            if ($accepted) {
                break;
            }
        }

        return [
            'status' => $accepted,
            'message' => $accepted ? ucfirst($mode) . ' call queued to extension ' . $supervisor . '.' : ucfirst($mode) . ' action failed.',
            'stdout' => $stdout,
            'error' => $accepted ? null : $stdout,
            'deliveryStatus' => $accepted ? 'queued' : 'failed',
            'mode' => $mode,
            'supervisorExtension' => $supervisor,
            'targetExtension' => $target,
            'spyApplication' => 'ChanSpy',
            'spyChannel' => $spyChannel,
            'spyTarget' => $spyTarget,
            'exactTargetFound' => (bool) $exactTarget,
        ];
    }

    private function supervisionCommandAccepted($code, $stdout)
    {
        if ((int)$code !== 0) return false;
        $failureMarkers = [
            'no such application', 'not found', 'no such channel',
            'unable to create channel', 'unable to request channel',
            'extension does not exist', 'does not exist in context',
        ];
        foreach ($failureMarkers as $marker) {
            if (stripos((string)$stdout, $marker) !== false) return false;
        }
        return true;
    }

    private function supervisionAttempts($supervisor, $target, $spyBase, $options)
    {
        $attempts = [];
        $targets = array_values(array_filter([$spyBase, 'PJSIP/' . $target, 'SIP/' . $target]));
        $supervisorChannels = ['Local/' . $supervisor . '@from-internal/n', 'PJSIP/' . $supervisor, 'SIP/' . $supervisor];
        foreach ($supervisorChannels as $channel) {
            foreach ($targets as $targetChannel) {
                $attempts[] = ['channel' => $channel, 'target' => $targetChannel, 'options' => $options];
            }
        }

        $seen = [];
        return array_values(array_filter($attempts, function ($attempt) use (&$seen) {
            $key = $attempt['channel'] . ':' . $attempt['target'] . ':' . $attempt['options'];
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
            return true;
        }));
    }

    private function channelSpyBase($channel)
    {
        $channel = trim((string)$channel);
        if ($channel === '') return null;
        $position = strrpos($channel, '-');
        if ($position === false || $position <= 0) return $channel;
        return substr($channel, 0, $position);
    }

    private function findActiveSpyTarget($extension)
    {
        $extension = preg_replace('/[^0-9*#]/', '', (string)$extension);
        if ($extension === '') return null;

        $output = [];
        @exec('asterisk -rx ' . escapeshellarg('core show channels concise') . ' 2>&1', $output, $code);
        if ($code !== 0 || empty($output)) return null;

        $matches = [];
        foreach ($output as $line) {
            if (strpos($line, '!') === false) continue;
            $fields = explode('!', trim($line));
            $channel = isset($fields[0]) ? $fields[0] : '';
            $context = isset($fields[1]) ? $fields[1] : '';
            $exten = isset($fields[2]) ? $fields[2] : '';
            $application = isset($fields[5]) ? $fields[5] : '';
            $applicationData = isset($fields[6]) ? $fields[6] : '';
            $callerId = isset($fields[7]) ? $fields[7] : '';
            $bridged = $this->firstActiveChannelField(isset($fields[10]) ? $fields[10] : '', isset($fields[11]) ? $fields[11] : '');
            $uniqueId = isset($fields[12]) ? trim($fields[12]) : '';
            $linkedId = isset($fields[13]) ? trim($fields[13]) : '';

            if (!$this->activeChannelMatchesExtension($extension, $channel, $bridged, $context, $exten, $callerId, $applicationData)) {
                continue;
            }
            if (preg_match('/^(AppDial|Hangup|Congestion|Busy)$/i', $application)) {
                continue;
            }

            $score = 0;
            if (preg_match('/^(PJSIP|SIP|IAX2)\/' . preg_quote($extension, '/') . '[-@]/i', $channel)) $score += 50;
            if (preg_match('/^(PJSIP|SIP|IAX2)\/' . preg_quote($extension, '/') . '[-@]/i', $bridged)) $score += 45;
            if (stripos($channel, 'Local/') !== 0) $score += 10;
            if ($bridged !== '') $score += 5;
            $matches[] = [
                'score' => $score,
                'channel' => $channel,
                'bridgedChannel' => $bridged,
                'uniqueId' => $uniqueId,
                'linkedId' => $linkedId,
            ];
        }

        if (empty($matches)) return null;
        usort($matches, function ($a, $b) { return $b['score'] <=> $a['score']; });
        return $matches[0];
    }

    private function activeChannelMatchesExtension($extension, $channel, $bridged, $context, $exten, $callerId, $applicationData)
    {
        $quoted = preg_quote($extension, '/');
        if (preg_match('/(?:^|\/|-)(' . $quoted . ')(?:[-@\/]|$)/', $channel)) return true;
        if ($bridged && preg_match('/(?:^|\/|-)(' . $quoted . ')(?:[-@\/]|$)/', $bridged)) return true;
        if ($exten === $extension) return true;
        if ($callerId === $extension) return true;
        if (strpos($context, 'from-internal') !== false && strpos($applicationData, '/' . $extension) !== false) return true;
        return false;
    }

    private function firstActiveChannelField()
    {
        foreach (func_get_args() as $value) {
            if ($value && preg_match('/(?:PJSIP|SIP|Local|IAX2)\//i', $value)) {
                return $value;
            }
        }
        return '';
    }

    private function discoverSystem()
    {
        $config = $this->getConnectorConfig();
        $recordingsPath = $this->detectRecordingsPath($config);
        return [
            'hostname' => php_uname('n') ?: null,
            'localIp' => $this->getLocalIp(),
            'phpVersion' => PHP_VERSION,
            'freepbxVersion' => $this->getFreePbxVersion(),
            'asteriskVersion' => $this->getAsteriskVersion(),
            'moduleVersion' => self::MODULE_VERSION,
            'connectorUrl' => $config['connectorUrl'] ?? $this->inferConnectorUrl(),
            'recordingsPath' => $recordingsPath,
            'timezone' => date_default_timezone_get(),
            'diskUsagePercent' => $this->diskUsagePercent('/'),
            'recordingDiskUsagePercent' => $this->diskUsagePercent($recordingsPath),
            'cdr' => $this->discoverCdr(),
            'recordingPaths' => $this->discoverRecordingPaths(),
            'logPaths' => $this->approvedLogPaths(),
            'commandProbes' => array_keys($this->approvedCommandProbes()),
            'capabilities' => ['read_db_schema', 'read_cdr', 'scan_recordings', 'sync_inventory', 'read_active_channels', 'tail_logs', 'fetch_approved_files', 'temporary_call_forward', 'permanent_call_forward', 'supervision', 'self_update'],
        ];
    }

    private function syncInventory()
    {
        $items = $this->discoverInventory();
        $types = array_values(array_unique(array_map(function ($item) { return $item['type']; }, $items)));
        $capabilities = ['faxTier' => $this->detectFaxTier(), 'webrtc' => $this->detectWebRtcCapability()];
        $this->postPortal('/api/pbx/sync/inventory', ['items' => $items, 'types' => $types, 'complete' => true, 'capabilities' => $capabilities]);
        return ['ok' => true, 'count' => count($items), 'message' => count($items) . ' PBX inventory items synced.', 'types' => $types, 'capabilities' => $capabilities];
    }

    private function discoverInventory()
    {
        $items = [];
        try {
            $pdo = $this->getAmpPdo();
            $voicemailByExtension = $this->discoverVoicemailByExtension($pdo);
            $followMeByExtension = $this->discoverFollowMeByExtension($pdo);
            $callForwardingByExtension = $this->discoverCallForwardingByExtension();
            $endpointStatusByExtension = $this->discoverEndpointStatusByExtension();
            foreach ($this->queryRows($pdo, 'users', ['extension', 'name', 'outboundcid', 'sipname']) as $row) {
                $extension = (string)($row['extension'] ?? '');
                if ($extension === '') continue;
                $metadata = $row;
                $metadata['voicemail'] = $voicemailByExtension[$extension] ?? ['enabled' => false];
                $metadata['followMe'] = $followMeByExtension[$extension] ?? ['enabled' => false];
                $metadata['callForwarding'] = $callForwardingByExtension[$extension] ?? [];
                $metadata['endpointStatus'] = $endpointStatusByExtension[$extension] ?? ['state' => 'unknown', 'tech' => null];
                $items[] = ['type' => 'extension', 'objectId' => $extension, 'extension' => $extension, 'number' => $extension, 'name' => $row['name'] ?? $extension, 'metadata' => $metadata];
            }
            foreach ($this->queryRows($pdo, 'ringgroups', ['grpnum', 'description', 'grplist', 'annmsg_id']) as $row) {
                $number = (string)($row['grpnum'] ?? '');
                if ($number === '') continue;
                $items[] = ['type' => 'ring_group', 'objectId' => $number, 'number' => $number, 'name' => $row['description'] ?? ('Ring Group ' . $number), 'metadata' => $row];
            }
            $faxTier = $this->detectFaxTier();
            $incomingRows = $this->queryRows($pdo, 'incoming', ['cidnum', 'extension', 'destination', 'faxexten', 'faxemail', 'description']);
            $faxRoutes = $this->discoverFaxRoutes($pdo, $incomingRows, $faxTier);
            $seenFaxRoutes = [];
            foreach ($incomingRows as $row) {
                $number = preg_replace('/\D/', '', (string)($row['extension'] ?? ''));
                if (strlen($number) < 10 || strlen($number) > 11) continue;
                $name = $row['description'] ?? ('Inbound route ' . $number);
                $items[] = ['type' => 'inbound_route', 'objectId' => $number, 'number' => $number, 'name' => $name, 'metadata' => $row];
                if (isset($faxRoutes[$number])) {
                    $items[] = ['type' => 'fax_route', 'objectId' => $number, 'number' => $number, 'name' => $name, 'metadata' => $faxRoutes[$number]];
                    $seenFaxRoutes[$number] = true;
                }
            }
            foreach ($faxRoutes as $number => $faxMetadata) {
                if (isset($seenFaxRoutes[$number])) continue;
                $items[] = ['type' => 'fax_route', 'objectId' => $number, 'number' => $number, 'name' => 'Fax ' . $number, 'metadata' => $faxMetadata];
            }
            foreach ($this->queryRows($pdo, 'ivr_details', ['id', 'name', 'description', 'announcement']) as $row) {
                $id = (string)($row['id'] ?? '');
                if ($id === '') continue;
                $items[] = ['type' => 'ivr', 'objectId' => $id, 'number' => $id, 'name' => $row['name'] ?? $row['description'] ?? ('IVR ' . $id), 'metadata' => $row];
            }
            foreach ($voicemailByExtension as $extension => $metadata) {
                $items[] = ['type' => 'voicemail', 'objectId' => $extension, 'extension' => $extension, 'number' => $extension, 'name' => $metadata['name'] ?? ('Voicemail ' . $extension), 'metadata' => ['voicemail' => $metadata]];
            }
        } catch (\Throwable $exception) {
            return [['type' => 'sync_error', 'objectId' => 'inventory', 'name' => 'Inventory sync failed', 'metadata' => ['error' => $this->sanitizeMessage($exception->getMessage())]]];
        }
        return $items;
    }

    private function discoverEndpointStatusByExtension()
    {
        if (!function_exists('exec')) return [];
        $items = [];
        $pjsip = [];
        @exec('asterisk -rx "pjsip show endpoints" 2>/dev/null', $pjsip, $pjsipCode);
        if ($pjsipCode === 0) {
            foreach ($pjsip as $line) {
                if (!preg_match('/^\s*Endpoint:\s+([0-9*#]+)(?:\/[^\s]+)?\s+(.+)$/i', (string)$line, $matches)) continue;
                $extension = trim($matches[1]);
                $statusText = strtolower(trim($matches[2]));
                $items[$extension] = [
                    'state' => $this->endpointStateFromText($statusText),
                    'tech' => 'pjsip',
                    'raw' => trim((string)$line),
                ];
            }
        }

        $sip = [];
        @exec('asterisk -rx "sip show peers" 2>/dev/null', $sip, $sipCode);
        if ($sipCode === 0) {
            foreach ($sip as $line) {
                if (!preg_match('/^\s*([0-9*#]+)\/[^\s]+\s+.+\s+(OK|UNKNOWN|UNREACHABLE|UNMONITORED|LAGGED|REACHABLE|UNREGISTERED|Rejected)(?:\s|\(|$)/i', (string)$line, $matches)) continue;
                $extension = trim($matches[1]);
                if (isset($items[$extension]) && $items[$extension]['state'] !== 'unknown') continue;
                $items[$extension] = [
                    'state' => $this->endpointStateFromText(strtolower($matches[2])),
                    'tech' => 'sip',
                    'raw' => trim((string)$line),
                ];
            }
        }

        return $items;
    }

    private function endpointStateFromText($text)
    {
        $text = strtolower((string)$text);
        if (preg_match('/\b(not in use|available|reachable|ok|idle|in use|busy|ringing|on hold)\b/', $text)) return 'online';
        if (preg_match('/\b(unavailable|unreachable|unregistered|rejected|unknown|lagged)\b/', $text)) return 'offline';
        return 'unknown';
    }

    private function discoverVoicemailByExtension(\PDO $pdo)
    {
        $items = [];
        $module = $this->voicemailModule();
        if ($module && method_exists($module, 'getVoicemail')) {
            $contexts = $module->getVoicemail(false);
            if (is_array($contexts)) {
                foreach ($contexts as $context => $mailboxes) {
                    if (in_array((string)$context, ['general', 'zonemessages', 'pbxaliases', 'device'], true) || !is_array($mailboxes)) continue;
                    foreach ($mailboxes as $mailbox => $row) {
                        if (!is_array($row)) continue;
                        $extension = (string)($row['mailbox'] ?? $mailbox);
                        if ($extension === '') continue;
                        $row['vmcontext'] = (string)$context;
                        $metadata = $this->normalizeVoicemailMailbox($row);
                        unset($metadata['password']);
                        $metadata['enabled'] = true;
                        $items[$extension] = $metadata;
                    }
                }
            }
        }
        if (!empty($items)) return $items;
        foreach ($this->queryRows($pdo, 'voicemail_users', ['extension', 'name', 'email', 'pager', 'options', 'saycid', 'envelope', 'attach', 'delete', 'context']) as $row) {
            $extension = (string)($row['extension'] ?? '');
            if ($extension === '') continue;
            $metadata = $this->normalizeVoicemailMailbox($row);
            unset($metadata['password']);
            $metadata['enabled'] = true;
            $items[$extension] = $metadata;
        }
        return $items;
    }

    private function discoverFollowMeByExtension(\PDO $pdo)
    {
        $items = [];
        foreach ($this->queryRows($pdo, 'findmefollow', ['grpnum', 'grplist', 'strategy', 'grptime', 'pre_ring', 'postdest', 'dring', 'needsconf', 'remotealert_id', 'toolate_id', 'ringing']) as $row) {
            $extension = (string)($row['grpnum'] ?? '');
            if ($extension === '') continue;
            $row['enabled'] = true;
            $items[$extension] = $row;
        }
        return $items;
    }

    private function discoverCallForwardingByExtension()
    {
        if (!function_exists('exec')) return [];
        $output = [];
        @exec('asterisk -rx "database show CF" 2>/dev/null', $output, $code);
        if ($code !== 0 || empty($output)) return [];
        $items = [];
        foreach ($output as $line) {
            if (!preg_match('#/([^/]+)/([^/\s]+)\s*:\s*(.+)$#', (string)$line, $matches)) continue;
            $family = strtoupper(trim($matches[1]));
            $extension = trim($matches[2]);
            $destination = trim($matches[3]);
            if ($extension === '' || $destination === '') continue;
            if (!isset($items[$extension])) $items[$extension] = [];
            if ($family === 'CF') $items[$extension]['unconditional'] = $destination;
            elseif ($family === 'CFB') $items[$extension]['busy'] = $destination;
            elseif ($family === 'CFU' || $family === 'CFNA') $items[$extension]['unavailable'] = $destination;
            else $items[$extension][$family] = $destination;
        }
        return $items;
    }

    private function discoverCdr()
    {
        try {
            $pdo = $this->getCdrPdo();
            return ['available' => true, 'columns' => $this->getCdrColumns($pdo), 'tables' => $this->getDatabaseTables($pdo)];
        } catch (\Throwable $exception) {
            return ['available' => false, 'error' => $this->sanitizeMessage($exception->getMessage())];
        }
    }

    private function getDatabaseTables(\PDO $pdo)
    {
        $tables = [];
        foreach ($pdo->query('SHOW TABLES') as $row) {
            $tables[] = array_values($row)[0];
        }
        return $tables;
    }

    private function syncCallsDynamic(array $input)
    {
        $config = $this->getConnectorConfig();
        $calls = $this->readRecentCdrRowsWithInput($config, $input);
        $this->postPortal('/api/pbx/sync/calls', ['calls' => $calls]);
        $config['lastCallSyncAt'] = gmdate('c');
        $this->setConnectorConfig($config);
        return ['ok' => true, 'count' => count($calls), 'message' => count($calls) . ' dynamic CDR rows synced.', 'cdr' => $this->discoverCdr()];
    }

    private function syncRecordingsDeep(array $input)
    {
        $config = $this->getConnectorConfig();
        $limit = max(1, min((int)($input['limit'] ?? 1000), 5000));
        $recordings = [];
        foreach ($this->discoverRecordingPaths() as $path) {
            if (!is_dir($path)) continue;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (count($recordings) >= $limit) break 2;
                if ($file->isFile() && preg_match('/\.(wav|mp3|gsm|ogg|m4a)$/i', $file->getFilename())) {
                    if ($file->getSize() <= 44) continue;
                    $recordings[] = ['filePath' => $file->getPathname(), 'fileName' => $file->getFilename(), 'fileSizeBytes' => $file->getSize(), 'format' => strtolower($file->getExtension()), 'durationSeconds' => $this->recordingDurationSeconds($file->getPathname()), 'recordingStartedAt' => gmdate('c', $file->getMTime())];
                }
            }
        }
        $this->postPortal('/api/pbx/sync/recordings', ['recordings' => $recordings]);
        $config['lastRecordingSyncAt'] = gmdate('c');
        $this->setConnectorConfig($config);
        return ['ok' => true, 'count' => count($recordings), 'message' => count($recordings) . ' recording metadata rows synced.', 'paths' => $this->discoverRecordingPaths()];
    }

    private function readRecentCdrRowsWithInput(array $config, array $input)
    {
        $pdo = $this->getCdrPdo();
        $columns = $this->getCdrColumns($pdo);
        if (empty($columns) || !in_array('calldate', $columns, true)) return [];
        $wanted = ['calldate', 'clid', 'src', 'dst', 'dcontext', 'channel', 'dstchannel', 'lastapp', 'lastdata', 'duration', 'billsec', 'disposition', 'amaflags', 'accountcode', 'uniqueid', 'userfield', 'recordingfile', 'cnum', 'cnam', 'outbound_cnum', 'outbound_cnam', 'did', 'linkedid', 'peeraccount', 'sequence'];
        $selected = array_values(array_intersect($wanted, $columns));
        $selectSql = implode(', ', array_map(function ($column) { return '`' . str_replace('`', '', $column) . '`'; }, $selected));
        $limit = max(1, min((int)($input['limit'] ?? 10000), 50000));
        $offset = max(0, min((int)($input['offset'] ?? 0), 1000000));
        $lookbackDays = max(1, min((int)($input['lookbackDays'] ?? 3650), 3650));
        $since = date('Y-m-d H:i:s', time() - 86400 * $lookbackDays);
        $stmt = $pdo->prepare("SELECT {$selectSql} FROM cdr WHERE calldate >= :since ORDER BY calldate DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute(['since' => $since]);
        $calls = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) $calls[] = $this->mapCdrRow($row, $config);
        return $calls;
    }

    private function tailApprovedLog(array $input)
    {
        $path = $this->approvedPath($input['path'] ?? '/var/log/asterisk/full', $this->approvedLogPaths());
        if (!$path || !is_file($path) || !is_readable($path)) return ['status' => false, 'errorCode' => 'log_unreadable', 'error' => 'Log path is not approved or readable.'];
        $lines = max(1, min((int)($input['lines'] ?? 200), 1000));
        $output = '';
        if (function_exists('exec')) @exec('tail -n ' . (int)$lines . ' ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        $output = isset($out) ? implode("\n", $out) : '';
        return ['ok' => true, 'path' => $path, 'stdout' => $this->sanitizeMessage($output)];
    }

    private function listApprovedPath(array $input)
    {
        $approved = array_merge($this->discoverRecordingPaths(), ['/var/log/asterisk', '/var/log/freepbx']);
        $path = $this->approvedPath($input['path'] ?? '/var/spool/asterisk/monitor', $approved);
        if (!$path || !is_dir($path)) return ['status' => false, 'errorCode' => 'path_unreadable', 'error' => 'Path is not approved or readable.'];
        $limit = max(1, min((int)($input['limit'] ?? 200), 1000));
        $items = [];
        foreach (new \DirectoryIterator($path) as $file) {
            if ($file->isDot()) continue;
            if (count($items) >= $limit) break;
            $items[] = ['name' => $file->getFilename(), 'path' => $file->getPathname(), 'type' => $file->isDir() ? 'dir' : 'file', 'size' => $file->isFile() ? $file->getSize() : null, 'modifiedAt' => gmdate('c', $file->getMTime())];
        }
        return ['ok' => true, 'path' => $path, 'items' => $items];
    }

    private function fetchApprovedFile(array $input)
    {
        $approved = array_merge($this->approvedLogPaths(), $this->discoverRecordingPaths());
        $path = $this->approvedPath($input['path'] ?? '', $approved);
        if (!$path || !is_file($path) || !is_readable($path)) return ['status' => false, 'errorCode' => 'file_unreadable', 'error' => 'File is not approved or readable.'];
        if (filesize($path) > 2 * 1024 * 1024) return ['status' => false, 'errorCode' => 'file_too_large', 'error' => 'File is too large.'];
        return ['ok' => true, 'path' => $path, 'contentBase64' => base64_encode(file_get_contents($path)), 'encoding' => 'base64'];
    }

    private function runCommandProbe(array $input)
    {
        $probes = $this->approvedCommandProbes();
        $name = (string)($input['probe'] ?? '');
        if (!isset($probes[$name])) return ['status' => false, 'errorCode' => 'probe_denied', 'error' => 'Probe is not approved.'];
        @exec($probes[$name] . ' 2>&1', $output, $code);
        return ['ok' => $code === 0, 'probe' => $name, 'exitCode' => $code, 'stdout' => $this->sanitizeMessage(implode("\n", $output))];
    }

    private function selfUpdate(array $input)
    {
        $url = (string)($input['releaseUrl'] ?? '');
        $expectedSha256 = strtolower((string)($input['expectedSha256'] ?? ''));
        if (
            !preg_match('/^https:\/\/github\.com\/aandrtechs\/jointtechs-freepbx-connector\/releases\/download\/v[0-9.]+\/jointtechsconnector-[0-9.]+\.tgz$/', $url)
            && !preg_match('/^https:\/\/portal\.joint\.tech\/jointtechsconnector-[0-9.]+\.tgz$/', $url)
        ) {
            return ['status' => false, 'errorCode' => 'update_url_denied', 'error' => 'Release URL is not approved.'];
        }
        if ($expectedSha256 === '') return ['status' => false, 'errorCode' => 'checksum_required', 'error' => 'Expected SHA256 is required for self-update.'];
        $tmp = '/tmp/jointtechsconnector-update-' . time() . '.tgz';
        @exec('curl -fsSL ' . escapeshellarg($url) . ' -o ' . escapeshellarg($tmp) . ' 2>&1', $downloadOutput, $downloadCode);
        if ($downloadCode !== 0 || !is_file($tmp)) return ['status' => false, 'errorCode' => 'download_failed', 'error' => 'Could not download release.', 'stderr' => $this->sanitizeMessage(implode("\n", $downloadOutput))];
        $actual = hash_file('sha256', $tmp);
        if (!hash_equals($expectedSha256, strtolower($actual))) return ['status' => false, 'errorCode' => 'checksum_mismatch', 'error' => 'Release checksum mismatch.'];
        @exec('fwconsole ma downloadinstall ' . escapeshellarg($url) . ' 2>&1 && fwconsole reload 2>&1', $output, $code);
        return ['ok' => $code === 0, 'exitCode' => $code, 'stdout' => $this->sanitizeMessage(implode("\n", $output))];
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
        $path = $this->detectRecordingsPath($config);
        $last = !empty($config['lastRecordingSyncAt']) ? strtotime((string)$config['lastRecordingSyncAt']) : false;
        $since = $last ? $last - 120 : time() - 86400 * 7;
        $recordings = [];
        if (is_dir($path)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (count($recordings) >= 2000) break;
                if (!$file->isFile() || $file->getMTime() < $since || !preg_match('/\.(wav|mp3|gsm|ogg|m4a)$/i', $file->getFilename()) || $file->getSize() <= 44) continue;
                $recordings[] = ['filePath' => $file->getPathname(), 'fileName' => $file->getFilename(), 'fileSizeBytes' => $file->getSize(), 'format' => strtolower($file->getExtension()), 'durationSeconds' => $this->recordingDurationSeconds($file->getPathname()), 'recordingStartedAt' => gmdate('c', $file->getMTime())];
            }
        }
        usort($recordings, function ($left, $right) { return strcmp($right['recordingStartedAt'], $left['recordingStartedAt']); });
        $this->postPortal('/api/pbx/sync/recordings', ['recordings' => $recordings, 'since' => gmdate('c', $since)]);
        $config['lastRecordingSyncAt'] = gmdate('c');
        $this->setConnectorConfig($config);
        return ['ok' => true, 'count' => count($recordings), 'since' => gmdate('c', $since)];
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

    private function fetchRecording(array $payload)
    {
        $path = $this->safeRecordingPath($payload['filePath'] ?? '');
        if (!$path || !is_file($path) || !is_readable($path)) {
            return ['status' => false, 'error' => 'Recording not available.'];
        }
        $size = filesize($path);
        if ($size !== false && $size <= 44) {
            return ['status' => false, 'error' => 'Recording file is empty.'];
        }
        if ($size !== false && $size > 50 * 1024 * 1024) {
            return ['status' => false, 'error' => 'Recording is too large for temporary playback cache.'];
        }
        $content = $this->recordingMp3Content($path);
        if (!$content) {
            return ['status' => false, 'error' => 'Recording could not be converted to MP3.'];
        }
        return [
            'ok' => true,
            'fileName' => preg_replace('/\.[^.]+$/', '.mp3', basename($path)),
            'fileSizeBytes' => strlen($content),
            'contentType' => 'audio/mpeg',
            'contentBase64' => base64_encode($content),
        ];
    }

    private function safeRecordingPath($path)
    {
        $real = realpath((string) $path);
        if (!$real) return null;
        foreach ($this->discoverRecordingPaths() as $root) {
            $base = realpath($root);
            if ($base && strpos($real, $base) === 0) return $real;
        }
        return null;
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
        $database = !empty($amp_conf['CDRDBNAME']) ? $amp_conf['CDRDBNAME'] : 'asteriskcdrdb';
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
            'recordingAvailable' => (bool) $recordingPath,
            'recordingFile' => $recordingFile,
            'recordingFilePath' => $recordingPath,
            'rawCdr' => $row,
        ];
    }

    private function findRecordingPath($recordingFile, $calldate, array $config)
    {
        $recordingFile = trim((string) $recordingFile);
        if ($recordingFile === '') return null;
        if ($recordingFile[0] === '/' && $this->recordingFileHasAudio($recordingFile)) return $recordingFile;
        $base = rtrim($config['recordingsPath'] ?? '/var/spool/asterisk/monitor', '/');
        $roots = $this->discoverRecordingPaths();
        array_unshift($roots, $base);
        $roots = array_values(array_unique(array_filter($roots)));
        $candidates = [];
        $timestamp = $calldate ? strtotime((string) $calldate) : false;
        foreach ($roots as $root) {
            $root = rtrim($root, '/');
            $candidates[] = $root . '/' . $recordingFile;
            if ($timestamp) {
                $candidates[] = $root . '/' . date('Y/m/d', $timestamp) . '/' . $recordingFile;
                $candidates[] = $root . '/' . date('Y/m', $timestamp) . '/' . $recordingFile;
                $candidates[] = $root . '/' . gmdate('Y/m/d', $timestamp) . '/' . $recordingFile;
                $candidates[] = $root . '/' . gmdate('Y/m', $timestamp) . '/' . $recordingFile;
            }
        }
        foreach ($candidates as $candidate) {
            if ($this->recordingFileHasAudio($candidate)) return $candidate;
        }
        return null;
    }

    private function recordingFileHasAudio($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }

        $size = filesize($path);
        return $size !== false && $size > 44;
    }

    private function getLocalIp()
    {
        if (function_exists('exec')) {
            @exec('hostname -I 2>/dev/null', $output, $code);
            if ($code === 0 && !empty($output[0])) {
                foreach (preg_split('/\s+/', trim($output[0])) as $candidate) {
                    if (filter_var($candidate, FILTER_VALIDATE_IP) && strpos($candidate, '127.') !== 0) {
                        return $candidate;
                    }
                }
            }
        }
        $hostname = php_uname('n');
        $ip = $hostname ? gethostbyname($hostname) : null;
        return $ip && $ip !== $hostname && strpos($ip, '127.') !== 0 ? $ip : null;
    }

    private function inferConnectorUrl()
    {
        $host = $_SERVER['HTTP_HOST'] ?? php_uname('n');
        $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) $host);
        if (!$host || $host === 'localhost') return '';
        return 'https://' . $host;
    }

    private function detectRecordingsPath(array $config)
    {
        $candidates = [];
        if (!empty($config['recordingsPath'])) $candidates[] = $config['recordingsPath'];
        $candidates[] = '/var/spool/asterisk/monitor';
        $candidates[] = '/var/spool/asterisk/monitorDONE';
        foreach ($candidates as $candidate) {
            if ($candidate && is_dir($candidate)) return rtrim($candidate, '/');
        }
        return '/var/spool/asterisk/monitor';
    }

    private function discoverRecordingPaths()
    {
        $paths = ['/var/spool/asterisk/monitor', '/var/spool/asterisk/monitorDONE', '/var/spool/asterisk/voicemail', '/var/lib/asterisk/sounds/recordings'];
        $config = $this->getConnectorConfig();
        if (!empty($config['recordingsPath'])) array_unshift($paths, $config['recordingsPath']);
        $existing = [];
        foreach (array_unique($paths) as $path) {
            if ($path && is_dir($path)) $existing[] = rtrim($path, '/');
        }
        return $existing ?: ['/var/spool/asterisk/monitor'];
    }

    private function approvedLogPaths()
    {
        return array_values(array_filter([
            '/var/log/asterisk/full',
            '/var/log/asterisk/messages',
            '/var/log/asterisk/freepbx.log',
            '/var/log/freepbx/freepbx.log',
            '/var/log/httpd/error_log',
            '/var/log/apache2/error.log',
        ], function ($path) { return file_exists($path) || is_dir(dirname($path)); }));
    }

    private function approvedCommandProbes()
    {
        return [
            'fwconsole_version' => 'fwconsole --version',
            'fwconsole_ma_list' => 'fwconsole ma list',
            'asterisk_version' => 'asterisk -rx "core show version"',
            'active_channels' => 'asterisk -rx "core show channels concise"',
            'disk_usage' => 'df -h',
            'memory' => 'free -m',
            'uptime' => 'uptime',
        ];
    }

    private function getAmpPdo()
    {
        global $amp_conf;
        $host = $amp_conf['AMPDBHOST'] ?? 'localhost';
        $user = $amp_conf['AMPDBUSER'] ?? 'freepbxuser';
        $password = $amp_conf['AMPDBPASS'] ?? '';
        $database = !empty($amp_conf['AMPDBNAME']) ? $amp_conf['AMPDBNAME'] : 'asterisk';
        return new \PDO("mysql:host={$host};dbname={$database};charset=utf8mb4", $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function queryRows(\PDO $pdo, $table, array $wantedColumns)
    {
        if (!$this->tableExists($pdo, $table)) return [];
        $columns = $this->tableColumns($pdo, $table);
        $selected = array_values(array_intersect($wantedColumns, $columns));
        if (empty($selected)) return [];
        $selectSql = implode(', ', array_map(function ($column) { return '`' . str_replace('`', '', $column) . '`'; }, $selected));
        $tableSql = '`' . str_replace('`', '', $table) . '`';
        $stmt = $pdo->query("SELECT {$selectSql} FROM {$tableSql} LIMIT 1000");
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    private function tableExists(\PDO $pdo, $table)
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    private function tableColumns(\PDO $pdo, $table)
    {
        $columns = [];
        $tableSql = '`' . str_replace('`', '', $table) . '`';
        foreach ($pdo->query("SHOW COLUMNS FROM {$tableSql}") as $row) {
            $columns[] = $row['Field'];
        }
        return $columns;
    }

    private function approvedPath($requested, array $approvedBases)
    {
        $requested = (string)$requested;
        if ($requested === '') return null;
        $real = realpath($requested);
        if (!$real) return null;
        foreach ($approvedBases as $base) {
            $baseReal = realpath($base);
            if (!$baseReal && is_file($base)) $baseReal = realpath(dirname($base));
            if (!$baseReal) continue;
            if ($real === $baseReal || strpos($real, rtrim($baseReal, '/') . '/') === 0) return $real;
        }
        return null;
    }

    private function safeCdrColumns()
    {
        try {
            $pdo = $this->getCdrPdo();
            return $this->getCdrColumns($pdo);
        } catch (\Throwable $exception) {
            return [];
        }
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
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
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

    private function recordingDurationSeconds($path)
    {
        if (!function_exists('exec')) return null;
        @exec('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($path) . ' 2>/dev/null', $output, $code);
        if ($code !== 0 || empty($output[0])) return null;
        $duration = (float) $output[0];
        return $duration > 0 ? (int) round($duration) : null;
    }

    private function recordingMp3Content($path)
    {
        if (!function_exists('exec')) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'jtc_mp3_');
        if (!$tmp) return null;
        $mp3 = $tmp . '.mp3';
        @unlink($tmp);
        @exec('ffmpeg -y -v error -i ' . escapeshellarg($path) . ' -vn -codec:a libmp3lame -b:a 64k ' . escapeshellarg($mp3) . ' 2>&1', $output, $code);
        if ($code !== 0 || !is_file($mp3) || filesize($mp3) <= 0) {
            @unlink($mp3);
            return null;
        }
        $content = file_get_contents($mp3);
        @unlink($mp3);
        return $content === false ? null : $content;
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
