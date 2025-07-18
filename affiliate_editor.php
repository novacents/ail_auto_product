<?php
/**
 * 어필리에이트 상품 등록 자동화 입력 페이지 (AliExpress 공식 스타일 - 좌우 분할 + 📱 반응형)
 * 노바센트(novacents.com) 전용 - 압축 최적화 버전 + 사용자 상세 정보 수집 기능 + 프롬프트 선택 기능
 * 수정: 새 상품 선택 시 사용자 입력 필드 초기화 기능 추가 + 진행률 계산 수정 (저장 버튼 클릭 시에만 완료) + 상단 이동 버튼 추가 + 키워드/상품 삭제 기능 추가
 */
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');
if (!current_user_can('manage_options')) { wp_die('접근 권한이 없습니다.'); }
$env_file = '/home/novacents/.env'; $env_vars = [];
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2); $env_vars[trim($key)] = trim($value);
        }
    }
}
if (isset($_POST['action']) && $_POST['action'] === 'generate_titles') {
    header('Content-Type: application/json');
    $keywords_input = sanitize_text_field($_POST['keywords']);
    if (empty($keywords_input)) { echo json_encode(['success' => false, 'message' => '키워드를 입력해주세요.']); exit; }
    $keywords = array_map('trim', explode(',', $keywords_input)); $keywords = array_filter($keywords);
    if (empty($keywords)) { echo json_encode(['success' => false, 'message' => '유효한 키워드를 입력해주세요.']); exit; }
    $combined_keywords = implode(',', $keywords);
    $script_locations = [__DIR__ . '/title_generator.py', '/home/novacents/title_generator.py'];
    $output = null; $found_script = false;
    foreach ($script_locations as $script_path) {
        if (file_exists($script_path)) {
            $script_dir = dirname($script_path);
            $command = "LANG=ko_KR.UTF-8 /usr/bin/env /usr/bin/python3 " . escapeshellarg($script_path) . " " . escapeshellarg($combined_keywords) . " 2>&1";
            $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
            $process = proc_open($command, $descriptorspec, $pipes, $script_dir, null);
            if (is_resource($process)) {
                fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error_output = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $return_code = proc_close($process);
                if ($return_code === 0 && !empty($output)) { $found_script = true; break; }
            }
        }
    }
    if (!$found_script) { echo json_encode(['success' => false, 'message' => 'Python 스크립트를 찾을 수 없거나 실행에 실패했습니다.']); exit; }
    $result = json_decode(trim($output), true);
    if ($result === null) { echo json_encode(['success' => false, 'message' => 'Python 스크립트 응답 파싱 실패.', 'raw_output' => $output]); exit; }
    echo json_encode($result); exit;
}

$success_message = ''; $error_message = '';
if (isset($_GET['success']) && $_GET['success'] == '1') { $success_message = '글이 성공적으로 발행 대기열에 추가되었습니다!'; }
if (isset($_GET['error'])) { $error_message = '오류: ' . urldecode($_GET['error']); }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>어필리에이트 상품 등록 - 노바센트</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;padding:20px;background-color:#f5f5f5;min-width:1200px;color:#1c1c1c}
.main-container{width:1800px;margin:0 auto;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);overflow:hidden}
.header-section{padding:30px;border-bottom:1px solid #e0e0e0;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white}
.header-section h1{margin:0 0 10px 0;font-size:28px}
.header-section .subtitle{margin:0 0 20px 0;opacity:0.9}
.header-form{display:grid;grid-template-columns:1fr 250px 250px;gap:15px;margin-top:20px}
.title-section{position:relative}
.title-input-row{display:flex;gap:10px;align-items:flex-end}
.title-input-row input{flex:1;padding:12px;border:1px solid rgba(255,255,255,0.3);border-radius:6px;background:rgba(255,255,255,0.1);color:white;font-size:16px}
.title-input-row input::placeholder{color:rgba(255,255,255,0.7)}
.category-section select,.prompt-section select{width:100%;padding:12px;border:1px solid rgba(255,255,255,0.3);border-radius:6px;background:rgba(255,255,255,0.1);color:white;font-size:16px}
.category-section select option,.prompt-section select option{background:#333;color:white}

/* 상단 네비게이션 추가 */
.nav-links{display:flex;gap:10px;margin-top:15px}
.nav-link{background:rgba(255,255,255,0.2);color:white;padding:8px 16px;border-radius:4px;text-decoration:none;font-size:14px;transition:all 0.3s}
.nav-link:hover{background:rgba(255,255,255,0.3);color:white}

.main-content{display:flex;min-height:600px}
.products-sidebar{width:600px;border-right:1px solid #e0e0e0;background:#fafafa;display:flex;flex-direction:column}
.sidebar-header{padding:20px;border-bottom:1px solid #e0e0e0;background:white}
.progress-info{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.progress-text{font-weight:bold;color:#333}
.progress-bar{flex:1;height:8px;background:#e0e0e0;border-radius:4px;margin:0 15px;overflow:hidden}
.progress-fill{height:100%;background:linear-gradient(90deg,#4CAF50,#45a049);width:0%;transition:width 0.3s ease}
.products-list{flex:1;overflow-y:auto;padding:0}
.keyword-group{border-bottom:1px solid #e0e0e0}
.keyword-header{padding:15px 20px;background:white;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;cursor:pointer}
.keyword-header:hover{background:#f8f9fa}
.keyword-info{display:flex;align-items:center;gap:10px}
.keyword-name{font-weight:600;color:#333}
.product-count{background:#007bff;color:white;padding:2px 8px;border-radius:12px;font-size:12px}
.keyword-actions{display:flex;gap:8px}
.product-item{padding:12px 40px;border-bottom:1px solid #f5f5f5;display:flex;align-items:center;gap:10px;cursor:pointer;transition:background 0.2s}
.product-item:hover{background:#f0f8ff}
.product-item.active{background:#e3f2fd;border-left:4px solid #2196F3}
.product-status{font-size:18px;width:20px}
.product-name{flex:1;font-size:14px;color:#555}
.product-actions{display:flex;gap:5px;margin-left:auto}
.sidebar-actions{padding:15px 20px;border-bottom:1px solid #e0e0e0;background:white}
.keyword-input-section{margin-top:10px;padding:15px;background:#f8f9fa;border-radius:6px;border:1px solid #e9ecef;display:none}
.keyword-input-section.show{display:block}
.keyword-input-row-inline{display:flex;gap:10px;align-items:center}
.keyword-input-row-inline input{flex:1;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px}
.keyword-input-row-inline button{padding:8px 16px;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:600}
.detail-panel{flex:1;width:1100px;padding:30px;overflow-y:auto}
.detail-header{margin-bottom:20px;padding-bottom:20px;border-bottom:2px solid #f0f0f0}

/* 🚀 상단 네비게이션 버튼 스타일 */
.top-navigation{margin-bottom:30px;padding:20px;background:#f8f9fa;border-radius:8px;border:1px solid #e9ecef}
.nav-buttons{display:flex;align-items:center;gap:10px}
.nav-buttons-left{display:flex;gap:10px}
.nav-divider{width:2px;height:40px;background:#ddd;margin:0 20px}
.nav-buttons-right{display:flex;gap:15px}
.btn-orange{background:#ff9900;color:white;border:none;padding:12px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.3s;text-decoration:none;display:inline-block}
.btn-orange:hover{background:#e68a00;transform:translateY(-1px)}
.btn-orange:disabled{background:#ccc;cursor:not-allowed;transform:none}

/* 로딩 오버레이 */
.loading-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:none;align-items:center;justify-content:center}
.loading-content{background:white;border-radius:10px;padding:40px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.3)}
.loading-spinner{display:inline-block;width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #ff9900;border-radius:50%;animation:spin 1s linear infinite;margin-bottom:20px}
.loading-text{font-size:18px;color:#333;font-weight:600}

/* 🔝 상단으로 이동 버튼 스타일 */
.scroll-to-top{position:fixed;bottom:30px;right:30px;width:50px;height:50px;background:#667eea;color:white;border:none;border-radius:50%;cursor:pointer;font-size:20px;display:none;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.2);transition:all 0.3s;z-index:1000}
.scroll-to-top:hover{background:#764ba2;transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,0.3)}
.scroll-to-top.show{display:flex}

.product-url-section{margin-bottom:30px;padding:20px;background:#f8f9fa;border-radius:8px;border:1px solid #e9ecef}
.url-input-group{display:flex;gap:10px;margin-bottom:15px}
.url-input-group input{flex:1;padding:12px;border:1px solid #ddd;border-radius:6px;font-size:16px}
.analysis-result{margin-top:15px;padding:20px;background:white;border-radius:8px;border:1px solid #ddd;display:none}
.analysis-result.show{display:block}
.product-card{background:#fff;border-radius:12px;padding:25px;box-shadow:0 2px 12px rgba(0,0,0,0.08);border:1px solid #f0f0f0;margin-bottom:20px}
.product-content-split{display:grid;grid-template-columns:400px 1fr;gap:30px;align-items:start;margin-bottom:25px}
.product-image-large{width:100%}
.product-image-large img{width:100%;max-width:400px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,0.15)}
.product-info-all{display:flex;flex-direction:column;gap:20px}
.aliexpress-logo-right{margin-bottom:15px}
.aliexpress-logo-right img{width:250px;height:60px;object-fit:contain}
.product-title-right{color:#1c1c1c;font-size:21px;font-weight:600;line-height:1.4;margin:0 0 20px 0;word-break:keep-all;overflow-wrap:break-word}
.product-price-right{background:linear-gradient(135deg,#e62e04 0%,#ff9900 100%);color:white;padding:14px 30px;border-radius:10px;font-size:40px;font-weight:700;text-align:center;margin-bottom:20px;box-shadow:0 4px 15px rgba(230,46,4,0.3)}
.product-rating-right{color:#1c1c1c;font-size:20px;display:flex;align-items:center;gap:10px;margin-bottom:15px}
.rating-stars{color:#ff9900}
.product-sales-right{color:#1c1c1c;font-size:18px;margin-bottom:15px}
.product-extra-info-right{background:#f8f9fa;border-radius:8px;padding:20px;margin-top:15px}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #eee;font-size:16px}
.info-row:last-child{border-bottom:none}
.info-label{color:#666;font-weight:500}
.info-value{color:#1c1c1c;font-weight:600}
.purchase-button-full{text-align:center;margin-top:30px;width:100%}
.purchase-button-full img{max-width:100%;height:auto;cursor:pointer;transition:transform 0.2s ease}
.purchase-button-full img:hover{transform:scale(1.02)}
.html-source-section{margin-top:30px;padding:20px;background:#f1f8ff;border-radius:8px;border:1px solid #b3d9ff}
.html-source-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.html-source-header h4{margin:0;color:#0066cc;font-size:18px}
.copy-btn{padding:8px 16px;background:#007bff;color:white;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.3s}
.copy-btn:hover{background:#0056b3}
.copy-btn.copied{background:#28a745}
.html-preview{background:white;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:15px}
.html-code{background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;padding:15px;font-family:'Courier New',monospace;font-size:12px;line-height:1.4;overflow-x:auto;white-space:pre;color:#333;max-height:300px;overflow-y:auto}
.preview-product-card{display:flex;justify-content:center;margin:25px 0}
.preview-card-content{border:2px solid #eee;padding:30px;border-radius:15px;background:#f9f9f9;box-shadow:0 4px 8px rgba(0,0,0,0.1);max-width:1000px;width:100%}
.preview-content-split{display:grid;grid-template-columns:400px 1fr;gap:30px;align-items:start;margin-bottom:25px}
.preview-image-large{width:100%;max-width:400px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,0.15)}
.preview-info-all{display:flex;flex-direction:column;gap:20px}
.preview-aliexpress-logo{margin-bottom:15px}
.preview-aliexpress-logo img{width:250px;height:60px;object-fit:contain}
.preview-card-title{color:#1c1c1c;margin:0 0 20px 0;font-size:21px;font-weight:600;line-height:1.4;word-break:keep-all;overflow-wrap:break-word}
.preview-price-main{background:linear-gradient(135deg,#e62e04 0%,#ff9900 100%);color:white;padding:14px 30px;border-radius:10px;font-size:40px;font-weight:700;text-align:center;margin:0 0 20px 0;box-shadow:0 4px 15px rgba(230,46,4,0.3)}
.preview-rating{color:#1c1c1c;font-size:20px;display:flex;align-items:center;gap:10px;margin:0 0 15px 0}
.preview-rating .rating-stars{color:#ff9900}
.preview-sales{color:#1c1c1c;font-size:18px;margin:0 0 15px 0}
.preview-button-container{text-align:center;margin-top:30px}
.preview-button-container img{max-width:100%;height:auto;cursor:pointer}
.user-input-section{margin-top:30px}
.input-group{margin-bottom:30px;padding:20px;background:white;border:1px solid #e0e0e0;border-radius:8px}
.input-group h3{margin:0 0 20px 0;padding-bottom:10px;border-bottom:2px solid #f0f0f0;color:#333;font-size:18px}
.form-row{display:grid;gap:15px;margin-bottom:15px}
.form-row.two-col{grid-template-columns:1fr 1fr}
.form-row.three-col{grid-template-columns:1fr 1fr 1fr}
.form-field label{display:block;margin-bottom:5px;font-weight:600;color:#333;font-size:14px}
.form-field input,.form-field textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box}
.form-field textarea{min-height:60px;resize:vertical}
.advantages-list{list-style:none;padding:0;margin:0}
.advantages-list li{margin-bottom:10px}
.advantages-list input{width:100%;padding:8px;border:1px solid #ddd;border-radius:4px}
.btn{padding:12px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.3s;text-decoration:none;display:inline-block;margin:0 5px}
.btn-primary{background-color:#007bff;color:white}
.btn-primary:hover{background-color:#0056b3}
.btn-secondary{background-color:#6c757d;color:white}
.btn-secondary:hover{background-color:#545b62}
.btn-success{background-color:#28a745;color:white}
.btn-success:hover{background-color:#1e7e34}
.btn-danger{background-color:#dc3545;color:white}
.btn-danger:hover{background-color:#c82333}
.btn-small{padding:6px 12px;font-size:12px}
.btn-large{padding:15px 30px;font-size:16px}
.keyword-generator{margin-top:15px;padding:15px;background-color:rgba(255,255,255,0.1);border-radius:6px;display:none}
.keyword-input-row{display:flex;gap:10px;margin-bottom:15px}
.keyword-input-row input{flex:1;padding:10px;border:1px solid rgba(255,255,255,0.3);border-radius:4px;background:rgba(255,255,255,0.1);color:white}
.generated-titles{margin-top:15px}
.title-options{display:grid;gap:8px}
.title-option{padding:12px 15px;background-color:rgba(255,255,255,0.1);border:2px solid rgba(255,255,255,0.3);border-radius:6px;cursor:pointer;transition:all 0.2s;text-align:left;color:white}
.title-option:hover{background-color:rgba(255,255,255,0.2);border-color:rgba(255,255,255,0.6)}
.loading{display:none;text-align:center;color:rgba(255,255,255,0.8);margin-top:10px}
.spinner{display:inline-block;width:20px;height:20px;border:3px solid rgba(255,255,255,0.3);border-top:3px solid white;border-radius:50%;animation:spin 1s linear infinite;margin-right:10px}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
.alert{padding:15px;border-radius:6px;margin-bottom:20px}
.alert-success{background-color:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert-error{background-color:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
.empty-state{text-align:center;padding:40px 20px;color:#666}
.empty-state h3{margin:0 0 10px 0;color:#999}
</style>
</head>
<body>
<!-- 로딩 오버레이 -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-content">
        <div class="loading-spinner"></div>
        <div class="loading-text">글을 발행하고 있습니다...</div>
        <div style="margin-top: 10px; color: #666; font-size: 14px;">잠시만 기다려주세요.</div>
    </div>
</div>

<!-- 🔝 상단으로 이동 버튼 -->
<button class="scroll-to-top" id="scrollToTop" onclick="scrollToTop()">⬆️</button>

<div class="main-container">
<div class="header-section">
<h1>🛍️ 어필리에이트 상품 등록</h1>
<p class="subtitle">알리익스프레스 전용 상품 글 생성기 + 사용자 상세 정보 활용 + 프롬프트 선택</p>

<!-- 상단 네비게이션 링크 추가 -->
<div class="nav-links">
    <a href="queue_manager.php" class="nav-link">📋 저장된 정보 관리</a>
</div>

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
<div class="prompt-section">
<label for="prompt_type" style="color: rgba(255,255,255,0.9); margin-bottom: 8px; display: block;">프롬프트 스타일</label>
<select id="prompt_type" name="prompt_type" required>
<option value="essential_items" selected>주제별 필수템형</option>
<option value="friend_review">친구 추천형</option>
<option value="professional_analysis">전문 분석형</option>
<option value="amazing_discovery">놀라움 발견형</option>
</select>
</div>
</div>
</form>
</div>
<div class="main-content">
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
<button type="button" class="btn btn-primary" onclick="toggleKeywordInput()" style="width: 100%; margin-bottom: 10px;">📁 키워드 추가</button>
<div class="keyword-input-section" id="keywordInputSection">
<div class="keyword-input-row-inline">
<input type="text" id="newKeywordInput" placeholder="새 키워드를 입력하세요" />
<button type="button" class="btn-success" onclick="addKeywordFromInput()">추가</button>
<button type="button" class="btn-secondary" onclick="cancelKeywordInput()">취소</button>
</div>
</div>
</div>
<div class="products-list" id="productsList">
<div class="empty-state">
<h3>📦 상품이 없습니다</h3>
<p>위의 "키워드 추가" 버튼을 클릭하여<br>첫 번째 키워드를 추가해보세요!</p>
</div>
</div>
</div>
<div class="detail-panel">
<div class="detail-header">
<h2 id="currentProductTitle">상품을 선택해주세요</h2>
<p id="currentProductSubtitle">왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.</p>
</div>

<!-- 🚀 상단 네비게이션 버튼 영역 -->
<div class="top-navigation" id="topNavigation" style="display: none;">
<div class="nav-buttons">
<div class="nav-buttons-left">
<button type="button" class="btn btn-secondary" onclick="previousProduct()">⬅️ 이전</button>
<button type="button" class="btn btn-success" onclick="saveCurrentProduct()">💾 저장</button>
<button type="button" class="btn btn-secondary" onclick="nextProduct()">다음 ➡️</button>
<button type="button" class="btn btn-primary" onclick="completeProduct()">✅ 완료</button>
</div>
<div class="nav-divider"></div>
<div class="nav-buttons-right">
<button type="button" class="btn-orange" onclick="publishNow()" id="publishNowBtn">🚀 즉시 발행</button>
</div>
</div>
</div>

<div id="productDetailContent" style="display: none;">
<div class="product-url-section">
<h3>🌏 알리익스프레스 상품 URL</h3>
<div class="url-input-group">
<input type="url" id="productUrl" placeholder="예: https://www.aliexpress.com/item/123456789.html">
<button type="button" class="btn btn-primary" onclick="analyzeProduct()">🔍 분석</button>
</div>
<div class="analysis-result" id="analysisResult">
<div class="product-card" id="productCard"></div>
<div class="html-source-section" id="htmlSourceSection" style="display: none;">
<div class="html-source-header">
<h4>📝 워드프레스 글 HTML 소스</h4>
<button type="button" class="copy-btn" onclick="copyHtmlSource()">📋 복사하기</button>
</div>
<div class="html-preview">
<h5 style="margin: 0 0 10px 0; color: #666;">미리보기:</h5>
<div id="htmlPreview"></div>
</div>
<div class="html-code" id="htmlCode"></div>
</div>
</div>
</div>
<div class="user-input-section">
<div class="input-group">
<h3>⚙️ 기능 및 스펙 <small style="color: #666;">(선택사항 - 빈 칸은 자동 제외)</small></h3>
<div class="form-row">
<div class="form-field">
<label>주요 기능</label>
<input type="text" id="main_function" placeholder="예: 자동 압축, 물 절약, 시간 단축 등">
</div>
</div>
<div class="form-row two-col">
<div class="form-field">
<label>크기/용량</label>
<input type="text" id="size_capacity" placeholder="예: 30cm × 20cm, 500ml 등">
</div>
<div class="form-field">
<label>색상</label>
<input type="text" id="color" placeholder="예: 화이트, 블랙, 실버 등">
</div>
</div>
<div class="form-row two-col">
<div class="form-field">
<label>재질/소재</label>
<input type="text" id="material" placeholder="예: 스테인리스 스틸, 실리콘 등">
</div>
<div class="form-field">
<label>전원/배터리</label>
<input type="text" id="power_battery" placeholder="예: USB 충전, 건전지 등">
</div>
</div>
</div>
<div class="input-group">
<h3>📊 효율성 분석 <small style="color: #666;">(선택사항 - 빈 칸은 자동 제외)</small></h3>
<div class="form-row">
<div class="form-field">
<label>해결하는 문제</label>
<input type="text" id="problem_solving" placeholder="예: 설거지 시간 오래 걸림">
</div>
</div>
<div class="form-row two-col">
<div class="form-field">
<label>시간 절약 효과</label>
<input type="text" id="time_saving" placeholder="예: 기존 10분 → 3분으로 단축">
</div>
<div class="form-field">
<label>공간 활용</label>
<input type="text" id="space_efficiency" placeholder="예: 50% 공간 절약">
</div>
</div>
<div class="form-row">
<div class="form-field">
<label>비용 절감</label>
<input type="text" id="cost_saving" placeholder="예: 월 전기료 30% 절약">
</div>
</div>
</div>
<div class="input-group">
<h3>🏠 사용 시나리오 <small style="color: #666;">(선택사항 - 빈 칸은 자동 제외)</small></h3>
<div class="form-row two-col">
<div class="form-field">
<label>주요 사용 장소</label>
<input type="text" id="usage_location" placeholder="예: 주방, 욕실, 거실 등">
</div>
<div class="form-field">
<label>사용 빈도</label>
<input type="text" id="usage_frequency" placeholder="예: 매일, 주 2-3회 등">
</div>
</div>
<div class="form-row two-col">
<div class="form-field">
<label>적합한 사용자</label>
<input type="text" id="target_users" placeholder="예: 1인 가구, 맞벌이 부부 등">
</div>
<div class="form-field">
<label>사용법 요약</label>
<input type="text" id="usage_method" placeholder="간단한 사용 단계">
</div>
</div>
</div>
<div class="input-group">
<h3>✅ 장점 및 주의사항 <small style="color: #666;">(선택사항 - 빈 칸은 자동 제외)</small></h3>
<div class="form-row">
<div class="form-field">
<label>핵심 장점 3가지</label>
<ol class="advantages-list">
<li><input type="text" id="advantage1" placeholder="예: 설치 간편함"></li>
<li><input type="text" id="advantage2" placeholder="예: 유지비 저렴함"></li>
<li><input type="text" id="advantage3" placeholder="예: 내구성 뛰어남"></li>
</ol>
</div>
</div>
<div class="form-row">
<div class="form-field">
<label>주의사항</label>
<textarea id="precautions" placeholder="예: 물기 주의, 정기 청소 필요 등"></textarea>
</div>
</div>
</div>
</div>
</div>
</div>
</div>
</div>

<script>
let keywords = []; let currentKeywordIndex = -1; let currentProductIndex = -1; let currentProductData = null;
document.addEventListener('DOMContentLoaded', function() { updateUI(); handleScrollToTop(); });

// 🔝 상단으로 이동 버튼 관련 함수
function handleScrollToTop() {
    const scrollBtn = document.getElementById('scrollToTop');
    
    window.addEventListener('scroll', function() {
        if (window.pageYOffset > 300) {
            scrollBtn.classList.add('show');
        } else {
            scrollBtn.classList.remove('show');
        }
    });
}

function scrollToTop() {
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

function formatPrice(price) { if (!price) return price; return price.replace(/₩(\d)/, '₩ $1'); }

function showDetailedError(title, message, debugData = null) {
    const existingModal = document.getElementById('errorModal');
    if (existingModal) { existingModal.remove(); }
    let fullMessage = message;
    if (debugData) { fullMessage += '\n\n=== 디버그 정보 ===\n'; fullMessage += JSON.stringify(debugData, null, 2); }
    const modal = document.createElement('div'); modal.id = 'errorModal';
    modal.style.cssText = `position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 10000; display: flex; align-items: center; justify-content: center;`;
    modal.innerHTML = `<div style="background: white; border-radius: 10px; padding: 30px; max-width: 800px; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3);"><h2 style="color: #dc3545; margin-bottom: 20px; font-size: 24px;">🚨 ${title}</h2><div style="margin-bottom: 20px;"><textarea id="errorContent" readonly style="width: 100%; height: 300px; padding: 15px; border: 1px solid #ddd; border-radius: 6px; font-family: 'Courier New', monospace; font-size: 12px; line-height: 1.4; background: #f8f9fa; resize: vertical;">${fullMessage}</textarea></div><div style="display: flex; gap: 10px; justify-content: flex-end;"><button onclick="copyErrorToClipboard()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">📋 복사하기</button><button onclick="closeErrorModal()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px;">닫기</button></div></div>`;
    document.body.appendChild(modal);
    modal.addEventListener('click', function(e) { if (e.target === modal) { closeErrorModal(); } });
}

function copyErrorToClipboard() {
    const errorContent = document.getElementById('errorContent'); errorContent.select(); document.execCommand('copy');
    const copyBtn = event.target; const originalText = copyBtn.textContent; copyBtn.textContent = '✅ 복사됨!'; copyBtn.style.background = '#28a745';
    setTimeout(() => { copyBtn.textContent = originalText; copyBtn.style.background = '#007bff'; }, 2000);
}

function closeErrorModal() { const modal = document.getElementById('errorModal'); if (modal) { modal.remove(); } }
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { closeErrorModal(); } });

function toggleTitleGenerator() { const generator = document.getElementById('titleGenerator'); generator.style.display = generator.style.display === 'none' ? 'block' : 'none'; }

async function generateTitles() {
    const keywordsInput = document.getElementById('titleKeyword').value.trim();
    if (!keywordsInput) { showDetailedError('입력 오류', '키워드를 입력해주세요.'); return; }
    const loading = document.getElementById('titleLoading'); const titlesDiv = document.getElementById('generatedTitles');
    loading.style.display = 'block'; titlesDiv.style.display = 'none';
    try {
        const formData = new FormData(); formData.append('action', 'generate_titles'); formData.append('keywords', keywordsInput);
        const response = await fetch('', { method: 'POST', body: formData }); const result = await response.json();
        if (result.success) { displayTitles(result.titles); } else { showDetailedError('제목 생성 실패', result.message); }
    } catch (error) { showDetailedError('제목 생성 오류', '제목 생성 중 오류가 발생했습니다.', { 'error': error.message, 'keywords': keywordsInput }); }
    finally { loading.style.display = 'none'; }
}

function displayTitles(titles) {
    const optionsDiv = document.getElementById('titleOptions'); const titlesDiv = document.getElementById('generatedTitles');
    optionsDiv.innerHTML = ''; titles.forEach((title) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'title-option'; button.textContent = title; button.onclick = () => selectTitle(title); optionsDiv.appendChild(button); });
    titlesDiv.style.display = 'block';
}

function selectTitle(title) { document.getElementById('title').value = title; document.getElementById('titleGenerator').style.display = 'none'; }

function toggleKeywordInput() {
    const inputSection = document.getElementById('keywordInputSection'); const isVisible = inputSection.classList.contains('show');
    if (isVisible) { inputSection.classList.remove('show'); } else { inputSection.classList.add('show'); document.getElementById('newKeywordInput').focus(); }
}

function addKeywordFromInput() {
    const input = document.getElementById('newKeywordInput'); const name = input.value.trim();
    if (name) { const keyword = { name: name, products: [] }; keywords.push(keyword); updateUI(); input.value = ''; document.getElementById('keywordInputSection').classList.remove('show'); addProduct(keywords.length - 1); } else { showDetailedError('입력 오류', '키워드를 입력해주세요.'); }
}

function cancelKeywordInput() { const input = document.getElementById('newKeywordInput'); input.value = ''; document.getElementById('keywordInputSection').classList.remove('show'); }

document.addEventListener('DOMContentLoaded', function() { document.getElementById('newKeywordInput').addEventListener('keypress', function(e) { if (e.key === 'Enter') { e.preventDefault(); addKeywordFromInput(); } }); });

function addKeyword() { toggleKeywordInput(); }

function addProduct(keywordIndex) {
    const product = { id: Date.now() + Math.random(), url: '', name: `상품 ${keywords[keywordIndex].products.length + 1}`, status: 'empty', analysisData: null, userData: {}, isSaved: false, generatedHtml: null }; // 🔧 generatedHtml 속성 추가
    keywords[keywordIndex].products.push(product); updateUI(); selectProduct(keywordIndex, keywords[keywordIndex].products.length - 1);
}

// 🚀 새로 추가된 키워드 삭제 함수
function deleteKeyword(keywordIndex) {
    if (confirm(`"${keywords[keywordIndex].name}" 키워드를 삭제하시겠습니까?\n이 키워드에 포함된 모든 상품도 함께 삭제됩니다.`)) {
        // 현재 선택된 키워드가 삭제되는 경우 초기화
        if (currentKeywordIndex === keywordIndex) {
            currentKeywordIndex = -1;
            currentProductIndex = -1;
            document.getElementById('topNavigation').style.display = 'none';
            document.getElementById('productDetailContent').style.display = 'none';
            document.getElementById('currentProductTitle').textContent = '상품을 선택해주세요';
            document.getElementById('currentProductSubtitle').textContent = '왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.';
        } else if (currentKeywordIndex > keywordIndex) {
            // 삭제된 키워드보다 뒤에 있는 키워드가 선택된 경우 인덱스 조정
            currentKeywordIndex--;
        }
        
        keywords.splice(keywordIndex, 1);
        updateUI();
    }
}

// 🚀 새로 추가된 상품 삭제 함수
function deleteProduct(keywordIndex, productIndex) {
    const product = keywords[keywordIndex].products[productIndex];
    if (confirm(`"${product.name}" 상품을 삭제하시겠습니까?`)) {
        // 현재 선택된 상품이 삭제되는 경우 초기화
        if (currentKeywordIndex === keywordIndex && currentProductIndex === productIndex) {
            currentKeywordIndex = -1;
            currentProductIndex = -1;
            document.getElementById('topNavigation').style.display = 'none';
            document.getElementById('productDetailContent').style.display = 'none';
            document.getElementById('currentProductTitle').textContent = '상품을 선택해주세요';
            document.getElementById('currentProductSubtitle').textContent = '왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.';
        } else if (currentKeywordIndex === keywordIndex && currentProductIndex > productIndex) {
            // 같은 키워드의 뒤에 있는 상품이 선택된 경우 인덱스 조정
            currentProductIndex--;
        }
        
        keywords[keywordIndex].products.splice(productIndex, 1);
        updateUI();
    }
}

// 🔧 새로운 사용자 입력 필드 초기화 함수
function clearUserInputFields() {
    // 기능 및 스펙 초기화
    document.getElementById('main_function').value = '';
    document.getElementById('size_capacity').value = '';
    document.getElementById('color').value = '';
    document.getElementById('material').value = '';
    document.getElementById('power_battery').value = '';
    
    // 효율성 분석 초기화
    document.getElementById('problem_solving').value = '';
    document.getElementById('time_saving').value = '';
    document.getElementById('space_efficiency').value = '';
    document.getElementById('cost_saving').value = '';
    
    // 사용 시나리오 초기화
    document.getElementById('usage_location').value = '';
    document.getElementById('usage_frequency').value = '';
    document.getElementById('target_users').value = '';
    document.getElementById('usage_method').value = '';
    
    // 장점 및 주의사항 초기화
    document.getElementById('advantage1').value = '';
    document.getElementById('advantage2').value = '';
    document.getElementById('advantage3').value = '';
    document.getElementById('precautions').value = '';
}

// 🔧 저장된 사용자 입력 필드 로드 함수
function loadUserInputFields(userData) {
    if (!userData) return;
    
    // 기능 및 스펙 로드
    if (userData.specs) {
        document.getElementById('main_function').value = userData.specs.main_function || '';
        document.getElementById('size_capacity').value = userData.specs.size_capacity || '';
        document.getElementById('color').value = userData.specs.color || '';
        document.getElementById('material').value = userData.specs.material || '';
        document.getElementById('power_battery').value = userData.specs.power_battery || '';
    }
    
    // 효율성 분석 로드
    if (userData.efficiency) {
        document.getElementById('problem_solving').value = userData.efficiency.problem_solving || '';
        document.getElementById('time_saving').value = userData.efficiency.time_saving || '';
        document.getElementById('space_efficiency').value = userData.efficiency.space_efficiency || '';
        document.getElementById('cost_saving').value = userData.efficiency.cost_saving || '';
    }
    
    // 사용 시나리오 로드
    if (userData.usage) {
        document.getElementById('usage_location').value = userData.usage.usage_location || '';
        document.getElementById('usage_frequency').value = userData.usage.usage_frequency || '';
        document.getElementById('target_users').value = userData.usage.target_users || '';
        document.getElementById('usage_method').value = userData.usage.usage_method || '';
    }
    
    // 장점 및 주의사항 로드
    if (userData.benefits) {
        if (userData.benefits.advantages) {
            document.getElementById('advantage1').value = userData.benefits.advantages[0] || '';
            document.getElementById('advantage2').value = userData.benefits.advantages[1] || '';
            document.getElementById('advantage3').value = userData.benefits.advantages[2] || '';
        }
        document.getElementById('precautions').value = userData.benefits.precautions || '';
    }
}

function selectProduct(keywordIndex, productIndex) {
    currentKeywordIndex = keywordIndex; currentProductIndex = productIndex; const product = keywords[keywordIndex].products[productIndex];
    document.querySelectorAll('.product-item').forEach(item => { item.classList.remove('active'); });
    const selectedItem = document.querySelector(`[data-keyword="${keywordIndex}"][data-product="${productIndex}"]`);
    if (selectedItem) { selectedItem.classList.add('active'); } 
    updateDetailPanel(product);
    
    // 🚀 상품 선택 시 상단 네비게이션 표시
    document.getElementById('topNavigation').style.display = 'block';
}

function updateDetailPanel(product) {
    const titleEl = document.getElementById('currentProductTitle'); const subtitleEl = document.getElementById('currentProductSubtitle');
    const contentEl = document.getElementById('productDetailContent'); const urlInput = document.getElementById('productUrl');
    titleEl.textContent = product.name; subtitleEl.textContent = `키워드: ${keywords[currentKeywordIndex].name}`; urlInput.value = product.url || '';
    
    // 🔧 사용자 입력 필드 초기화 또는 기존 데이터 로드
    if (product.userData && Object.keys(product.userData).length > 0) {
        // 기존에 저장된 데이터가 있으면 로드
        loadUserInputFields(product.userData);
    } else {
        // 새 상품이면 모든 필드 초기화
        clearUserInputFields();
    }
    
    if (product.analysisData) { showAnalysisResult(product.analysisData); } else { hideAnalysisResult(); } contentEl.style.display = 'block';
}

async function analyzeProduct() {
    const url = document.getElementById('productUrl').value.trim();
    if (!url) { showDetailedError('입력 오류', '상품 URL을 입력해주세요.'); return; }
    if (currentKeywordIndex === -1 || currentProductIndex === -1) { showDetailedError('선택 오류', '상품을 먼저 선택해주세요.'); return; }
    const product = keywords[currentKeywordIndex].products[currentProductIndex];
    product.url = url; product.status = 'analyzing'; updateUI();
    try {
        const response = await fetch('product_analyzer_v2.php', { method: 'POST', headers: { 'Content-Type': 'application/json', }, body: JSON.stringify({ action: 'analyze_product', url: url, platform: 'aliexpress' }) });
        if (!response.ok) { throw new Error(`HTTP 오류: ${response.status} ${response.statusText}`); }
        const responseText = await response.text(); let result;
        try { result = JSON.parse(responseText); } catch (parseError) { showDetailedError('JSON 파싱 오류', '서버 응답을 파싱할 수 없습니다.', { 'parseError': parseError.message, 'responseText': responseText, 'responseLength': responseText.length, 'url': url, 'timestamp': new Date().toISOString() }); product.status = 'error'; updateUI(); return; }
        if (result.success) { 
            product.analysisData = result.data; 
            product.status = 'completed'; 
            product.name = result.data.title || `상품 ${currentProductIndex + 1}`; 
            currentProductData = result.data; 
            showAnalysisResult(result.data); 
            
            // 🔧 HTML 생성 및 저장
            const generatedHtml = generateOptimizedMobileHtml(result.data);
            product.generatedHtml = generatedHtml;
            console.log('Generated HTML saved to product:', generatedHtml);
        } else { 
            product.status = 'error'; 
            showDetailedError('상품 분석 실패', result.message || '알 수 없는 오류가 발생했습니다.', { 'success': result.success, 'message': result.message, 'debug_info': result.debug_info || null, 'raw_output': result.raw_output || null, 'url': url, 'platform': 'aliexpress', 'timestamp': new Date().toISOString(), 'mobile_optimized': true }); 
        }
    } catch (error) { product.status = 'error'; showDetailedError('네트워크 오류', '상품 분석 중 네트워크 오류가 발생했습니다.', { 'error': error.message, 'stack': error.stack, 'url': url, 'timestamp': new Date().toISOString() }); }
    updateUI();
}

function showAnalysisResult(data) {
    const resultEl = document.getElementById('analysisResult'); const cardEl = document.getElementById('productCard');
    const ratingDisplay = data.rating_display ? data.rating_display.replace(/⭐/g, '').replace(/[()]/g, '').trim() : '정보 없음';
    const formattedPrice = formatPrice(data.price);
    cardEl.innerHTML = `<div class="product-content-split"><div class="product-image-large"><img src="${data.image_url}" alt="${data.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><div class="aliexpress-logo-right"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" /></div><h3 class="product-title-right">${data.title}</h3><div class="product-price-right">${formattedPrice}</div><div class="product-rating-right"><span class="rating-stars">⭐⭐⭐⭐⭐</span><span>(고객만족도: ${ratingDisplay})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}</div><div class="product-extra-info-right"><div class="info-row"><span class="info-label">상품 ID</span><span class="info-value">${data.product_id}</span></div><div class="info-row"><span class="info-label">플랫폼</span><span class="info-value">${data.platform}</span></div></div></div></div><div class="purchase-button-full"><a href="${data.affiliate_link}" target="_blank" rel="nofollow"><picture><source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></picture></a></div>`;
    resultEl.classList.add('show');
}

// 🔧 수정된 HTML 생성 함수 - HTML 반환하도록 변경
function generateOptimizedMobileHtml(data) {
    if (!data) return null;
    const ratingDisplay = data.rating_display ? data.rating_display.replace(/⭐/g, '').replace(/[()]/g, '').trim() : '정보 없음';
    const formattedPrice = formatPrice(data.price);
    const htmlCode = `<div style="display: flex; justify-content: center; margin: 25px 0;">
    <div style="border: 2px solid #eee; padding: 30px; border-radius: 15px; background: #f9f9f9; box-shadow: 0 4px 8px rgba(0,0,0,0.1); max-width: 1000px; width: 100%;">
        
        <div style="display: grid; grid-template-columns: 400px 1fr; gap: 30px; align-items: start; margin-bottom: 25px;">
            <div style="text-align: center;">
                <img src="${data.image_url}" alt="${data.title}" style="width: 100%; max-width: 400px; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.15);">
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div style="margin-bottom: 15px; text-align: center;">
                    <img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" style="width: 250px; height: 60px; object-fit: contain;" />
                </div>
                
                <h3 style="color: #1c1c1c; margin: 0 0 20px 0; font-size: 21px; font-weight: 600; line-height: 1.4; word-break: keep-all; overflow-wrap: break-word; text-align: center;">${data.title}</h3>
                
                <div style="background: linear-gradient(135deg, #e62e04 0%, #ff9900 100%); color: white; padding: 14px 30px; border-radius: 10px; font-size: 40px; font-weight: 700; text-align: center; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(230, 46, 4, 0.3);">
                    <strong>${formattedPrice}</strong>
                </div>
                
                <div style="color: #1c1c1c; font-size: 20px; display: flex; align-items: center; gap: 10px; margin-bottom: 15px; justify-content: center; flex-wrap: nowrap;">
                    <span style="color: #ff9900;">⭐⭐⭐⭐⭐</span>
                    <span>(고객만족도: ${ratingDisplay})</span>
                </div>
                
                <p style="color: #1c1c1c; font-size: 18px; margin: 0 0 15px 0; text-align: center;"><strong>📦 판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}</p>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 30px; width: 100%;">
            <a href="${data.affiliate_link}" target="_blank" rel="nofollow" style="text-decoration: none;">
                <picture>
                    <source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png">
                    <img src="https://novacents.com/tools/images/aliexpress-button-pc.png" 
                         alt="알리익스프레스에서 구매하기" 
                         style="max-width: 100%; height: auto; cursor: pointer;">
                </picture>
            </a>
        </div>
    </div>
</div>

<style>
@media (max-width: 1600px) {
    div[style*="grid-template-columns: 400px 1fr"] {
        display: block !important;
        grid-template-columns: none !important;
        gap: 15px !important;
    }
    
    img[style*="max-width: 400px"] {
        width: 95% !important;
        max-width: none !important;
        margin-bottom: 30px !important;
    }
    
    div[style*="gap: 20px"] {
        gap: 10px !important;
    }
    
    div[style*="text-align: center"] img[alt="AliExpress"] {
        display: block;
        margin: 0 !important;
    }
    div[style*="text-align: center"]:has(img[alt="AliExpress"]) {
        text-align: left !important;
        margin-bottom: 10px !important;
    }
    
    h3[style*="text-align: center"] {
        text-align: left !important;
        font-size: 18px !important;
        margin-bottom: 10px !important;
    }
    
    div[style*="font-size: 40px"] {
        font-size: 28px !important;
        padding: 12px 20px !important;
        margin-bottom: 10px !important;
    }
    
    div[style*="justify-content: center"][style*="flex-wrap: nowrap"] {
        justify-content: flex-start !important;
        font-size: 16px !important;
        margin-bottom: 10px !important;
        gap: 8px !important;
    }
    
    p[style*="text-align: center"] {
        text-align: left !important;
        font-size: 16px !important;
        margin-bottom: 10px !important;
    }
    
    div[style*="margin-top: 30px"] {
        margin-top: 15px !important;
    }
}

@media (max-width: 480px) {
    img[style*="width: 95%"] {
        width: 100% !important;
    }
    
    h3[style*="font-size: 18px"] {
        font-size: 16px !important;
    }
    
    div[style*="font-size: 28px"] {
        font-size: 24px !important;
    }
}
</style>`;
    
    const previewHtml = `<div class="preview-product-card"><div class="preview-card-content"><div class="product-content-split"><div class="product-image-large"><img src="${data.image_url}" alt="${data.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><div class="aliexpress-logo-right"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" /></div><h3 class="product-title-right">${data.title}</h3><div class="product-price-right">${formattedPrice}</div><div class="product-rating-right"><span class="rating-stars">⭐⭐⭐⭐⭐</span><span>(고객만족도: ${ratingDisplay})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${data.lastest_volume || '판매량 정보 없음'}</div></div></div><div class="purchase-button-full"><a href="${data.affiliate_link}" target="_blank" rel="nofollow"><picture><source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></picture></a></div></div></div>`;
    document.getElementById('htmlPreview').innerHTML = previewHtml; 
    document.getElementById('htmlCode').textContent = htmlCode; 
    document.getElementById('htmlSourceSection').style.display = 'block';
    
    // 🔧 생성된 HTML 반환
    return htmlCode;
}

async function copyHtmlSource() {
    const htmlCode = document.getElementById('htmlCode').textContent; const copyBtn = document.querySelector('.copy-btn');
    try { await navigator.clipboard.writeText(htmlCode); const originalText = copyBtn.textContent; copyBtn.textContent = '✅ 복사됨!'; copyBtn.classList.add('copied'); setTimeout(() => { copyBtn.textContent = originalText; copyBtn.classList.remove('copied'); }, 2000); } catch (error) { const codeEl = document.getElementById('htmlCode'); const range = document.createRange(); range.selectNodeContents(codeEl); const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range); showDetailedError('복사 알림', 'HTML 소스가 선택되었습니다. Ctrl+C로 복사하세요.'); }
}

function hideAnalysisResult() { const resultEl = document.getElementById('analysisResult'); resultEl.classList.remove('show'); document.getElementById('htmlSourceSection').style.display = 'none'; }

function updateUI() { updateProductsList(); updateProgress(); }

function updateProductsList() {
    const listEl = document.getElementById('productsList');
    if (keywords.length === 0) { listEl.innerHTML = `<div class="empty-state"><h3>📦 상품이 없습니다</h3><p>위의 "키워드 추가" 버튼을 클릭하여<br>첫 번째 키워드를 추가해보세요!</p></div>`; return; }
    let html = ''; 
    keywords.forEach((keyword, keywordIndex) => { 
        html += `<div class="keyword-group">
            <div class="keyword-header">
                <div class="keyword-info">
                    <span class="keyword-name">📁 ${keyword.name}</span>
                    <span class="product-count">${keyword.products.length}개</span>
                </div>
                <div class="keyword-actions">
                    <button type="button" class="btn btn-success btn-small" onclick="addProduct(${keywordIndex})">+상품</button>
                    <button type="button" class="btn btn-danger btn-small" onclick="deleteKeyword(${keywordIndex})">🗑️</button>
                </div>
            </div>`; 
        keyword.products.forEach((product, productIndex) => { 
            const statusIcon = getStatusIcon(product.status, product.isSaved); 
            html += `<div class="product-item" data-keyword="${keywordIndex}" data-product="${productIndex}" onclick="selectProduct(${keywordIndex}, ${productIndex})">
                <span class="product-status">${statusIcon}</span>
                <span class="product-name">${product.name}</span>
                <div class="product-actions">
                    <button type="button" class="btn btn-danger btn-small" onclick="event.stopPropagation(); deleteProduct(${keywordIndex}, ${productIndex})">🗑️</button>
                </div>
            </div>`; 
        }); 
        html += '</div>'; 
    }); 
    listEl.innerHTML = html;
}

// 🔧 수정된 상태 아이콘 함수 - 저장 여부까지 고려
function getStatusIcon(status, isSaved = false) { 
    switch (status) { 
        case 'completed': 
            return isSaved ? '✅' : '🔍'; // 분석 완료 + 저장됨 = ✅, 분석만 완료 = 🔍
        case 'analyzing': 
            return '🔄'; 
        case 'error': 
            return '⚠️'; 
        default: 
            return '❌'; 
    } 
}

// 🔧 수정된 진행률 계산 함수 - 저장된 상품만 완료로 인정
function updateProgress() {
    const totalProducts = keywords.reduce((sum, keyword) => sum + keyword.products.length, 0);
    const completedProducts = keywords.reduce((sum, keyword) => sum + keyword.products.filter(p => p.isSaved).length, 0); // 🔧 isSaved가 true인 상품만 완료로 인정
    const percentage = totalProducts > 0 ? (completedProducts / totalProducts) * 100 : 0;
    document.getElementById('progressFill').style.width = percentage + '%'; document.getElementById('progressText').textContent = `${completedProducts}/${totalProducts} 완성`;
}

// 🚀 새로 추가된 사용자 상세 정보 수집 함수들
function collectUserInputDetails() {
    const details = {};
    
    // 기능 및 스펙
    const specs = {};
    addIfNotEmpty(specs, 'main_function', 'main_function');
    addIfNotEmpty(specs, 'size_capacity', 'size_capacity');
    addIfNotEmpty(specs, 'color', 'color');
    addIfNotEmpty(specs, 'material', 'material');
    addIfNotEmpty(specs, 'power_battery', 'power_battery');
    if (Object.keys(specs).length > 0) details.specs = specs;
    
    // 효율성 분석
    const efficiency = {};
    addIfNotEmpty(efficiency, 'problem_solving', 'problem_solving');
    addIfNotEmpty(efficiency, 'time_saving', 'time_saving');
    addIfNotEmpty(efficiency, 'space_efficiency', 'space_efficiency');
    addIfNotEmpty(efficiency, 'cost_saving', 'cost_saving');
    if (Object.keys(efficiency).length > 0) details.efficiency = efficiency;
    
    // 사용 시나리오
    const usage = {};
    addIfNotEmpty(usage, 'usage_location', 'usage_location');
    addIfNotEmpty(usage, 'usage_frequency', 'usage_frequency');
    addIfNotEmpty(usage, 'target_users', 'target_users');
    addIfNotEmpty(usage, 'usage_method', 'usage_method');
    if (Object.keys(usage).length > 0) details.usage = usage;
    
    // 장점 및 주의사항
    const benefits = {};
    const advantages = [];
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

// 🔧 강화된 키워드 데이터 수집 함수 - 분석 데이터와 HTML도 포함
function collectKeywordsData() {
    console.log('collectKeywordsData: Starting comprehensive keyword data collection...');
    const keywordsData = [];
    
    keywords.forEach((keyword, keywordIndex) => {
        console.log(`Processing keyword ${keywordIndex}: ${keyword.name}`);
        
        // keyword_processor.php가 기대하는 형식으로 변경
        const keywordData = {
            name: keyword.name,
            coupang: [], // 쿠팡 링크 배열 (현재는 사용하지 않음)
            aliexpress: [], // 알리익스프레스 링크 배열
            products_data: [] // 🔧 새로 추가: 상품 분석 데이터와 HTML 정보
        };
        
        // 각 키워드의 상품 URL들과 분석 데이터 수집
        keyword.products.forEach((product, productIndex) => {
            console.log(`  Checking product ${productIndex}: "${product.url}"`);
            
            // 🔧 더 엄격한 URL 유효성 검사
            if (product.url && 
                typeof product.url === 'string' && 
                product.url.trim() !== '' && 
                product.url.trim() !== 'undefined' && 
                product.url.trim() !== 'null' &&
                product.url.includes('aliexpress.com')) {
                
                const trimmedUrl = product.url.trim();
                console.log(`    Valid URL found: ${trimmedUrl}`);
                keywordData.aliexpress.push(trimmedUrl);
                
                // 🔧 상품 분석 데이터와 HTML 소스도 함께 수집
                const productData = {
                    url: trimmedUrl,
                    analysis_data: product.analysisData || null,
                    generated_html: product.generatedHtml || null,
                    user_data: product.userData || {}
                };
                
                keywordData.products_data.push(productData);
                console.log(`    Product data collected:`, {
                    hasAnalysisData: !!product.analysisData,
                    hasGeneratedHtml: !!product.generatedHtml,
                    hasUserData: !!(product.userData && Object.keys(product.userData).length > 0)
                });
            } else {
                console.log(`    Invalid or empty URL skipped: "${product.url}"`);
            }
        });
        
        // 유효한 링크가 있는 키워드만 추가
        if (keywordData.aliexpress.length > 0) {
            console.log(`  Keyword "${keyword.name}" added with ${keywordData.aliexpress.length} valid links and ${keywordData.products_data.length} product data entries`);
            keywordsData.push(keywordData);
        } else {
            console.log(`  Keyword "${keyword.name}" skipped - no valid links`);
        }
    });
    
    console.log('Final comprehensive keywords data:', keywordsData);
    console.log('Total keywords with valid data:', keywordsData.length);
    
    return keywordsData;
}

function validateAndSubmitData(formData, isPublishNow = false) {
    console.log('validateAndSubmitData: Starting validation...');
    console.log('Form data:', formData);
    
    // 기본 검증
    if (!formData.title || formData.title.length < 5) {
        showDetailedError('입력 오류', '제목은 5자 이상이어야 합니다.');
        return false;
    }
    
    if (!formData.keywords || formData.keywords.length === 0) {
        showDetailedError('입력 오류', '최소 하나의 키워드와 상품 링크가 필요합니다.');
        return false;
    }
    
    // 각 키워드에 유효한 링크가 있는지 확인
    let hasValidLinks = false;
    let totalValidLinks = 0;
    let totalProductsWithData = 0;
    
    formData.keywords.forEach(keyword => {
        if (keyword.aliexpress && keyword.aliexpress.length > 0) {
            // 빈 문자열이 아닌 실제 URL만 카운트
            const validUrls = keyword.aliexpress.filter(url => 
                url && 
                typeof url === 'string' && 
                url.trim() !== '' && 
                url.includes('aliexpress.com')
            );
            
            if (validUrls.length > 0) {
                hasValidLinks = true;
                totalValidLinks += validUrls.length;
                totalProductsWithData += keyword.products_data ? keyword.products_data.length : 0;
                console.log(`Keyword "${keyword.name}" has ${validUrls.length} valid URLs and ${keyword.products_data ? keyword.products_data.length : 0} product data entries`);
            }
        }
    });
    
    if (!hasValidLinks || totalValidLinks === 0) {
        showDetailedError('입력 오류', '각 키워드에 최소 하나의 유효한 알리익스프레스 상품 링크가 있어야 합니다.\n\n현재 상태:\n- URL을 입력했는지 확인하세요\n- 분석 버튼을 클릭했는지 확인하세요\n- 알리익스프레스 URL인지 확인하세요');
        return false;
    }
    
    console.log(`Validation passed! Total valid links: ${totalValidLinks}, Products with data: ${totalProductsWithData}`);
    
    if (isPublishNow) {
        // 즉시 발행용 AJAX 전송
        return true;
    } else {
        // 폼 데이터를 hidden input으로 설정하여 전송
        const form = document.getElementById('affiliateForm');
        
        // 기존 hidden input 제거
        const existingInputs = form.querySelectorAll('input[type="hidden"]');
        existingInputs.forEach(input => input.remove());
        
        // 새로운 hidden input들 추가
        const hiddenInputs = [
            { name: 'title', value: formData.title },
            { name: 'category', value: formData.category },
            { name: 'prompt_type', value: formData.prompt_type },
            { name: 'keywords', value: JSON.stringify(formData.keywords) },
            { name: 'user_details', value: JSON.stringify(formData.user_details) }
        ];
        
        hiddenInputs.forEach(({ name, value }) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });
        
        console.log('Hidden inputs added, submitting form...');
        
        // 폼 전송
        form.submit();
        return true;
    }
}

// 🔧 수정된 버튼 기능들

// 🚀 새로운 즉시 발행 기능
async function publishNow() {
    console.log('🚀 즉시 발행을 시작합니다...');
    
    // 1. 기존 키워드 데이터 수집 (분석 데이터와 HTML 포함)
    const keywordsData = collectKeywordsData();
    
    // 2. 사용자 입력 상세 정보 수집 (빈 값 제외)
    const userDetails = collectUserInputDetails();
    
    // 3. 기본 정보 수집 (프롬프트 타입 추가)
    const formData = {
        title: document.getElementById('title').value.trim(),
        category: document.getElementById('category').value,
        prompt_type: document.getElementById('prompt_type').value,
        keywords: keywordsData,
        user_details: userDetails
    };
    
    console.log('즉시 발행용 종합 데이터:', formData);
    
    // 4. 검증
    if (!validateAndSubmitData(formData, true)) {
        return;
    }
    
    // 5. 로딩 표시
    const loadingOverlay = document.getElementById('loadingOverlay');
    const publishBtn = document.getElementById('publishNowBtn');
    
    loadingOverlay.style.display = 'flex';
    publishBtn.disabled = true;
    publishBtn.textContent = '발행 중...';
    
    try {
        // 6. 즉시 발행 요청 - keyword_processor.php로 직접 전송
        const response = await fetch('keyword_processor.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams({
                title: formData.title,
                category: formData.category,
                prompt_type: formData.prompt_type,
                keywords: JSON.stringify(formData.keywords),
                user_details: JSON.stringify(formData.user_details),
                publish_mode: 'immediate'
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✅ 글 발행이 완료되었습니다!');
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
        // 7. 로딩 해제
        loadingOverlay.style.display = 'none';
        publishBtn.disabled = false;
        publishBtn.textContent = '🚀 즉시 발행';
    }
}

// 🔧 수정된 저장 기능 (현재 상품만 저장) - isSaved 플래그 추가
function saveCurrentProduct() {
    if (currentKeywordIndex === -1 || currentProductIndex === -1) {
        showDetailedError('선택 오류', '저장할 상품을 먼저 선택해주세요.');
        return;
    }
    
    const product = keywords[currentKeywordIndex].products[currentProductIndex];
    
    // 현재 상품의 URL 업데이트
    const url = document.getElementById('productUrl').value.trim();
    if (url) {
        product.url = url;
    }
    
    // 사용자 입력 상세 정보 수집하여 개별 상품에 저장
    const userDetails = collectUserInputDetails();
    product.userData = userDetails;
    
    // 🔧 저장 완료 플래그 설정
    product.isSaved = true;
    
    // UI 업데이트
    updateUI();
    
    alert('💾 현재 상품 정보가 저장되었습니다!');
    console.log('저장된 상품 정보:', product);
}

// 🔧 수정된 완료 기능 (전체 데이터를 대기열에 저장) - 분석 데이터와 HTML 포함
function completeProduct() {
    console.log('✅ 전체 데이터를 대기열에 저장합니다...');
    
    // 1. 기존 키워드 데이터 수집 (분석 데이터와 HTML 포함)
    const keywordsData = collectKeywordsData();
    
    // 2. 사용자 입력 상세 정보 수집 (빈 값 제외)
    const userDetails = collectUserInputDetails();
    
    console.log('수집된 사용자 상세 정보:', userDetails);
    
    // 3. 기본 정보 수집 (프롬프트 타입 추가)
    const formData = {
        title: document.getElementById('title').value.trim(),
        category: document.getElementById('category').value,
        prompt_type: document.getElementById('prompt_type').value,
        keywords: keywordsData,
        user_details: userDetails // 새로 추가되는 사용자 상세 정보
    };
    
    console.log('전체 수집된 종합 데이터:', formData);
    
    // 4. 검증 및 전송
    if (validateAndSubmitData(formData)) {
        // validateAndSubmitData 내부에서 폼 전송이 이루어짐
        console.log('대기열 저장 요청이 전송되었습니다.');
    }
}

function previousProduct() {
    if (currentKeywordIndex === -1 || currentProductIndex === -1) return;
    
    const currentKeyword = keywords[currentKeywordIndex];
    if (currentProductIndex > 0) {
        selectProduct(currentKeywordIndex, currentProductIndex - 1);
    } else if (currentKeywordIndex > 0) {
        const prevKeyword = keywords[currentKeywordIndex - 1];
        selectProduct(currentKeywordIndex - 1, prevKeyword.products.length - 1);
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

document.getElementById('titleKeyword').addEventListener('keypress', function(e) { if (e.key === 'Enter') { e.preventDefault(); generateTitles(); } });
</script>
</body>
</html>