<?php
/**
 * 저장된 상품 관리 페이지
 * 저장된 상품들을 리스트 형태로 표시하고 편집/삭제/구글시트 내보내기 기능 제공
 */
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
if(!current_user_can('manage_options'))wp_die('접근 권한이 없습니다.');

$success_message='';$error_message='';
if(isset($_GET['success'])&&$_GET['success']=='1')$success_message='작업이 성공적으로 완료되었습니다!';
if(isset($_GET['error']))$error_message='오류: '.urldecode($_GET['error']);
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>저장된 상품 관리 - 노바센트</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;padding:20px;background:#f5f5f5;color:#1c1c1c}
.main-container{max-width:1400px;margin:0 auto;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);overflow:hidden}
.header-section{padding:30px;border-bottom:1px solid #e0e0e0;background:linear-gradient(135deg,#2196F3 0%,#1976D2 100%);color:white}
.header-section h1{margin:0 0 10px 0;font-size:28px}
.header-section .subtitle{margin:0 0 20px 0;opacity:0.9}
.stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-top:20px}
.stat-card{background:rgba(255,255,255,0.1);padding:20px;border-radius:8px;text-align:center}
.stat-number{font-size:32px;font-weight:bold;margin-bottom:5px}
.stat-label{font-size:14px;opacity:0.9}
.nav-links{display:flex;gap:10px;margin-top:20px;align-items:center}
.nav-link{background:rgba(255,255,255,0.2);color:white;padding:8px 16px;border-radius:4px;text-decoration:none;font-size:14px;transition:all 0.3s}
.nav-link:hover{background:rgba(255,255,255,0.3);color:white}
.main-content{padding:30px}
.controls-section{margin-bottom:30px;padding:20px;background:#f8f9fa;border-radius:8px}
.controls-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px}
.search-group{display:flex;gap:10px;align-items:center}
.search-group input{padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;width:300px}
.action-group{display:flex;gap:10px}
.btn{padding:10px 16px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.3s;text-decoration:none;display:inline-block}
.btn-primary{background:#007bff;color:white}.btn-primary:hover{background:#0056b3}
.btn-secondary{background:#6c757d;color:white}.btn-secondary:hover{background:#545b62}
.btn-success{background:#28a745;color:white}.btn-success:hover{background:#1e7e34}
.btn-danger{background:#dc3545;color:white}.btn-danger:hover{background:#c82333}
.btn-warning{background:#ffc107;color:#212529}.btn-warning:hover{background:#e0a800}
.btn-info{background:#17a2b8;color:white}.btn-info:hover{background:#138496}
.btn-small{padding:6px 12px;font-size:12px}
.products-table{width:100%;border-collapse:collapse;margin-top:20px;background:white;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1)}
.products-table th,.products-table td{padding:15px;text-align:left;border-bottom:1px solid #e0e0e0}
.products-table th{background:#f8f9fa;font-weight:600;color:#333;position:sticky;top:0;z-index:10}
.products-table tr:hover{background:#f8f9fa}
.product-image{width:80px;height:80px;object-fit:cover;border-radius:8px;cursor:pointer}
.product-title{max-width:300px;font-weight:600;color:#333;line-height:1.4}
.product-title a{color:#007bff;text-decoration:none}
.product-title a:hover{text-decoration:underline}
.product-price{font-size:18px;font-weight:bold;color:#e91e63}
.product-keyword{background:#e3f2fd;color:#1976d2;padding:4px 8px;border-radius:4px;font-size:12px;display:inline-block}
.product-actions{display:flex;gap:5px}
.created-date{color:#666;font-size:12px}
.checkbox-col{width:50px;text-align:center}
.image-col{width:100px}
.title-col{width:300px}
.price-col{width:100px}
.keyword-col{width:120px}
.date-col{width:120px}
.actions-col{width:150px}
.empty-state{text-align:center;padding:60px 20px;color:#666}
.empty-state h3{margin:0 0 10px 0;color:#999}
.loading{text-align:center;padding:40px;color:#666}
.loading-spinner{display:inline-block;width:32px;height:32px;border:3px solid #f3f3f3;border-top:3px solid #2196F3;border-radius:50%;animation:spin 1s linear infinite;margin-bottom:20px}
@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
.modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000;display:none;align-items:center;justify-content:center}
.modal-content{background:white;border-radius:12px;padding:30px;max-width:800px;max-height:80vh;overflow-y:auto;box-shadow:0 10px 30px rgba(0,0,0,0.3)}
.modal-header{margin-bottom:20px;padding-bottom:15px;border-bottom:2px solid #f0f0f0}
.modal-header h3{margin:0;color:#333}
.modal-body{margin-bottom:20px}
.modal-footer{display:flex;justify-content:flex-end;gap:10px}
.alert{padding:15px;border-radius:6px;margin-bottom:20px}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
.alert-info{background:#d1ecf1;color:#0c5460;border:1px solid #bee5eb}
.alert-warning{background:#fff3cd;color:#856404;border:1px solid #ffeaa7}
.export-info{margin-top:15px;text-align:center}
.sheets-url{margin-top:15px;padding:15px;background:#f8f9fa;border-radius:8px;border:1px solid #e9ecef}
.sheets-url a{color:#007bff;text-decoration:none;font-weight:600}
.sheets-url a:hover{text-decoration:underline}
.pagination{display:flex;justify-content:center;align-items:center;gap:10px;margin-top:30px}
.pagination button{padding:8px 12px;border:1px solid #ddd;background:white;cursor:pointer;border-radius:4px}
.pagination button:hover{background:#f8f9fa}
.pagination button.active{background:#007bff;color:white;border-color:#007bff}
.pagination button:disabled{opacity:0.5;cursor:not-allowed}
.bulk-actions{margin-top:15px;padding:15px;background:#fff3cd;border:1px solid #ffeaa7;border-radius:6px;display:none}
.bulk-actions.show{display:block}
.sort-header{cursor:pointer;user-select:none;position:relative}
.sort-header:hover{background:#e9ecef}
.sort-header.asc::after{content:' ↑';position:absolute;right:5px}
.sort-header.desc::after{content:' ↓';position:absolute;right:5px}
.sheets-actions{margin-top:20px;text-align:center;padding:20px;background:#f0f8ff;border-radius:8px;border:1px solid #b3d9ff}
.sheets-actions h4{margin:0 0 15px 0;color:#0066cc}
.debug-info{display:none;padding:10px;background:#f0f0f0;border:1px solid #ddd;margin-top:10px;font-size:12px;font-family:monospace}
</style>
</head>
<body>
<div class="modal" id="previewModal">
<div class="modal-content">
<div class="modal-header">
<h3>상품 미리보기</h3>
</div>
<div class="modal-body" id="previewContent">
</div>
<div class="modal-footer">
<button class="btn btn-secondary" onclick="closeModal('previewModal')">닫기</button>
</div>
</div>
</div>
<div class="modal" id="exportModal">
<div class="modal-content">
<div class="modal-header">
<h3>📊 구글 시트로 내보내기</h3>
</div>
<div class="modal-body">
<div class="alert alert-info">
선택된 <span id="exportCount">0</span>개의 상품을 구글 시트 '상품 발굴 데이터'에 저장합니다.
</div>
<div class="export-info">
<p><strong>내보내기 후</strong> 구글 시트에서 데이터를 확인하고 엑셀로 다운로드할 수 있습니다.</p>
</div>
<div class="sheets-url" id="sheetsUrlSection" style="display:none;">
<p><strong>구글 시트 URL:</strong></p>
<a id="sheetsUrl" href="#" target="_blank">구글 시트에서 보기</a>
</div>
</div>
<div class="modal-footer">
<button class="btn btn-secondary" onclick="closeModal('exportModal')">취소</button>
<button class="btn btn-success" onclick="confirmExportToSheets()" id="exportToSheetsBtn">📊 구글 시트로 내보내기</button>
</div>
</div>
</div>
<div class="main-container">
<div class="header-section">
<h1>📋 저장된 상품 관리</h1>
<p class="subtitle">발굴한 상품들을 관리하고 구글 시트로 내보내세요</p>
<?php if(!empty($success_message)):?>
<div class="alert alert-success"><?php echo esc_html($success_message);?></div>
<?php endif;?>
<?php if(!empty($error_message)):?>
<div class="alert alert-error"><?php echo esc_html($error_message);?></div>
<?php endif;?>
<div class="stats-row">
<div class="stat-card">
<div class="stat-number" id="totalCount">0</div>
<div class="stat-label">전체 상품</div>
</div>
<div class="stat-card">
<div class="stat-number" id="keywordCount">0</div>
<div class="stat-label">키워드 수</div>
</div>
<div class="stat-card">
<div class="stat-number" id="todayCount">0</div>
<div class="stat-label">오늘 저장</div>
</div>
</div>
<div class="nav-links">
<a href="product_save.php" class="nav-link">➕ 상품 추가</a>
<a href="affiliate_editor.php" class="nav-link">✍️ 상품 글 작성</a>
<button class="nav-link" onclick="openGoogleSheets()" style="border:none;background:rgba(255,255,255,0.2);">📊 구글 시트 보기</button>
</div>
</div>
<div class="main-content">
<div class="sheets-actions">
<h4>📊 구글 시트 관리</h4>
<button class="btn btn-primary" onclick="openGoogleSheets()">구글 시트 보기</button>
<button class="btn btn-success" onclick="syncAllToSheets()">전체 데이터 동기화</button>
</div>
<div class="controls-section">
<div class="controls-row">
<div class="search-group">
<input type="text" id="searchInput" placeholder="키워드나 상품명으로 검색...">
<button class="btn btn-primary" onclick="searchProducts()">🔍 검색</button>
<button class="btn btn-secondary" onclick="clearSearch()">초기화</button>
</div>
<div class="action-group">
<button class="btn btn-info" onclick="exportToExcel()" id="excelBtn" disabled>📥 엑셀 다운로드</button>
<button class="btn btn-success" onclick="exportSelected()" id="exportBtn" disabled>📊 구글 시트로 내보내기</button>
<button class="btn btn-danger" onclick="deleteSelected()" id="deleteBtn" disabled>🗑️ 삭제</button>
</div>
</div>
<div class="bulk-actions" id="bulkActions">
<strong>선택된 항목:</strong> <span id="selectedCount">0</span>개 |
<button class="btn btn-small btn-info" onclick="exportToExcel()">엑셀 다운로드</button>
<button class="btn btn-small btn-success" onclick="exportSelected()">구글 시트로 내보내기</button>
<button class="btn btn-small btn-danger" onclick="deleteSelected()">삭제</button>
<button class="btn btn-small btn-secondary" onclick="clearSelection()">선택 해제</button>
</div>
</div>
<div id="loadingSection" class="loading">
<div class="loading-spinner"></div>
<p>데이터를 불러오는 중...</p>
</div>
<div id="productsSection" style="display:none;">
<table class="products-table">
<thead>
<tr>
<th class="checkbox-col">
<input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
</th>
<th class="image-col">이미지</th>
<th class="title-col sort-header" onclick="sortTable('title')">상품명</th>
<th class="price-col sort-header" onclick="sortTable('price')">가격</th>
<th class="keyword-col sort-header" onclick="sortTable('keyword')">키워드</th>
<th class="date-col sort-header" onclick="sortTable('date')">저장일</th>
<th class="actions-col">작업</th>
</tr>
</thead>
<tbody id="productsTableBody">
</tbody>
</table>
<div class="pagination" id="pagination"></div>
</div>
<div id="emptySection" class="empty-state" style="display:none;">
<h3>📦 저장된 상품이 없습니다</h3>
<p>상품을 발굴하여 저장해보세요!</p>
<a href="product_save.php" class="btn btn-primary">상품 추가하기</a>
</div>
<div class="debug-info" id="debugInfo"></div>
</div>
</div>
<script>
let products=[];
let filteredProducts=[];
let selectedProducts=new Set();
let currentSort={field:null,direction:'asc'};
let currentPage=1;
const itemsPerPage=20;

// URL 정규화 함수 - 이중 슬래시 제거
function normalizeUrl(url) {
    if (!url) return '';
    
    // 프로토콜 부분은 보존하고 나머지 부분의 이중 슬래시 제거
    return url.replace(/([^:]\/)\/+/g, '$1');
}

// HTML 이스케이프 함수
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded',function(){
    loadProducts();
    
    // 검색 엔터키 처리
    document.getElementById('searchInput').addEventListener('keypress',function(e){
        if(e.key==='Enter')searchProducts();
    });
    
    // 모달 외부 클릭 시 닫기
    document.querySelectorAll('.modal').forEach(modal=>{
        modal.addEventListener('click',function(e){
            if(e.target===modal)closeModal(modal.id);
        });
    });
});

async function loadProducts(){
    try{
        const r=await fetch('product_save_handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'load'})
        });
        
        const rs=await r.json();
        
        if(rs.success){
            products=rs.data;
            filteredProducts=[...products];
            updateStats();
            renderTable();
            document.getElementById('loadingSection').style.display='none';
            
            if(products.length>0){
                document.getElementById('productsSection').style.display='block';
            }else{
                document.getElementById('emptySection').style.display='block';
            }
        }else{
            throw new Error(rs.message);
        }
    }catch(e){
        console.error('데이터 로드 오류:',e);
        alert('데이터를 불러오는 중 오류가 발생했습니다.');
        document.getElementById('loadingSection').style.display='none';
        document.getElementById('emptySection').style.display='block';
    }
}

function updateStats(){
    document.getElementById('totalCount').textContent=products.length;
    
    const keywords=new Set();
    let todayCount=0;
    const today=new Date().toDateString();
    
    products.forEach(p=>{
        keywords.add(p.keyword);
        if(new Date(p.created_at).toDateString()===today)todayCount++;
    });
    
    document.getElementById('keywordCount').textContent=keywords.size;
    document.getElementById('todayCount').textContent=todayCount;
}

function renderTable(){
    const tbody=document.getElementById('productsTableBody');
    const startIndex=(currentPage-1)*itemsPerPage;
    const endIndex=startIndex+itemsPerPage;
    const pageProducts=filteredProducts.slice(startIndex,endIndex);
    
    if(pageProducts.length===0){
        tbody.innerHTML='<tr><td colspan="7" class="empty-state"><h3>검색 결과가 없습니다</h3></td></tr>';
        document.getElementById('pagination').innerHTML='';
        return;
    }
    
    tbody.innerHTML=pageProducts.map(p=>{
        // URL 우선순위: 사용자 입력 원본(product_url) → 분석 결과(product_data.url) → 기타
        let productUrl = p.product_url || p.product_data?.url || p.url || '';
        
        // URL 정규화 (이중 슬래시 제거)
        productUrl = normalizeUrl(productUrl);
        
        // 디버그 정보 출력
        console.log('Product URL data:', {
            id: p.id,
            product_url: p.product_url,
            product_data_url: p.product_data?.url,
            url: p.url,
            normalized_url: productUrl
        });
        
        return `
        <tr>
            <td class="checkbox-col">
                <input type="checkbox" value="${escapeHtml(p.id)}" onchange="toggleProductSelection('${escapeHtml(p.id)}')" ${selectedProducts.has(p.id)?'checked':''}>
            </td>
            <td class="image-col">
                <img src="${escapeHtml(p.product_data?.image_url||'/tools/images/no-image.png')}" alt="${escapeHtml(p.product_data?.title||'상품 이미지')}" class="product-image" onclick="previewProduct('${escapeHtml(p.id)}')" onerror="this.src='/tools/images/no-image.png'">
            </td>
            <td class="title-col">
                <div class="product-title">
                    <a href="${escapeHtml(productUrl)}" target="_blank">${escapeHtml(p.product_data?.title||'제목 없음')}</a>
                </div>
            </td>
            <td class="price-col">
                <div class="product-price">${escapeHtml(p.product_data?.price||'가격 정보 없음')}</div>
            </td>
            <td class="keyword-col">
                <span class="product-keyword">${escapeHtml(p.keyword)}</span>
            </td>
            <td class="date-col">
                <div class="created-date">${formatDate(p.created_at)}</div>
            </td>
            <td class="actions-col">
                <div class="product-actions">
                    <button class="btn btn-small btn-primary" onclick="previewProduct('${escapeHtml(p.id)}')" title="미리보기">👁️</button>
                    <button class="btn btn-small btn-warning" onclick="editProduct('${escapeHtml(p.id)}')" title="수정">✏️</button>
                    <button class="btn btn-small btn-danger" onclick="deleteProduct('${escapeHtml(p.id)}')" title="삭제">🗑️</button>
                </div>
            </td>
        </tr>
        `;
    }).join('');
    
    renderPagination();
}

function renderPagination(){
    const totalPages=Math.ceil(filteredProducts.length/itemsPerPage);
    const pagination=document.getElementById('pagination');
    
    if(totalPages<=1){
        pagination.innerHTML='';
        return;
    }
    
    let html='';
    
    // 이전 버튼
    html+=`<button onclick="changePage(${currentPage-1})" ${currentPage===1?'disabled':''}>이전</button>`;
    
    // 페이지 번호
    const startPage=Math.max(1,currentPage-2);
    const endPage=Math.min(totalPages,currentPage+2);
    
    for(let i=startPage;i<=endPage;i++){
        html+=`<button onclick="changePage(${i})" class="${i===currentPage?'active':''}">${i}</button>`;
    }
    
    // 다음 버튼
    html+=`<button onclick="changePage(${currentPage+1})" ${currentPage===totalPages?'disabled':''}>다음</button>`;
    
    pagination.innerHTML=html;
}

function changePage(page){
    const totalPages=Math.ceil(filteredProducts.length/itemsPerPage);
    if(page<1||page>totalPages)return;
    
    currentPage=page;
    renderTable();
}

function formatDate(dateString){
    const date=new Date(dateString);
    return date.toLocaleDateString('ko-KR',{
        year:'numeric',
        month:'2-digit',
        day:'2-digit',
        hour:'2-digit',
        minute:'2-digit'
    });
}

function searchProducts(){
    const query=document.getElementById('searchInput').value.toLowerCase().trim();
    
    if(!query){
        filteredProducts=[...products];
    }else{
        filteredProducts=products.filter(p=>
            p.keyword.toLowerCase().includes(query)||
            (p.product_data.title&&p.product_data.title.toLowerCase().includes(query))
        );
    }
    
    currentPage=1;
    renderTable();
}

function clearSearch(){
    document.getElementById('searchInput').value='';
    filteredProducts=[...products];
    currentPage=1;
    renderTable();
}

function sortTable(field){
    if(currentSort.field===field){
        currentSort.direction=currentSort.direction==='asc'?'desc':'asc';
    }else{
        currentSort.field=field;
        currentSort.direction='asc';
    }
    
    // 정렬 헤더 UI 업데이트
    document.querySelectorAll('.sort-header').forEach(h=>{
        h.classList.remove('asc','desc');
    });
    document.querySelector(`[onclick="sortTable('${field}')"]`).classList.add(currentSort.direction);
    
    filteredProducts.sort((a,b)=>{
        let aVal,bVal;
        
        switch(field){
            case'title':
                aVal=(a.product_data.title||'').toLowerCase();
                bVal=(b.product_data.title||'').toLowerCase();
                break;
            case'price':
                aVal=parseFloat((a.product_data.price||'0').replace(/[^\d.]/g,''))||0;
                bVal=parseFloat((b.product_data.price||'0').replace(/[^\d.]/g,''))||0;
                break;
            case'keyword':
                aVal=a.keyword.toLowerCase();
                bVal=b.keyword.toLowerCase();
                break;
            case'date':
                aVal=new Date(a.created_at);
                bVal=new Date(b.created_at);
                break;
            default:
                return 0;
        }
        
        if(aVal<bVal)return currentSort.direction==='asc'?-1:1;
        if(aVal>bVal)return currentSort.direction==='asc'?1:-1;
        return 0;
    });
    
    currentPage=1;
    renderTable();
}

function toggleSelectAll(){
    const selectAll=document.getElementById('selectAll').checked;
    const checkboxes=document.querySelectorAll('tbody input[type="checkbox"]');
    
    checkboxes.forEach(cb=>{
        cb.checked=selectAll;
        if(selectAll)selectedProducts.add(cb.value);
        else selectedProducts.delete(cb.value);
    });
    
    updateSelectionUI();
}

function toggleProductSelection(id){
    if(selectedProducts.has(id)){
        selectedProducts.delete(id);
    }else{
        selectedProducts.add(id);
    }
    
    updateSelectionUI();
}

function updateSelectionUI(){
    const count=selectedProducts.size;
    const bulkActions=document.getElementById('bulkActions');
    const exportBtn=document.getElementById('exportBtn');
    const deleteBtn=document.getElementById('deleteBtn');
    const excelBtn=document.getElementById('excelBtn');
    
    if(count>0){
        bulkActions.classList.add('show');
        exportBtn.disabled=false;
        deleteBtn.disabled=false;
        excelBtn.disabled=false;
        document.getElementById('selectedCount').textContent=count;
    }else{
        bulkActions.classList.remove('show');
        exportBtn.disabled=true;
        deleteBtn.disabled=true;
        excelBtn.disabled=true;
    }
    
    // 전체 선택 체크박스 상태 업데이트
    const visibleCheckboxes=document.querySelectorAll('tbody input[type="checkbox"]');
    const checkedCount=Array.from(visibleCheckboxes).filter(cb=>cb.checked).length;
    document.getElementById('selectAll').checked=visibleCheckboxes.length>0&&checkedCount===visibleCheckboxes.length;
}

function clearSelection(){
    selectedProducts.clear();
    document.querySelectorAll('input[type="checkbox"]').forEach(cb=>cb.checked=false);
    updateSelectionUI();
}

function previewProduct(id){
    const product=products.find(p=>p.id===id);
    if(!product)return;
    
    // URL 우선순위: 사용자 입력 원본(product_url) → 분석 결과(product_data.url) → 기타
    let productUrl = product.product_url || product.product_data?.url || product.url || '';
    productUrl = normalizeUrl(productUrl);
    
    const content=document.getElementById('previewContent');
    content.innerHTML=`
        <div style="margin-bottom:20px;">
            <h4>${escapeHtml(product.product_data?.title||'제목 없음')}</h4>
            <p><strong>키워드:</strong> ${escapeHtml(product.keyword)}</p>
            <p><strong>가격:</strong> ${escapeHtml(product.product_data?.price||'가격 정보 없음')}</p>
            <p><strong>URL:</strong> <a href="${escapeHtml(productUrl)}" target="_blank">${escapeHtml(productUrl||'URL 없음')}</a></p>
            <p><strong>저장일:</strong> ${formatDate(product.created_at)}</p>
        </div>
        <div style="max-height:400px;overflow-y:auto;">
            ${product.generated_html||'<p>생성된 HTML이 없습니다.</p>'}
        </div>
    `;
    
    document.getElementById('previewModal').style.display='flex';
}

function editProduct(id){
    alert('상품 편집 기능은 준비 중입니다.');
}

async function deleteProduct(id){
    if(!confirm('이 상품을 삭제하시겠습니까?'))return;
    
    try{
        const r=await fetch('product_save_handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'delete',id:id})
        });
        
        const rs=await r.json();
        
        if(rs.success){
            alert('상품이 삭제되었습니다.');
            loadProducts();
        }else{
            throw new Error(rs.message);
        }
    }catch(e){
        alert('삭제 중 오류가 발생했습니다: '+e.message);
    }
}

function exportSelected(){
    if(selectedProducts.size===0){
        alert('내보낼 상품을 선택해주세요.');
        return;
    }
    
    document.getElementById('exportCount').textContent=selectedProducts.size;
    document.getElementById('sheetsUrlSection').style.display='none';
    document.getElementById('exportModal').style.display='flex';
}

async function confirmExportToSheets(){
    const selectedIds=Array.from(selectedProducts);
    const btn=document.getElementById('exportToSheetsBtn');
    const originalText=btn.textContent;
    
    btn.disabled=true;
    btn.textContent='내보내는 중...';
    
    try{
        const r=await fetch('product_save_handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'export_to_sheets',ids:selectedIds})
        });
        
        const rs=await r.json();
        
        if(rs.success){
            // 구글 시트 URL 표시
            document.getElementById('sheetsUrl').href=rs.spreadsheet_url;
            document.getElementById('sheetsUrl').textContent='구글 시트에서 확인하기';
            document.getElementById('sheetsUrlSection').style.display='block';
            
            btn.textContent=originalText;
            btn.disabled=false;
            
            alert(`${rs.rows_added}개의 상품이 구글 시트에 저장되었습니다!`);
        }else{
            throw new Error(rs.message);
        }
    }catch(e){
        alert('구글 시트 내보내기 중 오류가 발생했습니다: '+e.message);
        btn.textContent=originalText;
        btn.disabled=false;
    }
}

async function openGoogleSheets(){
    try{
        const r=await fetch('product_save_handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'get_sheets_url'})
        });
        
        const rs=await r.json();
        
        if(rs.success){
            window.open(rs.spreadsheet_url,'_blank');
        }else{
            throw new Error(rs.message);
        }
    }catch(e){
        alert('구글 시트를 열 수 없습니다: '+e.message);
    }
}

async function syncAllToSheets(){
    if(!confirm('모든 데이터를 구글 시트에 동기화하시겠습니까?'))return;
    
    try{
        const r=await fetch('product_save_handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'sync_to_sheets'})
        });
        
        const rs=await r.json();
        
        if(rs.success){
            alert(`${rs.rows_added}개의 상품이 구글 시트에 동기화되었습니다!`);
            window.open(rs.spreadsheet_url,'_blank');
        }else{
            throw new Error(rs.message);
        }
    }catch(e){
        alert('동기화 중 오류가 발생했습니다: '+e.message);
    }
}

async function deleteSelected(){
    if(selectedProducts.size===0){
        alert('삭제할 상품을 선택해주세요.');
        return;
    }
    
    if(!confirm(`선택된 ${selectedProducts.size}개의 상품을 삭제하시겠습니까?`))return;
    
    const deletePromises=Array.from(selectedProducts).map(id=>
        fetch('product_save_handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'delete',id:id})
        })
    );
    
    try{
        await Promise.all(deletePromises);
        alert('선택된 상품들이 삭제되었습니다.');
        clearSelection();
        loadProducts();
    }catch(e){
        alert('삭제 중 오류가 발생했습니다.');
    }
}

// 엑셀 다운로드 기능
function exportToExcel(){
    if(selectedProducts.size===0){
        alert('엑셀로 다운로드할 상품을 선택해주세요.');
        return;
    }
    
    // 선택된 상품 데이터 가져오기
    const selectedData=products.filter(p=>selectedProducts.has(p.id));
    
    // CSV 헤더 정의
    const headers=[
        'ID','키워드','상품명','가격','평점','판매량','이미지URL','상품URL','어필리에이트링크','생성일시',
        '주요기능','크기/용량','색상','재질/소재','전원/배터리',
        '해결하는문제','시간절약','공간활용','비용절감',
        '사용장소','사용빈도','적합한사용자','사용법',
        '장점1','장점2','장점3','주의사항'
    ];
    
    // CSV 데이터 생성
    let csvContent='\uFEFF'; // UTF-8 BOM 추가
    csvContent+=headers.join(',')+'\n';
    
    selectedData.forEach(product=>{
        const row=[];
        
        // URL 우선순위: 사용자 입력 원본(product_url) → 분석 결과(product_data.url) → 기타
        let productUrl = product.product_url || product.product_data?.url || product.url || '';
        productUrl = normalizeUrl(productUrl);
        
        // 기본 정보
        row.push(product.id);
        row.push(product.keyword);
        row.push(product.product_data?.title||'');
        row.push(product.product_data?.price||'');
        row.push(product.product_data?.rating_display||'');
        row.push(product.product_data?.lastest_volume||'');
        row.push(product.product_data?.image_url||'');
        row.push(productUrl);
        row.push(product.product_data?.affiliate_link||'');
        row.push(product.created_at||'');
        
        // 기능/스펙
        const specs=product.user_details?.specs||{};
        row.push(specs.main_function||'');
        row.push(specs.size_capacity||'');
        row.push(specs.color||'');
        row.push(specs.material||'');
        row.push(specs.power_battery||'');
        
        // 효율성
        const efficiency=product.user_details?.efficiency||{};
        row.push(efficiency.problem_solving||'');
        row.push(efficiency.time_saving||'');
        row.push(efficiency.space_efficiency||'');
        row.push(efficiency.cost_saving||'');
        
        // 사용법
        const usage=product.user_details?.usage||{};
        row.push(usage.usage_location||'');
        row.push(usage.usage_frequency||'');
        row.push(usage.target_users||'');
        row.push(usage.usage_method||'');
        
        // 장점/주의사항
        const benefits=product.user_details?.benefits||{};
        const advantages=benefits.advantages||[];
        row.push(advantages[0]||'');
        row.push(advantages[1]||'');
        row.push(advantages[2]||'');
        row.push(benefits.precautions||'');
        
        // CSV 형식으로 변환 (쉼표와 줄바꿈 처리)
        const csvRow=row.map(cell=>{
            const cellStr=String(cell).replace(/"/g,'""');
            return cellStr.includes(',')||cellStr.includes('\n')?`"${cellStr}"`:cellStr;
        });
        
        csvContent+=csvRow.join(',')+'\n';
    });
    
    // 다운로드 실행
    const blob=new Blob([csvContent],{type:'text/csv;charset=utf-8;'});
    const url=URL.createObjectURL(blob);
    const link=document.createElement('a');
    link.href=url;
    link.download=`상품_발굴_데이터_${new Date().toISOString().slice(0,10)}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    
    alert(`${selectedProducts.size}개의 상품이 엑셀 파일로 다운로드되었습니다.`);
}

function closeModal(modalId){
    document.getElementById(modalId).style.display='none';
}
</script>
</body>
</html>