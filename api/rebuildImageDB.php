<?php
header('Content-Type: application/json');

require_once '../lib/config.php';
require_once '../lib/db.php';
require_once __DIR__ . '/../lib/api.php';
requireAdminAccess();

rebuildPictureDB();
