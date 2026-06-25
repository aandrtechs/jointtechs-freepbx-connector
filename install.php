<?php
out(_("Installing Jointtechs Connector"));
jointtechsconnector_install_action_shim();
jointtechsconnector_install_sync_cron();
return true;

function jointtechsconnector_install_action_shim()
{
    $target = '/var/www/html/jointtechsconnector-action.php';
    $source = __DIR__ . '/actions.php';
    if (!is_file($source)) {
        out(_("Jointtechs action shim source missing."));
        return;
    }

    $contents = "<?php\nrequire_once " . var_export($source, true) . ";\n";
    if (@file_put_contents($target, $contents) === false) {
        out(_("Could not write Jointtechs public action shim. Portal-triggered actions may fail."));
        return;
    }
    @chmod($target, 0644);
    out(_("Installed Jointtechs public action shim."));
}

function jointtechsconnector_install_sync_cron()
{
    $moduleDir = __DIR__;
    $calls = $moduleDir . '/bin/sync-calls.php';
    $recordings = $moduleDir . '/bin/sync-recordings.php';
    $heartbeat = $moduleDir . '/bin/heartbeat.php';
    @chmod($calls, 0755);
    @chmod($recordings, 0755);
    @chmod($heartbeat, 0755);

    $cron = <<<CRON
*/5 * * * * asterisk php {$calls} >/dev/null 2>&1
*/15 * * * * asterisk php {$recordings} >/dev/null 2>&1
17 * * * * asterisk php {$heartbeat} >/dev/null 2>&1

CRON;

    if (@file_put_contents('/etc/cron.d/jointtechsconnector', $cron) === false) {
        out(_("Could not write Jointtechs sync cron. Call logs may require manual sync."));
        return;
    }
    @chmod('/etc/cron.d/jointtechsconnector', 0644);
    out(_("Installed Jointtechs call and recording sync schedule."));
}
