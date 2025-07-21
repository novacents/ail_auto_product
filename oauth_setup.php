<?php
/**
 * Google Drive OAuth 웹 기반 설정 페이지
 * 브라우저에서 쉽게 OAuth 인증을 수행할 수 있습니다.
 */

session_start();

// OAuth 클라이언트 정보
$client_id = "558249385120-e23fac20819dq4t3abahm06rdh4narjh.apps.googleusercontent.com";
$client_secret = "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";
$redirect_uri = "https://novacents.com/tools/oauth_setup.php";
$token_file = "/var/www/novacents/tools/google_token.json";

// 1단계: 인증 URL로 리다이렉트
if (!isset($_GET['code']) && !isset($_GET['action'])) {
    $auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive',
        'access_type' => 'offline',
        'prompt' => 'consent'
    ]);
    ?>
    <!DOCTYPE html>
    <html lang="ko">
    <head>
        <meta charset="UTF-8">
        <title>Google Drive OAuth 설정</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                max-width: 600px;
                margin: 50px auto;
                padding: 20px;
                background-color: #f5f5f5;
            }
            .container {
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            h1 {
                color: #333;
                text-align: center;
            }
            .info {
                background: #e3f2fd;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
            }
            .button {
                display: block;
                width: 100%;
                padding: 15px;
                background: #4285f4;
                color: white;
                text-align: center;
                text-decoration: none;
                border-radius: 5px;
                font-size: 16px;
                margin-top: 20px;
            }
            .button:hover {
                background: #357ae8;
            }
            .warning {
                background: #fff3cd;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
                border: 1px solid #ffeaa7;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔐 Google Drive OAuth 설정</h1>
            
            <div class="info">
                <h3>📋 설정 과정</h3>
                <ol>
                    <li>아래 버튼을 클릭하여 Google 로그인</li>
                    <li>Google Drive 접근 권한 허용</li>
                    <li>자동으로 토큰 파일 생성</li>
                    <li>설정 완료!</li>
                </ol>
            </div>
            
            <?php if (file_exists($token_file)): ?>
            <div class="warning">
                <strong>⚠️ 주의:</strong> 토큰 파일이 이미 존재합니다.<br>
                다시 설정하면 기존 토큰이 덮어씌워집니다.
            </div>
            <?php endif; ?>
            
            <a href="<?php echo $auth_url; ?>" class="button">
                🚀 Google 계정으로 로그인하기
            </a>
            
            <p style="text-align: center; margin-top: 20px; color: #666;">
                이 설정은 최초 1회만 필요합니다.
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// 2단계: 인증 코드 받기
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // 토큰 교환
    $token_url = "https://oauth2.googleapis.com/token";
    $token_data = [
        'code' => $code,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code'
    ];
    
    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code == 200) {
        $token_info = json_decode($response, true);
        
        // google_token.json 형식으로 변환
        $google_token = [
            "type" => "authorized_user",
            "client_id" => $client_id,
            "client_secret" => $client_secret,
            "refresh_token" => $token_info['refresh_token'] ?? null
        ];
        
        // 토큰 저장
        $saved = file_put_contents($token_file, json_encode($google_token, JSON_PRETTY_PRINT));
        
        if ($saved) {
            // 파일 권한 설정
            chmod($token_file, 0600);
            
            ?>
            <!DOCTYPE html>
            <html lang="ko">
            <head>
                <meta charset="UTF-8">
                <title>OAuth 설정 완료</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        max-width: 600px;
                        margin: 50px auto;
                        padding: 20px;
                        background-color: #f5f5f5;
                    }
                    .container {
                        background: white;
                        padding: 30px;
                        border-radius: 10px;
                        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    }
                    .success {
                        background: #d4edda;
                        padding: 20px;
                        border-radius: 5px;
                        margin: 20px 0;
                        text-align: center;
                        font-size: 18px;
                        color: #155724;
                    }
                    .button {
                        display: inline-block;
                        padding: 10px 20px;
                        background: #28a745;
                        color: white;
                        text-decoration: none;
                        border-radius: 5px;
                        margin: 10px;
                    }
                    .button:hover {
                        background: #218838;
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <h1 style="text-align: center;">✅ OAuth 설정 완료!</h1>
                    
                    <div class="success">
                        <strong>토큰이 성공적으로 저장되었습니다!</strong><br>
                        이제 이미지 자동화 시스템을 사용할 수 있습니다.
                    </div>
                    
                    <div style="text-align: center;">
                        <a href="image_selector.php" class="button">이미지 선택 페이지로 이동</a>
                        <a href="affiliate_editor.php" class="button">에디터로 돌아가기</a>
                    </div>
                    
                    <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 5px;">
                        <h3>📁 토큰 파일 정보</h3>
                        <p>경로: <?php echo $token_file; ?></p>
                        <p>권한: 600 (소유자만 읽기/쓰기)</p>
                    </div>
                </div>
            </body>
            </html>
            <?php
        } else {
            echo "<h1>❌ 오류</h1>";
            echo "<p>토큰 파일 저장에 실패했습니다.</p>";
            echo "<p>경로: " . $token_file . "</p>";
        }
    } else {
        echo "<h1>❌ 오류</h1>";
        echo "<p>토큰 교환에 실패했습니다.</p>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
    }
}