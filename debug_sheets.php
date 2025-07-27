<?php
/**
 * 구글시트 연동 디버깅 도구
 */
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
if(!current_user_can('manage_options'))wp_die('접근 권한이 없습니다.');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>구글시트 연동 디버깅</title>
<style>
body{font-family:Arial,sans-serif;padding:20px;background:#f5f5f5}
.container{max-width:800px;margin:0 auto;background:white;padding:30px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1)}
.test-section{margin:20px 0;padding:20px;border:1px solid #ddd;border-radius:5px}
.success{background:#d4edda;color:#155724;border-color:#c3e6cb}
.error{background:#f8d7da;color:#721c24;border-color:#f5c6cb}
.info{background:#d1ecf1;color:#0c5460;border-color:#bee5eb}
pre{background:#f8f9fa;padding:10px;border-radius:5px;overflow-x:auto}
button{padding:10px 15px;background:#007bff;color:white;border:none;border-radius:5px;cursor:pointer}
button:hover{background:#0056b3}
</style>
</head>
<body>
<div class="container">
<h1>구글시트 연동 디버깅</h1>

<div class="test-section">
<h3>1. 기본 환경 확인</h3>
<?php
echo "<p><strong>PHP 버전:</strong> " . PHP_VERSION . "</p>";
echo "<p><strong>워드프레스 루트:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p><strong>현재 디렉토리:</strong> " . __DIR__ . "</p>";

// Python 확인
$python_path = '/usr/bin/python3';
$python_check = shell_exec("which python3 2>&1");
echo "<p><strong>Python3 경로:</strong> " . ($python_check ? trim($python_check) : '찾을 수 없음') . "</p>";

// Python 버전 확인
$python_version = shell_exec("python3 --version 2>&1");
echo "<p><strong>Python3 버전:</strong> " . ($python_version ? trim($python_version) : '확인 불가') . "</p>";
?>
</div>

<div class="test-section">
<h3>2. 파일 존재 확인</h3>
<?php
$files_to_check = [
    '/var/www/novacents/tools/google_token.json' => 'OAuth 토큰 파일',
    __DIR__ . '/google_sheets_manager.php' => 'Google Sheets Manager',
    __DIR__ . '/product_save_handler.php' => 'Product Save Handler'
];

foreach ($files_to_check as $file => $desc) {
    $exists = file_exists($file);
    $class = $exists ? 'success' : 'error';
    echo "<div class='$class'>";
    echo "<strong>$desc:</strong> $file - " . ($exists ? '존재함' : '존재하지 않음');
    if ($exists) {
        echo " (크기: " . filesize($file) . " bytes)";
    }
    echo "</div>";
}
?>
</div>

<div class="test-section">
<h3>3. OAuth 토큰 상태 확인</h3>
<?php
$token_file = '/var/www/novacents/tools/google_token.json';
if (file_exists($token_file)) {
    $token_content = file_get_contents($token_file);
    $token_data = json_decode($token_content, true);
    
    if ($token_data) {
        echo "<div class='success'><strong>토큰 파일 파싱:</strong> 성공</div>";
        echo "<p><strong>토큰 타입:</strong> " . ($token_data['type'] ?? '알 수 없음') . "</p>";
        echo "<p><strong>클라이언트 ID:</strong> " . substr($token_data['client_id'] ?? '', 0, 20) . "...</p>";
        echo "<p><strong>리프레시 토큰 존재:</strong> " . (isset($token_data['refresh_token']) ? '예' : '아니오') . "</p>";
    } else {
        echo "<div class='error'><strong>토큰 파일 파싱:</strong> 실패</div>";
        echo "<pre>" . htmlspecialchars($token_content) . "</pre>";
    }
} else {
    echo "<div class='error'><strong>토큰 파일:</strong> 존재하지 않음</div>";
}
?>
</div>

<div class="test-section">
<h3>4. Python 라이브러리 확인</h3>
<?php
$libraries = ['google-auth', 'google-auth-oauthlib', 'google-api-python-client'];
foreach ($libraries as $lib) {
    $check_cmd = "python3 -c \"import " . str_replace('-', '.', str_replace('google-api-python-client', 'googleapiclient', $lib)) . "; print('OK')\" 2>&1";
    $result = shell_exec($check_cmd);
    $success = strpos($result, 'OK') !== false;
    $class = $success ? 'success' : 'error';
    echo "<div class='$class'><strong>$lib:</strong> " . ($success ? '설치됨' : '설치되지 않음') . "</div>";
    if (!$success) {
        echo "<pre>$result</pre>";
    }
}
?>
</div>

<div class="test-section">
<h3>5. 구글시트 연결 테스트</h3>
<button onclick="testGoogleSheets()">구글시트 연결 테스트</button>
<div id="sheetsTestResult"></div>
</div>

<div class="test-section">
<h3>6. product_save_handler.php 테스트</h3>
<button onclick="testProductHandler()">Product Handler 테스트</button>
<div id="handlerTestResult"></div>
</div>

</div>

<script>
async function testGoogleSheets() {
    const resultDiv = document.getElementById('sheetsTestResult');
    resultDiv.innerHTML = '<p>테스트 중...</p>';
    
    try {
        const response = await fetch('test_sheets_connection.php');
        const result = await response.text();
        resultDiv.innerHTML = '<pre>' + result + '</pre>';
    } catch (error) {
        resultDiv.innerHTML = '<div class="error">오류: ' + error.message + '</div>';
    }
}

async function testProductHandler() {
    const resultDiv = document.getElementById('handlerTestResult');
    resultDiv.innerHTML = '<p>테스트 중...</p>';
    
    try {
        const response = await fetch('product_save_handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({action: 'load'})
        });
        const result = await response.json();
        resultDiv.innerHTML = '<pre>' + JSON.stringify(result, null, 2) + '</pre>';
    } catch (error) {
        resultDiv.innerHTML = '<div class="error">오류: ' + error.message + '</div>';
    }
}
</script>
</body>
</html>