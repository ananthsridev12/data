<?php
require_once __DIR__ . '/bootstrap.php';

logout();
header('Location: login.php');
exit;
