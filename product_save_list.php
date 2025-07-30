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
.keyword-overview{margin-bottom:30px;padding:25px;background:#f8f9fa;border-radius:12px;border:1px solid #e0e0e0}
.keyword-overview h3{margin:0 0 20px 0;color:#333;font-size:18px;display:flex;align-items:center;gap:8px}
.keyword-controls{display:flex;gap:10px;margin-bottom:15px;align-items:center}
.keyword-sort{padding:6px 12px;border:1px solid #ddd;border-radius:6px;font-size:12px;background:white;cursor:pointer}
.keyword-toggle{background:#007bff;color:white;border:none;padding:8px 16px;border-radius:6px;font-size:12px;cursor:pointer;transition:background 0.3s}
.keyword-toggle:hover{background:#0056b3}
.keyword-toggle.collapsed{background:#6c757d}
.keyword-list{max-height:400px;overflow-y:auto;background:white;border-radius:8px;border:1px solid #e9ecef}
.keyword-list-header{background:#f8f9fa;padding:10px 15px;border-bottom:1px solid #e9ecef;font-weight:600;color:#333;position:sticky;top:0;z-index:5;display:grid;grid-template-columns:1fr auto 1fr auto 1fr auto;gap:20px;align-items:center}
.keyword-list-header span:nth-child(even){text-align:right;padding-right:20px}
.keyword-item-row{display:grid;grid-template-columns:1fr auto 1fr auto 1fr auto;gap:20px;align-items:center;border-bottom:1px solid #f0f0f0}
.keyword-item{display:flex;align-items:center;padding:10px 15px;transition:background 0.2s;cursor:pointer}
.keyword-item:hover{background:#f8f9fa}
.keyword-name{font-weight:500;color:#333;display:flex;align-items:center;gap:8px}
.keyword-count{background:#007bff;color:white;padding:4px 10px;border-radius:12px;font-size:12px;font-weight:600;min-width:25px;text-align:center;margin-right:20px}
.keyword-new-badge{background:#28a745;color:white;padding:2px 6px;border-radius:8px;font-size:9px;font-weight:600}
.controls-section{margin-bottom:30px;padding:20px;background:#f8f9fa;border-radius:8px}
.controls-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px}
.search-group{display:flex;gap:10px;align-items:center;flex:1;max-width:600px}
.search-group input{padding:10px;border:1px solid #ddd;border-radius:6px;font-size:14px;flex:1}
.action-group{display:flex;gap:10px}
.btn{padding:10px 16px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:all 0.3s;text-decoration:none;display:inline-block}
.btn-primary{background:#007bff;color:white}.btn-primary:hover{background:#0056b3}
.btn-secondary{background:#6c757d;color:white}.btn-secondary:hover{background:#545b62}
.btn-success{background:#28a745;color:white}.btn-success:hover{background:#1e7e34}
.btn-danger{background:#dc3545;color:white}.btn-danger:hover{background:#c82333}
.btn-warning{background:#ffc107;color:#212529}.btn-warning:hover{background:#e0a800}
.btn-info{background:#17a2b8;color:white}.btn-info:hover{background:#138496}
.btn-small{padding:6px 12px;font-size:12px}
.multi-search-section{margin-bottom:15px;padding:15px;background:#e8f5e9;border:1px solid #4caf50;border-radius:6px;display:none}
.multi-search-section.show{display:block}
.search-tags{margin-top:10px}
.search-tag{display:inline-block;background:#4caf50;color:white;padding:4px 12px;border-radius:16px;font-size:13px;margin:4px;position:relative}
.search-tag .remove{margin-left:8px;cursor:pointer;font-weight:bold}
.search-tag .remove:hover{color:#ff6b6b}
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
.product-keyword.highlighted{background:#ffc107;color:#212529;font-weight:bold}
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
<div class="keyword-overview">
<h3>🏷️ 키워드 현황</h3>
<div class="keyword-controls">
<select class="keyword-sort" id="keywordSort" onchange="sortKeywordList()">
<option value="name_asc">키워드명 오름차순</option>
<option value="name_desc">키워드명 내림차순</option>
<option value="count_desc">상품수 많은순</option>
<option value="count_asc">상품수 적은순</option>
<option value="recent">최근 추가순</option>
</select>
<button class="keyword-toggle" id="keywordToggle" onclick="toggleKeywordList()">키워드 목록 접기</button>
</div>
<div class="keyword-list" id="keywordList">
<div class="keyword-list-header">
<span>키워드명</span>
<span>상품수</span>
<span>키워드명</span>
<span>상품수</span>
<span>키워드명</span>
<span>상품수</span>
</div>
<div id="keywordListBody">
</div>
</div>
</div>
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
<button class="btn btn-warning" onclick="addToMultiSearch()" id="addSearchBtn" style="display:none;">➕ 추가검색</button>
<button class="btn btn-secondary" onclick="clearSearch()">초기화</button>
</div>
<div class="action-group">
<button class="btn btn-info" onclick="exportToExcel()" id="excelBtn" disabled>📥 엑셀 다운로드</button>
<button class="btn btn-success" onclick="exportSelected()" id="exportBtn" disabled>📊 구글 시트로 내보내기</button>
<button class="btn btn-danger" onclick="deleteSelected()" id="deleteBtn" disabled>🗑️ 삭제</button>
</div>
</div>
<div class="multi-search-section" id="multiSearchSection">
<strong>다중 키워드 검색 결과</strong> - <span id="searchResultInfo"></span>
<div class="search-tags" id="searchTags"></div>
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
</div>
</div>
<script>
let products=[];
let filteredProducts=[];
let selectedProducts=new Set();
let currentSort={field:null,direction:'asc'};
let currentPage=1;
const itemsPerPage=20;
let searchKeywords=new Set(); // 다중 검색을 위한 키워드 저장
let isMultiSearchMode=false; // 다중 검색 모드 여부
let keywordStats={}; // 키워드 통계 데이터

document.addEventListener('DOMContentLoaded',function(){
    loadProducts();
    
    // 검색 엔터키 처리
    document.getElementById('searchInput').addEventListener('keypress',function(e){
        if(e.key==='Enter'){
            searchProducts();
        }
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
            products=rs.data || [];
            filteredProducts=[...products];
            updateStats();
            updateKeywordOverview();
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
        if(p.keyword) keywords.add(p.keyword);
        if(new Date(p.created_at).toDateString()===today)todayCount++;
    });
    
    document.getElementById('keywordCount').textContent=keywords.size;
    document.getElementById('todayCount').textContent=todayCount;
}

function updateKeywordOverview(){
    // 키워드별 상품 수 계산
    keywordStats={};
    const today=new Date().toDateString();
    
    products.forEach(p=>{
        if(p.keyword){
            if(!keywordStats[p.keyword]){
                keywordStats[p.keyword]={
                    count:0,
                    latestDate:p.created_at,
                    isToday:false
                };
            }
            keywordStats[p.keyword].count++;
            
            // 최신 날짜 업데이트
            if(new Date(p.created_at)>new Date(keywordStats[p.keyword].latestDate)){
                keywordStats[p.keyword].latestDate=p.created_at;
            }
            
            // 오늘 추가된 키워드 체크
            if(new Date(p.created_at).toDateString()===today){
                keywordStats[p.keyword].isToday=true;
            }
        }
    });
    
    // 키워드 목록 업데이트
    renderKeywordList();
}

function renderKeywordList(){
    const listBody=document.getElementById('keywordListBody');
    const sortType=document.getElementById('keywordSort').value;
    
    // 키워드 배열 생성 및 정렬
    let keywordArray=Object.entries(keywordStats).map(([keyword,stat])=>({
        keyword,
        count:stat.count,
        latestDate:stat.latestDate,
        isToday:stat.isToday
    }));
    
    // 정렬
    switch(sortType){
        case'name_asc':
            keywordArray.sort((a,b)=>a.keyword.localeCompare(b.keyword));
            break;
        case'name_desc':
            keywordArray.sort((a,b)=>b.keyword.localeCompare(a.keyword));
            break;
        case'count_desc':
            keywordArray.sort((a,b)=>b.count-a.count);
            break;
        case'count_asc':
            keywordArray.sort((a,b)=>a.count-b.count);
            break;
        case'recent':
            keywordArray.sort((a,b)=>new Date(b.latestDate)-new Date(a.latestDate));
            break;
    }
    
    // 목록을 세 열로 나누어 렌더링
    listBody.innerHTML='';
    
    // 세 개씩 묶어서 처리
    for(let i=0;i<keywordArray.length;i+=3){
        const row=document.createElement('div');
        row.className='keyword-item-row';
        
        // 첫 번째 항목
        const item1=keywordArray[i];
        row.innerHTML+=`
            <div class="keyword-item" onclick="filterByKeyword('${item1.keyword}')">
                <span class="keyword-name">
                    ${item1.keyword}
                    ${item1.isToday?'<span class="keyword-new-badge">NEW</span>':''}
                </span>
            </div>
            <span class="keyword-count">${item1.count}</span>
        `;
        
        // 두 번째 항목 (있을 경우)
        if(i+1<keywordArray.length){
            const item2=keywordArray[i+1];
            row.innerHTML+=`
                <div class="keyword-item" onclick="filterByKeyword('${item2.keyword}')">
                    <span class="keyword-name">
                        ${item2.keyword}
                        ${item2.isToday?'<span class="keyword-new-badge">NEW</span>':''}
                    </span>
                </div>
                <span class="keyword-count">${item2.count}</span>
            `;
        }else{
            // 빈 칸 추가
            row.innerHTML+=`
                <div></div>
                <div></div>
            `;
        }
        
        // 세 번째 항목 (있을 경우)
        if(i+2<keywordArray.length){
            const item3=keywordArray[i+2];
            row.innerHTML+=`
                <div class="keyword-item" onclick="filterByKeyword('${item3.keyword}')">
                    <span class="keyword-name">
                        ${item3.keyword}
                        ${item3.isToday?'<span class="keyword-new-badge">NEW</span>':''}
                    </span>
                </div>
                <span class="keyword-count">${item3.count}</span>
            `;
        }else{
            // 빈 칸 추가
            row.innerHTML+=`
                <div></div>
                <div></div>
            `;
        }
        
        listBody.appendChild(row);
    }
}

function sortKeywordList(){
    renderKeywordList();
}

function toggleKeywordList(){
    const list=document.getElementById('keywordList');
    const toggle=document.getElementById('keywordToggle');
    
    if(list.style.display==='none'){
        list.style.display='block';
        toggle.textContent='키워드 목록 접기';
        toggle.classList.remove('collapsed');
    }else{
        list.style.display='none';
        toggle.textContent='키워드 목록 펼치기';
        toggle.classList.add('collapsed');
    }
}

function filterByKeyword(keyword){
    // 검색 입력창에 키워드 설정
    document.getElementById('searchInput').value=keyword;
    
    // 검색 실행
    searchProducts();
}

function searchProducts(){
    const query=document.getElementById('searchInput').value.toLowerCase().trim();
    
    if(!query){
        // 검색어가 없으면 전체 목록 표시
        filteredProducts=[...products];
        isMultiSearchMode=false;
        searchKeywords.clear();
        updateMultiSearchUI();
    }else{
        // 첫 번째 검색 수행
        searchKeywords.clear();
        searchKeywords.add(query);
        isMultiSearchMode=true;
        
        filteredProducts=products.filter(p=>
            (p.keyword && p.keyword.toLowerCase().includes(query)) ||
            (p.product_data && p.product_data.title && p.product_data.title.toLowerCase().includes(query))
        );
        
        updateMultiSearchUI();
        document.getElementById('addSearchBtn').style.display='inline-block';
    }
    
    currentPage=1;
    renderTable();
}

function addToMultiSearch(){
    const query=document.getElementById('searchInput').value.toLowerCase().trim();
    
    if(!query){
        alert('검색할 키워드를 입력해주세요.');
        return;
    }
    
    if(searchKeywords.has(query)){
        alert('이미 검색된 키워드입니다.');
        return;
    }
    
    // 새 키워드 추가
    searchKeywords.add(query);
    
    // 기존 결과에 새 검색 결과 추가
    const newResults=products.filter(p=>
        (p.keyword && p.keyword.toLowerCase().includes(query)) ||
        (p.product_data && p.product_data.title && p.product_data.title.toLowerCase().includes(query))
    );
    
    // 중복 제거하면서 결과 합치기
    const existingIds=new Set(filteredProducts.map(p=>p.id));
    newResults.forEach(product=>{
        if(!existingIds.has(product.id)){
            filteredProducts.push(product);
            existingIds.add(product.id);
        }
    });
    
    updateMultiSearchUI();
    document.getElementById('searchInput').value='';
    currentPage=1;
    renderTable();
}

function updateMultiSearchUI(){
    const multiSearchSection=document.getElementById('multiSearchSection');
    const searchTags=document.getElementById('searchTags');
    const searchResultInfo=document.getElementById('searchResultInfo');
    
    if(isMultiSearchMode && searchKeywords.size>0){
        multiSearchSection.classList.add('show');
        
        // 검색 태그 업데이트
        searchTags.innerHTML='';
        searchKeywords.forEach(keyword=>{
            const tag=document.createElement('span');
            tag.className='search-tag';
            tag.innerHTML=`${keyword}<span class="remove" onclick="removeSearchKeyword('${keyword}')">&times;</span>`;
            searchTags.appendChild(tag);
        });
        
        // 검색 결과 정보 업데이트
        searchResultInfo.textContent=`${searchKeywords.size}개 키워드로 ${filteredProducts.length}개 상품 검색됨`;
    }else{
        multiSearchSection.classList.remove('show');
    }
}

function removeSearchKeyword(keyword){
    searchKeywords.delete(keyword);
    
    if(searchKeywords.size===0){
        // 모든 키워드가 삭제되면 전체 목록 표시
        filteredProducts=[...products];
        isMultiSearchMode=false;
        document.getElementById('addSearchBtn').style.display='none';
    }else{
        // 남은 키워드로 다시 검색
        filteredProducts=[];
        const addedIds=new Set();
        
        searchKeywords.forEach(kw=>{
            const results=products.filter(p=>
                (p.keyword && p.keyword.toLowerCase().includes(kw)) ||
                (p.product_data && p.product_data.title && p.product_data.title.toLowerCase().includes(kw))
            );
            
            results.forEach(product=>{
                if(!addedIds.has(product.id)){
                    filteredProducts.push(product);
                    addedIds.add(product.id);
                }
            });
        });
    }
    
    updateMultiSearchUI();
    currentPage=1;
    renderTable();
}

function clearSearch(){
    document.getElementById('searchInput').value='';
    searchKeywords.clear();
    filteredProducts=[...products];
    isMultiSearchMode=false;
    document.getElementById('addSearchBtn').style.display='none';
    updateMultiSearchUI();
    currentPage=1;
    renderTable();
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
        // 키워드 하이라이트 확인
        let keywordClass = 'product-keyword';
        if(isMultiSearchMode && searchKeywords.size > 0){
            for(let keyword of searchKeywords){
                if(p.keyword && p.keyword.toLowerCase().includes(keyword)){
                    keywordClass = 'product-keyword highlighted';
                    break;
                }
            }
        }
        
        return `
        <tr>
            <td class="checkbox-col">
                <input type="checkbox" value="${p.id}" onchange="toggleProductSelection('${p.id}')" ${selectedProducts.has(p.id)?'checked':''}>
            </td>
            <td class="image-col">
                <img src="${p.product_data && p.product_data.image_url || '/tools/images/no-image.png'}" alt="${p.product_data && p.product_data.title || '상품 이미지'}" class="product-image" onclick="previewProduct('${p.id}')" onerror="this.src='/tools/images/no-image.png'">
            </td>
            <td class="title-col">
                <div class="product-title">
                    <a href="${p.product_url || ''}" target="_blank">${p.product_data && p.product_data.title || '제목 없음'}</a>
                </div>
            </td>
            <td class="price-col">
                <div class="product-price">${p.product_data && p.product_data.price || '가격 정보 없음'}</div>
            </td>
            <td class="keyword-col">
                <span class="${keywordClass}">${p.keyword || '키워드 없음'}</span>
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
    if(!dateString) return '';
    const date=new Date(dateString);
    return date.toLocaleDateString('ko-KR',{
        year:'numeric',
        month:'2-digit',
        day:'2-digit',
        hour:'2-digit',
        minute:'2-digit'
    });
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
                aVal=(a.product_data && a.product_data.title || '').toLowerCase();
                bVal=(b.product_data && b.product_data.title || '').toLowerCase();
                break;
            case'price':
                aVal=parseFloat((a.product_data && a.product_data.price || '0').replace(/[^\d.]/g,''))||0;
                bVal=parseFloat((b.product_data && b.product_data.price || '0').replace(/[^\d.]/g,''))||0;
                break;
            case'keyword':
                aVal=(a.keyword || '').toLowerCase();
                bVal=(b.keyword || '').toLowerCase();
                break;
            case'date':
                aVal=new Date(a.created_at || 0);
                bVal=new Date(b.created_at || 0);
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
    
    const content=document.getElementById('previewContent');
    content.innerHTML=`
        <div style="margin-bottom:20px;">
            <h4>${product.product_data && product.product_data.title || '제목 없음'}</h4>
            <p><strong>키워드:</strong> ${product.keyword || ''}</p>
            <p><strong>가격:</strong> ${product.product_data && product.product_data.price || '가격 정보 없음'}</p>
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
            
            alert(`${rs.rows_added || '선택된'}개의 상품이 구글 시트에 저장되었습니다!`);
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
            alert(`${rs.rows_added || '모든'}개의 상품이 구글 시트에 동기화되었습니다!`);
            if(rs.spreadsheet_url) window.open(rs.spreadsheet_url,'_blank');
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
    let csvContent = '\uFEFF'; // UTF-8 BOM 추가
    csvContent += headers.join(',') + '\n';
    
    selectedData.forEach(product=>{
        const row=[];
        
        // 기본 정보 - ID를 탭문자와 함께 추가하여 텍스트로 처리
        row.push(product.id ? '\t' + product.id : '');
        row.push(product.keyword || '');
        row.push((product.product_data && product.product_data.title) || '');
        row.push((product.product_data && product.product_data.price) || '');
        row.push((product.product_data && product.product_data.rating_display) || '');
        row.push((product.product_data && product.product_data.lastest_volume) || '');
        row.push((product.product_data && product.product_data.image_url) || '');
        row.push(product.product_url || '');
        row.push((product.product_data && product.product_data.affiliate_link) || '');
        row.push(product.created_at || '');
        
        // 기능/스펙
        const specs=(product.user_details && product.user_details.specs) || {};
        row.push(specs.main_function || '');
        row.push(specs.size_capacity || '');
        row.push(specs.color || '');
        row.push(specs.material || '');
        row.push(specs.power_battery || '');
        
        // 효율성
        const efficiency=(product.user_details && product.user_details.efficiency) || {};
        row.push(efficiency.problem_solving || '');
        row.push(efficiency.time_saving || '');
        row.push(efficiency.space_efficiency || '');
        row.push(efficiency.cost_saving || '');
        
        // 사용법
        const usage=(product.user_details && product.user_details.usage) || {};
        row.push(usage.usage_location || '');
        row.push(usage.usage_frequency || '');
        row.push(usage.target_users || '');
        row.push(usage.usage_method || '');
        
        // 장점/주의사항
        const benefits=(product.user_details && product.user_details.benefits) || {};
        const advantages=(benefits.advantages) || [];
        row.push(advantages[0] || '');
        row.push(advantages[1] || '');
        row.push(advantages[2] || '');
        row.push(benefits.precautions || '');
        
        // CSV 형식으로 변환 (쉼표와 줄바꿈 처리)
        const csvRow=row.map(cell=>{
            const cellStr=String(cell || '').replace(/"/g,'""');
            return cellStr.includes(',')||cellStr.includes('\n')?`"${cellStr}"`:cellStr;
        });
        
        csvContent+=csvRow.join(',')+'\n';
    });
    
    // 현재 날짜와 시간을 포함한 파일명 생성
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    
    const fileName = `상품_발굴_데이터_${year}${month}${day}_${hours}${minutes}${seconds}.csv`;
    
    // 다운로드 실행
    const blob=new Blob([csvContent],{type:'text/csv;charset=utf-8;'});
    const url=URL.createObjectURL(blob);
    const link=document.createElement('a');
    link.href=url;
    link.download=fileName;
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