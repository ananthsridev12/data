<?php
require_once __DIR__ . '/bootstrap.php';

header('Location: ' . (current_user() ? 'dashboard.php' : 'login.php'));
exit;
