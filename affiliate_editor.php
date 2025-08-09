<?php
/**
 * 어필리에이트 상품 등록 자동화 입력 페이지 - 압축 최적화 버전
 * 노바센트(novacents.com) 전용
 */
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
if(!current_user_can('manage_options'))wp_die('접근 권한이 없습니다.');
$env_file='/var/www/novacents/tools/.env';$env_vars=[];
if(file_exists($env_file)){$lines=file($env_file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);foreach($lines as $line){if(strpos($line,'=')!==false&&strpos($line,'#')!==0){list($key,$value)=explode('=',$line,2);$env_vars[trim($key)]=trim($value);}}}
if(isset($_POST['action'])&&$_POST['action']==='generate_titles'){
header('Content-Type: application/json');
$keywords_input=sanitize_text_field($_POST['keywords']);
if(empty($keywords_input)){echo json_encode(['success'=>false,'message'=>'키워드를 입력해주세요.']);exit;}
$keywords=array_map('trim',explode(',',$keywords_input));$keywords=array_filter($keywords);
if(empty($keywords)){echo json_encode(['success'=>false,'message'=>'유효한 키워드를 입력해주세요.']);exit;}
$combined_keywords=implode(',',$keywords);
$script_locations=[__DIR__.'/title_generator.py','/var/www/novacents/tools/title_generator.py'];
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
<link rel="stylesheet" href="affiliate_editor.css">
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
<!-- 두 번째 줄: 카테고리 + 프롬프트 스타일 -->
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
<button type="button" class="btn btn-success btn-small" onclick="saveUserSettings()" style="background:#28a745;color:white;">저장된 정보 관리</button>
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
<div class="batch-process-section" id="batchProcessSection" style="display:none;margin-top:15px;padding-top:15px;border-top:1px solid #c3e6cb;">
<div style="margin-bottom:10px;color:#155724;font-weight:600;">📋 일괄 처리</div>
<div class="keyword-input-row-inline">
<button type="button" class="btn-primary" onclick="batchAnalyzeAll()" id="batchAnalyzeBtn">🔍 전체 분석</button>
<button type="button" class="btn-success" onclick="batchSaveAll()" id="batchSaveBtn">💾 전체 저장</button>
</div>
<div class="batch-progress" id="batchProgress" style="display:none;margin-top:10px;">
<div style="background:#fff;border:1px solid #c3e6cb;border-radius:4px;padding:10px;font-size:13px;">
<div class="batch-progress-text" id="batchProgressText">진행 중...</div>
<div style="background:#e9ecef;height:6px;border-radius:3px;margin-top:5px;overflow:hidden;">
<div class="batch-progress-bar" id="batchProgressBar" style="background:#28a745;height:100%;width:0%;transition:width 0.3s ease;"></div>
</div>
</div>
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
<label>재질</label>
<input type="text" id="material" placeholder="예: 실리콘, 스테인리스, ABS 등">
</div>
<div class="form-field">
<label>전력/배터리</label>
<input type="text" id="power" placeholder="예: USB 충전, AA 건전지, 220V 등">
</div>
</div>
</div>
<div class="input-group">
<h3>🎯 타겟 및 용도 <small style="color:#666;">(선택사항 - 빈 칸은 자동 제외)</small></h3>
<div class="form-row">
<div class="form-field">
<label>주 사용 대상</label>
<input type="text" id="target_user" placeholder="예: 주부, 직장인, 학생, 반려동물 등">
</div>
</div>
<div class="form-row two-col">
<div class="form-field">
<label>사용 장소</label>
<input type="text" id="usage_location" placeholder="예: 부엌, 욕실, 사무실, 야외 등">
</div>
<div class="form-field">
<label>계절/시기</label>
<input type="text" id="season" placeholder="예: 여름용, 겨울용, 연중무휴 등">
</div>
</div>
</div>
<div class="input-group">
<h3>✨ 장점 및 특징 <small style="color:#666;">(최대 5개까지 - 빈 칸은 자동 제외)</small></h3>
<ul class="advantages-list">
<li><input type="text" id="advantage_1" placeholder="장점 1: 예) 간편한 원터치 조작"></li>
<li><input type="text" id="advantage_2" placeholder="장점 2: 예) 공간 절약형 컴팩트 디자인"></li>
<li><input type="text" id="advantage_3" placeholder="장점 3: 예) 세척이 쉬운 분리형 구조"></li>
<li><input type="text" id="advantage_4" placeholder="장점 4: 예) 친환경 소재 사용"></li>
<li><input type="text" id="advantage_5" placeholder="장점 5: 예) 뛰어난 내구성과 품질"></li>
</ul>
</div>
</div>
</div>
</div>
</div>
<script src="affiliate_editor.js"></script>
</body>
</html>