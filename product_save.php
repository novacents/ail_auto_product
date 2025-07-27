<?php
/**
 * 알리익스프레스 상품 발굴 저장 시스템
 * 임시 저장 → 정리 → 엑셀 내보내기를 위한 간소화된 입력 페이지
 */
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
if(!current_user_can('manage_options'))wp_die('접근 권한이 없습니다.');

$success_message='';$error_message='';
if(isset($_GET['success'])&&$_GET['success']=='1')$success_message='상품이 성공적으로 저장되었습니다!';
if(isset($_GET['error']))$error_message='오류: '.urldecode($_GET['error']);
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>상품 발굴 저장 - 노바센트</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;padding:20px;background:#f5f5f5;min-width:1200px;color:#1c1c1c}
.main-container{width:1400px;margin:0 auto;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);overflow:hidden}
.header-section{padding:30px;border-bottom:1px solid #e0e0e0;background:linear-gradient(135deg,#4CAF50 0%,#45a049 100%);color:white}
.header-section h1{margin:0 0 10px 0;font-size:28px}
.header-section .subtitle{margin:0 0 20px 0;opacity:0.9}
.header-form{display:grid;grid-template-columns:1fr;gap:15px;margin-top:20px}
.form-group{display:flex;flex-direction:column}
.form-group label{color:rgba(255,255,255,0.9);margin-bottom:8px;display:block;font-size:14px}
.input-with-button{display:flex;gap:10px;align-items:flex-end}
.input-with-button input{flex:1;padding:12px;border:1px solid rgba(255,255,255,0.3);border-radius:6px;background:rgba(255,255,255,0.1);color:white;font-size:16px}
.input-with-button input::placeholder{color:rgba(255,255,255,0.7)}
.input-with-button input.error{border-color:#ff6b6b;background:rgba(255,107,107,0.1)}
.nav-links{display:flex;gap:10px;margin-top:15px;align-items:center}
.nav-link{background:rgba(255,255,255,0.2);color:white;padding:8px 16px;border-radius:4px;text-decoration:none;font-size:14px;transition:all 0.3s}
.nav-link:hover{background:rgba(255,255,255,0.3);color:white}
.main-content{padding:30px}
.product-url-section{margin-bottom:30px;padding:20px;background:#f8f9fa;border-radius:8px;border:1px solid #e9ecef}
.url-input-group{display:flex;gap:10px;margin-bottom:15px}
.url-input-group input{flex:1;padding:12px;border:1px solid #ddd;border-radius:6px;font-size:16px}
.analysis-result{margin-top:15px;padding:20px;background:white;border-radius:8px;border:1px solid #ddd;display:none}
.analysis-result.show{display:block}
.product-card{background:#fff;border-radius:12px;padding:25px;box-shadow:0 2px 12px rgba(0,0,0,0.08);border:1px solid #f0f0f0;margin-bottom:20px}
.product-content-split{display:grid;grid-template-columns:300px 1fr;gap:30px;align-items:start;margin-bottom:25px}
.product-image-large{width:100%}
.product-image-large img{width:100%;max-width:300px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,0.15)}
.product-info-all{display:flex;flex-direction:column;gap:15px}
.product-title-right{color:#1c1c1c;font-size:18px;font-weight:600;line-height:1.4;margin:0 0 15px 0;word-break:keep-all;overflow-wrap:break-word}
.product-price-right{background:linear-gradient(135deg,#e62e04 0%,#ff9900 100%);color:white;padding:12px 25px;border-radius:8px;font-size:32px;font-weight:700;text-align:center;margin-bottom:15px;box-shadow:0 4px 15px rgba(230,46,4,0.3);display:inline-block}
.product-rating-right{color:#1c1c1c;font-size:16px;display:flex;align-items:center;gap:10px;margin-bottom:10px}
.rating-stars{color:#ff9900}
.product-sales-right{color:#1c1c1c;font-size:16px;margin-bottom:10px}
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
.form-field label{display:block;margin-bottom:5px;font-weight:600;color:#333;font-size:14px}
.form-field input,.form-field textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;box-sizing:border-box}
.form-field textarea{min-height:60px;resize:vertical}
.advantages-list{list-style:none;padding:0;margin:0}
.advantages-list li{margin-bottom:10px}
.advantages-list input{width:100%;padding:8px;border:1px solid #ddd;border-radius:4px}
.action-buttons{margin-top:30px;padding:20px;background:#f8f9fa;border-radius:8px;text-align:center}
.btn{padding:12px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.3s;text-decoration:none;display:inline-block;margin:0 5px}
.btn-primary{background:#007bff;color:white}.btn-primary:hover{background:#0056b3}
.btn-secondary{background:#6c757d;color:white}.btn-secondary:hover{background:#545b62}
.btn-success{background:#28a745;color:white}.btn-success:hover{background:#1e7e34}
.btn-danger{background:#dc3545;color:white}.btn-danger:hover{background:#c82333}
.btn-large{padding:15px 30px;font-size:16px}
.alert{padding:15px;border-radius:6px;margin-bottom:20px}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
.alert-warning{background:#fff3cd;color:#856404;border:1px solid #ffeaa7}
.loading-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:10000;display:none;align-items:center;justify-content:center}
.loading-content{background:white;border-radius:10px;padding:40px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.3)}
.loading-spinner{display:inline-block;width:40px;height:40px;border:4px solid #f3f3f3;border-top:4px solid #4CAF50;border-radius:50%;animation:spin 1s linear infinite;margin-bottom:20px}
.loading-text{font-size:18px;color:#333;font-weight:600}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
.success-modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:10001;display:none;align-items:center;justify-content:center}
.success-modal-content{background:white;border-radius:12px;padding:40px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.3);min-width:400px;max-width:500px}
.success-modal-icon{font-size:60px;margin-bottom:20px}
.success-modal-title{font-size:24px;font-weight:bold;color:#28a745;margin-bottom:15px}
.success-modal-message{font-size:16px;color:#666;margin-bottom:30px;line-height:1.5}
.success-modal-button{background:#28a745;color:white;border:none;padding:12px 30px;border-radius:6px;cursor:pointer;font-size:16px;font-weight:600;transition:all 0.3s}
.success-modal-button:hover{background:#1e7e34}
.error-tooltip{position:relative;display:inline-block}
.error-tooltip::after{content:attr(data-error);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#ff6b6b;color:white;padding:8px 12px;border-radius:4px;font-size:12px;white-space:nowrap;margin-bottom:5px;z-index:1000;opacity:0;transition:opacity 0.3s}
.error-tooltip::before{content:'';position:absolute;bottom:100%;left:50%;transform:translateX(-50%);border:5px solid transparent;border-top-color:#ff6b6b;margin-bottom:-5px;z-index:1000;opacity:0;transition:opacity 0.3s}
.error-tooltip.show::after,.error-tooltip.show::before{opacity:1}
</style>
</head>
<body>
<div class="loading-overlay" id="loadingOverlay">
<div class="loading-content">
<div class="loading-spinner"></div>
<div class="loading-text">저장하고 있습니다...</div>
<div style="margin-top:10px;color:#666;font-size:14px;">잠시만 기다려주세요.</div>
</div>
</div>
<div class="success-modal" id="successModal">
<div class="success-modal-content">
<div class="success-modal-icon" id="successIcon">✅</div>
<div class="success-modal-title" id="successTitle">저장 완료!</div>
<div class="success-modal-message" id="successMessage">상품이 성공적으로 저장되었습니다.</div>
<button class="success-modal-button" onclick="closeSuccessModal()">확인</button>
</div>
</div>
<div class="main-container">
<div class="header-section">
<h1>🛍️ 알리익스프레스 상품 발굴 저장</h1>
<p class="subtitle">상품을 빠르게 저장하고 나중에 정리하여 활용하세요</p>
<?php if(!empty($success_message)):?>
<div class="alert alert-success"><?php echo esc_html($success_message);?></div>
<?php endif;?>
<?php if(!empty($error_message)):?>
<div class="alert alert-error"><?php echo esc_html($error_message);?></div>
<?php endif;?>
<div class="header-form">
<div class="form-group">
<label for="keyword">검색 키워드</label>
<div class="input-with-button">
<input type="text" id="keyword" name="keyword" placeholder="예: 주방용품, 캠핑용품, 여름용품 등" required>
</div>
</div>
<div class="nav-links">
<a href="product_save_list.php" class="nav-link">📋 저장된 상품 보기</a>
<a href="affiliate_editor.php" class="nav-link">✍️ 상품 글 작성</a>
</div>
</div>
</div>
<div class="main-content">
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
<div class="action-buttons">
<button type="button" class="btn btn-success btn-large" onclick="saveProduct()">💾 저장하기</button>
<button type="button" class="btn btn-secondary btn-large" onclick="resetForm()">🔄 초기화</button>
<a href="product_save_list.php" class="btn btn-primary btn-large">📋 저장된 상품 보기</a>
</div>
</div>
</div>
<script>
let currentProductData=null;
let generatedHtml=null;

document.addEventListener('DOMContentLoaded',function(){
    document.getElementById('keyword').focus();
    document.getElementById('successModal').addEventListener('click',function(e){
        if(e.target===document.getElementById('successModal'))closeSuccessModal();
    });
});

function showSuccessModal(t,m,i='✅'){
    document.getElementById('successIcon').textContent=i;
    document.getElementById('successTitle').textContent=t;
    document.getElementById('successMessage').textContent=m;
    document.getElementById('successModal').style.display='flex';
}

function closeSuccessModal(){
    document.getElementById('successModal').style.display='none';
    resetForm();
}

function showError(t,m){
    alert(`${t}\n\n${m}`);
}

function showValidationError(inputId, message) {
    const input = document.getElementById(inputId);
    if (!input) return;
    
    // 입력 필드에 오류 스타일 추가
    input.classList.add('error');
    input.focus();
    
    // 알림 메시지 표시
    alert(`⚠️ 입력 확인\n\n${message}`);
    
    // 3초 후 오류 스타일 제거
    setTimeout(() => {
        input.classList.remove('error');
    }, 3000);
}

function formatPrice(p){
    return p?p.replace(/₩(\d)/,'₩ $1'):p;
}

async function analyzeProduct(){
    const u=document.getElementById('productUrl').value.trim();
    if(!u){
        showValidationError('productUrl', '상품 URL을 입력해주세요.');
        return;
    }
    
    const lo=document.getElementById('loadingOverlay');
    lo.style.display='flex';
    
    try{
        const r=await fetch('product_analyzer_v2.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                action:'analyze_product',
                url:u,
                platform:'aliexpress'
            })
        });
        
        if(!r.ok)throw new Error(`HTTP 오류: ${r.status} ${r.statusText}`);
        
        const rt=await r.text();
        let rs;
        try{
            rs=JSON.parse(rt);
        }catch(e){
            showError('JSON 파싱 오류','서버 응답을 파싱할 수 없습니다.');
            return;
        }
        
        if(rs.success){
            currentProductData=rs.data;
            showAnalysisResult(rs.data);
            generatedHtml=generateOptimizedMobileHtml(rs.data);
        }else{
            showError('상품 분석 실패',rs.message||'알 수 없는 오류가 발생했습니다.');
        }
    }catch(e){
        showError('네트워크 오류','상품 분석 중 네트워크 오류가 발생했습니다.');
    }finally{
        lo.style.display='none';
    }
}

function showAnalysisResult(d){
    const r=document.getElementById('analysisResult');
    const c=document.getElementById('productCard');
    const rd=d.rating_display?d.rating_display.replace(/⭐/g,'').replace(/[()]/g,'').trim():'정보 없음';
    const fp=formatPrice(d.price);
    
    c.innerHTML=`
        <div class="product-content-split">
            <div class="product-image-large">
                <img src="${d.image_url}" alt="${d.title}" onerror="this.style.display='none'">
            </div>
            <div class="product-info-all">
                <h3 class="product-title-right">${d.title}</h3>
                <div class="product-price-right">${fp}</div>
                <div class="product-rating-right">
                    <span class="rating-stars">⭐⭐⭐⭐⭐</span>
                    <span>(고객만족도: ${rd})</span>
                </div>
                <div class="product-sales-right">
                    <strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}
                </div>
            </div>
        </div>
    `;
    
    r.classList.add('show');
}

function generateOptimizedMobileHtml(d){
    if(!d)return null;
    const rd=d.rating_display?d.rating_display.replace(/⭐/g,'').replace(/[()]/g,'').trim():'정보 없음';
    const fp=formatPrice(d.price);
    
    const hc=`<div style="display:flex;justify-content:center;margin:25px 0;"><div style="border:2px solid #eee;padding:30px;border-radius:15px;background:#f9f9f9;box-shadow:0 4px 8px rgba(0,0,0,0.1);max-width:1000px;width:100%;"><div style="display:grid;grid-template-columns:400px 1fr;gap:30px;align-items:start;margin-bottom:25px;"><div style="text-align:center;"><img src="${d.image_url}" alt="${d.title}" style="width:100%;max-width:400px;border-radius:12px;box-shadow:0 6px 20px rgba(0,0,0,0.15);"></div><div style="display:flex;flex-direction:column;gap:20px;"><div style="margin-bottom:15px;text-align:center;"><img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" style="width:250px;height:60px;object-fit:contain;"/></div><h3 style="color:#1c1c1c;margin:0 0 20px 0;font-size:21px;font-weight:600;line-height:1.4;word-break:keep-all;overflow-wrap:break-word;text-align:center;">${d.title}</h3><div style="background:linear-gradient(135deg,#e62e04 0%,#ff9900 100%);color:white;padding:14px 30px;border-radius:10px;font-size:40px;font-weight:700;text-align:center;margin-bottom:20px;box-shadow:0 4px 15px rgba(230,46,4,0.3);"><strong>${fp}</strong></div><div style="color:#1c1c1c;font-size:20px;display:flex;align-items:center;gap:10px;margin-bottom:15px;justify-content:center;flex-wrap:nowrap;"><span style="color:#ff9900;">⭐⭐⭐⭐⭐</span><span>(고객만족도: ${rd})</span></div><p style="color:#1c1c1c;font-size:18px;margin:0 0 15px 0;text-align:center;"><strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}</p></div></div><div style="text-align:center;margin-top:30px;width:100%;"><a href="${d.affiliate_link}" target="_blank" rel="nofollow" style="text-decoration:none;"><picture><source media="(max-width:1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png"><img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기" style="max-width:100%;height:auto;cursor:pointer;"></picture></a></div></div></div><style>@media(max-width:1600px){div[style*="grid-template-columns:400px 1fr"]{display:block!important;grid-template-columns:none!important;gap:15px!important;}img[style*="max-width:400px"]{width:95%!important;max-width:none!important;margin-bottom:30px!important;}div[style*="gap:20px"]{gap:10px!important;}div[style*="text-align:center"] img[alt="AliExpress"]{display:block;margin:0!important;}div[style*="text-align:center"]:has(img[alt="AliExpress"]){text-align:left!important;margin-bottom:10px!important;}h3[style*="text-align:center"]{text-align:left!important;font-size:18px!important;margin-bottom:10px!important;}div[style*="font-size:40px"]{font-size:28px!important;padding:12px 20px!important;margin-bottom:10px!important;}div[style*="justify-content:center"][style*="flex-wrap:nowrap"]{justify-content:flex-start!important;font-size:16px!important;margin-bottom:10px!important;gap:8px!important;}p[style*="text-align:center"]{text-align:left!important;font-size:16px!important;margin-bottom:10px!important;}div[style*="margin-top:30px"]{margin-top:15px!important;}}@media(max-width:480px){img[style*="width:95%"]{width:100%!important;}h3[style*="font-size:18px"]{font-size:16px!important;}div[style*="font-size:28px"]{font-size:24px!important;}}</style>`;
    
    const ph=`<div class="preview-product-card"><div class="preview-card-content"><div class="product-content-split"><div class="product-image-large"><img src="${d.image_url}" alt="${d.title}" onerror="this.style.display='none'"></div><div class="product-info-all"><h3 class="product-title-right">${d.title}</h3><div class="product-price-right">${fp}</div><div class="product-rating-right"><span class="rating-stars">⭐⭐⭐⭐⭐</span><span>(고객만족도: ${rd})</span></div><div class="product-sales-right"><strong>📦 판매량:</strong> ${d.lastest_volume||'판매량 정보 없음'}</div></div></div></div></div>`;
    
    document.getElementById('htmlPreview').innerHTML=ph;
    document.getElementById('htmlCode').textContent=hc;
    document.getElementById('htmlSourceSection').style.display='block';
    
    return hc;
}

async function copyHtmlSource(){
    const hc=document.getElementById('htmlCode').textContent;
    const cb=document.querySelector('.copy-btn');
    
    try{
        await navigator.clipboard.writeText(hc);
        const ot=cb.textContent;
        cb.textContent='✅ 복사됨!';
        cb.classList.add('copied');
        setTimeout(()=>{
            cb.textContent=ot;
            cb.classList.remove('copied');
        },2000);
    }catch(e){
        showError('복사 실패','클립보드 접근 권한이 없습니다.');
    }
}

function collectUserInputDetails(){
    const d={},sp={},ef={},us={},be={},av=[];
    
    addIfNotEmpty(sp,'main_function','main_function');
    addIfNotEmpty(sp,'size_capacity','size_capacity');
    addIfNotEmpty(sp,'color','color');
    addIfNotEmpty(sp,'material','material');
    addIfNotEmpty(sp,'power_battery','power_battery');
    if(Object.keys(sp).length>0)d.specs=sp;
    
    addIfNotEmpty(ef,'problem_solving','problem_solving');
    addIfNotEmpty(ef,'time_saving','time_saving');
    addIfNotEmpty(ef,'space_efficiency','space_efficiency');
    addIfNotEmpty(ef,'cost_saving','cost_saving');
    if(Object.keys(ef).length>0)d.efficiency=ef;
    
    addIfNotEmpty(us,'usage_location','usage_location');
    addIfNotEmpty(us,'usage_frequency','usage_frequency');
    addIfNotEmpty(us,'target_users','target_users');
    addIfNotEmpty(us,'usage_method','usage_method');
    if(Object.keys(us).length>0)d.usage=us;
    
    ['advantage1','advantage2','advantage3'].forEach(id=>{
        const v=document.getElementById(id)?.value.trim();
        if(v)av.push(v);
    });
    if(av.length>0)be.advantages=av;
    
    addIfNotEmpty(be,'precautions','precautions');
    if(Object.keys(be).length>0)d.benefits=be;
    
    return d;
}

function addIfNotEmpty(o,k,e){
    const v=document.getElementById(e)?.value.trim();
    if(v)o[k]=v;
}

async function saveProduct(){
    const keyword=document.getElementById('keyword').value.trim();
    const productUrl=document.getElementById('productUrl').value.trim();
    
    // 키워드 필수 입력 검사
    if(!keyword){
        showValidationError('keyword', '키워드를 입력해주세요.\n\n키워드는 상품을 분류하고 검색하는데 필요한 필수 정보입니다.');
        return;
    }
    
    // 상품 URL 필수 입력 검사
    if(!productUrl){
        showValidationError('productUrl', '상품 URL을 입력해주세요.');
        return;
    }
    
    // 상품 분석 완료 여부 검사
    if(!currentProductData){
        alert('⚠️ 분석 필요\n\n먼저 상품을 분석해주세요.\n\n"🔍 분석" 버튼을 클릭하여 상품 정보를 불러온 후 저장할 수 있습니다.');
        document.getElementById('productUrl').focus();
        return;
    }
    
    const lo=document.getElementById('loadingOverlay');
    lo.style.display='flex';
    
    const userData=collectUserInputDetails();
    
    const saveData={
        id:Date.now().toString(),
        keyword:keyword,
        product_url:productUrl,
        product_data:currentProductData,
        user_details:userData,
        generated_html:generatedHtml,
        created_at:new Date().toISOString(),
        status:'saved'
    };
    
    try{
        const r=await fetch('product_save_handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                action:'save',
                data:saveData
            })
        });
        
        const rs=await r.json();
        
        if(rs.success){
            showSuccessModal('저장 완료!','상품이 성공적으로 저장되었습니다.');
        }else{
            showError('저장 실패',rs.message||'알 수 없는 오류가 발생했습니다.');
        }
    }catch(e){
        showError('저장 오류','상품 저장 중 오류가 발생했습니다.');
    }finally{
        lo.style.display='none';
    }
}

function resetForm(){
    document.getElementById('keyword').value='';
    document.getElementById('productUrl').value='';
    document.getElementById('analysisResult').classList.remove('show');
    document.getElementById('htmlSourceSection').style.display='none';
    
    ['main_function','size_capacity','color','material','power_battery',
     'problem_solving','time_saving','space_efficiency','cost_saving',
     'usage_location','usage_frequency','target_users','usage_method',
     'advantage1','advantage2','advantage3','precautions'].forEach(id=>{
        document.getElementById(id).value='';
    });
    
    // 오류 스타일 제거
    document.querySelectorAll('.error').forEach(el => {
        el.classList.remove('error');
    });
    
    currentProductData=null;
    generatedHtml=null;
    
    document.getElementById('keyword').focus();
}

// 키보드 단축키
document.addEventListener('keydown',function(e){
    if(e.ctrlKey&&e.key==='s'){
        e.preventDefault();
        saveProduct();
    }
});
</script>
</body>
</html>