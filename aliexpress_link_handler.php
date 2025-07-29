<?php
session_start();
require_once __DIR__ . '/check_auth.php';

header('Content-Type: application/json');

$json_file = __DIR__ . '/aliexpress_keyword_links.json';

// POST 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $data = json_decode($_POST['data'], true);
        
        if ($data === null) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit;
        }
        
        // 데이터 검증
        foreach ($data as $keyword => $link) {
            if (empty($keyword) || empty($link)) {
                echo json_encode(['success' => false, 'message' => '키워드나 링크가 비어있습니다']);
                exit;
            }
            
            if (!filter_var($link, FILTER_VALIDATE_URL)) {
                echo json_encode(['success' => false, 'message' => "올바르지 않은 URL 형식: $link"]);
                exit;
            }
        }
        
        // JSON 파일로 저장
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $result = file_put_contents($json_file, $json);
        
        if ($result !== false) {
            // 성공 로그 기록
            $log_message = date('Y-m-d H:i:s') . " - AliExpress 키워드 링크 저장 성공 (" . count($data) . "개 키워드)\n";
            file_put_contents(__DIR__ . '/processor_log.txt', $log_message, FILE_APPEND | LOCK_EX);
            
            echo json_encode(['success' => true, 'message' => '저장되었습니다']);
        } else {
            echo json_encode(['success' => false, 'message' => '파일 쓰기 실패']);
        }
    }
}
?>