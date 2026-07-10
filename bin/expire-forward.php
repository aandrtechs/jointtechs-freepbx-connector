#!/usr/bin/env php
<?php

require_once('/etc/freepbx.conf');

$extension = $argv[1] ?? '';
$generation = $argv[2] ?? '';
$result = FreePBX::Jointtechsconnector()->runTemporaryForwardExpiryCli($extension, $generation);
echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
