<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Logger;
use App\Core\Session;

// Send each portal back to its own login screen after logout.
if (!empty($_SESSION['admin_username'])) {
    Logger::audit('admin', $_SESSION['admin_username'], 'logout');
    $target = 'adminlogin.php';
} elseif (!empty($_SESSION['student_id'])) {
    Logger::audit('student', (string) ($_SESSION['roll_number'] ?? $_SESSION['student_id']), 'logout', []);
    $target = 'slogin.php';
} else {
    $target = 'slogin.php';
}

Session::destroy();
header('Location: ' . $target);
exit;
