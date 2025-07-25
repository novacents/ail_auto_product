<?php
/**
 * 저장된 상품 관리 페이지
 * 저장된 상품들을 리스트 형태로 표시하고 편집/삭제/내보내기 기능 제공
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
.export-options{margin-top:15px}
.export-options label{display:block;margin-bottom:10px;font-weight:600}
.export-options select{width:100%;padding:10px;border:1px solid #ddd;border-radius:6px}
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
<h3>내보내기 옵션</h3>
</div>
<div class="modal-body">
<div class="export-options">
<label>내보내기 형식:</label>
<select id="exportFormat">
<option value="csv">CSV 파일</option>
<option value="excel">엑셀 파일 (.xlsx)</option>
<option value="json">JSON 파일</option>
</select>
</div>
<div class="alert alert-info">
선택된 <span id="exportCount">0</span>개의 상품을 내보냅니다.
</div>
</div>
<div class="modal-footer">
<button class="btn btn-secondary" onclick="closeModal('exportModal')">취소</button>
<button class="btn btn-success" onclick="confirmExport()">내보내기</button>
</div>
</div>
</div>
<div class="main-container">
<div class="header-section">
<h1>📋 저장된 상품 관리</h1>
<p class="subtitle">발굴한 상품들을 관리하고 정리하여 활용하세요</p>
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
</div>
</div>
<div class="main-content">
<div class="controls-section">
<div class="controls-row">
<div class="search-group">
<input type="text" id="searchInput" placeholder="키워드나 상품명으로 검색...">
<button class="btn btn-primary" onclick="searchProducts()">🔍 검색</button>
<button class="btn btn-secondary" onclick="clearSearch()">초기화</button>
</div>
<div class="action-group">
<button class="btn btn-success" onclick="exportSelected()" id="exportBtn" disabled>📤 내보내기</button>
<button class="btn btn-danger" onclick="deleteSelected()" id="deleteBtn" disabled>🗑️ 삭제</button>
</div>
</div>
<div class="bulk-actions" id="bulkActions">
<strong>선택된 항목:</strong> <span id="selectedCount">0</span>개 |
<button class="btn btn-small btn-success" onclick="exportSelected()">내보내기</button>
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
</div>
</div>
<script>
let products=[];
let filteredProducts=[];
let selectedProducts=new Set();
let currentSort={field:null,direction:'asc'};
let currentPage=1;
const itemsPerPage=20;

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
    
    tbody.innerHTML=pageProducts.map(p=>`
        <tr>
            <td class="checkbox-col">
                <input type="checkbox" value="${p.id}" onchange="toggleProductSelection('${p.id}')" ${selectedProducts.has(p.id)?'checked':''}>
            </td>
            <td class="image-col">
                <img src="${p.product_data.image_url||'/tools/images/no-image.png'}" alt="${p.product_data.title||'상품 이미지'}" class="product-image" onclick="previewProduct('${p.id}')" onerror="this.src='/tools/images/no-image.png'">
            </td>
            <td class="title-col">
                <div class="product-title">
                    <a href="${p.product_url}" target="_blank">${p.product_data.title||'제목 없음'}</a>
                </div>
            </td>
            <td class="price-col">
                <div class="product-price">${p.product_data.price||'가격 정보 없음'}</div>
            </td>
            <td class="keyword-col">
                <span class="product-keyword">${p.keyword}</span>
            </td>
            <td class="date-col">
                <div class="created-date">${formatDate(p.created_at)}</div>
            </td>
            <td class="actions-col">
                <div class="product-actions">
                    <button class="btn btn-small btn-primary" onclick="previewProduct('${p.id}')" title="미리보기">👁️</button>
                    <button class="btn btn-small btn-warning" onclick="editProduct('${p.id}')" title="수정">✏️</button>
                    <button class="btn btn-small btn-danger" onclick="deleteProduct('${p.id}')" title="삭제">🗑️</button>
                </div>
            </td>
        </tr>
    `).join('');
    
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
    
    if(count>0){
        bulkActions.classList.add('show');
        exportBtn.disabled=false;
        deleteBtn.disabled=false;
        document.getElementById('selectedCount').textContent=count;
    }else{
        bulkActions.classList.remove('show');
        exportBtn.disabled=true;
        deleteBtn.disabled=true;
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
    
    const content=document.getElementById('previewContent');
    content.innerHTML=`
        <div style="margin-bottom:20px;">
            <h4>${product.product_data.title||'제목 없음'}</h4>
            <p><strong>키워드:</strong> ${product.keyword}</p>
            <p><strong>가격:</strong> ${product.product_data.price||'가격 정보 없음'}</p>
            <p><strong>저장일:</strong> ${formatDate(product.created_at)}</p>
        </div>
        <div style="max-height:400px;overflow-y:auto;">
            ${product.generated_html||'<p>생성된 HTML이 없습니다.</p>'}
        </div>
    `;
    
    document.getElementById('previewModal').style.display='flex';
}

function editProduct(id){
    // 상품 편집은 나중에 구현
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
            loadProducts(); // 데이터 새로고침
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
    document.getElementById('exportModal').style.display='flex';
}

async function confirmExport(){
    const format=document.getElementById('exportFormat').value;
    const selectedIds=Array.from(selectedProducts);
    
    try{
        const r=await fetch('product_save_handler.php',{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({action:'export',ids:selectedIds,format:format})
        });
        
        const rs=await r.json();
        
        if(rs.success){
            // CSV 형태로 다운로드
            downloadData(rs.data,format);
            closeModal('exportModal');
            alert('내보내기가 완료되었습니다.');
        }else{
            throw new Error(rs.message);
        }
    }catch(e){
        alert('내보내기 중 오류가 발생했습니다: '+e.message);
    }
}

function downloadData(data,format){
    let content,filename,mimeType;
    
    switch(format){
        case'csv':
            content=convertToCSV(data);
            filename=`products_${new Date().toISOString().split('T')[0]}.csv`;
            mimeType='text/csv';
            break;
        case'json':
            content=JSON.stringify(data,null,2);
            filename=`products_${new Date().toISOString().split('T')[0]}.json`;
            mimeType='application/json';
            break;
        default:
            content=convertToCSV(data);
            filename=`products_${new Date().toISOString().split('T')[0]}.csv`;
            mimeType='text/csv';
    }
    
    const blob=new Blob([content],{type:mimeType});
    const url=URL.createObjectURL(blob);
    const a=document.createElement('a');
    a.href=url;
    a.download=filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function convertToCSV(data){
    const headers=['ID','키워드','상품명','가격','평점','판매량','이미지URL','상품URL','어필리에이트링크','생성일시'];
    const csvContent=[
        headers.join(','),
        ...data.map(row=>row.map(cell=>`"${String(cell).replace(/"/g,'""')}"`).join(','))
    ].join('\n');
    
    return'\ufeff'+csvContent; // UTF-8 BOM 추가
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
        loadProducts(); // 데이터 새로고침
    }catch(e){
        alert('삭제 중 오류가 발생했습니다.');
    }
}

function closeModal(modalId){
    document.getElementById(modalId).style.display='none';
}
</script>
</body>
</html>