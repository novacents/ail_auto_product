<?php
/**
 * product_save_handler.php 테스트 도구
 */
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
if(!current_user_can('manage_options'))wp_die('접근 권한이 없습니다.');

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Product Save Handler 테스트</h1>";

// 1. 파일 존재 확인
echo "<h2>1. 파일 존재 확인</h2>";
$handler_file = __DIR__ . '/product_save_handler.php';
echo "Handler 파일: " . ($handler_file) . "<br>";
echo "존재 여부: " . (file_exists($handler_file) ? '예' : '아니오') . "<br>";

$sheets_file = __DIR__ . '/google_sheets_manager.php';
echo "Sheets 파일: " . ($sheets_file) . "<br>";
echo "존재 여부: " . (file_exists($sheets_file) ? '예' : '아니오') . "<br>";

$token_file = '/var/www/novacents/tools/google_token.json';
echo "토큰 파일: " . ($token_file) . "<br>";
echo "존재 여부: " . (file_exists($token_file) ? '예' : '아니오') . "<br>";

// 2. Handler 직접 호출 테스트
echo "<h2>2. Handler 직접 호출 테스트</h2>";

try {
    // POST 데이터 시뮬레이션
    $_POST['test'] = 'test';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    // 출력 버퍼링 시작
    ob_start();
    
    // JSON 데이터 시뮬레이션
    $json_data = json_encode(['action' => 'load']);
    
    // 임시 파일에 JSON 데이터 저장
    $temp_input = tempnam(sys_get_temp_dir(), 'php_input');
    file_put_contents($temp_input, $json_data);
    
    // php://input 시뮬레이션을 위한 include
    echo "<strong>Handler 출력:</strong><br>";
    echo "<pre>";
    
    // Handler 파일 include (직접 실행)
    $old_input = 'php://input';
    
    // 간단한 테스트: GoogleSheetsManager 클래스 로드 확인
    require_once($sheets_file);
    echo "GoogleSheetsManager 클래스 로드 성공<br>";
    
    $manager = new GoogleSheetsManager();
    echo "GoogleSheetsManager 인스턴스 생성 성공<br>";
    
    // getAllProducts 메서드 직접 호출
    $result = $manager->getAllProducts();
    echo "getAllProducts 결과:<br>";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    echo "</pre>";
    
} catch (Exception $e) {
    echo "<strong>오류 발생:</strong><br>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

// 3. 직접 CURL 테스트
echo "<h2>3. CURL을 통한 Handler 테스트</h2>";

try {
    $url = 'https://novacents.com/tools/product_save_handler.php';
    $data = json_encode(['action' => 'load']);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "<strong>HTTP 상태 코드:</strong> " . $http_code . "<br>";
    if ($error) {
        echo "<strong>CURL 오류:</strong> " . $error . "<br>";
    }
    echo "<strong>응답:</strong><br>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
    
    if ($response) {
        $json_response = json_decode($response, true);
        if ($json_response) {
            echo "<strong>JSON 파싱 성공:</strong><br>";
            echo "<pre>" . json_encode($json_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        } else {
            echo "<strong>JSON 파싱 실패:</strong> " . json_last_error_msg() . "<br>";
        }
    }
    
} catch (Exception $e) {
    echo "<strong>CURL 테스트 오류:</strong> " . $e->getMessage() . "<br>";
}

echo "<br><a href='product_save_list.php'>product_save_list.php로 돌아가기</a>";
?>