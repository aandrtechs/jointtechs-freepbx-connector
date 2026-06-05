<?php
$config = FreePBX::Jointtechsconnector()->getConnectorConfig();
?>
<h2>Jointtechs Connector Pairing</h2>
<div id="jointtechsconnector-message" class="alert" style="display:none;"></div>
<form id="jointtechsconnector-pairing-form" autocomplete="off" method="post" action="ajax.php?module=jointtechsconnector&amp;command=pair">
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
  <button id="jointtechsconnector-pair-button" class="btn btn-primary" type="submit"><?php echo _("Pair PBX"); ?></button>
</form>
<hr>
<p><strong><?php echo _("Status"); ?>:</strong> <?php echo empty($config['pbxId']) ? _("Not paired") : _("Paired"); ?></p>
<?php if (!empty($config['pbxId'])) { ?>
<p><strong><?php echo _("PBX ID"); ?>:</strong> <?php echo htmlspecialchars($config['pbxId'], ENT_QUOTES); ?></p>
<?php } ?>

<script>
(function () {
  var form = document.getElementById('jointtechsconnector-pairing-form');
  var button = document.getElementById('jointtechsconnector-pair-button');
  var message = document.getElementById('jointtechsconnector-message');
  if (!form || !button || !message || !window.fetch) {
    return;
  }

  function showMessage(success, text) {
    message.className = 'alert ' + (success ? 'alert-success' : 'alert-danger');
    message.textContent = text || (success ? '<?php echo _("PBX paired successfully."); ?>' : '<?php echo _("Pairing failed."); ?>');
    message.style.display = 'block';
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    message.style.display = 'none';
    button.disabled = true;
    button.textContent = '<?php echo _("Pairing..."); ?>';

    fetch(form.action, {
      method: 'POST',
      credentials: 'same-origin',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (response) {
      return response.json();
    }).then(function (data) {
      showMessage(!!data.status, data.message);
      if (data.status) {
        window.setTimeout(function () { window.location.reload(); }, 900);
      }
    }).catch(function () {
      showMessage(false, '<?php echo _("Pairing failed. Check the portal URL, pairing code, and outbound HTTPS access, then try again."); ?>');
    }).finally(function () {
      button.disabled = false;
      button.textContent = '<?php echo _("Pair PBX"); ?>';
    });
  });
})();
</script>
