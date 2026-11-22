<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && file_exists($_SERVER['DOCUMENT_ROOT'] . $path) && !is_dir($_SERVER['DOCUMENT_ROOT'] . $path)) { return false; }
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
