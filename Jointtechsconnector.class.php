<?php

namespace FreePBX\modules;

class Jointtechsconnector extends \FreePBX_Helpers implements \BMO
{
    private const CONFIG_KEY = 'JOINTTECHS_CONNECTOR_CONFIG';

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        $this->setConfig([]);
        return true;
    }

    public function backup()
    {
        return [
            'config' => $this->getConfig(),
        ];
    }

    public function restore($backup)
    {
        if (isset($backup['config']) && is_array($backup['config'])) {
            $this->setConfig($backup['config']);
        }
        return true;
    }

    public function getConfig()
    {
        $raw = $this->FreePBX->Config->get(self::CONFIG_KEY);
        if (!$raw) {
            return [
                'portalUrl' => '',
                'pbxId' => '',
                'pairedAt' => null,
                'lastHeartbeatAt' => null,
                'lastCallSyncAt' => null,
                'lastRecordingSyncAt' => null,
            ];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function setConfig(array $config)
    {
        $this->FreePBX->Config->set(self::CONFIG_KEY, json_encode($config));
    }
}
