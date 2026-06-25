<?php
out(_("Removing Jointtechs Connector"));
jointtechsconnector_remove_action_shim();
jointtechsconnector_remove_sync_cron();
return true;

function jointtechsconnector_remove_action_shim()
{
    $target = '/var/www/html/jointtechsconnector-action.php';
    if (is_file($target)) {
        @unlink($target);
        out(_("Removed Jointtechs public action shim."));
    }
}

function jointtechsconnector_remove_sync_cron()
{
    $target = '/etc/cron.d/jointtechsconnector';
    if (is_file($target)) {
        @unlink($target);
        out(_("Removed Jointtechs sync schedule."));
    }
}
