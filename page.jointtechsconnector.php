<?php

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

echo load_view(__DIR__ . '/views/pairing.php');
