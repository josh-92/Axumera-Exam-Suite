<?php

declare(strict_types=1);

/**
 * Intentionally minimal operational endpoint.  It is used only by the private
 * runtime controller and never emits configuration, licensing, or DB details.
 */
require __DIR__ . '/app/bootstrap.php';

try {
    \App\Core\Database::connection()->query('SELECT 1');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['status' => 'ok']);
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'unhealthy']);
}
