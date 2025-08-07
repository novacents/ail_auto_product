<?php
/**
 * Affiliate 상품 정보 편집기 - 큐 시스템 통합 버전
 * 버전: v3.1 (2025-08-06)
 * 
 * 주요 기능:
 * 1. 키워드별 상품 정보 입력
 * 2. 사용자 정의 상품 상세 정보 입력
 * 3. AI 분석을 통한 상품 정보 자동 수집
 * 4. 큐 시스템을 통한 배치 저장
 * 5. Excel/CSV 파일 업로드 지원
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');

if (!current_user_can('manage_options')) {
    wp_die('접근 권한이 없습니다.');
}

// 오류 보고 설정
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/www/novacents/tools/php_error_log.txt');

// 큐 유틸리티 포함
require_once('/var/www/novacents/tools/queue_utils.php');

// 디버그 로그 함수
function debug_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [AFFILIATE_EDITOR] $message" . PHP_EOL;
    file_put_contents('/var/www/novacents/tools/debug_log.txt', $log_message, FILE_APPEND | LOCK_EX);
}

// 세션 시작
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

debug_log("affiliate_editor.php: Script started");
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Affiliate 상품 정보 편집기 v3.1</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
            line-height: 1.6;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            margin: 0;
            font-size: 2.2em;
            font-weight: 300;
        }
        
        .header p {
            margin: 10px 0 0;
            opacity: 0.9;
            font-size: 1.1em;
        }
        
        .content {
            padding: 30px;
        }
        
        .section {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .section h2 {
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
            margin-top: 0;
            font-size: 1.4em;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            align-items: start;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-group.small {
            flex: 0.5;
        }
        
        .form-group.large {
            flex: 2;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #495057;
            font-size: 0.9em;
        }
        
        input[type="text"], input[type="url"], textarea, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ced4da;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }
        
        input[type="text"]:focus, input[type="url"]:focus, textarea:focus, select:focus {
            outline: none;
            border-color: #80bdff;
            box-shadow: 0 0 5px rgba(0,123,255,.25);
        }
        
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            text-align: center;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #1e7e34;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #117a8b;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 15px 0;
        }
        
        .keyword-item {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            background: white;
            position: relative;
        }
        
        .keyword-item h3 {
            color: #495057;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.2em;
            border-left: 4px solid #007bff;
            padding-left: 10px;
        }
        
        .keyword-actions {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            gap: 5px;
        }
        
        .keyword-actions .btn {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .product-item {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            background: #fdfdfd;
        }
        
        .product-item img {
            max-width: 100%;
            height: 150px;
            object-fit: cover;
            border-radius: 4px;
            margin-bottom: 10px;
        }
        
        .product-title {
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
            line-height: 1.4;
        }
        
        .product-price {
            color: #e74c3c;
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .product-info {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 3px;
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .loading.active {
            display: block;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid transparent;
        }
        
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
        
        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .status-processing {
            background-color: #cce7ff;
            color: #004085;
        }
        
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2.5em;
            font-weight: 700;
            color: #007bff;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .file-upload-area {
            border: 2px dashed #ced4da;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin-bottom: 20px;
            transition: border-color 0.3s;
        }
        
        .file-upload-area:hover {
            border-color: #007bff;
        }
        
        .file-upload-area.dragover {
            border-color: #007bff;
            background-color: rgba(0, 123, 255, 0.05);
        }
        
        .progress-bar {
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            background-color: #007bff;
            height: 20px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            font-weight: 600;
        }
        
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }
            
            .btn-group {
                justify-content: center;
            }
            
            .content {
                padding: 15px;
            }
            
            .product-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* 반응형 개선 */
        @media (max-width: 1200px) {
            .container {
                margin: 10px;
            }
        }
        
        /* 접근성 개선 */
        .btn:focus {
            outline: 2px solid #80bdff;
            outline-offset: 2px;
        }
        
        /* 다크모드 지원 준비 */
        @media (prefers-color-scheme: dark) {
            /* 향후 다크모드 스타일 추가 예정 */
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🛍️ Affiliate 상품 정보 편집기</h1>
            <p>키워드별 상품 정보를 분석하고 관리하는 통합 도구 - 큐 시스템 연동</p>
        </div>

        <div class="content">
            <!-- 큐 통계 섹션 -->
            <div class="section">
                <h2>📊 시스템 현황</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number" id="totalQueue">-</div>
                        <div class="stat-label">총 큐 항목</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="pendingQueue">-</div>
                        <div class="stat-label">대기 중</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="completedQueue">-</div>
                        <div class="stat-label">완료됨</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="currentProducts">0</div>
                        <div class="stat-label">현재 상품수</div>
                    </div>
                </div>
                <div class="btn-group">
                    <button class="btn btn-info" onclick="loadQueueStats()">📈 통계 새로고침</button>
                    <button class="btn btn-success" onclick="window.open('/tools/queue_manager.php', '_blank')">🎛️ 큐 관리자</button>
                </div>
            </div>

            <!-- Excel/CSV 파일 업로드 섹션 -->
            <div class="section">
                <h2>📂 Excel/CSV 파일 업로드</h2>
                <div class="file-upload-area" id="fileUploadArea">
                    <div style="font-size: 3em; margin-bottom: 10px;">📄</div>
                    <p><strong>Excel 파일을 여기에 드래그하거나 클릭하여 업로드</strong></p>
                    <p style="color: #6c757d; font-size: 0.9em;">지원 형식: CSV, XLS, XLSX (최대 10MB)</p>
                    <input type="file" id="excelFileInput" accept=".csv,.xls,.xlsx" style="display: none;">
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" onclick="document.getElementById('excelFileInput').click()">
                        📁 파일 선택
                    </button>
                    <button class="btn btn-success" id="batchAnalyzeBtn" onclick="batchAnalyzeAll()" disabled>
                        🔍 전체 분석
                    </button>
                    <button class="btn btn-warning" id="batchSaveBtn" onclick="batchSaveAll()" disabled>
                        💾 전체 저장
                    </button>
                </div>
                <div class="progress-bar" id="uploadProgress" style="display: none;">
                    <div class="progress-fill" id="uploadProgressFill">0%</div>
                </div>
            </div>

            <!-- 기본 설정 섹션 -->
            <div class="section">
                <h2>⚙️ 기본 설정</h2>
                <div class="form-row">
                    <div class="form-group">
                        <label for="postTitle">포스트 제목</label>
                        <input type="text" id="postTitle" placeholder="예: 2024 최고의 스마트 홈 아이템 추천">
                    </div>
                    <div class="form-group">
                        <label for="categorySelect">카테고리</label>
                        <select id="categorySelect">
                            <option value="354">Today's Pick</option>
                            <option value="355">기발한 잡화점</option>
                            <option value="356">스마트 리빙</option>
                            <option value="12">우리잇템</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="promptType">포스트 스타일</label>
                        <select id="promptType">
                            <option value="essential_items">필수템형 🎯</option>
                            <option value="friend_review">친구 추천형 👫</option>
                            <option value="professional_analysis">전문 분석형 📊</option>
                            <option value="amazing_discovery">놀라움 발견형 ✨</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- 키워드 및 상품 입력 섹션 -->
            <div class="section">
                <h2>🔍 키워드 및 상품 관리</h2>
                <div class="form-row">
                    <div class="form-group large">
                        <label for="keywordInput">키워드</label>
                        <input type="text" id="keywordInput" placeholder="예: 무선 이어폰, 스마트 워치, 블루투스 스피커">
                    </div>
                    <div class="form-group">
                        <label for="productUrl">상품 URL</label>
                        <input type="url" id="productUrl" placeholder="https://aliexpress.com/...">
                    </div>
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" onclick="addKeywordProduct()">➕ 상품 추가</button>
                    <button class="btn btn-info" onclick="analyzeAllProducts()">🔍 모든 상품 분석</button>
                    <button class="btn btn-success" onclick="saveToQueue()">💾 큐에 저장</button>
                    <button class="btn btn-warning" onclick="publishImmediately()">🚀 즉시 발행</button>
                </div>
            </div>

            <!-- 상품 목록 표시 영역 -->
            <div class="section">
                <h2>📋 현재 상품 목록</h2>
                <div id="keywordsList">
                    <p style="text-align: center; color: #6c757d; font-style: italic;">
                        상품을 추가하거나 Excel 파일을 업로드하면 여기에 표시됩니다.
                    </p>
                </div>
            </div>

            <!-- 사용자 정의 상세 정보 섹션 -->
            <div class="section" style="display: none;" id="userDetailsSection">
                <h2>📝 사용자 정의 상세 정보</h2>
                <p style="color: #6c757d; margin-bottom: 20px;">
                    상품에 대한 추가 정보를 입력하면 더 풍부한 컨텐츠를 생성할 수 있습니다.
                </p>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>기능/스펙 정보</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <input type="text" id="mainFunction" placeholder="주요 기능">
                            <input type="text" id="sizeCapacity" placeholder="크기/용량">
                            <input type="text" id="color" placeholder="색상">
                            <input type="text" id="material" placeholder="재질/소재">
                            <input type="text" id="powerBattery" placeholder="전원/배터리">
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>효율성 정보</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <textarea id="problemSolving" placeholder="해결하는 문제" rows="2"></textarea>
                            <textarea id="timeSaving" placeholder="시간 절약 효과" rows="2"></textarea>
                            <textarea id="spaceEfficiency" placeholder="공간 활용도" rows="2"></textarea>
                            <textarea id="costSaving" placeholder="비용 절감 효과" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>사용법 정보</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <input type="text" id="usageLocation" placeholder="사용 장소">
                            <input type="text" id="usageFrequency" placeholder="사용 빈도">
                            <input type="text" id="targetUsers" placeholder="적합한 사용자">
                            <textarea id="usageMethod" placeholder="사용 방법" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>장점 및 주의사항</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                            <input type="text" id="advantage1" placeholder="주요 장점 1">
                            <input type="text" id="advantage2" placeholder="주요 장점 2">
                            <input type="text" id="advantage3" placeholder="주요 장점 3">
                        </div>
                        <textarea id="precautions" placeholder="주의사항 및 단점" rows="3" style="margin-top: 10px; width: 100%;"></textarea>
                    </div>
                </div>
            </div>

            <!-- 로딩 상태 -->
            <div class="loading" id="loadingDiv">
                <div class="spinner"></div>
                <p>처리 중입니다... 잠시만 기다려주세요.</p>
            </div>
        </div>
    </div>

    <script>
        // 전역 변수
        let currentKeywords = [];
        let uploadedData = [];
        
        // 페이지 로드 시 실행
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Affiliate Editor v3.1 loaded');
            loadQueueStats();
            setupFileUpload();
        });
        
        // 큐 통계 로드
        async function loadQueueStats() {
            try {
                const response = await fetch('/tools/keyword_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_queue_stats'
                });
                
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('totalQueue').textContent = result.stats.total || 0;
                    document.getElementById('pendingQueue').textContent = result.stats.pending || 0;
                    document.getElementById('completedQueue').textContent = result.stats.completed || 0;
                }
            } catch (error) {
                console.error('큐 통계 로드 실패:', error);
            }
        }
        
        // 파일 업로드 설정
        function setupFileUpload() {
            const fileInput = document.getElementById('excelFileInput');
            const uploadArea = document.getElementById('fileUploadArea');
            
            // 파일 선택 시 처리
            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length > 0) {
                    handleFileUpload(e.target.files[0]);
                }
            });
            
            // 드래그 앤 드롭 처리
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
                
                if (e.dataTransfer.files.length > 0) {
                    handleFileUpload(e.dataTransfer.files[0]);
                }
            });
            
            uploadArea.addEventListener('click', function() {
                fileInput.click();
            });
        }
        
        // 파일 업로드 처리
        async function handleFileUpload(file) {
            const progressBar = document.getElementById('uploadProgress');
            const progressFill = document.getElementById('uploadProgressFill');
            
            try {
                // 파일 크기 체크 (10MB)
                if (file.size > 10 * 1024 * 1024) {
                    throw new Error('파일 크기가 10MB를 초과합니다.');
                }
                
                // 진행률 표시
                progressBar.style.display = 'block';
                progressFill.style.width = '0%';
                progressFill.textContent = '업로드 중...';
                
                // FormData 생성
                const formData = new FormData();
                formData.append('excel_file', file);
                
                // 파일 업로드
                const response = await fetch('/tools/excel_import_handler.php', {
                    method: 'POST',
                    body: formData
                });
                
                progressFill.style.width = '50%';
                progressFill.textContent = '파일 처리 중...';
                
                const result = await response.json();
                
                if (result.success) {
                    uploadedData = result.data;
                    processExcelData(result.data);
                    
                    progressFill.style.width = '100%';
                    progressFill.textContent = '완료!';
                    
                    // 버튼 활성화
                    document.getElementById('batchAnalyzeBtn').disabled = false;
                    document.getElementById('batchSaveBtn').disabled = false;
                    
                    alert(`✅ ${result.total_products}개 상품이 성공적으로 업로드되었습니다.`);
                    
                    setTimeout(() => {
                        progressBar.style.display = 'none';
                    }, 2000);
                } else {
                    throw new Error(result.message || '파일 처리 실패');
                }
                
            } catch (error) {
                console.error('파일 업로드 오류:', error);
                alert('❌ 파일 업로드 실패: ' + error.message);
                progressBar.style.display = 'none';
            }
        }
        
        // Excel 데이터 처리
        function processExcelData(data) {
            console.log('Processing Excel data:', data);
            
            // 기존 키워드 초기화
            currentKeywords = [];
            
            // 키워드별로 그룹핑
            const keywordGroups = {};
            
            data.forEach((item, index) => {
                const keyword = item.keyword;
                const url = item.url;
                
                if (!keyword || !url) {
                    console.warn(`Item ${index} missing keyword or URL:`, item);
                    return;
                }
                
                if (!keywordGroups[keyword]) {
                    keywordGroups[keyword] = [];
                }
                
                // 상품 데이터 구조 생성
                const productData = {
                    url: url,
                    title: '분석 대기 중...',
                    price: '',
                    original_price: '',
                    image_url: '',
                    rating: '',
                    orders: '',
                    shipping: '',
                    analyzed: false,
                    user_details: item.user_details || null
                };
                
                keywordGroups[keyword].push(productData);
            });
            
            // currentKeywords 배열에 추가
            Object.keys(keywordGroups).forEach(keyword => {
                currentKeywords.push({
                    keyword: keyword,
                    products_data: keywordGroups[keyword]
                });
            });
            
            // UI 업데이트
            updateKeywordsList();
            updateProductCount();
            
            console.log('Excel data processed. Current keywords:', currentKeywords);
        }
        
        // 개별 상품 추가
        function addKeywordProduct() {
            const keyword = document.getElementById('keywordInput').value.trim();
            const url = document.getElementById('productUrl').value.trim();
            
            if (!keyword || !url) {
                alert('키워드와 상품 URL을 모두 입력해주세요.');
                return;
            }
            
            // URL 형식 체크
            try {
                new URL(url);
            } catch (e) {
                alert('올바른 URL 형식이 아닙니다.');
                return;
            }
            
            // 기존 키워드 찾기 또는 새로 생성
            let keywordData = currentKeywords.find(k => k.keyword === keyword);
            if (!keywordData) {
                keywordData = {
                    keyword: keyword,
                    products_data: []
                };
                currentKeywords.push(keywordData);
            }
            
            // 중복 URL 체크
            const existingProduct = keywordData.products_data.find(p => p.url === url);
            if (existingProduct) {
                alert('이미 추가된 상품입니다.');
                return;
            }
            
            // 상품 데이터 추가
            keywordData.products_data.push({
                url: url,
                title: '분석 대기 중...',
                price: '',
                original_price: '',
                image_url: '',
                rating: '',
                orders: '',
                shipping: '',
                analyzed: false
            });
            
            // 입력 필드 초기화
            document.getElementById('keywordInput').value = '';
            document.getElementById('productUrl').value = '';
            
            // UI 업데이트
            updateKeywordsList();
            updateProductCount();
            
            console.log('Product added:', keyword, url);
        }
        
        // 모든 상품 분석
        async function analyzeAllProducts() {
            if (currentKeywords.length === 0) {
                alert('분석할 상품이 없습니다.');
                return;
            }
            
            const loadingDiv = document.getElementById('loadingDiv');
            loadingDiv.classList.add('active');
            
            let totalProducts = 0;
            let analyzedProducts = 0;
            
            // 총 상품 수 계산
            currentKeywords.forEach(keywordData => {
                totalProducts += keywordData.products_data.length;
            });
            
            try {
                for (const keywordData of currentKeywords) {
                    for (const product of keywordData.products_data) {
                        if (!product.analyzed) {
                            await analyzeProduct(product);
                            analyzedProducts++;
                            
                            // 진행률 업데이트
                            const progress = Math.round((analyzedProducts / totalProducts) * 100);
                            loadingDiv.querySelector('p').textContent = 
                                `분석 중... ${analyzedProducts}/${totalProducts} (${progress}%)`;
                        }
                    }
                }
                
                updateKeywordsList();
                alert(`✅ ${analyzedProducts}개 상품 분석이 완료되었습니다.`);
                
            } catch (error) {
                console.error('분석 중 오류:', error);
                alert('❌ 상품 분석 중 오류가 발생했습니다: ' + error.message);
            } finally {
                loadingDiv.classList.remove('active');
            }
        }
        
        // 개별 상품 분석
        async function analyzeProduct(product) {
            try {
                // 실제 API 호출 대신 더미 데이터 사용
                // TODO: 실제 상품 분석 API 구현
                
                // 임시 더미 데이터
                product.title = '분석된 상품 제목 - ' + product.url.split('/').pop();
                product.price = '$' + Math.floor(Math.random() * 100 + 10);
                product.original_price = '$' + Math.floor(Math.random() * 150 + 50);
                product.image_url = 'https://via.placeholder.com/200x200?text=Product';
                product.rating = (Math.random() * 2 + 3).toFixed(1);
                product.orders = Math.floor(Math.random() * 10000 + 100);
                product.shipping = 'Free Shipping';
                product.analyzed = true;
                
                // 짧은 딜레이 (실제 API 호출 시뮬레이션)
                await new Promise(resolve => setTimeout(resolve, 500));
                
            } catch (error) {
                console.error('상품 분석 실패:', error);
                product.title = '분석 실패 - ' + error.message;
            }
        }
        
        // 배치 분석 (Excel 업로드용)
        async function batchAnalyzeAll() {
            if (currentKeywords.length === 0) {
                alert('분석할 상품이 없습니다.');
                return;
            }
            
            const loadingDiv = document.getElementById('loadingDiv');
            loadingDiv.classList.add('active');
            
            try {
                let totalAnalyzed = 0;
                
                for (const keywordData of currentKeywords) {
                    for (const product of keywordData.products_data) {
                        if (!product.analyzed) {
                            await analyzeProduct(product);
                            totalAnalyzed++;
                        }
                    }
                }
                
                updateKeywordsList();
                alert(`✅ ${totalAnalyzed}개 상품의 배치 분석이 완료되었습니다.`);
                
            } catch (error) {
                console.error('배치 분석 오류:', error);
                alert('❌ 배치 분석 중 오류가 발생했습니다: ' + error.message);
            } finally {
                loadingDiv.classList.remove('active');
            }
        }
        
        // 배치 저장 (Excel 업로드용)
        async function batchSaveAll() {
            if (currentKeywords.length === 0) {
                alert('저장할 상품이 없습니다.');
                return;
            }
            
            // 데이터 유효성 검증
            const validationResult = validateAndSubmitData();
            if (!validationResult.isValid) {
                alert('❌ ' + validationResult.message);
                return;
            }
            
            const loadingDiv = document.getElementById('loadingDiv');
            loadingDiv.classList.add('active');
            
            try {
                const queueData = collectKeywordsData();
                
                const response = await fetch('/tools/keyword_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=save_to_queue&queue_data=' + encodeURIComponent(JSON.stringify(queueData))
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`✅ ${currentKeywords.length}개 키워드의 상품 정보가 큐에 저장되었습니다.\n큐 ID: ${result.queue_id}`);
                    
                    // 통계 업데이트
                    loadQueueStats();
                    
                    // 데이터 초기화 확인
                    if (confirm('저장이 완료되었습니다. 현재 작업 내용을 초기화하시겠습니까?')) {
                        resetForm();
                    }
                } else {
                    throw new Error(result.message || '저장 실패');
                }
                
            } catch (error) {
                console.error('배치 저장 오류:', error);
                alert('❌ 배치 저장 중 오류가 발생했습니다: ' + error.message);
            } finally {
                loadingDiv.classList.remove('active');
            }
        }
        
        // 큐에 저장
        async function saveToQueue() {
            if (currentKeywords.length === 0) {
                alert('저장할 키워드가 없습니다.');
                return;
            }
            
            const loadingDiv = document.getElementById('loadingDiv');
            loadingDiv.classList.add('active');
            
            try {
                const queueData = collectKeywordsData();
                
                const response = await fetch('/tools/keyword_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=save_to_queue&queue_data=' + encodeURIComponent(JSON.stringify(queueData))
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`✅ 큐에 저장되었습니다!\n큐 ID: ${result.queue_id}`);
                    loadQueueStats(); // 통계 업데이트
                } else {
                    alert('❌ 저장 실패: ' + (result.message || '알 수 없는 오류'));
                }
                
            } catch (error) {
                console.error('저장 오류:', error);
                alert('❌ 저장 중 오류가 발생했습니다: ' + error.message);
            } finally {
                loadingDiv.classList.remove('active');
            }
        }
        
        // 즉시 발행
        async function publishImmediately() {
            if (currentKeywords.length === 0) {
                alert('발행할 키워드가 없습니다.');
                return;
            }
            
            if (!confirm('선택한 내용을 즉시 포스트로 발행하시겠습니까?')) {
                return;
            }
            
            const loadingDiv = document.getElementById('loadingDiv');
            loadingDiv.classList.add('active');
            
            try {
                const queueData = collectKeywordsData();
                
                const response = await fetch('/tools/keyword_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=immediate_publish&queue_data=' + encodeURIComponent(JSON.stringify(queueData))
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`✅ 포스트가 성공적으로 발행되었습니다!\n포스트 ID: ${result.post_id}`);
                    loadQueueStats(); // 통계 업데이트
                } else {
                    alert('❌ 발행 실패: ' + (result.message || '알 수 없는 오류'));
                }
                
            } catch (error) {
                console.error('발행 오류:', error);
                alert('❌ 발행 중 오류가 발생했습니다: ' + error.message);
            } finally {
                loadingDiv.classList.remove('active');
            }
        }
        
        // 키워드 데이터 수집
        function collectKeywordsData() {
            const title = document.getElementById('postTitle').value.trim() || 
                         currentKeywords.map(k => k.keyword).join(', ') + ' 상품 모음';
            
            const categoryId = document.getElementById('categorySelect').value;
            const promptType = document.getElementById('promptType').value;
            
            // 사용자 정의 상세 정보 수집
            const userDetails = {
                specs: {
                    main_function: document.getElementById('mainFunction')?.value?.trim() || '',
                    size_capacity: document.getElementById('sizeCapacity')?.value?.trim() || '',
                    color: document.getElementById('color')?.value?.trim() || '',
                    material: document.getElementById('material')?.value?.trim() || '',
                    power_battery: document.getElementById('powerBattery')?.value?.trim() || ''
                },
                efficiency: {
                    problem_solving: document.getElementById('problemSolving')?.value?.trim() || '',
                    time_saving: document.getElementById('timeSaving')?.value?.trim() || '',
                    space_efficiency: document.getElementById('spaceEfficiency')?.value?.trim() || '',
                    cost_saving: document.getElementById('costSaving')?.value?.trim() || ''
                },
                usage: {
                    usage_location: document.getElementById('usageLocation')?.value?.trim() || '',
                    usage_frequency: document.getElementById('usageFrequency')?.value?.trim() || '',
                    target_users: document.getElementById('targetUsers')?.value?.trim() || '',
                    usage_method: document.getElementById('usageMethod')?.value?.trim() || ''
                },
                benefits: {
                    advantages: [
                        document.getElementById('advantage1')?.value?.trim() || '',
                        document.getElementById('advantage2')?.value?.trim() || '',
                        document.getElementById('advantage3')?.value?.trim() || ''
                    ].filter(adv => adv.length > 0),
                    precautions: document.getElementById('precautions')?.value?.trim() || ''
                }
            };
            
            return {
                title: title,
                category_id: categoryId,
                prompt_type: promptType,
                keywords: currentKeywords.map(keywordData => {
                    return {
                        keyword: keywordData.keyword,
                        products_data: keywordData.products_data.map(product => {
                            return {
                                ...product,
                                user_details: product.user_details || userDetails
                            };
                        })
                    };
                }),
                user_details: userDetails
            };
        }
        
        // 데이터 검증
        function validateAndSubmitData() {
            if (currentKeywords.length === 0) {
                return { isValid: false, message: '최소 1개 이상의 키워드를 추가해주세요.' };
            }
            
            // 각 키워드에 상품이 있는지 확인
            for (const keywordData of currentKeywords) {
                if (!keywordData.products_data || keywordData.products_data.length === 0) {
                    return { 
                        isValid: false, 
                        message: `키워드 "${keywordData.keyword}"에 상품이 없습니다.` 
                    };
                }
                
                // 각 상품의 URL 유효성 검사
                for (const product of keywordData.products_data) {
                    if (!product.url) {
                        return { 
                            isValid: false, 
                            message: `키워드 "${keywordData.keyword}"의 상품 중 URL이 없는 항목이 있습니다.` 
                        };
                    }
                    
                    // AliExpress URL 검증 개선
                    if (!(product.url.includes('aliexpress') || product.url.includes('ali.ski') || product.url.includes('s.click'))) {
                        return { 
                            isValid: false, 
                            message: `키워드 "${keywordData.keyword}"의 상품 URL이 올바르지 않습니다: ${product.url}` 
                        };
                    }
                }
            }
            
            return { isValid: true, message: '검증 완료' };
        }
        
        // UI 업데이트 함수들
        function updateKeywordsList() {
            const keywordsList = document.getElementById('keywordsList');
            
            if (currentKeywords.length === 0) {
                keywordsList.innerHTML = `
                    <p style="text-align: center; color: #6c757d; font-style: italic;">
                        상품을 추가하거나 Excel 파일을 업로드하면 여기에 표시됩니다.
                    </p>
                `;
                return;
            }
            
            let html = '';
            
            currentKeywords.forEach((keywordData, index) => {
                html += `
                    <div class="keyword-item">
                        <div class="keyword-actions">
                            <button class="btn btn-info" onclick="analyzeKeywordProducts(${index})">🔍 분석</button>
                            <button class="btn btn-danger" onclick="removeKeyword(${index})">🗑️ 삭제</button>
                        </div>
                        
                        <h3>🔍 ${keywordData.keyword}</h3>
                        
                        <div class="product-grid">
                `;
                
                keywordData.products_data.forEach((product, productIndex) => {
                    const statusClass = product.analyzed ? 'status-completed' : 'status-pending';
                    const statusText = product.analyzed ? 'ANALYZED' : 'PENDING';
                    
                    html += `
                        <div class="product-item">
                            <span class="status-badge ${statusClass}">${statusText}</span>
                            
                            ${product.image_url ? 
                                `<img src="${product.image_url}" alt="${product.title}" onerror="this.src='https://via.placeholder.com/200x200?text=No+Image'">` : 
                                `<div style="height: 150px; background: #f8f9fa; display: flex; align-items: center; justify-content: center; border-radius: 4px; margin-bottom: 10px;">
                                    <span style="color: #6c757d;">이미지 없음</span>
                                </div>`
                            }
                            
                            <div class="product-title">${product.title}</div>
                            
                            ${product.price ? `<div class="product-price">${product.price}</div>` : ''}
                            
                            ${product.original_price && product.original_price !== product.price ? 
                                `<div class="product-info">원가: <s>${product.original_price}</s></div>` : ''
                            }
                            
                            ${product.rating ? `<div class="product-info">⭐ ${product.rating}</div>` : ''}
                            ${product.orders ? `<div class="product-info">📦 ${product.orders} orders</div>` : ''}
                            ${product.shipping ? `<div class="product-info">🚚 ${product.shipping}</div>` : ''}
                            
                            <div class="product-info" style="margin-top: 10px;">
                                <a href="${product.url}" target="_blank" style="font-size: 12px; word-break: break-all;">
                                    ${product.url.length > 50 ? product.url.substring(0, 50) + '...' : product.url}
                                </a>
                            </div>
                            
                            <div style="margin-top: 10px;">
                                <button class="btn btn-info" style="font-size: 11px; padding: 4px 8px;" 
                                        onclick="analyzeIndividualProduct(${index}, ${productIndex})">
                                    재분석
                                </button>
                                <button class="btn btn-danger" style="font-size: 11px; padding: 4px 8px;" 
                                        onclick="removeProduct(${index}, ${productIndex})">
                                    삭제
                                </button>
                            </div>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            });
            
            keywordsList.innerHTML = html;
            
            // 상세 정보 섹션 표시
            document.getElementById('userDetailsSection').style.display = 'block';
        }
        
        function updateProductCount() {
            let totalProducts = 0;
            currentKeywords.forEach(keywordData => {
                totalProducts += keywordData.products_data.length;
            });
            document.getElementById('currentProducts').textContent = totalProducts;
        }
        
        // 키워드별 상품 분석
        async function analyzeKeywordProducts(keywordIndex) {
            const keywordData = currentKeywords[keywordIndex];
            const loadingDiv = document.getElementById('loadingDiv');
            loadingDiv.classList.add('active');
            
            try {
                for (const product of keywordData.products_data) {
                    if (!product.analyzed) {
                        await analyzeProduct(product);
                    }
                }
                
                updateKeywordsList();
                alert(`✅ "${keywordData.keyword}" 키워드의 상품 분석이 완료되었습니다.`);
                
            } catch (error) {
                console.error('키워드 분석 오류:', error);
                alert('❌ 분석 중 오류가 발생했습니다: ' + error.message);
            } finally {
                loadingDiv.classList.remove('active');
            }
        }
        
        // 개별 상품 재분석
        async function analyzeIndividualProduct(keywordIndex, productIndex) {
            const product = currentKeywords[keywordIndex].products_data[productIndex];
            const loadingDiv = document.getElementById('loadingDiv');
            loadingDiv.classList.add('active');
            
            try {
                product.analyzed = false; // 재분석을 위해 상태 초기화
                await analyzeProduct(product);
                updateKeywordsList();
                alert('✅ 상품 재분석이 완료되었습니다.');
                
            } catch (error) {
                console.error('상품 재분석 오류:', error);
                alert('❌ 재분석 중 오류가 발생했습니다: ' + error.message);
            } finally {
                loadingDiv.classList.remove('active');
            }
        }
        
        // 키워드 삭제
        function removeKeyword(index) {
            if (confirm(`"${currentKeywords[index].keyword}" 키워드를 삭제하시겠습니까?`)) {
                currentKeywords.splice(index, 1);
                updateKeywordsList();
                updateProductCount();
            }
        }
        
        // 상품 삭제
        function removeProduct(keywordIndex, productIndex) {
            const keyword = currentKeywords[keywordIndex].keyword;
            const product = currentKeywords[keywordIndex].products_data[productIndex];
            
            if (confirm(`"${keyword}" 키워드에서 상품을 삭제하시겠습니까?`)) {
                currentKeywords[keywordIndex].products_data.splice(productIndex, 1);
                
                // 키워드에 상품이 없으면 키워드도 삭제
                if (currentKeywords[keywordIndex].products_data.length === 0) {
                    currentKeywords.splice(keywordIndex, 1);
                }
                
                updateKeywordsList();
                updateProductCount();
            }
        }
        
        // 폼 초기화
        function resetForm() {
            currentKeywords = [];
            uploadedData = [];
            
            document.getElementById('postTitle').value = '';
            document.getElementById('keywordInput').value = '';
            document.getElementById('productUrl').value = '';
            
            // 사용자 정의 상세 정보 초기화
            ['mainFunction', 'sizeCapacity', 'color', 'material', 'powerBattery',
             'problemSolving', 'timeSaving', 'spaceEfficiency', 'costSaving',
             'usageLocation', 'usageFrequency', 'targetUsers', 'usageMethod',
             'advantage1', 'advantage2', 'advantage3', 'precautions'].forEach(id => {
                const element = document.getElementById(id);
                if (element) element.value = '';
            });
            
            // 버튼 비활성화
            document.getElementById('batchAnalyzeBtn').disabled = true;
            document.getElementById('batchSaveBtn').disabled = true;
            
            updateKeywordsList();
            updateProductCount();
        }
    </script>
</body>
</html>