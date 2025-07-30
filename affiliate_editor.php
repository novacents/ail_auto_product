<?php
/**
 * 어필리에이트 상품 등록 자동화 입력 페이지 - 압축 최적화 버전
 * 노바센트(novacents.com) 전용
 */
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
if(!current_user_can('manage_options'))wp_die('접근 권한이 없습니다.');
$env_file='/home/novacents/.env';$env_vars=[];
if(file_exists($env_file)){$lines=file($env_file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);foreach($lines as $line){if(strpos($line,'=')!==false&&strpos($line,'#')!==0){list($key,$value)=explode('=',$line,2);$env_vars[trim($key)]=trim($value);}}}
if(isset($_POST['action'])&&$_POST['action']==='generate_titles'){
header('Content-Type: application/json');
$keywords_input=sanitize_text_field($_POST['keywords']);
if(empty($keywords_input)){echo json_encode(['success'=>false,'message'=>'키워드를 입력해주세요.']);exit;}
$keywords=array_map('trim',explode(',',$keywords_input));$keywords=array_filter($keywords);
if(empty($keywords)){echo json_encode(['success'=>false,'message'=>'유효한 키워드를 입력해주세요.']);exit;}
$combined_keywords=implode(',',$keywords);
$script_locations=[__DIR__.'/title_generator.py','/home/novacents/title_generator.py'];
$output=null;$found_script=false;
foreach($script_locations as $script_path){
if(file_exists($script_path)){
$script_dir=dirname($script_path);
$command="LANG=ko_KR.UTF-8 /usr/bin/env /usr/bin/python3 ".escapeshellarg($script_path)." ".escapeshellarg($combined_keywords)." 2>&1";
$descriptorspec=[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]];
$process=proc_open($command,$descriptorspec,$pipes,$script_dir,null);
if(is_resource($process)){
fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error_output=stream_get_contents($pipes[2]);
fclose($pipes[1]);fclose($pipes[2]);$return_code=proc_close($process);
if($return_code===0&&!empty($output)){$found_script=true;break;}}}}
if(!$found_script){echo json_encode(['success'=>false,'message'=>'Python 스크립트를 찾을 수 없거나 실행에 실패했습니다.']);exit;}
$result=json_decode(trim($output),true);
if($result===null){echo json_encode(['success'=>false,'message'=>'Python 스크립트 응답 파싱 실패.','raw_output'=>$output]);exit;}
echo json_encode($result);exit;}
$success_message='';$error_message='';
if(isset($_GET['success'])&&$_GET['success']=='1')$success_message='글이 성공적으로 발행 대기열에 추가되었습니다!';
if(isset($_GET['error']))$error_message='오류: '.urldecode($_GET['error']);
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>어필리에이트 상품 등록 - 노바센트</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;padding:20px;background:#f5f5f5;min-width:1200px;color:#1c1c1c}
.main-container{width:1800px;margin:0 auto;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);overflow:hidden}
.header-section{padding:30px;border-bottom:1px solid #e0e0e0;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white}
.header-section h1{margin:0 0 10px 0;font-size:28px}
.header-section .subtitle{margin:0 0 20px 0;opacity:0.9}
.header-form{display:grid;grid-template-columns:1fr;gap:15px;margin-top:20px}
.input-row{display:grid;grid-template-columns:1fr 1fr;gap:15px;align-items:end}
.form-group{display:flex;flex-direction:column}
.form-group label{color:rgba(255,255,255,0.9);margin-bottom:8px;display:block;font-size:14px}
.input-with-button{display:flex;gap:10px;align-items:flex-end}
.input-with-button input{flex:1;padding:12px;border:1px solid rgba(255,255,255,0.3);border-radius:6px;background:rgba(255,255,255,0.1);color:white;font-size:16px}
.input-with-button input::placeholder{color:rgba(255,255,255,0.7)}
.input-with-button select{flex:1;padding:12px;border:1px solid rgba(255,255,255,0.3);border-radius:6px;background:rgba(255,255,255,0.1);color:white;font-size:16px}
.input-with-button select option{background:#333;color:white}
.nav-links{display:flex;gap:10px;margin-top:15px;align-items:center}
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
.keyword-group{border-bottom:1px solid #e0e0e0;position:relative}
.keyword-group.draggable{cursor:move}
.keyword-group.dragging{opacity:0.5;transform:rotate(2deg);box-shadow:0 8px 16px rgba(0,0,0,0.2)}
.keyword-group.drag-over{border:2px dashed #007bff;background:#f0f8ff}
.keyword-header{padding:15px 15px;background:white;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;cursor:pointer;position:relative}
.keyword-header:hover{background:#f8f9fa}
.keyword-header-left{display:flex;align-items:center;gap:10px}
.keyword-order-controls{display:flex;flex-direction:column;gap:2px;margin-right:10px}
.keyword-order-btn{background:#007bff;color:white;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;line-height:1;transition:all 0.2s;font-weight:600}
.keyword-order-btn:hover{background:#0056b3;transform:translateY(-1px)}
.keyword-order-btn:disabled{background:#ccc;cursor:not-allowed;transform:none}
.keyword-info{display:flex;align-items:center;gap:10px}
.keyword-name{font-weight:600;color:#333}
.product-count{background:#007bff;color:white;padding:2px 8px;border-radius:12px;font-size:12px}
.keyword-actions{display:flex;gap:8px;align-items:center}
.product-item{padding:12px 15px;border-bottom:1px solid #f5f5f5;display:flex;align-items:center;gap:10px;cursor:pointer;transition:background 0.2s;position:relative}
.product-item.draggable{cursor:move}
.product-item.dragging{opacity:0.5;transform:rotate(1deg);box-shadow:0 4px 8px rgba(0,0,0,0.2);background:#e3f2fd}
.product-item.drag-over{border:2px dashed #28a745;background:#f0fff0}
.product-item:hover{background:#f0f8ff}
.product-item.active{background:#e3f2fd;border-left:4px solid #2196F3}
.product-order-controls{display:flex;flex-direction:column;gap:2px;margin-right:10px}
.product-order-btn{background:#28a745;color:white;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;font-size:11px;line-height:1;transition:all 0.2s;font-weight:600}
.product-order-btn:hover{background:#1e7e34;transform:translateY(-1px)}
.product-order-btn:disabled{background:#ccc;cursor:not-allowed;transform:none}
.product-status{font-size:18px;width:20px}
.product-name{flex:1;font-size:14px;color:#555}
.product-actions{display:flex;gap:5px;margin-left:auto;align-items:center}
.sidebar-actions{padding:15px 20px;border-bottom:1px solid #e0e0e0;background:white}
.keyword-input-section{margin-top:10px;padding:15px;background:#f8f9fa;border-radius:6px;border:1px solid #e9ecef;display:none}
.keyword-input-section.show{display:block}
.keyword-input-row-inline{display:flex;gap:10px;align-items:center}
.keyword-input-row-inline input{flex:1;padding:8px 12px;border:1px solid #ddd;border-radius:4px;font-size:14px}
.keyword-input-row-inline button{padding:8px 16px;border:none;border-radius:4px;cursor:pointer;font-size:14px;font-weight:600}
.excel-upload-section{margin-top:10px;padding:15px;background:#e8f5e8;border-radius:6px;border:1px solid #c3e6cb;display:none}
.excel-upload-section.show{display:block}
.file-input-wrapper{position:relative;display:inline-block;width:100%;margin-bottom:10px}
.file-input{position:absolute;left:-9999px}
.file-input-label{display:block;width:100%;padding:10px;background:#28a745;color:white;border-radius:4px;text-align:center;cursor:pointer;font-size:14px;font-weight:600;transition:background 0.3s}
.file-input-label:hover{background:#218838}
.file-name{color:#155724;font-size:13px;margin-top:5px;word-break:break-all}
.detail-panel{flex:1;width:1100px;padding:30px;overflow-y:auto}
.detail-header{margin-bottom:20px;padding-bottom:20px;border-bottom:2px solid #f0f0f0}
.top-navigation{margin-bottom:30px;padding:20px;background:#f8f9fa;border-radius:8px;border:1px solid #e9ecef}
.nav-buttons{display:flex;align-items:center;gap:10px}
.nav-buttons-left{display:flex;gap:10px}
.nav-divider{width:2px;height:40px;background:#ddd;margin:0 20px}
.nav-buttons-right{display:flex;gap:15px}
.btn-orange{background:#ff9900;color:white;border:none;padding:12px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.3s;text-decoration:none;display:inline-block}
.btn-orange:hover{background:#e68a00;transform:translateY(-1px)}
.btn-orange:disabled{background:#ccc;cursor:not-allowed;transform:none}
.loading-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:none;align-items:center;justify-content:center}
.loading-content{background:white;border-radius:10px;padding:40px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.3)}
.loading-spinner{display:inline-block;width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #ff9900;border-radius:50%;animation:spin 1s linear infinite;margin-bottom:20px}
.loading-text{font-size:18px;color:#333;font-weight:600}
.scroll-to-top{position:fixed;bottom:30px;right:30px;width:50px;height:50px;background:#667eea;color:white;border:none;border-radius:50%;cursor:pointer;font-size:20px;display:none;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,0.2);transition:all 0.3s;z-index:1000}
.scroll-to-top:hover{background:#764ba2;transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,0.3)}
.scroll-to-top.show{display:flex}
.success-modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10001;display:none;align-items:center;justify-content:center}
.success-modal-content{background:white;border-radius:12px;padding:40px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.3);min-width:400px;max-width:500px}
.success-modal-icon{font-size:60px;margin-bottom:20px}
.success-modal-title{font-size:24px;font-weight:bold;color:#28a745;margin-bottom:15px}
.success-modal-message{font-size:16px;color:#666;margin-bottom:30px;line-height:1.5}
.success-modal-button{background:#28a745;color:white;border:none;padding:12px 30px;border-radius:6px;cursor:pointer;font-size:16px;font-weight:600;transition:all 0.3s}
.success-modal-button:hover{background:#1e7e34}
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
.btn-primary{background:#007bff;color:white}.btn-primary:hover{background:#0056b3}
.btn-secondary{background:#6c757d;color:white}.btn-secondary:hover{background:#545b62}
.btn-success{background:#28a745;color:white}.btn-success:hover{background:#1e7e34}
.btn-danger{background:#dc3545;color:white}.btn-danger:hover{background:#c82333}
.btn-small{padding:6px 12px;font-size:12px}
.btn-large{padding:15px 30px;font-size:16px}
.keyword-generator{margin-top:15px;padding:15px;background:rgba(255,255,255,0.1);border-radius:6px;display:none}
.keyword-input-row{display:flex;gap:10px;margin-bottom:15px}
.keyword-input-row input{flex:1;padding:10px;border:1px solid rgba(255,255,255,0.3);border-radius:4px;background:rgba(255,255,255,0.1);color:white}
.generated-titles{margin-top:15px}
.title-options{display:grid;gap:8px}
.title-option{padding:12px 15px;background:rgba(255,255,255,0.1);border:2px solid rgba(255,255,255,0.3);border-radius:6px;cursor:pointer;transition:all 0.2s;text-align:left;color:white}
.title-option:hover{background:rgba(255,255,255,0.2);border-color:rgba(255,255,255,0.6)}
.loading{display:none;text-align:center;color:rgba(255,255,255,0.8);margin-top:10px}
.spinner{display:inline-block;width:20px;height:20px;border:3px solid rgba(255,255,255,0.3);border-top:3px solid white;border-radius:50%;animation:spin 1s linear infinite;margin-right:10px}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
.alert{padding:15px;border-radius:6px;margin-bottom:20px}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
.empty-state{text-align:center;padding:40px 20px;color:#666}
.empty-state h3{margin:0 0 10px 0;color:#999}
.drag-placeholder{border:2px dashed #ccc;background:#f9f9f9;margin:5px 0;height:40px;display:flex;align-items:center;justify-content:center;color:#999;font-size:14px;border-radius:4px}
.drop-zone{border:2px dashed #007bff;background:rgba(0,123,255,0.1);border-radius:4px}
.drag-helper{position:fixed;pointer-events:none;z-index:9999;opacity:0.8;background:white;padding:10px;border-radius:4px;box-shadow:0 4px 8px rgba(0,0,0,0.2);transform:rotate(3deg)}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay">
<div class="loading-content">
<div class="loading-spinner"></div>
<div class="loading-text">글을 발행하고 있습니다...</div>
<div style="margin-top:10px;color:#666;font-size:14px;">잠시만 기다려주세요.</div>
</div>
</div>
<div class="success-modal" id="successModal">
<div class="success-modal-content">
<div class="success-modal-icon" id="successIcon">✅</div>
<div class="success-modal-title" id="successTitle">저장 완료!</div>
<div class="success-modal-message" id="successMessage">정보가 성공적으로 저장되었습니다.</div>
<button class="success-modal-button" onclick="closeSuccessModal()">확인</button>
</div>
</div>
<button class="scroll-to-top" id="scrollToTop" onclick="scrollToTop()">⬆️</button>
<div class="main-container">
<div class="header-section">
<h1>🛍️ 어필리에이트 상품 등록</h1>
<p class="subtitle">알리익스프레스 전용 상품 글 생성기 + 사용자 상세 정보 활용 + 프롬프트 선택</p>
<?php if(!empty($success_message)):?>
<div class="alert alert-success"><?php echo esc_html($success_message);?></div>
<?php endif;?>
<?php if(!empty($error_message)):?>
<div class="alert alert-error"><?php echo esc_html($error_message);?></div>
<?php endif;?>
<form method="POST" action="keyword_processor.php" id="affiliateForm">
<div class="header-form">
<!-- 첫 번째 줄: 글 제목 + 썸네일 URL -->
<div class="input-row">
<div class="form-group">
<label for="title">글 제목</label>
<div class="input-with-button">
<input type="text" id="title" name="title" placeholder="글 제목을 입력하거나 아래 '제목 생성' 버튼을 클릭하세요" required>
<button type="button" class="btn btn-secondary" onclick="toggleTitleGenerator()">제목 생성</button>
</div>
<div class="keyword-generator" id="titleGenerator">
<label for="titleKeyword" style="color:rgba(255,255,255,0.9);">제목 생성 키워드 (콤마로 구분)</label>
<div class="keyword-input-row">
<input type="text" id="titleKeyword" placeholder="예: 물놀이용품, 비치웨어, 여름용품">
<button type="button" class="btn btn-primary" onclick="generateTitles()">생성</button>
</div>
<div class="loading" id="titleLoading">
<div class="spinner"></div>
제목을 생성하고 있습니다...
</div>
<div class="generated-titles" id="generatedTitles" style="display:none;">
<label style="color:rgba(255,255,255,0.9);">추천 제목 (클릭하여 선택)</label>
<div class="title-options" id="titleOptions"></div>
</div>
</div>
</div>
<div class="form-group">
<label for="thumbnail_url">썸네일 이미지 URL</label>
<div class="input-with-button">
<input type="text" id="thumbnail_url" name="thumbnail_url" placeholder="URL획득 버튼을 클릭하여 이미지를 선택하세요">
<button type="button" class="btn btn-secondary" onclick="openImageSelector()">URL획득</button>
</div>
</div>
</div>
<!-- 두 번째 줄: 카테고리 + 프롬프트 + 저장된 정보 관리 -->
<div class="input-row">
<div class="form-group">
<label for="category">카테고리</label>
<div class="input-with-button">
<select id="category" name="category" required>
<option value="356" selected>스마트 리빙</option>
<option value="355">기발한 잡화점</option>
<option value="354">Today's Pick</option>
<option value="12">우리잇템</option>
</select>
</div>
</div>
<div class="form-group">
<label for="prompt_type">프롬프트 스타일</label>
<div class="input-with-button">
<select id="prompt_type" name="prompt_type" required>
<option value="essential_items" selected>주제별 필수템형</option>
<option value="friend_review">친구 추천형</option>
<option value="professional_analysis">전문 분석형</option>
<option value="amazing_discovery">놀라움 발견형</option>
</select>
<a href="queue_manager.php" class="nav-link" style="margin-left:10px;">📋 저장된 정보 관리</a>
</div>
</div>
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
<button type="button" class="btn btn-primary" onclick="toggleKeywordInput()" style="width:100%;margin-bottom:10px;">📁 키워드 추가</button>
<div class="keyword-input-section" id="keywordInputSection">
<div class="keyword-input-row-inline">
<input type="text" id="newKeywordInput" placeholder="새 키워드를 입력하세요"/>
<button type="button" class="btn-success" onclick="addKeywordFromInput()">추가</button>
<button type="button" class="btn-secondary" onclick="cancelKeywordInput()">취소</button>
</div>
</div>
<button type="button" class="btn btn-success" onclick="toggleExcelUpload()" style="width:100%;margin-bottom:10px;">📄 엑셀 업로드</button>
<div class="excel-upload-section" id="excelUploadSection">
<div class="file-input-wrapper">
<input type="file" class="file-input" id="excelFile" accept=".xlsx,.xls,.csv">
<label for="excelFile" class="file-input-label">📄 파일 선택</label>
<div class="file-name" id="fileName"></div>
</div>
<div class="keyword-input-row-inline">
<button type="button" class="btn-success" onclick="uploadExcelFile()">업로드 & 자동입력</button>
<button type="button" class="btn-secondary" onclick="cancelExcelUpload()">취소</button>
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
<div class="top-navigation" id="topNavigation" style="display:none;">
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
<div id="productDetailContent" style="display:none;">
<div class="product-url-section">
<h3>🌏 알리익스프레스 상품 URL</h3>
<div class="url-input-group">
<input type="url" id="productUrl" placeholder="예: https://www.aliexpress.com/item/123456789.html">
<button type="button" class="btn btn-primary" onclick="analyzeProduct()">🔍 분석</button>
</div>
<div class="analysis-result" id="analysisResult">
<div class="product-card" id="productCard"></div>
<div class="html-source-section" id="htmlSourceSection" style="display:none;">
<div class="html-source-header">
<h4>📝 워드프레스 글 HTML 소스</h4>
<button type="button" class="copy-btn" onclick="copyHtmlSource()">📋 복사하기</button>
</div>
<div class="html-preview">
<h5 style="margin:0 0 10px 0;color:#666;">미리보기:</h5>
<div id="htmlPreview"></div>
</div>
<div class="html-code" id="htmlCode"></div>
</div>
</div>
</div>
<div class="user-input-section">
<div class="input-group">
<h3>⚙️ 기능 및 스펙 <small style="color:#666;">(선택사항 - 빈 칸은 자동 제외)</small></h3>
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
<h3>📊 효율성 분석 <small style="color:#666;">(선택사항 - 빈 칸은 자동 제외)</small></h3>
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
<h3>🏠 사용 시나리오 <small style="color:#666;">(선택사항 - 빈 칸은 자동 제외)</small></h3>
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
<h3>✅ 장점 및 주의사항 <small style="color:#666;">(선택사항 - 빈 칸은 자동 제외)</small></h3>
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
let kw=[],cKI=-1,cPI=-1,cPD=null;
let draggedElement=null,draggedType=null,draggedIndex=null,draggedKeywordIndex=null;
document.addEventListener('DOMContentLoaded',function(){updateUI();handleScrollToTop();checkForNewImageUrl();setupFileInput();});
function showSuccessModal(t,m,i='✅'){document.getElementById('successIcon').textContent=i;document.getElementById('successTitle').textContent=t;document.getElementById('successMessage').textContent=m;document.getElementById('successModal').style.display='flex';setTimeout(()=>{closeSuccessModal();},2000);}
function closeSuccessModal(){document.getElementById('successModal').style.display='none';}
document.addEventListener('DOMContentLoaded',function(){document.getElementById('successModal').addEventListener('click',function(e){if(e.target===document.getElementById('successModal'))closeSuccessModal();});});
function handleScrollToTop(){window.addEventListener('scroll',function(){document.getElementById('scrollToTop').classList.toggle('show',window.pageYOffset>300);});}
function scrollToTop(){window.scrollTo({top:0,behavior:'smooth'});}
function openImageSelector(){window.open('image_selector.php','_blank');}
window.addEventListener('focus',function(){checkForNewImageUrl();});
function checkForNewImageUrl(){const newUrl=localStorage.getItem('selected_image_url');if(newUrl){document.getElementById('thumbnail_url').value=newUrl;localStorage.removeItem('selected_image_url');}}
function formatPrice(p){return p?p.replace(/₩(\d)/,'₩ $1'):p;}
function showDetailedError(t,m,d=null){const em=document.getElementById('errorModal');if(em)em.remove();let fm=m;if(d)fm+='\n\n=== 디버그 정보 ===\n'+JSON.stringify(d,null,2);const md=document.createElement('div');md.id='errorModal';md.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:flex;align-items:center;justify-content:center;';md.innerHTML=`<div style="background:white;border-radius:10px;padding:30px;max-width:800px;max-height:80vh;overflow-y:auto;box-shadow:0 10px 30px rgba(0,0,0,0.3);"><h2 style="color:#dc3545;margin-bottom:20px;font-size:24px;">🚨 ${t}</h2><div style="margin-bottom:20px;"><textarea id="errorContent" readonly style="width:100%;height:300px;padding:15px;border:1px solid #ddd;border-radius:6px;font-family:'Courier New',monospace;font-size:12px;line-height:1.4;background:#f8f9fa;resize:vertical;">${fm}</textarea></div><div style="display:flex;gap:10px;justify-content:flex-end;"><button onclick="copyErrorToClipboard()" style="padding:10px 20px;background:#007bff;color:white;border:none;border-radius:6px;cursor:pointer;font-size:14px;">📋 복사하기</button><button onclick="closeErrorModal()" style="padding:10px 20px;background:#6c757d;color:white;border:none;border-radius:6px;cursor:pointer;font-size:14px;">닫기</button></div></div>`;document.body.appendChild(md);md.addEventListener('click',function(e){if(e.target===md)closeErrorModal();});}
function copyErrorToClipboard(){const ec=document.getElementById('errorContent');ec.select();document.execCommand('copy');const cb=event.target;const ot=cb.textContent;cb.textContent='✅ 복사됨!';cb.style.background='#28a745';setTimeout(()=>{cb.textContent=ot;cb.style.background='#007bff';},2000);}
function closeErrorModal(){const m=document.getElementById('errorModal');if(m)m.remove();}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeErrorModal();});
function toggleTitleGenerator(){const g=document.getElementById('titleGenerator');g.style.display=g.style.display==='none'?'block':'none';}
async function generateTitles(){const ki=document.getElementById('titleKeyword').value.trim();if(!ki){showDetailedError('입력 오류','키워드를 입력해주세요.');return;}const l=document.getElementById('titleLoading'),td=document.getElementById('generatedTitles');l.style.display='block';td.style.display='none';try{const fd=new FormData();fd.append('action','generate_titles');fd.append('keywords',ki);const r=await fetch('',{method:'POST',body:fd});const rs=await r.json();if(rs.success)displayTitles(rs.titles);else showDetailedError('제목 생성 실패',rs.message);}catch(e){showDetailedError('제목 생성 오류','제목 생성 중 오류가 발생했습니다.',{'error':e.message,'keywords':ki});}finally{l.style.display='none';}}
function displayTitles(t){const od=document.getElementById('titleOptions'),td=document.getElementById('generatedTitles');od.innerHTML='';t.forEach((ti)=>{const b=document.createElement('button');b.type='button';b.className='title-option';b.textContent=ti;b.onclick=()=>selectTitle(ti);od.appendChild(b);});td.style.display='block';}
function selectTitle(t){document.getElementById('title').value=t;document.getElementById('titleGenerator').style.display='none';}
function toggleKeywordInput(){const is=document.getElementById('keywordInputSection');if(is.classList.contains('show'))is.classList.remove('show');else{is.classList.add('show');document.getElementById('newKeywordInput').focus();}}
function addKeywordFromInput(){const i=document.getElementById('newKeywordInput'),n=i.value.trim();if(n){kw.push({name:n,products:[]});updateUI();i.value='';document.getElementById('keywordInputSection').classList.remove('show');addProduct(kw.length-1);}else showDetailedError('입력 오류','키워드를 입력해주세요.');}
function cancelKeywordInput(){document.getElementById('newKeywordInput').value='';document.getElementById('keywordInputSection').classList.remove('show');}
function toggleExcelUpload(){const es=document.getElementById('excelUploadSection');if(es.classList.contains('show'))es.classList.remove('show');else{es.classList.add('show');}}
function cancelExcelUpload(){document.getElementById('excelUploadSection').classList.remove('show');document.getElementById('excelFile').value='';document.getElementById('fileName').textContent='';}
function setupFileInput(){document.getElementById('excelFile').addEventListener('change',function(e){const file=e.target.files[0];if(file){document.getElementById('fileName').textContent=`선택된 파일: ${file.name}`;}else{document.getElementById('fileName').textContent='';}});}
async function uploadExcelFile(){const fileInput=document.getElementById('excelFile');if(!fileInput.files[0]){showDetailedError('파일 선택 오류','업로드할 엑셀 파일을 선택해주세요.');return;}const formData=new FormData();formData.append('excel_file',fileInput.files[0]);try{const response=await fetch('excel_import_handler.php',{method:'POST',body:formData});const result=await response.json();if(result.success){processExcelData(result.data);showSuccessModal('업로드 완료!','엑셀 파일이 성공적으로 업로드되고 자동 입력되었습니다.','📄');cancelExcelUpload();}else{showDetailedError('업로드 실패',result.message);}}catch(error){showDetailedError('업로드 오류','파일 업로드 중 오류가 발생했습니다.',{'error':error.message});}}
function processExcelData(data){if(!data||data.length===0){showDetailedError('데이터 오류','엑셀 파일에서 유효한 데이터를 찾을 수 없습니다.');return;}data.forEach(item=>{if(item.keyword&&item.url){let keywordIndex=-1;for(let i=0;i<kw.length;i++){if(kw[i].name===item.keyword){keywordIndex=i;break;}}if(keywordIndex===-1){kw.push({name:item.keyword,products:[]});keywordIndex=kw.length-1;}const product={id:Date.now()+Math.random(),url:item.url,name:`상품 ${kw[keywordIndex].products.length+1}`,status:'empty',analysisData:null,userData:item.user_details||{},isSaved:false,generatedHtml:null};kw[keywordIndex].products.push(product);}});updateUI();}
document.addEventListener('DOMContentLoaded',function(){document.getElementById('newKeywordInput').addEventListener('keypress',function(e){if(e.key==='Enter'){e.preventDefault();addKeywordFromInput();}});});
function addProduct(ki){const p={id:Date.now()+Math.random(),url:'',name:`상품 ${kw[ki].products.length+1}`,status:'empty',analysisData:null,userData:{},isSaved:false,generatedHtml:null};kw[ki].products.push(p);updateUI();selectProduct(ki,kw[ki].products.length-1);}
function deleteKeyword(ki){if(confirm(`"${kw[ki].name}" 키워드를 삭제하시겠습니까?\n이 키워드에 포함된 모든 상품도 함께 삭제됩니다.`)){if(cKI===ki){cKI=-1;cPI=-1;document.getElementById('topNavigation').style.display='none';document.getElementById('productDetailContent').style.display='none';document.getElementById('currentProductTitle').textContent='상품을 선택해주세요';document.getElementById('currentProductSubtitle').textContent='왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.';}else if(cKI>ki)cKI--;kw.splice(ki,1);updateUI();}}
function deleteProduct(ki,pi){const p=kw[ki].products[pi];if(confirm(`"${p.name}" 상품을 삭제하시겠습니까?`)){if(cKI===ki&&cPI===pi){cKI=-1;cPI=-1;document.getElementById('topNavigation').style.display='none';document.getElementById('productDetailContent').style.display='none';document.getElementById('currentProductTitle').textContent='상품을 선택해주세요';document.getElementById('currentProductSubtitle').textContent='왼쪽 목록에서 상품을 클릭하여 편집을 시작하세요.';}else if(cKI===ki&&cPI>pi)cPI--;kw[ki].products.splice(pi,1);updateUI();}}
// 키워드 순서 변경 함수들 (팝업 제거)
function moveKeywordUp(keywordIndex){if(keywordIndex<=0)return;const keyword=kw.splice(keywordIndex,1)[0];kw.splice(keywordIndex-1,0,keyword);if(cKI===keywordIndex)cKI=keywordIndex-1;else if(cKI===keywordIndex-1)cKI=keywordIndex;updateUI();}
function moveKeywordDown(keywordIndex){if(keywordIndex>=kw.length-1)return;const keyword=kw.splice(keywordIndex,1)[0];kw.splice(keywordIndex+1,0,keyword);if(cKI===keywordIndex)cKI=keywordIndex+1;else if(cKI===keywordIndex+1)cKI=keywordIndex;updateUI();}
// 상품 순서 변경 함수들 (팝업 제거)
function moveProductUp(keywordIndex,productIndex){if(productIndex<=0)return;const product=kw[keywordIndex].products.splice(productIndex,1)[0];kw[keywordIndex].products.splice(productIndex-1,0,product);if(cKI===keywordIndex&&cPI===productIndex)cPI=productIndex-1;else if(cKI===keywordIndex&&cPI===productIndex-1)cPI=productIndex;updateUI();}
function moveProductDown(keywordIndex,productIndex){if(productIndex>=kw[keywordIndex].products.length-1)return;const product=kw[keywordIndex].products.splice(productIndex,1)[0];kw[keywordIndex].products.splice(productIndex+1,0,product);if(cKI===keywordIndex&&cPI===productIndex)cPI=productIndex+1;else if(cKI===keywordIndex&&cPI===productIndex+1)cPI=productIndex;updateUI();}
function clearUserInputFields(){['main_function','size_capacity','color','material','power_battery','problem_solving','time_saving','space_efficiency','cost_saving','usage_location','usage_frequency','target_users','usage_method','advantage1','advantage2','advantage3','precautions'].forEach(id=>document.getElementById(id).value='');}
function loadUserInputFields(u){if(!u)return;if(u.specs){['main_function','size_capacity','color','material','power_battery'].forEach(k=>{if(u.specs[k])document.getElementById(k).value=u.specs[k];});}if(u.efficiency){['problem_solving','time_saving','space_efficiency','cost_saving'].forEach(k=>{if(u.efficiency[k])document.getElementById(k).value=u.efficiency[k];});}if(u.usage){['usage_location','usage_frequency','target_users','usage_method'].forEach(k=>{if(u.usage[k])document.getElementById(k).value=u.usage[k];});}if(u.benefits){if(u.benefits.advantages){u.benefits.advantages.forEach((v,i)=>{if(i<3)document.getElementById(`advantage${i+1}`).value=v;});}if(u.benefits.precautions)document.getElementById('precautions').value=u.benefits.precautions;}}
function selectProduct(ki,pi){cKI=ki;cPI=pi;const p=kw[ki].products[pi];document.querySelectorAll('.product-item').forEach(i=>i.classList.remove('active'));const si=document.querySelector(`[data-keyword="${ki}"][data-product="${pi}"]`);if(si)si.classList.add('active');updateDetailPanel(p);document.getElementById('topNavigation').style.display='block';}
function updateDetailPanel(p){document.getElementById('currentProductTitle').textContent=p.name;document.getElementById('currentProductSubtitle').textContent=`키워드: ${kw[cKI].name}`;document.getElementById('productUrl').value=p.url||'';if(p.userData&&Object.keys(p.userData).length>0)loadUserInputFields(p.userData);else clearUserInputFields();if(p.analysisData)showAnalysisResult(p.analysisData);else hideAnalysisResult();document.getElementById('productDetailContent').style.display='block';}
async function analyzeProduct(){const u=document.getElementById('productUrl').value.trim();if(!u){showDetailedError('입력 오류','상품 URL을 입력해주세요.');return;}if(cKI===-1||cPI===-1){showDetailedError('선택 오류','상품을 먼저 선택해주세요.');return;}const p=kw[cKI].products[cPI];p.url=u;p.status='analyzing';updateUI();try{const r=await fetch('product_analyzer_v2.php',{method:'POST',headers:{'Content-Type':'application/json',},body:JSON.stringify({action:'analyze_product',url:u,platform:'aliexpress'})});if(!r.ok)throw new Error(`HTTP 오류: ${r.status} ${r.statusText}`);const rt=await r.text();let rs;try{rs=JSON.parse(rt);}catch(e){showDetailedError('JSON 파싱 오류','서버 응답을 파싱할 수 없습니다.',{'parseError':e.message,'responseText':rt,'responseLength':rt.length,'url':u,'timestamp':new Date().toISOString()});p.status='error';updateUI();return;}if(rs.success){p.analysisData=rs.data;p.status='completed';p.name=rs.data.title||`상품 ${cPI+1}`;cPD=rs.data;showAnalysisResult(rs.data);const gh=generateOptimizedMobileHtml(rs.data);p.generatedHtml=gh;}else{p.status='error';showDetailedError('상품 분석 실패',rs.message||'알 수 없는 오류가 발생했습니다.',{'success':rs.success,'message':rs.message,'debug_info':rs.debug_info||null,'raw_output':rs.raw_output||null,'url':u,'platform':'aliexpress','timestamp':new Date().toISOString(),'mobile_optimized':true});}}catch(e){p.status='error';showDetailedError('네트워크 오류','상품 분석 중 네트워크 오류가 발생했습니다.',{'error':e.message,'stack':e.stack,'url':u,'timestamp':new Date().toISOString()});}updateUI();}
function showAnalysisResult(d){const r=document.getElementById('analysisResult'),c=document.getElementById('productCard');const rd=d.rating_display?d.rating_display.replace(/[()]/g,'').trim():'정보 없음';const fp=formatPrice(d.price);c.innerHTML=`<div class="product-content-split"><div class="product-image-large"><img src="${d.image_url}" alt="${d.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><div class="aliexpress-logo-right"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress"/></div><h3 class="product-title-right">${d.title}</h3><div class="product-price-right">${fp}</div><div class="product-rating-right"><span class="rating-stars">⭐⭐⭐⭐⭐</span><span>(고객만족도: ${rd})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}</div><div class="product-extra-info-right"><div class="info-row"><span class="info-label">상품 ID</span><span class="info-value">${d.product_id}</span></div><div class="info-row"><span class="info-label">플랫폼</span><span class="info-value">${d.platform}</span></div></div></div></div><div class="purchase-button-full"><a href="${d.affiliate_link}" target="_blank" rel="nofollow"><picture><source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></picture></a></div>`;r.classList.add('show');}
function generateOptimizedMobileHtml(d){if(!d)return null;const rd=d.rating_display?d.rating_display.replace(/[()]/g,'').trim():'정보 없음';const fp=formatPrice(d.price);const hc=`<div style="display:flex;justify-content:center;margin:25px 0;"><div style="border:2px solid #eee;padding:30px;border-radius:15px;background:#f9f9f9;box-shadow:0 4px 8px rgba(0,0,0,0.1);max-width:1000px;width:100%;"><div style="display:grid;grid-template-columns:400px 1fr;gap:30px;align-items:start;margin-bottom:25px;"><div style="text-align:center;"><img src="${d.image_url}" alt="${d.title}" style="width:100%;max-width:400px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,0.15);"></div><div style="display:flex;flex-direction:column;gap:20px;"><div style="margin-bottom:15px;text-align:center;"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" style="width:250px;height:60px;object-fit:contain;"/></div><h3 style="color:#1c1c1c;margin:0 0 20px 0;font-size:21px;font-weight:600;line-height:1.4;word-break:keep-all;overflow-wrap:break-word;text-align:center;">${d.title}</h3><div style="background:linear-gradient(135deg,#e62e04 0%,#ff9900 100%);color:white;padding:14px 30px;border-radius:10px;font-size:40px;font-weight:700;text-align:center;margin-bottom:20px;box-shadow:0 4px 15px rgba(230,46,4,0.3);"><strong>${fp}</strong></div><div style="color:#1c1c1c;font-size:20px;display:flex;align-items:center;gap:10px;margin-bottom:15px;justify-content:center;flex-wrap:nowrap;"><span style="color:#ff9900;">⭐⭐⭐⭐⭐</span><span>(고객만족도: ${rd})</span></div><p style="color:#1c1c1c;font-size:18px;margin:0 0 15px 0;text-align:center;"><strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}</p></div></div><div style="text-align:center;margin-top:30px;width:100%;"><a href="${d.affiliate_link}" target="_blank" rel="nofollow" style="text-decoration:none;"><picture><source media="(max-width:1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기" style="max-width:100%;height:auto;cursor:pointer;"></picture></a></div></div></div><style>@media(max-width:1600px){div[style*="grid-template-columns:400px 1fr"]{display:block!important;grid-template-columns:none!important;gap:15px!important;}img[style*="max-width:400px"]{width:95%!important;max-width:none!important;margin-bottom:30px!important;}div[style*="gap:20px"]{gap:10px!important;}div[style*="text-align:center"] img[alt="AliExpress"]{display:block;margin:0!important;}div[style*="text-align:center"]:has(img[alt="AliExpress"]){text-align:left!important;margin-bottom:10px!important;}h3[style*="text-align:center"]{text-align:left!important;font-size:18px!important;margin-bottom:10px!important;}div[style*="font-size:40px"]{font-size:28px!important;padding:12px 20px!important;margin-bottom:10px!important;}div[style*="justify-content:center"][style*="flex-wrap:nowrap"]{justify-content:flex-start!important;font-size:16px!important;margin-bottom:10px!important;gap:8px!important;}p[style*="text-align:center"]{text-align:left!important;font-size:16px!important;margin-bottom:10px!important;}div[style*="margin-top:30px"]{margin-top:15px!important;}}@media(max-width:480px){img[style*="width:95%"]{width:100%!important;}h3[style*="font-size:18px"]{font-size:16px!important;}div[style*="font-size:28px"]{font-size:24px!important;}}}</style>`;const ph=`<div class="preview-product-card"><div class="preview-card-content"><div class="product-content-split"><div class="product-image-large"><img src="${d.image_url}" alt="${d.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><div class="aliexpress-logo-right"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress"/></div><h3 class="product-title-right">${d.title}</h3><div class="product-price-right">${fp}</div><div class="product-rating-right"><span class="rating-stars">⭐⭐⭐⭐⭐</span><span>(고객만족도: ${rd})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}</div></div></div><div class="purchase-button-full"><a href="${d.affiliate_link}" target="_blank" rel="nofollow"><picture><source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기"></picture></a></div></div></div>`;document.getElementById('htmlPreview').innerHTML=ph;document.getElementById('htmlCode').textContent=hc;document.getElementById('htmlSourceSection').style.display='block';return hc;}
async function copyHtmlSource(){const hc=document.getElementById('htmlCode').textContent,cb=document.querySelector('.copy-btn');try{await navigator.clipboard.writeText(hc);const ot=cb.textContent;cb.textContent='✅ 복사됨!';cb.classList.add('copied');setTimeout(()=>{cb.textContent=ot;cb.classList.remove('copied');},2000);}catch(e){const ce=document.getElementById('htmlCode'),r=document.createRange();r.selectNodeContents(ce);const s=window.getSelection();s.removeAllRanges();s.addRange(r);showDetailedError('복사 알림','HTML 소스가 선택되었습니다. Ctrl+C로 복사하세요.');}}
function hideAnalysisResult(){document.getElementById('analysisResult').classList.remove('show');document.getElementById('htmlSourceSection').style.display='none';}
function updateUI(){updateProductsList();updateProgress();}
function updateProductsList(){const l=document.getElementById('productsList');if(kw.length===0){l.innerHTML='<div class="empty-state"><h3>📦 상품이 없습니다</h3><p>위의 "키워드 추가" 버튼을 클릭하여<br>첫 번째 키워드를 추가해보세요!</p></div>';return;}let h='';kw.forEach((k,ki)=>{h+=`<div class="keyword-group draggable" draggable="true" data-keyword-index="${ki}"><div class="keyword-header"><div class="keyword-header-left"><div class="keyword-order-controls"><button type="button" class="keyword-order-btn" onclick="handleKeywordOrderChange(event, ${ki}, 'up')" ${ki===0?'disabled':''}title="키워드 위로">▲</button><button type="button" class="keyword-order-btn" onclick="handleKeywordOrderChange(event, ${ki}, 'down')" ${ki===kw.length-1?'disabled':''}title="키워드 아래로">▼</button></div><div class="keyword-info"><span class="keyword-name">📁 ${k.name}</span><span class="product-count">${k.products.length}개</span></div></div><div class="keyword-actions"><button type="button" class="btn btn-success btn-small" onclick="addProduct(${ki})">+상품</button><button type="button" class="btn btn-danger btn-small" onclick="deleteKeyword(${ki})">🗑️</button></div></div>`;k.products.forEach((p,pi)=>{const si=getStatusIcon(p.status,p.isSaved);h+=`<div class="product-item draggable" draggable="true" data-keyword="${ki}" data-product="${pi}" onclick="selectProduct(${ki},${pi})"><div class="product-order-controls"><button type="button" class="product-order-btn" onclick="handleProductOrderChange(event, ${ki}, ${pi}, 'up')" ${pi===0?'disabled':''}title="상품 위로">▲</button><button type="button" class="product-order-btn" onclick="handleProductOrderChange(event, ${ki}, ${pi}, 'down')" ${pi===k.products.length-1?'disabled':''}title="상품 아래로">▼</button></div><span class="product-status">${si}</span><span class="product-name">${p.name}</span><div class="product-actions"><button type="button" class="btn btn-danger btn-small" onclick="event.stopPropagation();deleteProduct(${ki},${pi})">🗑️</button></div></div>`;});h+='</div>';});l.innerHTML=h;setupDragAndDrop();}
// 이벤트 핸들러 함수들 추가
function handleKeywordOrderChange(event, keywordIndex, direction) {
    event.stopPropagation();
    if (direction === 'up') {
        moveKeywordUp(keywordIndex);
    } else {
        moveKeywordDown(keywordIndex);
    }
}
function handleProductOrderChange(event, keywordIndex, productIndex, direction) {
    event.stopPropagation();
    if (direction === 'up') {
        moveProductUp(keywordIndex, productIndex);
    } else {
        moveProductDown(keywordIndex, productIndex);
    }
}
function getStatusIcon(s,is=false){switch(s){case'completed':return is?'✅':'🔍';case'analyzing':return'🔄';case'error':return'⚠️';default:return'❌';}}
function updateProgress(){const tp=kw.reduce((s,k)=>s+k.products.length,0),cp=kw.reduce((s,k)=>s+k.products.filter(p=>p.isSaved).length,0),pe=tp>0?(cp/tp)*100:0;document.getElementById('progressFill').style.width=pe+'%';document.getElementById('progressText').textContent=`${cp}/${tp} 완성`;}
function collectUserInputDetails(){const d={},sp={},ef={},us={},be={},av=[];addIfNotEmpty(sp,'main_function','main_function');addIfNotEmpty(sp,'size_capacity','size_capacity');addIfNotEmpty(sp,'color','color');addIfNotEmpty(sp,'material','material');addIfNotEmpty(sp,'power_battery','power_battery');if(Object.keys(sp).length>0)d.specs=sp;addIfNotEmpty(ef,'problem_solving','problem_solving');addIfNotEmpty(ef,'time_saving','time_saving');addIfNotEmpty(ef,'space_efficiency','space_efficiency');addIfNotEmpty(ef,'cost_saving','cost_saving');if(Object.keys(ef).length>0)d.efficiency=ef;addIfNotEmpty(us,'usage_location','usage_location');addIfNotEmpty(us,'usage_frequency','usage_frequency');addIfNotEmpty(us,'target_users','target_users');addIfNotEmpty(us,'usage_method','usage_method');if(Object.keys(us).length>0)d.usage=us;['advantage1','advantage2','advantage3'].forEach(id=>{const v=document.getElementById(id)?.value.trim();if(v)av.push(v);});if(av.length>0)be.advantages=av;addIfNotEmpty(be,'precautions','precautions');if(Object.keys(be).length>0)d.benefits=be;return d;}
function addIfNotEmpty(o,k,e){const v=document.getElementById(e)?.value.trim();if(v)o[k]=v;}
function collectKeywordsData(){const kd=[];kw.forEach((k,ki)=>{const kdt={name:k.name,coupang:[],aliexpress:[],products_data:[]};k.products.forEach((p,pi)=>{if(p.url&&typeof p.url==='string'&&p.url.trim()!==''&&p.url.trim()!=='undefined'&&p.url.trim()!=='null'&&p.url.includes('aliexpress.com')){const tu=p.url.trim();kdt.aliexpress.push(tu);const pd={url:tu,analysis_data:p.analysisData||null,generated_html:p.generatedHtml||null,user_data:p.userData||{}};kdt.products_data.push(pd);}});if(kdt.aliexpress.length>0)kd.push(kdt);});return kd;}
function validateAndSubmitData(fd,ip=false){if(!fd.title||fd.title.length<5){showDetailedError('입력 오류','제목은 5자 이상이어야 합니다.');return false;}if(!fd.keywords||fd.keywords.length===0){showDetailedError('입력 오류','최소 하나의 키워드와 상품 링크가 필요합니다.');return false;}let hv=false,tv=0,tpd=0;fd.keywords.forEach(k=>{if(k.aliexpress&&k.aliexpress.length>0){const vu=k.aliexpress.filter(u=>u&&typeof u==='string'&&u.trim()!==''&&u.includes('aliexpress.com'));if(vu.length>0){hv=true;tv+=vu.length;tpd+=k.products_data?k.products_data.length:0;}}});if(!hv||tv===0){showDetailedError('입력 오류','각 키워드에 최소 하나의 유효한 알리익스프레스 상품 링크가 있어야 합니다.\\n\\n현재 상태:\\n- URL을 입력했는지 확인하세요\\n- 분석 버튼을 클릭했는지 확인하세요\\n- 알리익스프레스 URL인지 확인하세요');return false;}if(ip)return true;else{const f=document.getElementById('affiliateForm'),ei=f.querySelectorAll('input[type="hidden"]');ei.forEach(i=>i.remove());const hi=[{name:'title',value:fd.title},{name:'category',value:fd.category},{name:'prompt_type',value:fd.prompt_type},{name:'keywords',value:JSON.stringify(fd.keywords)},{name:'user_details',value:JSON.stringify(fd.user_details)},{name:'thumbnail_url',value:document.getElementById('thumbnail_url').value.trim()}];hi.forEach(({name,value})=>{const i=document.createElement('input');i.type='hidden';i.name=name;i.value=value;f.appendChild(i);});f.submit();return true;}}
async function publishNow(){const kd=collectKeywordsData(),ud=collectUserInputDetails(),fd={title:document.getElementById('title').value.trim(),category:document.getElementById('category').value,prompt_type:document.getElementById('prompt_type').value,keywords:kd,user_details:ud,thumbnail_url:document.getElementById('thumbnail_url').value.trim()};if(!validateAndSubmitData(fd,true))return;const lo=document.getElementById('loadingOverlay'),pb=document.getElementById('publishNowBtn');lo.style.display='flex';pb.disabled=true;pb.textContent='발행 중...';try{const r=await fetch('keyword_processor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({title:fd.title,category:fd.category,prompt_type:fd.prompt_type,keywords:JSON.stringify(fd.keywords),user_details:JSON.stringify(fd.user_details),thumbnail_url:fd.thumbnail_url,publish_mode:'immediate'})});const rs=await r.json();if(rs.success){showSuccessModal('발행 완료!','글이 성공적으로 발행되었습니다!','🚀');if(rs.post_url)window.open(rs.post_url,'_blank');}else showDetailedError('발행 실패',rs.message||'알 수 없는 오류가 발생했습니다.');}catch(e){showDetailedError('발행 오류','즉시 발행 중 오류가 발생했습니다.',{'error':e.message,'timestamp':new Date().toISOString()});}finally{lo.style.display='none';pb.disabled=false;pb.textContent='🚀 즉시 발행';}}
function saveCurrentProduct(){if(cKI===-1||cPI===-1){showDetailedError('선택 오류','저장할 상품을 먼저 선택해주세요.');return;}const p=kw[cKI].products[cPI],u=document.getElementById('productUrl').value.trim();if(u)p.url=u;const ud=collectUserInputDetails();p.userData=ud;p.isSaved=true;updateUI();showSuccessModal('저장 완료!','현재 상품 정보가 성공적으로 저장되었습니다.','💾');}
function completeProduct(){const kd=collectKeywordsData(),ud=collectUserInputDetails(),fd={title:document.getElementById('title').value.trim(),category:document.getElementById('category').value,prompt_type:document.getElementById('prompt_type').value,keywords:kd,user_details:ud,thumbnail_url:document.getElementById('thumbnail_url').value.trim()};if(validateAndSubmitData(fd))console.log('대기열 저장 요청이 전송되었습니다.');}
function previousProduct(){if(cKI===-1||cPI===-1)return;const ck=kw[cKI];if(cPI>0)selectProduct(cKI,cPI-1);else if(cKI>0){const pk=kw[cKI-1];selectProduct(cKI-1,pk.products.length-1);}}
function nextProduct(){if(cKI===-1||cPI===-1)return;const ck=kw[cKI];if(cPI<ck.products.length-1)selectProduct(cKI,cPI+1);else if(cKI<kw.length-1)selectProduct(cKI+1,0);}
function setupDragAndDrop(){const keywordGroups=document.querySelectorAll('.keyword-group');const productItems=document.querySelectorAll('.product-item');keywordGroups.forEach((group,index)=>{group.addEventListener('dragstart',handleKeywordDragStart);group.addEventListener('dragend',handleDragEnd);group.addEventListener('dragover',handleDragOver);group.addEventListener('drop',handleKeywordDrop);});productItems.forEach((item,index)=>{item.addEventListener('dragstart',handleProductDragStart);item.addEventListener('dragend',handleDragEnd);item.addEventListener('dragover',handleDragOver);item.addEventListener('drop',handleProductDrop);});}
function handleKeywordDragStart(e){if(!e.target.classList.contains('keyword-group'))return;e.stopPropagation();draggedElement=e.target;draggedType='keyword';draggedIndex=parseInt(e.target.dataset.keywordIndex);e.target.classList.add('dragging');e.dataTransfer.effectAllowed='move';}
function handleProductDragStart(e){if(!e.target.classList.contains('product-item'))return;e.stopPropagation();draggedElement=e.target;draggedType='product';draggedKeywordIndex=parseInt(e.target.dataset.keyword);draggedIndex=parseInt(e.target.dataset.product);e.target.classList.add('dragging');e.dataTransfer.effectAllowed='move';}
function handleDragEnd(e){e.target.classList.remove('dragging');document.querySelectorAll('.drag-over').forEach(el=>el.classList.remove('drag-over'));draggedElement=null;draggedType=null;draggedIndex=null;draggedKeywordIndex=null;}
function handleDragOver(e){e.preventDefault();e.dataTransfer.dropEffect='move';}
function handleKeywordDrop(e){e.preventDefault();e.stopPropagation();if(draggedType!=='keyword'||!e.target.closest('.keyword-group'))return;const targetGroup=e.target.closest('.keyword-group');if(!targetGroup||targetGroup===draggedElement)return;const targetIndex=parseInt(targetGroup.dataset.keywordIndex);if(draggedIndex!==targetIndex){const draggedKeyword=kw.splice(draggedIndex,1)[0];kw.splice(targetIndex,0,draggedKeyword);if(cKI===draggedIndex)cKI=targetIndex;else if(cKI===targetIndex)cKI=draggedIndex<targetIndex?cKI+1:cKI-1;else if(cKI>Math.min(draggedIndex,targetIndex)&&cKI<=Math.max(draggedIndex,targetIndex))cKI+=draggedIndex<targetIndex?-1:1;updateUI();showSuccessModal('키워드 순서 변경','키워드 순서가 성공적으로 변경되었습니다.','🔄');}}
function handleProductDrop(e){e.preventDefault();e.stopPropagation();if(draggedType!=='product'||!e.target.closest('.product-item'))return;const targetItem=e.target.closest('.product-item');if(!targetItem||targetItem===draggedElement)return;const targetKeywordIndex=parseInt(targetItem.dataset.keyword);const targetProductIndex=parseInt(targetItem.dataset.product);if(draggedKeywordIndex===targetKeywordIndex&&draggedIndex===targetProductIndex)return;const draggedProduct=kw[draggedKeywordIndex].products.splice(draggedIndex,1)[0];kw[targetKeywordIndex].products.splice(targetProductIndex,0,draggedProduct);if(cKI===draggedKeywordIndex&&cPI===draggedIndex){cKI=targetKeywordIndex;cPI=targetProductIndex;}else if(cKI===targetKeywordIndex){if(cPI>=targetProductIndex)cPI++;}updateUI();showSuccessModal('상품 순서 변경','상품 순서가 성공적으로 변경되었습니다.','🔄');}
document.getElementById('titleKeyword').addEventListener('keypress',function(e){if(e.key==='Enter'){e.preventDefault();generateTitles();}});
</script>
</body>
</html>