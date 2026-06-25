<?php
out(_("Removing Jointtechs Connector"));
jointtechsconnector_remove_action_shim();
return true;

function jointtechsconnector_remove_action_shim()
{
    $target = '/var/www/html/jointtechsconnector-action.php';
    if (is_file($target)) {
        @unlink($target);
        out(_("Removed Jointtechs public action shim."));
    }
}
