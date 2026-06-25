<?php
out(_("Installing Jointtechs Connector"));
jointtechsconnector_install_action_shim();
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
