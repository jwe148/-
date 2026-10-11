<?php
declare(strict_types=1);
require __DIR__.'/auth.php'; requireLogin();
require_once __DIR__.'/db.php';
require __DIR__.'/data/catalog_provider.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET'); http_response_code(405); echo '{"error":"只支持查询"}'; exit; }
try {
    if(!databaseModeEnabled()) throw new RuntimeException('需要数据库模式');
    echo json_encode(['courses'=>array_values(readCourseDefinitions(databaseConnection()))],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch(Throwable $e) { error_log('Catalog interface failed: '.$e->getMessage()); http_response_code(503); echo '{"error":"课程目录暂不可用"}'; }
