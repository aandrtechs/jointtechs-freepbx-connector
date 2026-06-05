<?php
$config = FreePBX::Jointtechsconnector()->getConnectorConfig();
?>
<h2>Jointtechs Connector Pairing</h2>
<form autocomplete="off" method="post" action="ajax.php?module=jointtechsconnector&amp;command=pair">
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label" for="portalUrl"><?php echo _("Portal URL"); ?></label>
      </div>
      <div class="col-md-9">
        <input class="form-control" id="portalUrl" name="portalUrl" type="url" value="<?php echo htmlspecialchars($config['portalUrl'] ?: 'https://portal.joint.tech', ENT_QUOTES); ?>" required>
      </div>
    </div>
  </div>
  <div class="element-container">
    <div class="row">
      <div class="col-md-3">
        <label class="control-label" for="pairingCode"><?php echo _("Pairing Code"); ?></label>
      </div>
      <div class="col-md-9">
        <input class="form-control" id="pairingCode" name="pairingCode" type="text" placeholder="ABCDEF-123456" required>
      </div>
    </div>
  </div>
  <button class="btn btn-primary" type="submit"><?php echo _("Pair PBX"); ?></button>
</form>
<hr>
<p><strong><?php echo _("Status"); ?>:</strong> <?php echo empty($config['pbxId']) ? _("Not paired") : _("Paired"); ?></p>
