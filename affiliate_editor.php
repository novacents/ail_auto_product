<?php
/**
 * Affiliate Editor - 키워드 기반 상품 큐레이션 시스템
 * 버전: v2.7 (2025-08-06)
 * 
 * 주요 기능:
 * 1. 엑셀 파일 업로드 및 키워드 추출
 * 2. AliExpress 상품 링크 분석 및 데이터 수집
 * 3. 키워드별 상품 그룹핑 및 HTML 생성
 * 4. WordPress 포스팅 자동화 (즉시발행/큐등록)
 * 5. 사용자 입력 상세정보 수집 및 관리
 */

// 오류 보고 설정
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/novacents/tools/php_error_log.txt');

// WordPress 설정 로드
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');

// 세션 시작
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 사용자 권한 확인
if (!is_user_logged_in() || !current_user_can('edit_posts')) {
    wp_die('이 페이지에 접근할 권한이 없습니다.');
}

// 디버그 로그 함수
function debug_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [AFFILIATE_EDITOR] $message" . PHP_EOL;
    file_put_contents('/var/www/novacents/tools/debug_log.txt', $log_message, FILE_APPEND | LOCK_EX);
}

// 카테고리 목록
$categories = [
    '354' => "Today's Pick",
    '355' => '기발한 잡화점',
    '356' => '스마트 리빙',
    '12' => '우리잇템'
];

// 프롬프트 타입 목록
$prompt_types = [
    'essential_items' => '필수템형 🎯',
    'friend_review' => '친구 추천형 👫',
    'professional_analysis' => '전문 분석형 📊',
    'amazing_discovery' => '놀라움 발견형 ✨'
];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>어필리에이트 에디터 v2.7</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .version {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .main-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 30px;
            align-items: start;
        }
        
        .left-panel {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .right-panel {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: sticky;
            top: 20px;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 1.4em;
            margin-bottom: 20px;
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 8px;
            font-weight: 600;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .btn {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            justify-content: center;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.4);
        }
        
        .btn:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }
        
        .btn-secondary:hover {
            box-shadow: 0 4px 12px rgba(149, 165, 166, 0.4);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        
        .btn-danger:hover {
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #27ae60, #229954);
        }
        
        .btn-success:hover {
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.4);
        }
        
        .upload-area {
            border: 3px dashed #3498db;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            background: #f8fbff;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .upload-area:hover {
            border-color: #2980b9;
            background: #e8f4fd;
        }
        
        .upload-area.dragover {
            border-color: #27ae60;
            background: #e8f8f5;
        }
        
        .keywords-container {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #e1e8ed;
            border-radius: 8px;
            padding: 15px;
            background: #fafbfc;
        }
        
        .keyword-group {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 8px;
            margin-bottom: 15px;
            padding: 15px;
            transition: all 0.3s ease;
        }
        
        .keyword-group:hover {
            border-color: #3498db;
            box-shadow: 0 2px 8px rgba(52, 152, 219, 0.1);
        }
        
        .keyword-group.selected {
            border-color: #27ae60;
            background: #f8fff8;
        }
        
        .keyword-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .keyword-title {
            font-size: 1.1em;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .keyword-stats {
            background: #3498db;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
        }
        
        .products-list {
            display: grid;
            gap: 10px;
        }
        
        .product-item {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .product-item:hover {
            border-color: #3498db;
            background: #e8f4fd;
        }
        
        .product-item.selected {
            border-color: #27ae60;
            background: #e8f8f5;
        }
        
        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .product-name {
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9em;
        }
        
        .status-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
        }
        
        .status-empty { background: #ecf0f1; color: #7f8c8d; }
        .status-analyzing { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-error { background: #f8d7da; color: #721c24; }
        
        .product-url {
            font-size: 0.8em;
            color: #7f8c8d;
            word-break: break-all;
            margin: 5px 0;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 0.75em;
        }
        
        .progress-container {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #27ae60, #2ecc71);
            transition: width 0.3s ease;
        }
        
        .progress-text {
            text-align: center;
            margin-top: 8px;
            font-size: 0.9em;
            color: #6c757d;
        }
        
        .detail-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .detail-form .full-width {
            grid-column: span 2;
        }
        
        .advantages-inputs {
            display: grid;
            gap: 10px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e1e8ed;
        }
        
        .modal-title {
            font-size: 1.3em;
            color: #2c3e50;
            font-weight: 600;
        }
        
        .close {
            font-size: 28px;
            cursor: pointer;
            color: #7f8c8d;
        }
        
        .close:hover {
            color: #e74c3c;
        }
        
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            z-index: 2000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .loading-text {
            font-size: 1.2em;
            color: #2c3e50;
        }
        
        .batch-controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .batch-progress {
            display: none;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            border: 1px solid #dee2e6;
        }
        
        .navigation-controls {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin: 15px 0;
        }
        
        .publish-controls {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
        }
        
        .title-suggestions {
            display: none;
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            position: absolute;
            width: calc(100% - 32px);
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .title-suggestion {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f1f3f4;
            transition: background-color 0.2s ease;
        }
        
        .title-suggestion:hover {
            background: #f8f9fa;
        }
        
        .title-suggestion:last-child {
            border-bottom: none;
        }
        
        .title-input-container {
            position: relative;
        }
        
        @media (max-width: 1024px) {
            .main-layout {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .right-panel {
                position: static;
            }
            
            .detail-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 어필리에이트 에디터</h1>
            <div class="version">v2.7 - 키워드 기반 상품 큐레이션 시스템</div>
        </div>

        <div class="main-layout">
            <div class="left-panel">
                <!-- 파일 업로드 섹션 -->
                <div class="section">
                    <h3 class="section-title">📁 엑셀 파일 업로드</h3>
                    
                    <div class="upload-area" onclick="document.getElementById('excelFile').click()">
                        <input type="file" id="excelFile" accept=".xlsx,.xls" style="display: none;">
                        <div style="font-size: 3em; margin-bottom: 15px;">📊</div>
                        <p style="font-size: 1.2em; margin-bottom: 10px; font-weight: 600;">엑셀 파일을 선택하거나 여기로 드래그하세요</p>
                        <p style="color: #7f8c8d;">키워드와 상품 링크가 포함된 엑셀 파일을 업로드해주세요</p>
                    </div>

                    <button class="btn" onclick="processExcel()" style="margin-top: 20px; width: 100%;">
                        📋 업로드 & 자동입력
                    </button>
                </div>

                <!-- 키워드 및 상품 관리 섹션 -->
                <div class="section">
                    <h3 class="section-title">🔍 키워드 & 상품 관리</h3>
                    
                    <div class="batch-controls">
                        <button class="btn" id="batchAnalyzeBtn" onclick="batchAnalyzeAll()">
                            🔍 전체 분석
                        </button>
                        <button class="btn btn-success" id="batchSaveBtn" onclick="batchSaveAll()">
                            💾 전체 저장
                        </button>
                    </div>

                    <div class="batch-progress" id="batchProgress">
                        <div class="progress-text" id="batchProgressText">처리 중...</div>
                        <div class="progress-bar">
                            <div class="progress-fill" id="batchProgressBar" style="width: 0%"></div>
                        </div>
                    </div>

                    <div class="keywords-container" id="keywordsContainer">
                        <!-- 키워드 그룹들이 여기에 동적으로 추가됩니다 -->
                    </div>
                </div>
            </div>

            <div class="right-panel">
                <!-- 제목 생성 섹션 -->
                <div class="section">
                    <h3 class="section-title">📝 제목 생성</h3>
                    
                    <div class="form-group">
                        <label for="titleKeyword">키워드 입력</label>
                        <input type="text" id="titleKeyword" placeholder="제목 생성용 키워드 입력">
                    </div>
                    
                    <button class="btn btn-secondary" onclick="generateTitles()" style="width: 100%; margin-bottom: 15px;">
                        ✨ 제목 생성
                    </button>
                    
                    <div class="title-input-container">
                        <div class="form-group">
                            <label for="title">제목</label>
                            <input type="text" id="title" placeholder="포스팅 제목을 입력하세요">
                        </div>
                        <div class="title-suggestions" id="titleSuggestions"></div>
                    </div>
                </div>

                <!-- 기본 설정 섹션 -->
                <div class="section">
                    <h3 class="section-title">⚙️ 기본 설정</h3>
                    
                    <div class="form-group">
                        <label for="category">카테고리</label>
                        <select id="category">
                            <?php foreach ($categories as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="prompt_type">프롬프트 타입</label>
                        <select id="prompt_type">
                            <?php foreach ($prompt_types as $type => $name): ?>
                                <option value="<?= $type ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="thumbnail_url">썸네일 URL</label>
                        <input type="url" id="thumbnail_url" placeholder="썸네일 이미지 URL (선택사항)">
                    </div>
                </div>

                <!-- 상품 상세 정보 섹션 -->
                <div class="section">
                    <h3 class="section-title">📋 상품 상세 정보</h3>
                    
                    <div class="form-group">
                        <label for="productUrl">현재 상품 URL</label>
                        <input type="url" id="productUrl" placeholder="선택된 상품의 URL">
                    </div>
                    
                    <div class="navigation-controls">
                        <button class="btn btn-secondary btn-small" onclick="previousProduct()">◀ 이전</button>
                        <button class="btn btn-secondary btn-small" onclick="nextProduct()">다음 ▶</button>
                    </div>
                    
                    <button class="btn" onclick="saveCurrentProduct()" style="width: 100%; margin: 15px 0;">
                        💾 현재 상품 저장
                    </button>
                    
                    <!-- 상품 세부 정보 입력 폼 -->
                    <div class="detail-form">
                        <!-- 제품 사양 -->
                        <div class="form-group">
                            <label for="main_function">주요 기능</label>
                            <input type="text" id="main_function" placeholder="예: 무선충전, 터치조작">
                        </div>
                        <div class="form-group">
                            <label for="size_capacity">크기/용량</label>
                            <input type="text" id="size_capacity" placeholder="예: 500ml, 15x10cm">
                        </div>
                        <div class="form-group">
                            <label for="color">색상</label>
                            <input type="text" id="color" placeholder="예: 화이트, 블랙">
                        </div>
                        <div class="form-group">
                            <label for="material">재질</label>
                            <input type="text" id="material" placeholder="예: 실리콘, 스테인리스">
                        </div>
                        <div class="form-group">
                            <label for="power_battery">전원/배터리</label>
                            <input type="text" id="power_battery" placeholder="예: USB-C, 리튬배터리">
                        </div>
                        
                        <!-- 효율성 정보 -->
                        <div class="form-group">
                            <label for="problem_solving">해결하는 문제</label>
                            <input type="text" id="problem_solving" placeholder="예: 정리정돈 어려움">
                        </div>
                        <div class="form-group">
                            <label for="time_saving">시간절약</label>
                            <input type="text" id="time_saving" placeholder="예: 청소시간 50% 단축">
                        </div>
                        <div class="form-group">
                            <label for="space_efficiency">공간효율</label>
                            <input type="text" id="space_efficiency" placeholder="예: 벽면 부착 가능">
                        </div>
                        <div class="form-group">
                            <label for="cost_saving">비용절약</label>
                            <input type="text" id="cost_saving" placeholder="예: 일회용품 대체 가능">
                        </div>
                        
                        <!-- 사용법 정보 -->
                        <div class="form-group">
                            <label for="usage_location">사용 장소</label>
                            <input type="text" id="usage_location" placeholder="예: 주방, 침실, 사무실">
                        </div>
                        <div class="form-group">
                            <label for="usage_frequency">사용 빈도</label>
                            <input type="text" id="usage_frequency" placeholder="예: 매일, 주 3회">
                        </div>
                        <div class="form-group">
                            <label for="target_users">타겟 사용자</label>
                            <input type="text" id="target_users" placeholder="예: 직장인, 학생, 주부">
                        </div>
                        <div class="form-group">
                            <label for="usage_method">사용 방법</label>
                            <input type="text" id="usage_method" placeholder="예: 원터치 조작, 앱 연동">
                        </div>
                        
                        <!-- 장점 및 효용 -->
                        <div class="form-group full-width">
                            <label>주요 장점</label>
                            <div class="advantages-inputs">
                                <input type="text" id="advantage1" placeholder="장점 1">
                                <input type="text" id="advantage2" placeholder="장점 2">
                                <input type="text" id="advantage3" placeholder="장점 3">
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label for="precautions">주의사항</label>
                            <textarea id="precautions" rows="3" placeholder="사용 시 주의사항이나 제한사항"></textarea>
                        </div>
                    </div>
                </div>

                <!-- 진행 상황 -->
                <div class="section">
                    <h3 class="section-title">📊 진행 상황</h3>
                    <div class="progress-container">
                        <div class="progress-bar">
                            <div class="progress-fill" id="progressFill" style="width: 0%"></div>
                        </div>
                        <div class="progress-text" id="progressText">0/0 완성</div>
                    </div>
                </div>

                <!-- 발행 컨트롤 -->
                <div class="publish-controls">
                    <button class="btn btn-success" id="publishNowBtn" onclick="publishNow()">
                        🚀 즉시 발행
                    </button>
                    <button class="btn" onclick="completeProduct()">
                        📝 큐에 저장
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 로딩 오버레이 -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <div class="loading-text" id="loadingText">처리 중입니다...</div>
    </div>

    <!-- 성공 모달 -->
    <div class="modal" id="successModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="successTitle">성공!</h3>
                <span class="close" onclick="closeModal('successModal')">&times;</span>
            </div>
            <div id="successMessage">작업이 완료되었습니다.</div>
            <button class="btn" onclick="closeModal('successModal')" style="margin-top: 20px; width: 100%;">
                확인
            </button>
        </div>
    </div>

    <!-- 오류 모달 -->
    <div class="modal" id="errorModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="errorTitle">오류</h3>
                <span class="close" onclick="closeModal('errorModal')">&times;</span>
            </div>
            <div id="errorMessage">오류가 발생했습니다.</div>
            <div id="errorDetails" style="margin-top: 15px; font-size: 0.9em; color: #666;"></div>
            <button class="btn btn-danger" onclick="closeModal('errorModal')" style="margin-top: 20px; width: 100%;">
                확인
            </button>
        </div>
    </div>

    <!-- 숨겨진 폼 (기존 제출 방식용) -->
    <form id="affiliateForm" action="keyword_processor.php" method="post" style="display: none;">
        <!-- 동적으로 생성되는 hidden input들 -->
    </form>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        // 전역 변수들
        let keywords = [];
        let currentKeywordIndex = -1;
        let currentProductIndex = -1;
        let draggedElement = null;
        let draggedType = null;
        let draggedIndex = null;
        let draggedKeywordIndex = null;

        // 초기화
        document.addEventListener('DOMContentLoaded', function() {
            setupFileUpload();
            setupDragAndDrop();
            updateUI();
            
            // 제목 입력 필드에 이벤트 리스너 추가
            const titleInput = document.getElementById('title');
            const suggestions = document.getElementById('titleSuggestions');
            
            titleInput.addEventListener('focus', function() {
                if (suggestions.children.length > 0) {
                    suggestions.style.display = 'block';
                }
            });
            
            titleInput.addEventListener('blur', function() {
                setTimeout(() => suggestions.style.display = 'none', 200);
            });
        });

        // 파일 업로드 설정
        function setupFileUpload() {
            const fileInput = document.getElementById('excelFile');
            const uploadArea = document.querySelector('.upload-area');
            
            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    uploadArea.style.background = '#e8f8f5';
                    uploadArea.innerHTML = `
                        <div style="font-size: 2em; margin-bottom: 10px;">✅</div>
                        <p style="font-size: 1.2em; font-weight: 600;">파일 선택됨: ${e.target.files[0].name}</p>
                        <p style="color: #7f8c8d;">업로드 & 자동입력 버튼을 클릭하세요</p>
                    `;
                }
            });
            
            // 드래그앤드롭
            uploadArea.addEventListener('dragover', function(e) {
                e.preventDefault();
                uploadArea.classList.add('dragover');
            });
            
            uploadArea.addEventListener('dragleave', function(e) {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
            });
            
            uploadArea.addEventListener('drop', function(e) {
                e.preventDefault();
                uploadArea.classList.remove('dragover');
                
                const files = e.dataTransfer.files;
                if (files.length > 0 && (files[0].name.endsWith('.xlsx') || files[0].name.endsWith('.xls'))) {
                    fileInput.files = files;
                    fileInput.dispatchEvent(new Event('change'));
                }
            });
        }

        // 엑셀 파일 처리
        function processExcel() {
            const fileInput = document.getElementById('excelFile');
            if (!fileInput.files[0]) {
                showDetailedError('파일 오류', '먼저 엑셀 파일을 선택해주세요.');
                return;
            }
            
            const file = fileInput.files[0];
            const reader = new FileReader();
            
            reader.onload = function(e) {
                try {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                    const rows = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });
                    
                    if (rows.length < 2) {
                        showDetailedError('데이터 오류', '엑셀 파일에 데이터가 충분하지 않습니다.');
                        return;
                    }
                    
                    parseExcelData(rows);
                    showSuccessModal('업로드 완료!', '엑셀 데이터가 성공적으로 로드되었습니다.', '📊');
                } catch (error) {
                    showDetailedError('파일 오류', '엑셀 파일을 읽는 중 오류가 발생했습니다.', {
                        'error': error.message,
                        'filename': file.name
                    });
                }
            };
            
            reader.readAsArrayBuffer(file);
        }

        // 엑셀 데이터 파싱
        function parseExcelData(rows) {
            keywords = [];
            const keywordMap = new Map();
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                if (row.length < 2) continue;
                
                const keyword = String(row[0] || '').trim();
                const url = String(row[1] || '').trim();
                
                if (!keyword || !url) continue;
                
                if (!keywordMap.has(keyword)) {
                    keywordMap.set(keyword, {
                        name: keyword,
                        products: []
                    });
                }
                
                keywordMap.get(keyword).products.push({
                    name: `상품 ${keywordMap.get(keyword).products.length + 1}`,
                    url: url,
                    status: 'empty',
                    analysisData: null,
                    generatedHtml: null,
                    userData: {},
                    isSaved: false
                });
            }
            
            keywords = Array.from(keywordMap.values());
            updateUI();
        }

        // UI 업데이트
        function updateUI() {
            updateKeywordsContainer();
            updateProgress();
        }

        function updateKeywordsContainer() {
            const container = document.getElementById('keywordsContainer');
            container.innerHTML = '';
            
            if (keywords.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                        <div style="font-size: 2em; margin-bottom: 15px;">📋</div>
                        <p>엑셀 파일을 업로드하여 키워드를 추가하세요</p>
                    </div>
                `;
                return;
            }
            
            keywords.forEach((keyword, keywordIndex) => {
                const keywordDiv = document.createElement('div');
                keywordDiv.className = 'keyword-group';
                keywordDiv.draggable = true;
                keywordDiv.dataset.keywordIndex = keywordIndex;
                
                if (keywordIndex === currentKeywordIndex) {
                    keywordDiv.classList.add('selected');
                }
                
                const completedCount = keyword.products.filter(p => p.status === 'completed').length;
                const totalCount = keyword.products.length;
                
                keywordDiv.innerHTML = `
                    <div class="keyword-header">
                        <div class="keyword-title">${keyword.name}</div>
                        <div class="keyword-stats">${completedCount}/${totalCount}</div>
                    </div>
                    <div class="products-list">
                        ${keyword.products.map((product, productIndex) => `
                            <div class="product-item ${keywordIndex === currentKeywordIndex && productIndex === currentProductIndex ? 'selected' : ''}" 
                                 draggable="true" 
                                 data-keyword="${keywordIndex}" 
                                 data-product="${productIndex}"
                                 onclick="selectProduct(${keywordIndex}, ${productIndex})">
                                <div class="product-header">
                                    <div class="product-name">${product.name}</div>
                                    <div class="status-badge status-${product.status}">
                                        ${getStatusIcon(product.status, product.isSaved)}
                                    </div>
                                </div>
                                ${product.url ? `<div class="product-url">${product.url.length > 50 ? product.url.substring(0, 50) + '...' : product.url}</div>` : ''}
                                <div class="action-buttons">
                                    <button class="btn btn-small" onclick="event.stopPropagation(); analyzeProduct(${keywordIndex}, ${productIndex})">
                                        🔍 분석
                                    </button>
                                    <button class="btn btn-secondary btn-small" onclick="event.stopPropagation(); editProductUrl(${keywordIndex}, ${productIndex})">
                                        ✏️ 수정
                                    </button>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                `;
                
                container.appendChild(keywordDiv);
            });
            
            setupDragAndDrop();
        }

        function selectProduct(keywordIndex, productIndex) {
            currentKeywordIndex = keywordIndex;
            currentProductIndex = productIndex;
            
            const product = keywords[keywordIndex].products[productIndex];
            document.getElementById('productUrl').value = product.url || '';
            
            // 사용자 데이터 로드
            if (product.userData) {
                const userData = product.userData;
                const specs = userData.specs || {};
                const efficiency = userData.efficiency || {};
                const usage = userData.usage || {};
                const benefits = userData.benefits || {};
                
                document.getElementById('main_function').value = specs.main_function || '';
                document.getElementById('size_capacity').value = specs.size_capacity || '';
                document.getElementById('color').value = specs.color || '';
                document.getElementById('material').value = specs.material || '';
                document.getElementById('power_battery').value = specs.power_battery || '';
                
                document.getElementById('problem_solving').value = efficiency.problem_solving || '';
                document.getElementById('time_saving').value = efficiency.time_saving || '';
                document.getElementById('space_efficiency').value = efficiency.space_efficiency || '';
                document.getElementById('cost_saving').value = efficiency.cost_saving || '';
                
                document.getElementById('usage_location').value = usage.usage_location || '';
                document.getElementById('usage_frequency').value = usage.usage_frequency || '';
                document.getElementById('target_users').value = usage.target_users || '';
                document.getElementById('usage_method').value = usage.usage_method || '';
                
                if (benefits.advantages) {
                    document.getElementById('advantage1').value = benefits.advantages[0] || '';
                    document.getElementById('advantage2').value = benefits.advantages[1] || '';
                    document.getElementById('advantage3').value = benefits.advantages[2] || '';
                }
                document.getElementById('precautions').value = benefits.precautions || '';
            }
            
            updateUI();
        }

        async function analyzeProduct(keywordIndex, productIndex) {
            const product = keywords[keywordIndex].products[productIndex];
            
            if (!product.url || product.url.trim() === '') {
                showDetailedError('URL 오류', '분석할 상품의 URL을 먼저 입력해주세요.');
                return;
            }
            
            product.status = 'analyzing';
            updateUI();
            
            try {
                const response = await fetch('product_analyzer_v2.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'analyze_product',
                        url: product.url,
                        platform: 'aliexpress'
                    })
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP 오류: ${response.status}`);
                }
                
                const result = await response.json();
                
                if (result.success) {
                    product.analysisData = result.data;
                    product.status = 'completed';
                    product.name = result.data.title || `상품 ${productIndex + 1}`;
                    
                    // HTML 생성
                    const generatedHtml = generateOptimizedMobileHtml(result.data, false);
                    product.generatedHtml = generatedHtml;
                    
                    showSuccessModal('분석 완료!', `${product.name} 상품 분석이 완료되었습니다.`, '🔍');
                } else {
                    product.status = 'error';
                    showDetailedError('분석 실패', result.message || '상품 분석에 실패했습니다.');
                }
            } catch (error) {
                product.status = 'error';
                showDetailedError('분석 오류', '상품 분석 중 오류가 발생했습니다.', {
                    'error': error.message,
                    'url': product.url
                });
            }
            
            updateUI();
        }

        function editProductUrl(keywordIndex, productIndex) {
            const product = keywords[keywordIndex].products[productIndex];
            const newUrl = prompt('새로운 URL을 입력하세요:', product.url || '');
            
            if (newUrl !== null && newUrl.trim() !== '') {
                product.url = newUrl.trim();
                product.status = 'empty';
                product.analysisData = null;
                product.generatedHtml = null;
                updateUI();
                
                showSuccessModal('URL 수정', '상품 URL이 수정되었습니다.', '✏️');
            }
        }

        // HTML 생성 함수
        function generateOptimizedMobileHtml(productData, includeStyles = true) {
            const styles = includeStyles ? `
            <style>
            .product-container { max-width: 100%; margin: 0 auto; padding: 15px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; }
            .product-image { width: 100%; max-width: 400px; height: auto; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
            .product-title { font-size: 1.4em; font-weight: 700; color: #333; margin-bottom: 15px; }
            .price-section { background: linear-gradient(135deg, #FF6B6B, #4ECDC4); color: white; padding: 15px; border-radius: 12px; margin-bottom: 20px; text-align: center; }
            .current-price { font-size: 1.8em; font-weight: 800; margin-bottom: 5px; }
            .original-price { font-size: 1.1em; text-decoration: line-through; opacity: 0.8; }
            .discount-rate { font-size: 1.2em; font-weight: 600; margin-top: 8px; }
            .features-grid { display: grid; gap: 12px; margin-bottom: 20px; }
            .feature-item { background: #f8f9fa; padding: 12px; border-radius: 8px; border-left: 4px solid #4ECDC4; }
            .feature-title { font-weight: 600; color: #333; margin-bottom: 5px; }
            .feature-content { color: #666; font-size: 0.95em; }
            .rating-section { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; padding: 12px; background: #fff8e1; border-radius: 8px; }
            .rating-stars { color: #ffc107; font-size: 1.2em; }
            .rating-text { font-weight: 600; color: #333; }
            .orders-count { color: #666; font-size: 0.9em; }
            .buy-button { background: linear-gradient(135deg, #FF6B6B, #4ECDC4); color: white; border: none; padding: 15px 30px; border-radius: 25px; font-size: 1.1em; font-weight: 600; width: 100%; cursor: pointer; text-decoration: none; display: block; text-align: center; margin: 20px 0; }
            .shipping-info { background: #e8f5e8; padding: 12px; border-radius: 8px; margin-bottom: 15px; text-align: center; color: #2d5a2d; font-weight: 500; }
            @media (max-width: 768px) { .product-container { padding: 10px; } .features-grid { grid-template-columns: 1fr; } }
            </style>
            ` : '';
            
            const discountRate = productData.original_price && productData.current_price ? 
                Math.round((1 - parseFloat(productData.current_price.replace(/[^0-9.]/g, '')) / parseFloat(productData.original_price.replace(/[^0-9.]/g, ''))) * 100) : 0;
            
            return `${styles}
            <div class="product-container">
                ${productData.image_url ? `<img src="${productData.image_url}" alt="${productData.title}" class="product-image">` : ''}
                
                <h2 class="product-title">${productData.title || '상품명'}</h2>
                
                <div class="price-section">
                    <div class="current-price">${productData.current_price || '가격 정보 없음'}</div>
                    ${productData.original_price && productData.original_price !== productData.current_price ? 
                        `<div class="original-price">정가: ${productData.original_price}</div>` : ''}
                    ${discountRate > 0 ? `<div class="discount-rate">🔥 ${discountRate}% 할인!</div>` : ''}
                </div>
                
                ${productData.rating || productData.orders_count ? `
                <div class="rating-section">
                    ${productData.rating ? `
                        <div class="rating-stars">${'★'.repeat(Math.floor(parseFloat(productData.rating)))}</div>
                        <div class="rating-text">${productData.rating}점</div>
                    ` : ''}
                    ${productData.orders_count ? `<div class="orders-count">${productData.orders_count} 주문</div>` : ''}
                </div>
                ` : ''}
                
                ${productData.features && productData.features.length > 0 ? `
                <div class="features-grid">
                    ${productData.features.map(feature => `
                        <div class="feature-item">
                            <div class="feature-title">✨ 주요 특징</div>
                            <div class="feature-content">${feature}</div>
                        </div>
                    `).join('')}
                </div>
                ` : ''}
                
                ${productData.shipping_info ? `
                <div class="shipping-info">
                    🚚 ${productData.shipping_info}
                </div>
                ` : ''}
                
                <a href="${productData.product_url}" class="buy-button" target="_blank">
                    🛒 지금 구매하기
                </a>
            </div>`;
        }

        // 제목 생성 함수
        async function generateTitles() {
            const keyword = document.getElementById('titleKeyword').value.trim();
            if (!keyword) {
                showDetailedError('입력 오류', '제목 생성용 키워드를 입력해주세요.');
                return;
            }
            
            try {
                const response = await fetch('title_generator.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        keyword: keyword,
                        count: 5
                    })
                });
                
                const result = await response.json();
                
                if (result.success && result.titles) {
                    const suggestions = document.getElementById('titleSuggestions');
                    suggestions.innerHTML = result.titles.map(title => 
                        `<div class="title-suggestion" onclick="selectTitle('${title.replace(/'/g, "\\'")}')">${title}</div>`
                    ).join('');
                    suggestions.style.display = 'block';
                    
                    // 첫 번째 제목을 자동으로 입력
                    if (result.titles.length > 0) {
                        document.getElementById('title').value = result.titles[0];
                    }
                    
                    showSuccessModal('제목 생성 완료!', `${result.titles.length}개의 제목이 생성되었습니다.`, '✨');
                } else {
                    showDetailedError('제목 생성 실패', result.message || '제목 생성에 실패했습니다.');
                }
            } catch (error) {
                showDetailedError('제목 생성 오류', '제목 생성 중 오류가 발생했습니다.', {
                    'error': error.message,
                    'keyword': keyword
                });
            }
        }

        function selectTitle(title) {
            document.getElementById('title').value = title;
            document.getElementById('titleSuggestions').style.display = 'none';
        }

        // 모달 관련 함수들
        function showSuccessModal(title, message, icon = '✅') {
            document.getElementById('successTitle').textContent = icon + ' ' + title;
            document.getElementById('successMessage').textContent = message;
            document.getElementById('successModal').style.display = 'flex';
        }

        function showDetailedError(title, message, details = null) {
            document.getElementById('errorTitle').textContent = '❌ ' + title;
            document.getElementById('errorMessage').textContent = message;
            
            const detailsDiv = document.getElementById('errorDetails');
            if (details) {
                detailsDiv.style.display = 'block';
                detailsDiv.innerHTML = '<strong>상세 정보:</strong><br>' + 
                    Object.entries(details).map(([key, value]) => `${key}: ${value}`).join('<br>');
            } else {
                detailsDiv.style.display = 'none';
            }
            
            document.getElementById('errorModal').style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // 상태 아이콘 가져오기
        function getStatusIcon(status, isSaved = false) {
            switch (status) {
                case 'completed':
                    return isSaved ? '✅' : '🔍';
                case 'analyzing':
                    return '🔄';
                case 'error':
                    return '⚠️';
                default:
                    return '❌';
            }
        }

        // 진행 상황 업데이트
        function updateProgress() {
            const totalProducts = keywords.reduce((sum, keyword) => sum + keyword.products.length, 0);
            const completedProducts = keywords.reduce((sum, keyword) => 
                sum + keyword.products.filter(product => product.isSaved).length, 0);
            
            const percentage = totalProducts > 0 ? (completedProducts / totalProducts) * 100 : 0;
            
            document.getElementById('progressFill').style.width = percentage + '%';
            document.getElementById('progressText').textContent = `${completedProducts}/${totalProducts} 완성`;
        }

        // 사용자 입력 상세정보 수집
        function collectUserInputDetails() {
            const details = {};
            const specs = {};
            const efficiency = {};
            const usage = {};
            const benefits = {};
            const advantages = [];
            
            // 제품 사양
            addIfNotEmpty(specs, 'main_function', 'main_function');
            addIfNotEmpty(specs, 'size_capacity', 'size_capacity');
            addIfNotEmpty(specs, 'color', 'color');
            addIfNotEmpty(specs, 'material', 'material');
            addIfNotEmpty(specs, 'power_battery', 'power_battery');
            if (Object.keys(specs).length > 0) details.specs = specs;
            
            // 효율성 정보
            addIfNotEmpty(efficiency, 'problem_solving', 'problem_solving');
            addIfNotEmpty(efficiency, 'time_saving', 'time_saving');
            addIfNotEmpty(efficiency, 'space_efficiency', 'space_efficiency');
            addIfNotEmpty(efficiency, 'cost_saving', 'cost_saving');
            if (Object.keys(efficiency).length > 0) details.efficiency = efficiency;
            
            // 사용법 정보
            addIfNotEmpty(usage, 'usage_location', 'usage_location');
            addIfNotEmpty(usage, 'usage_frequency', 'usage_frequency');
            addIfNotEmpty(usage, 'target_users', 'target_users');
            addIfNotEmpty(usage, 'usage_method', 'usage_method');
            if (Object.keys(usage).length > 0) details.usage = usage;
            
            // 장점 수집
            ['advantage1', 'advantage2', 'advantage3'].forEach(id => {
                const value = document.getElementById(id)?.value.trim();
                if (value) advantages.push(value);
            });
            if (advantages.length > 0) benefits.advantages = advantages;
            
            addIfNotEmpty(benefits, 'precautions', 'precautions');
            if (Object.keys(benefits).length > 0) details.benefits = benefits;
            
            return details;
        }

        function addIfNotEmpty(obj, key, elementId) {
            const value = document.getElementById(elementId)?.value.trim();
            if (value) obj[key] = value;
        }

        // 키워드 데이터 수집
        function collectKeywordsData() {
            const keywordsData = [];
            
            keywords.forEach((keyword, keywordIndex) => {
                const keywordData = {
                    name: keyword.name,
                    coupang: [],
                    aliexpress: [],
                    products_data: []
                };
                
                keyword.products.forEach((product, productIndex) => {
                    if (product.url && 
                        typeof product.url === 'string' && 
                        product.url.trim() !== '' && 
                        product.url.trim() !== 'undefined' && 
                        product.url.trim() !== 'null' && 
                        product.url.includes('aliexpress.com')) {
                        
                        const trimmedUrl = product.url.trim();
                        keywordData.aliexpress.push(trimmedUrl);
                        
                        const productData = {
                            url: trimmedUrl,
                            analysis_data: product.analysisData || null,
                            generated_html: product.generatedHtml || null,
                            user_data: product.userData || {}
                        };
                        
                        keywordData.products_data.push(productData);
                    }
                });
                
                if (keywordData.aliexpress.length > 0) {
                    keywordsData.push(keywordData);
                }
            });
            
            return keywordsData;
        }

        // 데이터 검증 및 제출
        function validateAndSubmitData(formData, isPrecheck = false) {
            // 제목 검증
            if (!formData.title || formData.title.length < 5) {
                showDetailedError('입력 오류', '제목은 5자 이상이어야 합니다.');
                return false;
            }
            
            // 키워드 검증
            if (!formData.keywords || formData.keywords.length === 0) {
                showDetailedError('입력 오류', '최소 하나의 키워드와 상품 링크가 필요합니다.');
                return false;
            }
            
            // AliExpress URL 검증
            let hasValidUrls = false;
            let totalValidUrls = 0;
            let totalProductsData = 0;
            
            formData.keywords.forEach(keyword => {
                if (keyword.aliexpress && keyword.aliexpress.length > 0) {
                    const validUrls = keyword.aliexpress.filter(url => 
                        url && 
                        typeof url === 'string' && 
                        url.trim() !== '' && 
                        url.includes('aliexpress.com')
                    );
                    
                    if (validUrls.length > 0) {
                        hasValidUrls = true;
                        totalValidUrls += validUrls.length;
                        totalProductsData += keyword.products_data ? keyword.products_data.length : 0;
                    }
                }
            });
            
            if (!hasValidUrls || totalValidUrls === 0) {
                showDetailedError('입력 오류', 
                    '각 키워드에 최소 하나의 유효한 알리익스프레스 상품 링크가 있어야 합니다.\n\n' +
                    '현재 상태:\n' +
                    '- URL을 입력했는지 확인하세요\n' +
                    '- 분석 버튼을 클릭했는지 확인하세요\n' +
                    '- 알리익스프레스 URL인지 확인하세요'
                );
                return false;
            }
            
            if (isPrecheck) {
                return true;
            } else {
                // 폼 제출
                const form = document.getElementById('affiliateForm');
                const existingInputs = form.querySelectorAll('input[type="hidden"]');
                existingInputs.forEach(input => input.remove());
                
                const hiddenInputs = [
                    { name: 'title', value: formData.title },
                    { name: 'category', value: formData.category },
                    { name: 'prompt_type', value: formData.prompt_type },
                    { name: 'keywords', value: JSON.stringify(formData.keywords) },
                    { name: 'user_details', value: JSON.stringify(formData.user_details) },
                    { name: 'thumbnail_url', value: document.getElementById('thumbnail_url').value.trim() }
                ];
                
                hiddenInputs.forEach(({ name, value }) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                });
                
                form.submit();
                return true;
            }
        }

        // 즉시 발행
        async function publishNow() {
            const keywordsData = collectKeywordsData();
            const userDetails = collectUserInputDetails();
            const formData = {
                title: document.getElementById('title').value.trim(),
                category: document.getElementById('category').value,
                prompt_type: document.getElementById('prompt_type').value,
                keywords: keywordsData,
                user_details: userDetails,
                thumbnail_url: document.getElementById('thumbnail_url').value.trim()
            };
            
            if (!validateAndSubmitData(formData, true)) return;
            
            const loadingOverlay = document.getElementById('loadingOverlay');
            const publishBtn = document.getElementById('publishNowBtn');
            
            loadingOverlay.style.display = 'flex';
            publishBtn.disabled = true;
            publishBtn.textContent = '발행 중...';
            
            try {
                const response = await fetch('keyword_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        title: formData.title,
                        category: formData.category,
                        prompt_type: formData.prompt_type,
                        keywords: JSON.stringify(formData.keywords),
                        user_details: JSON.stringify(formData.user_details),
                        thumbnail_url: formData.thumbnail_url,
                        publish_mode: 'immediate'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showSuccessModal('발행 완료!', '글이 성공적으로 발행되었습니다!', '🚀');
                    if (result.post_url) {
                        window.open(result.post_url, '_blank');
                    }
                } else {
                    showDetailedError('발행 실패', result.message || '알 수 없는 오류가 발생했습니다.');
                }
            } catch (error) {
                showDetailedError('발행 오류', '즉시 발행 중 오류가 발생했습니다.', {
                    'error': error.message,
                    'timestamp': new Date().toISOString()
                });
            } finally {
                loadingOverlay.style.display = 'none';
                publishBtn.disabled = false;
                publishBtn.textContent = '🚀 즉시 발행';
            }
        }

        // 현재 상품 저장
        function saveCurrentProduct() {
            if (currentKeywordIndex === -1 || currentProductIndex === -1) {
                showDetailedError('선택 오류', '저장할 상품을 먼저 선택해주세요.');
                return;
            }
            
            const product = keywords[currentKeywordIndex].products[currentProductIndex];
            const url = document.getElementById('productUrl').value.trim();
            if (url) product.url = url;
            
            const userDetails = collectUserInputDetails();
            product.userData = userDetails;
            product.isSaved = true;
            
            updateUI();
            showSuccessModal('저장 완료!', '현재 상품 정보가 성공적으로 저장되었습니다.', '💾');
        }

        // 완료 및 큐에 저장
        function completeProduct() {
            const keywordsData = collectKeywordsData();
            const userDetails = collectUserInputDetails();
            const formData = {
                title: document.getElementById('title').value.trim(),
                category: document.getElementById('category').value,
                prompt_type: document.getElementById('prompt_type').value,
                keywords: keywordsData,
                user_details: userDetails,
                thumbnail_url: document.getElementById('thumbnail_url').value.trim()
            };
            
            if (validateAndSubmitData(formData)) {
                console.log('대기열 저장 요청이 전송되었습니다.');
            }
        }

        // 이전/다음 상품 네비게이션
        function previousProduct() {
            if (currentKeywordIndex === -1 || currentProductIndex === -1) return;
            
            const currentKeyword = keywords[currentKeywordIndex];
            if (currentProductIndex > 0) {
                selectProduct(currentKeywordIndex, currentProductIndex - 1);
            } else if (currentKeywordIndex > 0) {
                const previousKeyword = keywords[currentKeywordIndex - 1];
                selectProduct(currentKeywordIndex - 1, previousKeyword.products.length - 1);
            }
        }

        function nextProduct() {
            if (currentKeywordIndex === -1 || currentProductIndex === -1) return;
            
            const currentKeyword = keywords[currentKeywordIndex];
            if (currentProductIndex < currentKeyword.products.length - 1) {
                selectProduct(currentKeywordIndex, currentProductIndex + 1);
            } else if (currentKeywordIndex < keywords.length - 1) {
                selectProduct(currentKeywordIndex + 1, 0);
            }
        }

        // 드래그 앤 드롭 설정
        function setupDragAndDrop() {
            const keywordGroups = document.querySelectorAll('.keyword-group');
            const productItems = document.querySelectorAll('.product-item');
            
            keywordGroups.forEach((group, index) => {
                group.addEventListener('dragstart', handleKeywordDragStart);
                group.addEventListener('dragend', handleDragEnd);
                group.addEventListener('dragover', handleDragOver);
                group.addEventListener('drop', handleKeywordDrop);
            });
            
            productItems.forEach((item, index) => {
                item.addEventListener('dragstart', handleProductDragStart);
                item.addEventListener('dragend', handleDragEnd);
                item.addEventListener('dragover', handleDragOver);
                item.addEventListener('drop', handleProductDrop);
            });
        }

        function handleKeywordDragStart(e) {
            if (!e.target.classList.contains('keyword-group')) return;
            e.stopPropagation();
            draggedElement = e.target;
            draggedType = 'keyword';
            draggedIndex = parseInt(e.target.dataset.keywordIndex);
            e.target.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        }

        function handleProductDragStart(e) {
            if (!e.target.classList.contains('product-item')) return;
            e.stopPropagation();
            draggedElement = e.target;
            draggedType = 'product';
            draggedKeywordIndex = parseInt(e.target.dataset.keyword);
            draggedIndex = parseInt(e.target.dataset.product);
            e.target.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        }

        function handleDragEnd(e) {
            e.target.classList.remove('dragging');
            document.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
            draggedElement = null;
            draggedType = null;
            draggedIndex = null;
            draggedKeywordIndex = null;
        }

        function handleDragOver(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        }

        function handleKeywordDrop(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (draggedType !== 'keyword' || !e.target.closest('.keyword-group')) return;
            
            const targetGroup = e.target.closest('.keyword-group');
            if (!targetGroup || targetGroup === draggedElement) return;
            
            const targetIndex = parseInt(targetGroup.dataset.keywordIndex);
            
            if (draggedIndex !== targetIndex) {
                const draggedKeyword = keywords.splice(draggedIndex, 1)[0];
                keywords.splice(targetIndex, 0, draggedKeyword);
                
                // 현재 선택된 키워드 인덱스 조정
                if (currentKeywordIndex === draggedIndex) {
                    currentKeywordIndex = targetIndex;
                } else if (currentKeywordIndex === targetIndex) {
                    currentKeywordIndex = draggedIndex < targetIndex ? currentKeywordIndex + 1 : currentKeywordIndex - 1;
                } else if (currentKeywordIndex > Math.min(draggedIndex, targetIndex) && 
                          currentKeywordIndex <= Math.max(draggedIndex, targetIndex)) {
                    currentKeywordIndex += draggedIndex < targetIndex ? -1 : 1;
                }
                
                updateUI();
                showSuccessModal('키워드 순서 변경', '키워드 순서가 성공적으로 변경되었습니다.', '🔄');
            }
        }

        function handleProductDrop(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (draggedType !== 'product' || !e.target.closest('.product-item')) return;
            
            const targetItem = e.target.closest('.product-item');
            if (!targetItem || targetItem === draggedElement) return;
            
            const targetKeywordIndex = parseInt(targetItem.dataset.keyword);
            const targetProductIndex = parseInt(targetItem.dataset.product);
            
            if (draggedKeywordIndex === targetKeywordIndex && draggedIndex === targetProductIndex) return;
            
            const draggedProduct = keywords[draggedKeywordIndex].products.splice(draggedIndex, 1)[0];
            keywords[targetKeywordIndex].products.splice(targetProductIndex, 0, draggedProduct);
            
            // 현재 선택된 상품 인덱스 조정
            if (currentKeywordIndex === draggedKeywordIndex && currentProductIndex === draggedIndex) {
                currentKeywordIndex = targetKeywordIndex;
                currentProductIndex = targetProductIndex;
            } else if (currentKeywordIndex === targetKeywordIndex) {
                if (currentProductIndex >= targetProductIndex) {
                    currentProductIndex++;
                }
            }
            
            updateUI();
            showSuccessModal('상품 순서 변경', '상품 순서가 성공적으로 변경되었습니다.', '🔄');
        }

        // 일괄 처리 함수들
        async function batchAnalyzeAll() {
            const totalProducts = getAllProducts();
            if (totalProducts.length === 0) {
                showDetailedError('분석 오류', '분석할 상품이 없습니다.');
                return;
            }
            
            const batchAnalyzeBtn = document.getElementById('batchAnalyzeBtn');
            const batchProgress = document.getElementById('batchProgress');
            const batchProgressText = document.getElementById('batchProgressText');
            const batchProgressBar = document.getElementById('batchProgressBar');
            
            batchAnalyzeBtn.disabled = true;
            batchAnalyzeBtn.textContent = '분석 중...';
            batchProgress.style.display = 'block';
            
            let completed = 0;
            
            for (let i = 0; i < totalProducts.length; i++) {
                const { keywordIndex, productIndex, product } = totalProducts[i];
                
                try {
                    batchProgressText.textContent = `분석 중... (${completed + 1}/${totalProducts.length}) - ${product.name}`;
                    batchProgressBar.style.width = `${(completed / totalProducts.length) * 100}%`;
                    
                    if (product.url && product.url.trim() !== '' && product.status === 'empty') {
                        product.status = 'analyzing';
                        updateUI();
                        
                        const response = await fetch('product_analyzer_v2.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                action: 'analyze_product',
                                url: product.url,
                                platform: 'aliexpress'
                            })
                        });
                        
                        if (!response.ok) {
                            throw new Error(`HTTP 오류: ${response.status}`);
                        }
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            product.analysisData = result.data;
                            product.status = 'completed';
                            product.name = result.data.title || `상품 ${productIndex + 1}`;
                            
                            const generatedHtml = generateOptimizedMobileHtml(result.data, false);
                            product.generatedHtml = generatedHtml;
                        } else {
                            product.status = 'error';
                            console.error(`상품 분석 실패: ${result.message}`);
                        }
                    }
                } catch (error) {
                    product.status = 'error';
                    console.error(`상품 분석 중 오류: ${error.message}`);
                }
                
                completed++;
                updateUI();
                await new Promise(resolve => setTimeout(resolve, 1000)); // 1초 지연
            }
            
            batchProgressText.textContent = `분석 완료! (${completed}/${totalProducts.length})`;
            batchProgressBar.style.width = '100%';
            showSuccessModal('일괄 분석 완료!', `총 ${completed}개 상품의 분석이 완료되었습니다.`, '🔍');
            
            setTimeout(() => {
                batchProgress.style.display = 'none';
                batchAnalyzeBtn.disabled = false;
                batchAnalyzeBtn.textContent = '🔍 전체 분석';
            }, 3000);
        }

        async function batchSaveAll() {
            const totalProducts = getAllProducts();
            if (totalProducts.length === 0) {
                showDetailedError('저장 오류', '저장할 상품이 없습니다.');
                return;
            }
            
            const batchSaveBtn = document.getElementById('batchSaveBtn');
            const batchProgress = document.getElementById('batchProgress');
            const batchProgressText = document.getElementById('batchProgressText');
            const batchProgressBar = document.getElementById('batchProgressBar');
            
            batchSaveBtn.disabled = true;
            batchSaveBtn.textContent = '저장 중...';
            batchProgress.style.display = 'block';
            
            let completed = 0;
            
            // 개별 상품 저장 처리
            for (let i = 0; i < totalProducts.length; i++) {
                const { keywordIndex, productIndex, product } = totalProducts[i];
                
                try {
                    batchProgressText.textContent = `저장 중... (${completed + 1}/${totalProducts.length}) - ${product.name}`;
                    batchProgressBar.style.width = `${(completed / totalProducts.length) * 100}%`;
                    
                    if (product.url && product.url.trim() !== '' && product.status === 'completed' && !product.isSaved) {
                        product.isSaved = true;
                    }
                } catch (error) {
                    console.error(`상품 저장 중 오류: ${error.message}`);
                }
                
                completed++;
                updateUI();
                await new Promise(resolve => setTimeout(resolve, 200)); // 0.2초 지연
            }
            
            // 큐 저장 처리
            batchProgressText.textContent = '큐 저장 중...';
            batchProgressBar.style.width = '90%';
            
            try {
                const keywordsData = collectKeywordsData();
                const userDetails = collectUserInputDetails();
                const formData = {
                    title: document.getElementById('title').value.trim(),
                    category: document.getElementById('category').value,
                    prompt_type: document.getElementById('prompt_type').value,
                    keywords: keywordsData,
                    user_details: userDetails,
                    thumbnail_url: document.getElementById('thumbnail_url').value.trim()
                };
                
                if (!validateAndSubmitData(formData, true)) {
                    showDetailedError('저장 오류', '입력 데이터 검증에 실패했습니다.');
                    return;
                }
                
                const response = await fetch('keyword_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        title: formData.title,
                        category: formData.category,
                        prompt_type: formData.prompt_type,
                        keywords: JSON.stringify(formData.keywords),
                        user_details: JSON.stringify(formData.user_details),
                        thumbnail_url: formData.thumbnail_url,
                        publish_mode: 'queue'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    batchProgressText.textContent = `완료! 큐에 저장됨 (${completed}/${totalProducts.length})`;
                    batchProgressBar.style.width = '100%';
                    showSuccessModal('일괄 저장 완료!', `총 ${completed}개 상품이 저장되고 큐에 등록되었습니다.`, '💾');
                } else {
                    throw new Error(result.message || '큐 저장 실패');
                }
            } catch (error) {
                console.error('큐 저장 오류:', error);
                batchProgressText.textContent = '저장 완료, 큐 등록 실패';
                showSuccessModal('부분 완료', `상품 저장은 완료되었으나 큐 등록에 실패했습니다.\n오류: ${error.message}`, '⚠️');
            } finally {
                setTimeout(() => {
                    batchProgress.style.display = 'none';
                    batchSaveBtn.disabled = false;
                    batchSaveBtn.textContent = '💾 전체 저장';
                }, 3000);
            }
        }

        function getAllProducts() {
            const products = [];
            keywords.forEach((keyword, keywordIndex) => {
                keyword.products.forEach((product, productIndex) => {
                    products.push({ keywordIndex, productIndex, product });
                });
            });
            return products;
        }

        // 제목 키워드 입력에서 엔터 키 처리
        document.getElementById('titleKeyword').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                generateTitles();
            }
        });
    </script>
</body>
</html>