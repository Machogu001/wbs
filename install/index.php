<?php
$install_lock = __DIR__ . '/../config/installed.lock';
if (file_exists($install_lock)) {
	header('Location: /');
	exit;
}
require_once __DIR__ . '/install.php';
