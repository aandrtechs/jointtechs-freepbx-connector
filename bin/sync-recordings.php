#!/usr/bin/env php
<?php

require_once('/etc/freepbx.conf');

$result = FreePBX::Jointtechsconnector()->runRecordingSyncCli();
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
