<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Logger;
use App\Core\Session;

if (!empty($_SESSION['admin_username'])) {
    Logger::audit('admin', $_SESSION['admin_username'], 'logout');
}

Session::destroy();
header("Location: adminlogin.php");
exit();
