<?php

namespace FreePBX\modules;

class Jointtechsconnector_ajax extends \FreePBX_Helpers implements \BMO
{
    public function ajaxRequest($req, &$setting)
    {
        return in_array($req, ['pair'], true);
    }

    public function ajaxHandler()
    {
        $command = $_REQUEST['command'] ?? '';
        if ($command !== 'pair') {
            return ['status' => false, 'message' => _('Unknown command')];
        }

        return [
            'status' => false,
            'message' => _('Pairing transport is scaffolded only in 0.1.0. Use the hosted portal API contract in README.md for implementation.'),
        ];
    }
}
