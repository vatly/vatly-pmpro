<?php
/**
 * Router for running WordPress on PHP's built-in server (Amp orbs, see
 * .amp/services.yaml): serves existing files, sends everything else to
 * index.php so pretty permalinks and the REST API work.
 */

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = $_SERVER['DOCUMENT_ROOT'] . $path;

if ($path !== '/' && is_file($file)) {
	return false;
}

$index = is_dir($file) && is_file(rtrim($file, '/') . '/index.php')
	? rtrim($path, '/') . '/index.php'
	: '/index.php';

$_SERVER['SCRIPT_NAME'] = $index;
require $_SERVER['DOCUMENT_ROOT'] . $index;
