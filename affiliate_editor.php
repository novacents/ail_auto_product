<?php
/**
 * 어필리에이트 상품 등록 자동화 입력 페이지 (AliExpress 공식 스타일 - 좌우 분할 + 📱 반응형)
 * 노바센트(novacents.com) 전용
 * 알리익스프레스 어필리에이트 전용 상품 글 생성
 * + HTML 소스 생성 및 클립보드 복사 기능
 * + 인라인 키워드 입력창
 * + 🌟 AliExpress 공식 스타일 HTML 레이아웃 (좌우 분할)
 * + 🎨 왼쪽 큰 이미지, 오른쪽 모든 정보, 하단 구매 버튼
 * + 📱 모바일/태블릿 완전 반응형 지원
 */

// 워드프레스 환경 로드
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');

// 관리자 권한 확인
if (!current_user_can('manage_options')) {
    wp_die('접근 권한이 없습니다.');
}

// 환경변수 로드
$env_file = '/home/novacents/.env';
$env_vars = [];
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $env_vars[trim($key)] = trim($value);
        }
    }
}

// Gemini API 제목 생성 처리
if (isset($_POST['action']) && $_POST['action'] === 'generate_titles') {
    header('Content-Type: application/json');
    
    $keywords_input = sanitize_text_field($_POST['keywords']);
    
    if (empty($keywords_input)) {
        echo json_encode(['success' => false, 'message' => '키워드를 입력해주세요.']);
        exit;
    }
    
    $keywords = array_map('trim', explode(',', $keywords_input));
    $keywords = array_filter($keywords);
    
    if (empty($keywords)) {
        echo json_encode(['success' => false, 'message' => '유효한 키워드를 입력해주세요.']);
        exit;
    }
    
    $combined_keywords = implode(',', $keywords);
    
    $script_locations = [
        __DIR__ . '/title_generator.py',
        '/home/novacents/title_generator.py'
    ];
    
    $output = null;
    $found_script = false;

    foreach ($script_locations as $script_path) {
        if (file_exists($script_path)) {
            $script_dir = dirname($script_path);
            $command = "LANG=ko_KR.UTF-8 /usr/bin/env /usr/bin/python3 " . escapeshellarg($script_path) . " " . escapeshellarg($combined_keywords) . " 2>&1";
            
            $descriptorspec = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ];
            
            $process = proc_open($command, $descriptorspec, $pipes, $script_dir, null);
            
            if (is_resource($process)) {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                $error_output = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $return_code = proc_close($process);
                
                if ($return_code === 0 && !empty($output)) {
                    $found_script = true;
                    break;
                }
            }
        }
    }
    
    if (!$found_script) {
        echo json_encode(['success' => false, 'message' => 'Python 스크립트를 찾을 수 없거나 실행에 실패했습니다.']);
        exit;
    }
    
    $result = json_decode(trim($output), true);
    
    if ($result === null) {
        echo json_encode([
            'success' => false, 
            'message' => 'Python 스크립트 응답 파싱 실패.',
            'raw_output' => $output
        ]);
        exit;
    }
    
    echo json_encode($result);
    exit;
}

// URL 파라미터로 전달된 메시지 처리
$success_message = '';
$error_message = '';

if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = '글이 성공적으로 발행 대기열에 추가되었습니다!';
}

if (isset($_GET['error'])) {
    $error_message = '오류: ' . urldecode($_GET['error']);
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>어필리에이트 상품 등록 - 노바센트</title>
    <style>
        /* 기존 스타일 유지 + 새로운 스타일 추가 */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            min-width: 1200px;
            color: #1c1c1c;
        }
        
        .main-container {
            width: 1800px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header-section {
            padding: 30px;
            border-bottom: 1px solid #e0e0e0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .header-section h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
        }
        
        .header-section .subtitle {
            margin: 0 0 20px 0;
            opacity: 0.9;
        }
        
        .header-form {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 20px;
            margin-top: 20px;
        }
        
        .title-section {
            position: relative;
        }
        
        .title-input-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        .title-input-row input {
            flex: 1;
            padding: 12px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 6px;
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 16px;
        }
        
        .title-input-row input::placeholder {
            color: rgba(255,255,255,0.7);
        }
        
        .category-section select {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 6px;
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 16px;
        }
        
        .category-section select option {
            background: #333;
            color: white;
        }
        
        .main-content {
            display: flex;
            min-height: 600px;
        }
        
        .products-sidebar {
            width: 600px;
            border-right: 1px solid #e0e0e0;
            background: #fafafa;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
            background: white;
        }
        
        .progress-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .progress-text {
            font-weight: bold;
            color: #333;
        }
        
        .progress-bar {
            flex: 1;
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            margin: 0 15px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #45a049);
            width: 0%;
            transition: width 0.3s ease;
        }
        
        .products-list {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }
        
        .keyword-group {
            border-bottom: 1px solid #e0e0e0;
        }
        
        .keyword-header {
            padding: 15px 20px;
            background: white;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }
        
        .keyword-header:hover {
            background: #f8f9fa;
        }
        
        .keyword-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .keyword-name {
            font-weight: 600;
            color: #333;
        }
        
        .product-count {
            background: #007bff;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .keyword-actions {
            display: flex;
            gap: 8px;
        }
        
        .product-item {
            padding: 12px 40px;
            border-bottom: 1px solid #f5f5f5;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .product-item:hover {
            background: #f0f8ff;
        }
        
        .product-item.active {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
        }
        
        .product-status {
            font-size: 18px;
            width: 20px;
        }
        
        .product-name {
            flex: 1;
            font-size: 14px;
            color: #555;
        }
        
        .sidebar-actions {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            background: white;
        }
        
        /* 🔥 새로 추가: 인라인 키워드 입력창 스타일 */
        .keyword-input-section {
            margin-top: 10px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            display: none;
        }
        
        .keyword-input-section.show {
            display: block;
        }
        
        .keyword-input-row-inline {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .keyword-input-row-inline input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .keyword-input-row-inline button {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        
        .detail-panel {
            flex: 1;
            width: 1100px;
            padding: 30px;
            overflow-y: auto;
        }
        
        .detail-header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .product-url-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .url-input-group {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .url-input-group input {
            flex: 1;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
        }
        
        .analysis-result {
            margin-top: 15px;
            padding: 20px;
            background: white;
            border-radius: 8px;
            border: 1px solid #ddd;
            display: none;
        }
        
        .analysis-result.show {
            display: block;
        }
        
        /* 🎨 AliExpress 좌우분할 스타일 상품 카드 */
        .product-card {
            background: #fff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            border: 1px solid #f0f0f0;
            margin-bottom: 20px;
        }
        
        /* 🖼️ 좌우 분할 레이아웃 (이미지 400px, 정보 영역 확대) */
        .product-content-split {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 30px;
            align-items: start;
            margin-bottom: 25px;
        }
        
        /* 🖼️ 왼쪽 이미지 (400px로 조정) */
        .product-image-large {
            width: 100%;
        }
        
        .product-image-large img {
            width: 100%;
            max-width: 400px;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        /* 📋 오른쪽 정보 영역 (더 넓어짐) */
        .product-info-all {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        /* 🏷️ AliExpress 로고 (오른쪽 상단) */
        .aliexpress-logo-right {
            margin-bottom: 15px;
        }
        
        .aliexpress-logo-right img {
            width: 250px;
            height: 60px;
            object-fit: contain;
        }
        
        /* 📝 상품명 (오른쪽) - 23px → 21px로 수정 */
        .product-title-right {
            color: #1c1c1c;
            font-size: 21px;
            font-weight: 600;
            line-height: 1.4;
            margin: 0 0 20px 0;
            word-break: keep-all;
            overflow-wrap: break-word;
        }
        
        /* 💰 가격 (오른쪽, 지정된 배경색) - 40px로 증가, 세로 패딩 16px → 14px로 수정 */
        .product-price-right {
            background: linear-gradient(135deg, #e62e04 0%, #ff9900 100%);
            color: white;
            padding: 14px 30px;
            border-radius: 10px;
            font-size: 40px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(230, 46, 4, 0.3);
        }
        
        /* ⭐ 평점 (오른쪽, 고객만족도 형태) */
        .product-rating-right {
            color: #1c1c1c;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .rating-stars {
            color: #ff9900;
        }
        
        /* 📦 판매량 (오른쪽) */
        .product-sales-right {
            color: #1c1c1c;
            font-size: 18px;
            margin-bottom: 15px;
        }
        
        /* 📊 기타 정보 (오른쪽) */
        .product-extra-info-right {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 15px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            font-size: 16px;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #666;
            font-weight: 500;
        }
        
        .info-value {
            color: #1c1c1c;
            font-weight: 600;
        }
        
        /* 🛒 구매 버튼 (하단 전체 폭) */
        .purchase-button-full {
            text-align: center;
            margin-top: 30px;
            width: 100%;
        }
        
        .purchase-button-full img {
            max-width: 100%;
            height: auto;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .purchase-button-full img:hover {
            transform: scale(1.02);
        }
        
        /* HTML 소스 관련 스타일 */
        .html-source-section {
            margin-top: 30px;
            padding: 20px;
            background: #f1f8ff;
            border-radius: 8px;
            border: 1px solid #b3d9ff;
        }
        
        .html-source-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .html-source-header h4 {
            margin: 0;
            color: #0066cc;
            font-size: 18px;
        }
        
        .copy-btn {
            padding: 8px 16px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .copy-btn:hover {
            background: #0056b3;
        }
        
        .copy-btn.copied {
            background: #28a745;
        }
        
        .html-preview {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .html-code {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
            overflow-x: auto;
            white-space: pre;
            color: #333;
            max-height: 300px;
            overflow-y: auto;
        }
        
        /* 🎨 미리보기 AliExpress 좌우분할 스타일 */
        .preview-product-card {
            display: flex;
            justify-content: center;
            margin: 25px 0;
        }
        
        .preview-card-content {
            border: 2px solid #eee;
            padding: 30px;
            border-radius: 15px;
            background: #f9f9f9;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            max-width: 1000px;
            width: 100%;
        }
        
        /* 미리보기도 동일하게 400px로 조정 */
        .preview-content-split {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 30px;
            align-items: start;
            margin-bottom: 25px;
        }
        
        .preview-image-large {
            width: 100%;
            max-width: 400px;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        .preview-info-all {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .preview-aliexpress-logo {
            margin-bottom: 15px;
        }
        
        .preview-aliexpress-logo img {
            width: 250px;
            height: 60px;
            object-fit: contain;
        }
        
        /* 미리보기 상품명 - 23px → 21px로 수정 */
        .preview-card-title {
            color: #1c1c1c;
            margin: 0 0 20px 0;
            font-size: 21px;
            font-weight: 600;
            line-height: 1.4;
            word-break: keep-all;
            overflow-wrap: break-word;
        }
        
        /* 💰 미리보기 가격 - 40px로 증가, 세로 패딩 16px → 14px로 수정 */
        .preview-price-main {
            background: linear-gradient(135deg, #e62e04 0%, #ff9900 100%);
            color: white;
            padding: 14px 30px;
            border-radius: 10px;
            font-size: 40px;
            font-weight: 700;
            text-align: center;
            margin: 0 0 20px 0;
            box-shadow: 0 4px 15px rgba(230, 46, 4, 0.3);
        }
        
        .preview-rating {
            color: #1c1c1c;
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 15px 0;
        }
        
        .preview-rating .rating-stars {
            color: #ff9900;
        }
        
        .preview-sales {
            color: #1c1c1c;
            font-size: 18px;
            margin: 0 0 15px 0;
        }
        
        .preview-button-container {
            text-align: center;
            margin-top: 30px;
        }
        
        .preview-button-container img {
            max-width: 100%;
            height: auto;
            cursor: pointer;
        }
        
        .user-input-section {
            margin-top: 30px;
        }
        
        .input-group {
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
        }
        
        .input-group h3 {
            margin: 0 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            color: #333;
            font-size: 18px;
        }
        
        .form-row {
            display: grid;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-row.two-col {
            grid-template-columns: 1fr 1fr;
        }
        
        .form-row.three-col {
            grid-template-columns: 1fr 1fr 1fr;
        }
        
        .form-field label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-field input,
        .form-field textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .form-field textarea {
            min-height: 60px;
            resize: vertical;
        }
        
        .advantages-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .advantages-list li {
            margin-bottom: 10px;
        }
        
        .advantages-list input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .navigation-section {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #f0f0f0;
            text-align: center;
        }
        
        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            margin: 0 5px;
        }
        
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #0056b3;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #545b62;
        }
        
        .btn-success {
            background-color: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #1e7e34;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-large {
            padding: 15px 30px;
            font-size: 16px;
        }
        
        .keyword-generator {
            margin-top: 15px;
            padding: 15px;
            background-color: rgba(255,255,255,0.1);
            border-radius: 6px;
            display: none;
        }
        
        .keyword-input-row {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .keyword-input-row input {
            flex: 1;
            padding: 10px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 4px;
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .generated-titles {
            margin-top: 15px;
        }
        
        .title-options {
            display: grid;
            gap: 8px;
        }
        
        .title-option {
            padding: 12px 15px;
            background-color: rgba(255,255,255,0.1);
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            text-align: left;
            color: white;
        }
        
        .title-option:hover {
            background-color: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.6);
        }
        
        .loading {
            display: none;
            text-align: center;
            color: rgba(255,255,255,0.8);
            margin-top: 10px;
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }
        
        .empty-state h3 {
            margin: 0 0 10px 0;
            color: #999;
        }
        
        /* 반응형 디자인 */
        @media (max-width: 1920px) {
            .main-container {
                width: 95%;
                min-width: 1400px;
            }
        }
        
        @media (max-width: 1600px) {
            .main-container {
                transform: scale(0.9);
                transform-origin: top center;
                margin-top: -50px;
            }
        }
        
        /* 📱 모바일/태블릿 반응형 CSS (768px 이하) */
        @media (max-width: 768px) {
            /* 기본 레이아웃 모바일 최적화 */
            body {
                min-width: unset;
                padding: 10px;
            }
            
            .main-container {
                width: 100%;
                transform: none;
                margin-top: 0;
            }
            
            .header-section {
                padding: 20px;
            }
            
            .header-section h1 {
                font-size: 24px;
            }
            
            .header-form {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .main-content {
                flex-direction: column;
            }
            
            .products-sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #e0e0e0;
            }
            
            .detail-panel {
                width: 100%;
                padding: 20px;
            }
            
            /* 📱 모바일 상품 카드 레이아웃 - 세로 배치 */
            .product-content-split {
                display: block !important;
                grid-template-columns: none !important;
                gap: 20px;
            }
            
            /* 📱 모바일 이미지 크기 최적화 (300px) */
            .product-image-large {
                text-align: center;
                margin-bottom: 20px;
            }
            
            .product-image-large img {
                max-width: 300px !important;
                width: 90%;
            }
            
            /* 📱 모바일 AliExpress 로고 - 좌측정렬 */
            .aliexpress-logo-right {
                text-align: left !important;
                margin-bottom: 15px;
            }
            
            .aliexpress-logo-right img {
                width: 200px;
                height: 48px;
            }
            
            /* 📱 모바일 상품명 - 좌측정렬, 크기 조정 */
            .product-title-right {
                text-align: left !important;
                font-size: 18px !important;
                line-height: 1.3;
                margin-bottom: 15px !important;
            }
            
            /* 📱 모바일 가격 박스 최적화 */
            .product-price-right {
                font-size: 28px !important;
                padding: 12px 20px !important;
                margin-bottom: 15px !important;
            }
            
            /* 📱 모바일 평점 최적화 */
            .product-rating-right {
                font-size: 16px !important;
                flex-direction: column;
                align-items: flex-start !important;
                gap: 5px !important;
                margin-bottom: 12px !important;
            }
            
            /* 📱 모바일 판매량 최적화 */
            .product-sales-right {
                font-size: 16px !important;
                margin-bottom: 12px !important;
            }
            
            /* 📱 모바일 기타 정보 숨기기 */
            .product-extra-info-right {
                display: none;
            }
            
            /* 📱 모바일 구매 버튼 최적화 */
            .purchase-button-full {
                margin-top: 20px;
            }
            
            /* 📱 모바일 미리보기도 동일하게 적용 */
            .preview-content-split {
                display: block !important;
                grid-template-columns: none !important;
                gap: 20px;
            }
            
            .preview-image-large {
                max-width: 300px !important;
                width: 90%;
                margin: 0 auto 20px auto;
                display: block;
            }
            
            .preview-aliexpress-logo {
                text-align: left !important;
            }
            
            .preview-aliexpress-logo img {
                width: 200px;
                height: 48px;
            }
            
            .preview-card-title {
                text-align: left !important;
                font-size: 18px !important;
                line-height: 1.3;
            }
            
            .preview-price-main {
                font-size: 28px !important;
                padding: 12px 20px !important;
                margin-bottom: 15px !important;
            }
            
            .preview-rating {
                font-size: 16px !important;
                flex-direction: column;
                align-items: flex-start !important;
                gap: 5px !important;
            }
            
            .preview-sales {
                font-size: 16px !important;
            }
            
            /* 📱 모바일 폼 최적화 */
            .url-input-group {
                flex-direction: column;
                gap: 10px;
            }
            
            .form-row.two-col,
            .form-row.three-col {
                grid-template-columns: 1fr;
            }
            
            .html-source-header {
                flex-direction: column;
                gap: 10px;
                align-items: stretch;
            }
            
            .html-code {
                font-size: 11px;
                max-height: 200px;
            }
        }
        
        /* 📱 추가 모바일 최적화 (480px 이하) */
        @media (max-width: 480px) {
            .header-section {
                padding: 15px;
            }
            
            .header-section h1 {
                font-size: 20px;
            }
            
            .detail-panel {
                padding: 15px;
            }
            
            .product-image-large img {
                max-width: 280px !important;
            }
            
            .aliexpress-logo-right img {
                width: 180px;
                height: 43px;
            }
            
            .product-title-right {
                font-size: 16px !important;
            }
            
            .product-price-right {
                font-size: 24px !important;
                padding: 10px 15px !important;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- 헤더 섹션 -->
        <div class="header-section">
            <h1>🛍️ 어필리에이트 상품 등록</h1>
            <p class="subtitle">알리익스프레스 전용 상품 글 생성기</p>
            
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success"><?php echo esc_html($success_message); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error"><?php echo esc_html($error_message); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="keyword_processor.php" id="affiliateForm">
                <div class="header-form">
                    <div class="title-section">
                        <label for="title" style="color: rgba(255,255,255,0.9); margin-bottom: 8px; display: block;">글 제목</label>
                        <div class="title-input-row">
                            <input type="text" id="title" name="title" placeholder="글 제목을 입력하거나 아래 '제목 생성' 버튼을 클릭하세요" required>
                            <button type="button" class="btn btn-secondary" onclick="toggleTitleGenerator()">제목 생성</button>
                        </div>
                        
                        <div class="keyword-generator" id="titleGenerator">
                            <label for="titleKeyword" style="color: rgba(255,255,255,0.9);">제목 생성 키워드 (콤마로 구분)</label>
                            <div class="keyword-input-row">
                                <input type="text" id="titleKeyword" placeholder="예: 물놀이용품, 비치웨어, 여름용품">
                                <button type="button" class="btn btn-primary" onclick="generateTitles()">생성</button>
                            </div>
                            
                            <div class="loading" id="titleLoading">
                                <div class="spinner"></div>
                                제목을 생성하고 있습니다...
                            </div>
                            
                            <div class="generated-titles" id="generatedTitles" style="display:none;">
                                <label style="color: rgba(255,255,255,0.9);">추천 제목 (클릭하여 선택)</label>
                                <div class="title-options" id="titleOptions"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="category-section">
                        <label for="category" style="color: rgba(255,255,255,0.9); margin-bottom: 8px; display: block;">카테고리</label>
                        <select id="category" name="category" required>
                            <option value="356" selected>스마트 리빙</option>
                            <option value="355">기발한 잡화점</option>
                            <option value="354">Today's Pick</option>
                            <option value="12">우리잇템</option>
                        </select>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- 메인 콘텐츠 -->
        <div class="main-content">
            <!-- 상품 목록 사이드바 -->
            <div class="products-sidebar">
                <div class="sidebar-header">
                    <div class="progress-info">
                        <span class="progress-text">진행률</span>
                        <div class="progress-bar">
                            <div class="progress-fill" id="progressFill"></div>
                        </div>
                        <span class="progress-text" id="progressText">0/0 완성</span>
                    </div>
                </div>
                
                <div class="sidebar-actions">
                    <!-- 🔥 개선된 키워드 추가 버튼 -->
                    <button type="button" class="btn btn-primary" onclick="toggleKeywordInput()" style="width: 100%; margin-bottom: 10px;">📁 키워드 추가</button>
                    
                    <!-- 🔥 새로 추가: 인라인 키워드 입력창 -->
                    <div class="keyword-input-section" id="keywordInputSection">
                        <div class="keyword-input-row-inline">
                            <input type="text" id="newKeywordInput" placeholder="새 키워드를 입력하세요" />
                            <button type="button" class="btn-success" onclick="addKeywordFromInput()">추가</button>
                            <button type="button" class="btn-secondary" onclick="cancelKeywordInput()">취소</button>
                        </div>
                    </div>
                    
                    <button type="button" class="btn btn-success" onclick="saveAll()" style="width: 100%;">💾 전체 저장</button>
                </div>
                
                <div class="products-list" id="productsList">
                    <div class="empty-state">
                        <h3>📦 상품이 없습니다</h3>
                        <p>위의 "키워드 추가" 버튼을 클릭하여<br>첫 번째 키워드를 추가해보세요!</p>
                    </div>
                </div>
            </div>
            
            <!-- 상세 편집 패널 -->
            <div class="detail-panel">
                <div class="detail-header">
                    <h2 id="currentProductTitle">상품을 선택해주세요</h2>
                    <p id="currentProductSubtitle">왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.</p>
                </div>
                
                <div id="productDetailContent" style="display: none;">
                    <!-- 상품 URL 입력 섹션 -->
                    <div class="product-url-section">
                        <h3>🌏 알리익스프레스 상품 URL</h3>
                        <div class="url-input-group">
                            <input type="url" id="productUrl" placeholder="예: https://www.aliexpress.com/item/123456789.html">
                            <button type="button" class="btn btn-primary" onclick="analyzeProduct()">🔍 분석</button>
                        </div>
                        
                        <!-- 인라인 분석 결과 -->
                        <div class="analysis-result" id="analysisResult">
                            <div class="product-card" id="productCard">
                                <!-- 분석 결과가 여기에 표시됩니다 -->
                            </div>
                            
                            <!-- HTML 소스 생성 섹션 -->
                            <div class="html-source-section" id="htmlSourceSection" style="display: none;">
                                <div class="html-source-header">
                                    <h4>📝 워드프레스 글 HTML 소스</h4>
                                    <button type="button" class="copy-btn" onclick="copyHtmlSource()">📋 복사하기</button>
                                </div>
                                
                                <div class="html-preview">
                                    <h5 style="margin: 0 0 10px 0; color: #666;">미리보기:</h5>
                                    <div id="htmlPreview">
                                        <!-- HTML 미리보기가 여기에 표시됩니다 -->
                                    </div>
                                </div>
                                
                                <div class="html-code" id="htmlCode">
                                    <!-- HTML 소스 코드가 여기에 표시됩니다 -->
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 사용자 입력 양식 (기존과 동일) -->
                    <div class="user-input-section">
                        <!-- 기능 및 스펙 -->
                        <div class="input-group">
                            <h3>⚙️ 기능 및 스펙</h3>
                            <div class="form-row">
                                <div class="form-field">
                                    <label>주요 기능</label>
                                    <input type="text" placeholder="예: 자동 압축, 물 절약, 시간 단축 등">
                                </div>
                            </div>
                            <div class="form-row two-col">
                                <div class="form-field">
                                    <label>크기/용량</label>
                                    <input type="text" placeholder="예: 30cm × 20cm, 500ml 등">
                                </div>
                                <div class="form-field">
                                    <label>색상</label>
                                    <input type="text" placeholder="예: 화이트, 블랙, 실버 등">
                                </div>
                            </div>
                            <div class="form-row two-col">
                                <div class="form-field">
                                    <label>재질/소재</label>
                                    <input type="text" placeholder="예: 스테인리스 스틸, 실리콘 등">
                                </div>
                                <div class="form-field">
                                    <label>전원/배터리</label>
                                    <input type="text" placeholder="예: USB 충전, 건전지 등">
                                </div>
                            </div>
                        </div>
                        
                        <!-- 효율성 분석 -->
                        <div class="input-group">
                            <h3>📊 효율성 분석</h3>
                            <div class="form-row">
                                <div class="form-field">
                                    <label>해결하는 문제</label>
                                    <input type="text" placeholder="예: 설거지 시간 오래 걸림">
                                </div>
                            </div>
                            <div class="form-row two-col">
                                <div class="form-field">
                                    <label>시간 절약 효과</label>
                                    <input type="text" placeholder="예: 기존 10분 → 3분으로 단축">
                                </div>
                                <div class="form-field">
                                    <label>공간 활용</label>
                                    <input type="text" placeholder="예: 50% 공간 절약">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-field">
                                    <label>비용 절감</label>
                                    <input type="text" placeholder="예: 월 전기료 30% 절약">
                                </div>
                            </div>
                        </div>
                        
                        <!-- 사용 시나리오 -->
                        <div class="input-group">
                            <h3>🏠 사용 시나리오</h3>
                            <div class="form-row two-col">
                                <div class="form-field">
                                    <label>주요 사용 장소</label>
                                    <input type="text" placeholder="예: 주방, 욕실, 거실 등">
                                </div>
                                <div class="form-field">
                                    <label>사용 빈도</label>
                                    <input type="text" placeholder="예: 매일, 주 2-3회 등">
                                </div>
                            </div>
                            <div class="form-row two-col">
                                <div class="form-field">
                                    <label>적합한 사용자</label>
                                    <input type="text" placeholder="예: 1인 가구, 맞벌이 부부 등">
                                </div>
                                <div class="form-field">
                                    <label>사용법 요약</label>
                                    <input type="text" placeholder="간단한 사용 단계">
                                </div>
                            </div>
                        </div>
                        
                        <!-- 장점 및 주의사항 -->
                        <div class="input-group">
                            <h3>✅ 장점 및 주의사항</h3>
                            <div class="form-row">
                                <div class="form-field">
                                    <label>핵심 장점 3가지</label>
                                    <ol class="advantages-list">
                                        <li><input type="text" placeholder="예: 설치 간편함"></li>
                                        <li><input type="text" placeholder="예: 유지비 저렴함"></li>
                                        <li><input type="text" placeholder="예: 내구성 뛰어남"></li>
                                    </ol>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-field">
                                    <label>주의사항</label>
                                    <textarea placeholder="예: 물기 주의, 정기 청소 필요 등"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 네비게이션 -->
                    <div class="navigation-section">
                        <button type="button" class="btn btn-secondary" onclick="previousProduct()">⬅️ 이전</button>
                        <button type="button" class="btn btn-success" onclick="saveCurrentProduct()">💾 저장</button>
                        <button type="button" class="btn btn-secondary" onclick="nextProduct()">다음 ➡️</button>
                        <button type="button" class="btn btn-primary btn-large" onclick="completeProduct()">✅ 완료</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // 전역 변수
        let keywords = [];
        let currentKeywordIndex = -1;
        let currentProductIndex = -1;
        let currentProductData = null;
        
        // 초기화
        document.addEventListener('DOMContentLoaded', function() {
            updateUI();
        });
        
        // 🔧 가격 공백 추가 함수
        function formatPrice(price) {
            if (!price) return price;
            // ₩와 숫자 사이에 공백 추가 (₩3,440 → ₩ 3,440)
            return price.replace(/₩(\d)/, '₩ $1');
        }
        
        // 🔧 개선된 오류 처리 함수들 (복사 가능한 큰 팝업)
        function showDetailedError(title, message, debugData = null) {
            // 기존 오류 모달이 있으면 제거
            const existingModal = document.getElementById('errorModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // 상세 정보 포함
            let fullMessage = message;
            if (debugData) {
                fullMessage += '\n\n=== 디버그 정보 ===\n';
                fullMessage += JSON.stringify(debugData, null, 2);
            }
            
            // 큰 모달 창 생성
            const modal = document.createElement('div');
            modal.id = 'errorModal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            
            modal.innerHTML = `
                <div style="
                    background: white;
                    border-radius: 10px;
                    padding: 30px;
                    max-width: 800px;
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
                ">
                    <h2 style="color: #dc3545; margin-bottom: 20px; font-size: 24px;">
                        🚨 ${title}
                    </h2>
                    
                    <div style="margin-bottom: 20px;">
                        <textarea id="errorContent" readonly style="
                            width: 100%;
                            height: 300px;
                            padding: 15px;
                            border: 1px solid #ddd;
                            border-radius: 6px;
                            font-family: 'Courier New', monospace;
                            font-size: 12px;
                            line-height: 1.4;
                            background: #f8f9fa;
                            resize: vertical;
                        ">${fullMessage}</textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button onclick="copyErrorToClipboard()" style="
                            padding: 10px 20px;
                            background: #007bff;
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 14px;
                        ">📋 복사하기</button>
                        
                        <button onclick="closeErrorModal()" style="
                            padding: 10px 20px;
                            background: #6c757d;
                            color: white;
                            border: none;
                            border-radius: 6px;
                            cursor: pointer;
                            font-size: 14px;
                        ">닫기</button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // 모달 외부 클릭시 닫기
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeErrorModal();
                }
            });
        }

        // 오류 내용 클립보드 복사
        function copyErrorToClipboard() {
            const errorContent = document.getElementById('errorContent');
            errorContent.select();
            document.execCommand('copy');
            
            // 복사 완료 알림
            const copyBtn = event.target;
            const originalText = copyBtn.textContent;
            copyBtn.textContent = '✅ 복사됨!';
            copyBtn.style.background = '#28a745';
            
            setTimeout(() => {
                copyBtn.textContent = originalText;
                copyBtn.style.background = '#007bff';
            }, 2000);
        }

        // 오류 모달 닫기
        function closeErrorModal() {
            const modal = document.getElementById('errorModal');
            if (modal) {
                modal.remove();
            }
        }

        // ESC 키로 모달 닫기
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeErrorModal();
            }
        });
        
        // 제목 생성기 토글
        function toggleTitleGenerator() {
            const generator = document.getElementById('titleGenerator');
            generator.style.display = generator.style.display === 'none' ? 'block' : 'none';
        }
        
        // 제목 생성
        async function generateTitles() {
            const keywordsInput = document.getElementById('titleKeyword').value.trim();
            
            if (!keywordsInput) {
                showDetailedError('입력 오류', '키워드를 입력해주세요.');
                return;
            }
            
            const loading = document.getElementById('titleLoading');
            const titlesDiv = document.getElementById('generatedTitles');
            
            loading.style.display = 'block';
            titlesDiv.style.display = 'none';
            
            try {
                const formData = new FormData();
                formData.append('action', 'generate_titles');
                formData.append('keywords', keywordsInput);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    displayTitles(result.titles);
                } else {
                    showDetailedError('제목 생성 실패', result.message);
                }
            } catch (error) {
                showDetailedError('제목 생성 오류', '제목 생성 중 오류가 발생했습니다.', {
                    'error': error.message,
                    'keywords': keywordsInput
                });
            } finally {
                loading.style.display = 'none';
            }
        }
        
        // 생성된 제목 표시
        function displayTitles(titles) {
            const optionsDiv = document.getElementById('titleOptions');
            const titlesDiv = document.getElementById('generatedTitles');
            
            optionsDiv.innerHTML = '';
            
            titles.forEach((title) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'title-option';
                button.textContent = title;
                button.onclick = () => selectTitle(title);
                optionsDiv.appendChild(button);
            });
            
            titlesDiv.style.display = 'block';
        }
        
        // 제목 선택
        function selectTitle(title) {
            document.getElementById('title').value = title;
            document.getElementById('titleGenerator').style.display = 'none';
        }
        
        // 🔥 새로 추가: 인라인 키워드 입력창 토글
        function toggleKeywordInput() {
            const inputSection = document.getElementById('keywordInputSection');
            const isVisible = inputSection.classList.contains('show');
            
            if (isVisible) {
                inputSection.classList.remove('show');
            } else {
                inputSection.classList.add('show');
                document.getElementById('newKeywordInput').focus();
            }
        }
        
        // 🔥 새로 추가: 입력창에서 키워드 추가
        function addKeywordFromInput() {
            const input = document.getElementById('newKeywordInput');
            const name = input.value.trim();
            
            if (name) {
                const keyword = {
                    name: name,
                    products: []
                };
                keywords.push(keyword);
                updateUI();
                
                // 입력창 초기화 및 숨기기
                input.value = '';
                document.getElementById('keywordInputSection').classList.remove('show');
                
                // 새로 추가된 키워드에 첫 번째 상품 추가
                addProduct(keywords.length - 1);
            } else {
                showDetailedError('입력 오류', '키워드를 입력해주세요.');
            }
        }
        
        // 🔥 새로 추가: 키워드 입력 취소
        function cancelKeywordInput() {
            const input = document.getElementById('newKeywordInput');
            input.value = '';
            document.getElementById('keywordInputSection').classList.remove('show');
        }
        
        // 🔥 새로 추가: 엔터키로 키워드 추가
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('newKeywordInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    addKeywordFromInput();
                }
            });
        });
        
        // 기존 키워드 추가 함수 (폴백용)
        function addKeyword() {
            // 인라인 입력창으로 대체됨
            toggleKeywordInput();
        }
        
        // 상품 추가
        function addProduct(keywordIndex) {
            const product = {
                id: Date.now() + Math.random(),
                url: '',
                name: `상품 ${keywords[keywordIndex].products.length + 1}`,
                status: 'empty',
                analysisData: null,
                userData: {}
            };
            
            keywords[keywordIndex].products.push(product);
            updateUI();
            
            // 새로 추가된 상품 선택
            selectProduct(keywordIndex, keywords[keywordIndex].products.length - 1);
        }
        
        // 상품 선택
        function selectProduct(keywordIndex, productIndex) {
            currentKeywordIndex = keywordIndex;
            currentProductIndex = productIndex;
            
            const product = keywords[keywordIndex].products[productIndex];
            
            // 상품 선택 상태 업데이트
            document.querySelectorAll('.product-item').forEach(item => {
                item.classList.remove('active');
            });
            
            const selectedItem = document.querySelector(`[data-keyword="${keywordIndex}"][data-product="${productIndex}"]`);
            if (selectedItem) {
                selectedItem.classList.add('active');
            }
            
            // 상세 패널 업데이트
            updateDetailPanel(product);
        }
        
        // 상세 패널 업데이트
        function updateDetailPanel(product) {
            const titleEl = document.getElementById('currentProductTitle');
            const subtitleEl = document.getElementById('currentProductSubtitle');
            const contentEl = document.getElementById('productDetailContent');
            const urlInput = document.getElementById('productUrl');
            
            titleEl.textContent = product.name;
            subtitleEl.textContent = `키워드: ${keywords[currentKeywordIndex].name}`;
            
            urlInput.value = product.url || '';
            
            // 분석 결과 표시
            if (product.analysisData) {
                showAnalysisResult(product.analysisData);
            } else {
                hideAnalysisResult();
            }
            
            contentEl.style.display = 'block';
        }
        
        // 🔧 개선된 상품 분석 (AliExpress 좌우분할 스타일)
        async function analyzeProduct() {
            const url = document.getElementById('productUrl').value.trim();
            
            if (!url) {
                showDetailedError('입력 오류', '상품 URL을 입력해주세요.');
                return;
            }
            
            if (currentKeywordIndex === -1 || currentProductIndex === -1) {
                showDetailedError('선택 오류', '상품을 먼저 선택해주세요.');
                return;
            }
            
            const product = keywords[currentKeywordIndex].products[currentProductIndex];
            product.url = url;
            product.status = 'analyzing';
            updateUI();
            
            try {
                console.log('🚀 AliExpress 좌우분할 스타일 API 호출 시작');
                
                const response = await fetch('product_analyzer_v2.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'analyze_product',
                        url: url,
                        platform: 'aliexpress'
                    })
                });
                
                // 🔧 응답 상태 확인 강화
                if (!response.ok) {
                    throw new Error(`HTTP 오류: ${response.status} ${response.statusText}`);
                }
                
                const responseText = await response.text();
                console.log('📨 원본 응답:', responseText);
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    // 🔧 JSON 파싱 실패 시 상세 오류 표시
                    showDetailedError(
                        'JSON 파싱 오류', 
                        '서버 응답을 파싱할 수 없습니다.',
                        {
                            'parseError': parseError.message,
                            'responseText': responseText,
                            'responseLength': responseText.length,
                            'url': url,
                            'timestamp': new Date().toISOString()
                        }
                    );
                    product.status = 'error';
                    updateUI();
                    return;
                }
                
                console.log('📊 파싱된 결과:', result);
                
                if (result.success) {
                    product.analysisData = result.data;
                    product.status = 'completed';
                    product.name = result.data.title || `상품 ${currentProductIndex + 1}`;
                    currentProductData = result.data;
                    
                    showAnalysisResult(result.data);
                    generateResponsiveAliExpressHtml(result.data);
                    
                    // 🎨 성공 로그 (AliExpress 좌우분할 스타일)
                    console.log('✅ AliExpress 좌우분할 스타일 분석 성공:');
                    console.log('  한국어 상품명:', result.data.title || 'N/A');
                    console.log('  평점 표시:', result.data.rating_display || 'N/A');
                    console.log('  판매량:', result.data.lastest_volume || 'N/A');
                    console.log('  전체 데이터:', result.data);
                    
                } else {
                    product.status = 'error';
                    
                    // 🔧 상세한 오류 정보 표시 (개선된 모달 사용)
                    showDetailedError(
                        '상품 분석 실패',
                        result.message || '알 수 없는 오류가 발생했습니다.',
                        {
                            'success': result.success,
                            'message': result.message,
                            'debug_info': result.debug_info || null,
                            'raw_output': result.raw_output || null,
                            'url': url,
                            'platform': 'aliexpress',
                            'timestamp': new Date().toISOString(),
                            'responsive_style_applied': true
                        }
                    );
                    
                    console.error('❌ 분석 실패:', result);
                }
                
            } catch (error) {
                product.status = 'error';
                
                // 🔧 네트워크 오류 상세 표시
                showDetailedError(
                    '네트워크 오류',
                    '상품 분석 중 네트워크 오류가 발생했습니다.',
                    {
                        'error': error.message,
                        'stack': error.stack,
                        'url': url,
                        'timestamp': new Date().toISOString()
                    }
                );
                
                console.error('❌ 네트워크 오류:', error);
            }
            
            updateUI();
        }
        
        // 🎨 AliExpress 좌우분할 스타일 분석 결과 표시
        function showAnalysisResult(data) {
            const resultEl = document.getElementById('analysisResult');
            const cardEl = document.getElementById('productCard');
            
            // 평점 처리 (고객만족도 형태) - 중복 괄호 제거
            const ratingDisplay = data.rating_display ? data.rating_display.replace(/⭐/g, '').replace(/[()]/g, '').trim() : '정보 없음';
            
            // 🔧 가격 공백 추가 처리
            const formattedPrice = formatPrice(data.price);
            
            // AliExpress 좌우분할 스타일 카드 디자인 - 분석화면 블록 (400px 이미지)
            cardEl.innerHTML = `
                <!-- 좌우 분할 레이아웃 (이미지 400px, 정보 영역 확대) -->
                <div class="product-content-split">
                    <!-- 왼쪽: 이미지 (400px로 조정) -->
                    <div class="product-image-large">
                        <img src="${data.image_url}" alt="${data.title}" onerror="this.style.display='none'">
                    </div>
                    
                    <!-- 오른쪽: 모든 정보 (더 넓어진 영역) -->
                    <div class="product-info-all">
                        <!-- AliExpress 로고 -->
                        <div class="aliexpress-logo-right">
                            <img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" />
                        </div>
                        
                        <!-- 상품명 -->
                        <h3 class="product-title-right">${data.title}</h3>
                        
                        <!-- 가격 (지정된 배경색) -->
                        <div class="product-price-right">
                            ${formattedPrice}
                        </div>
                        
                        <!-- 평점 (고객만족도 형태) -->
                        <div class="product-rating-right">
                            <span class="rating-stars">⭐⭐⭐⭐⭐</span>
                            <span>(고객만족도: ${ratingDisplay})</span>
                        </div>
                        
                        <!-- 판매량 -->
                        <div class="product-sales-right">
                            <strong>📦 판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}
                        </div>
                        
                        <!-- 기타 정보 -->
                        <div class="product-extra-info-right">
                            <div class="info-row">
                                <span class="info-label">상품 ID</span>
                                <span class="info-value">${data.product_id}</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">플랫폼</span>
                                <span class="info-value">${data.platform}</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 구매 버튼 (하단 전체 폭) -->
                <div class="purchase-button-full">
                    <a href="${data.affiliate_link}" target="_blank" rel="nofollow">
                        <picture>
                            <source media="(max-width: 768px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png">
                            <img src="https://novacents.com/tools/images/aliexpress-button-pc.png" 
                                 alt="알리익스프레스에서 구매하기">
                        </picture>
                    </a>
                </div>
            `;
            
            resultEl.classList.add('show');
        }
        
        // 📱 반응형 AliExpress HTML 소스 생성 함수 (모바일 최적화)
        function generateResponsiveAliExpressHtml(data) {
            if (!data) return;
            
            console.log('📱 반응형 AliExpress HTML 소스 생성 시작');
            
            // 평점 처리 (고객만족도 형태) - 중복 괄호 제거
            const ratingDisplay = data.rating_display ? data.rating_display.replace(/⭐/g, '').replace(/[()]/g, '').trim() : '정보 없음';
            
            // 🔧 가격 공백 추가 처리
            const formattedPrice = formatPrice(data.price);
            
            // 📱 완전 반응형 AliExpress HTML 코드 생성 (모바일 좌측정렬)
            const htmlCode = `<div style="display: flex; justify-content: center; margin: 25px 0;">
    <div style="border: 2px solid #eee; padding: 30px; border-radius: 15px; background: #f9f9f9; box-shadow: 0 4px 8px rgba(0,0,0,0.1); max-width: 1000px; width: 100%;">
        
        <!-- 📱 반응형 좌우 분할 레이아웃 -->
        <div style="display: grid; grid-template-columns: 400px 1fr; gap: 30px; align-items: start; margin-bottom: 25px;">
            <!-- 왼쪽: 이미지 (PC: 400px, 모바일: 300px) -->
            <div style="text-align: center;">
                <img src="${data.image_url}" alt="${data.title}" style="width: 100%; max-width: 400px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.15);">
            </div>
            
            <!-- 오른쪽: 모든 정보 -->
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <!-- AliExpress 로고 (PC: 중앙, 모바일: 좌측) -->
                <div style="margin-bottom: 15px; text-align: center;">
                    <img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" style="width: 250px; height: 60px; object-fit: contain;" />
                </div>
                
                <!-- 상품명 (PC: 중앙, 모바일: 좌측) -->
                <h3 style="color: #1c1c1c; margin: 0 0 20px 0; font-size: 21px; font-weight: 600; line-height: 1.4; word-break: keep-all; overflow-wrap: break-word; text-align: center;">${data.title}</h3>
                
                <!-- 가격 (지정된 배경색) -->
                <div style="background: linear-gradient(135deg, #e62e04 0%, #ff9900 100%); color: white; padding: 14px 30px; border-radius: 10px; font-size: 40px; font-weight: 700; text-align: center; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(230, 46, 4, 0.3);">
                    <strong>${formattedPrice}</strong>
                </div>
                
                <!-- 평점 (고객만족도 형태) -->
                <div style="color: #1c1c1c; font-size: 20px; display: flex; align-items: center; gap: 10px; margin-bottom: 15px; justify-content: center;">
                    <span style="color: #ff9900;">⭐⭐⭐⭐⭐</span>
                    <span>(고객만족도: ${ratingDisplay})</span>
                </div>
                
                <!-- 판매량 -->
                <p style="color: #1c1c1c; font-size: 18px; margin: 0 0 15px 0; text-align: center;"><strong>📦 판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}</p>
            </div>
        </div>
        
        <!-- 구매 버튼 (하단 전체 폭) -->
        <div style="text-align: center; margin-top: 30px; width: 100%;">
            <a href="${data.affiliate_link}" target="_blank" rel="nofollow" style="text-decoration: none;">
                <picture>
                    <source media="(max-width: 768px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png">
                    <img src="https://novacents.com/tools/images/aliexpress-button-pc.png" 
                         alt="알리익스프레스에서 구매하기" 
                         style="max-width: 100%; height: auto; cursor: pointer;">
                </picture>
            </a>
        </div>
    </div>
</div>

<style>
/* 📱 모바일 반응형 CSS */
@media (max-width: 768px) {
    /* 좌우 분할을 세로 배치로 변경 */
    div[style*="grid-template-columns: 400px 1fr"] {
        display: block !important;
        grid-template-columns: none !important;
    }
    
    /* 모바일 이미지 크기 최적화 */
    img[style*="max-width: 400px"] {
        max-width: 300px !important;
    }
    
    /* 모바일 로고 좌측정렬 */
    div[style*="text-align: center"] img[alt="AliExpress"] {
        display: block;
        margin: 0 !important;
    }
    div[style*="text-align: center"]:has(img[alt="AliExpress"]) {
        text-align: left !important;
    }
    
    /* 모바일 상품명 좌측정렬 */
    h3[style*="text-align: center"] {
        text-align: left !important;
        font-size: 18px !important;
    }
    
    /* 모바일 가격 크기 조정 */
    div[style*="font-size: 40px"] {
        font-size: 28px !important;
        padding: 12px 20px !important;
    }
    
    /* 모바일 평점 세로 배치 */
    div[style*="justify-content: center"] {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 5px !important;
        justify-content: flex-start !important;
        font-size: 16px !important;
    }
    
    /* 모바일 판매량 좌측정렬 */
    p[style*="text-align: center"] {
        text-align: left !important;
        font-size: 16px !important;
    }
}

@media (max-width: 480px) {
    /* 더 작은 화면 최적화 */
    img[style*="max-width: 300px"] {
        max-width: 280px !important;
    }
    
    h3[style*="font-size: 18px"] {
        font-size: 16px !important;
    }
    
    div[style*="font-size: 28px"] {
        font-size: 24px !important;
    }
}
</style>`;
            
            // 📱 반응형 HTML 미리보기 생성 (CSS 효과 적용됨)
            const previewHtml = `
                <div class="preview-product-card">
                    <div class="preview-card-content">
                        <!-- 좌우 분할 레이아웃 (모바일에서 세로 배치) -->
                        <div class="product-content-split">
                            <!-- 왼쪽: 이미지 -->
                            <div class="product-image-large">
                                <img src="${data.image_url}" alt="${data.title}" onerror="this.style.display='none'">
                            </div>
                            
                            <!-- 오른쪽: 모든 정보 -->
                            <div class="product-info-all">
                                <!-- AliExpress 로고 -->
                                <div class="aliexpress-logo-right">
                                    <img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" />
                                </div>
                                
                                <!-- 상품명 -->
                                <h3 class="product-title-right">${data.title}</h3>
                                
                                <!-- 가격 (지정된 배경색) -->
                                <div class="product-price-right">
                                    ${formattedPrice}
                                </div>
                                
                                <!-- 평점 (고객만족도 형태) -->
                                <div class="product-rating-right">
                                    <span class="rating-stars">⭐⭐⭐⭐⭐</span>
                                    <span>(고객만족도: ${ratingDisplay})</span>
                                </div>
                                
                                <!-- 판매량 -->
                                <div class="product-sales-right">
                                    <strong>📦 판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}
                                </div>
                            </div>
                        </div>
                        
                        <!-- 구매 버튼 (하단 전체 폭) -->
                        <div class="purchase-button-full">
                            <a href="${data.affiliate_link}" target="_blank" rel="nofollow">
                                <picture>
                                    <source media="(max-width: 768px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png">
                                    <img src="https://novacents.com/tools/images/aliexpress-button-pc.png" 
                                         alt="알리익스프레스에서 구매하기">
                                </picture>
                            </a>
                        </div>
                    </div>
                </div>
            `;
            
            // DOM에 표시
            document.getElementById('htmlPreview').innerHTML = previewHtml;
            document.getElementById('htmlCode').textContent = htmlCode;
            document.getElementById('htmlSourceSection').style.display = 'block';
            
            console.log('✅ 📱 반응형 AliExpress HTML 소스 생성 완료 (모바일 좌측정렬)');
        }
        
        // 클립보드 복사 함수
        async function copyHtmlSource() {
            const htmlCode = document.getElementById('htmlCode').textContent;
            const copyBtn = document.querySelector('.copy-btn');
            
            try {
                await navigator.clipboard.writeText(htmlCode);
                
                // 버튼 상태 변경
                const originalText = copyBtn.textContent;
                copyBtn.textContent = '✅ 복사됨!';
                copyBtn.classList.add('copied');
                
                // 2초 후 원래 상태로 복원
                setTimeout(() => {
                    copyBtn.textContent = originalText;
                    copyBtn.classList.remove('copied');
                }, 2000);
                
                console.log('📋 📱 반응형 AliExpress HTML 소스 클립보드 복사 완료');
                
            } catch (error) {
                console.error('❌ 클립보드 복사 실패:', error);
                
                // 폴백: 텍스트 선택
                const codeEl = document.getElementById('htmlCode');
                const range = document.createRange();
                range.selectNodeContents(codeEl);
                const selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                
                showDetailedError('복사 알림', 'HTML 소스가 선택되었습니다. Ctrl+C로 복사하세요.');
            }
        }
        
        // 분석 결과 숨기기
        function hideAnalysisResult() {
            const resultEl = document.getElementById('analysisResult');
            resultEl.classList.remove('show');
            
            // HTML 소스 섹션도 숨기기
            document.getElementById('htmlSourceSection').style.display = 'none';
        }
        
        // UI 업데이트
        function updateUI() {
            updateProductsList();
            updateProgress();
        }
        
        // 상품 목록 업데이트
        function updateProductsList() {
            const listEl = document.getElementById('productsList');
            
            if (keywords.length === 0) {
                listEl.innerHTML = `
                    <div class="empty-state">
                        <h3>📦 상품이 없습니다</h3>
                        <p>위의 "키워드 추가" 버튼을 클릭하여<br>첫 번째 키워드를 추가해보세요!</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            
            keywords.forEach((keyword, keywordIndex) => {
                html += `
                    <div class="keyword-group">
                        <div class="keyword-header">
                            <div class="keyword-info">
                                <span class="keyword-name">📁 ${keyword.name}</span>
                                <span class="product-count">${keyword.products.length}개</span>
                            </div>
                            <div class="keyword-actions">
                                <button type="button" class="btn btn-success btn-small" onclick="addProduct(${keywordIndex})">+상품</button>
                            </div>
                        </div>
                `;
                
                keyword.products.forEach((product, productIndex) => {
                    const statusIcon = getStatusIcon(product.status);
                    html += `
                        <div class="product-item" data-keyword="${keywordIndex}" data-product="${productIndex}" onclick="selectProduct(${keywordIndex}, ${productIndex})">
                            <span class="product-status">${statusIcon}</span>
                            <span class="product-name">${product.name}</span>
                        </div>
                    `;
                });
                
                html += '</div>';
            });
            
            listEl.innerHTML = html;
        }
        
        // 상태 아이콘 반환
        function getStatusIcon(status) {
            switch (status) {
                case 'completed': return '✅';
                case 'analyzing': return '🔄';
                case 'error': return '⚠️';
                default: return '❌';
            }
        }
        
        // 진행률 업데이트
        function updateProgress() {
            const totalProducts = keywords.reduce((sum, keyword) => sum + keyword.products.length, 0);
            const completedProducts = keywords.reduce((sum, keyword) => 
                sum + keyword.products.filter(p => p.status === 'completed').length, 0);
            
            const percentage = totalProducts > 0 ? (completedProducts / totalProducts) * 100 : 0;
            
            document.getElementById('progressFill').style.width = percentage + '%';
            document.getElementById('progressText').textContent = `${completedProducts}/${totalProducts} 완성`;
        }
        
        // 네비게이션 함수들
        function previousProduct() {
            console.log('이전 상품');
        }
        
        function nextProduct() {
            console.log('다음 상품');
        }
        
        function saveCurrentProduct() {
            console.log('현재 상품 저장');
            alert('상품이 저장되었습니다!');
        }
        
        function completeProduct() {
            if (currentKeywordIndex !== -1 && currentProductIndex !== -1) {
                const product = keywords[currentKeywordIndex].products[currentProductIndex];
                product.status = 'completed';
                updateUI();
                alert('상품이 완료되었습니다!');
            }
        }
        
        function saveAll() {
            console.log('전체 저장');
            alert('모든 내용이 저장되었습니다!');
        }
        
        // 엔터키로 제목 생성
        document.getElementById('titleKeyword').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                generateTitles();
            }
        });
    </script>
</body>
</html>