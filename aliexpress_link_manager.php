<?php
session_start();

// JSON 파일 경로
$json_file = __DIR__ . '/aliexpress_keyword_links.json';

// JSON 파일 읽기
function loadKeywordLinks() {
    global $json_file;
    if (file_exists($json_file)) {
        $content = file_get_contents($json_file);
        return json_decode($content, true) ?: [];
    }
    return [];
}

// JSON 파일 저장
function saveKeywordLinks($data) {
    global $json_file;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents($json_file, $json) !== false;
}

// 초기 데이터 로드
$keyword_links = loadKeywordLinks();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AliExpress 키워드 링크 관리</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .aliexpress-logo {
            height: 40px;
            vertical-align: middle;
        }
        
        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        
        .search-box input {
            width: 100%;
            padding: 10px 40px 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .search-box::before {
            content: "🔍";
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #ff4747;
            color: white;
        }
        
        .btn-primary:hover {
            background: #e63939;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .stats {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            gap: 30px;
            align-items: center;
        }
        
        .stat-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .keyword {
            font-weight: 500;
            color: #333;
        }
        
        .link {
            color: #007bff;
            text-decoration: none;
            word-break: break-all;
            font-size: 13px;
        }
        
        .link:hover {
            text-decoration: underline;
        }
        
        .actions {
            display: flex;
            gap: 10px;
        }
        
        .edit-form {
            display: none;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            color: #999;
        }
        
        .check-column {
            width: 40px;
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
        }
        
        .modal-content {
            background: white;
            width: 90%;
            max-width: 600px;
            margin: 50px auto;
            padding: 30px;
            border-radius: 8px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .json-preview {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
            white-space: pre-wrap;
            max-height: 400px;
            overflow-y: auto;
        }
        
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 15px 20px;
            background: #28a745;
            color: white;
            border-radius: 4px;
            display: none;
            z-index: 2000;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 12px;
        }

        .link-preview {
            margin-top: 10px;
            padding: 10px;
            background: #e8f4f8;
            border-left: 4px solid #007bff;
            border-radius: 4px;
            font-size: 13px;
        }

        .link-preview strong {
            color: #007bff;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <img src="/tools/images/Ali_black_logo.webp" alt="AliExpress" class="aliexpress-logo">
            키워드 링크 관리
        </h1>

        <div class="stats">
            <div class="stat-item">
                <span>📊 총 키워드:</span>
                <strong id="totalCount">0</strong>개
            </div>
            <div class="stat-item">
                <span>📄 JSON 파일:</span>
                <strong>aliexpress_keyword_links.json</strong>
            </div>
            <div class="stat-item">
                <span>💾 마지막 저장:</span>
                <strong id="lastSaved">-</strong>
            </div>
        </div>

        <div class="controls">
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="키워드 검색...">
            </div>
            <button class="btn btn-primary" onclick="showAddForm()">➕ 새 키워드 추가</button>
            <button class="btn btn-success" onclick="saveToFile()">💾 JSON 파일 저장</button>
            <button class="btn btn-warning" onclick="showJsonPreview()">👁️ JSON 미리보기</button>
        </div>

        <!-- 추가/수정 폼 -->
        <div id="editForm" class="edit-form">
            <h3 id="formTitle">새 키워드 추가</h3>
            <form id="keywordForm">
                <div class="form-group">
                    <label for="keyword">키워드 *</label>
                    <input type="text" id="keyword" required placeholder="예: 수영 고글">
                </div>
                <div class="form-group">
                    <label for="link">알리익스프레스 어필리에이트 링크 *</label>
                    <input type="url" id="link" required placeholder="https://s.click.aliexpress.com/e/_ok6Lkf9">
                    <div class="link-preview" id="linkPreview" style="display: none;">
                        <strong>미리보기:</strong> 이 링크는 '<span id="previewKeyword"></span>' 키워드 검색 결과로 연결됩니다.
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">저장</button>
                    <button type="button" class="btn" onclick="hideForm()">취소</button>
                </div>
            </form>
        </div>

        <!-- 선택 항목 작업 -->
        <div id="bulkActions" style="display: none; margin-bottom: 20px;">
            <button class="btn btn-danger" onclick="deleteSelected()">🗑️ 선택 항목 삭제</button>
            <span style="margin-left: 10px;">선택됨: <strong id="selectedCount">0</strong>개</span>
        </div>

        <!-- 키워드 테이블 -->
        <table id="keywordTable">
            <thead>
                <tr>
                    <th class="check-column">
                        <input type="checkbox" id="checkAll" onchange="toggleCheckAll()">
                    </th>
                    <th>키워드</th>
                    <th>어필리에이트 링크</th>
                    <th style="width: 120px;">작업</th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <!-- 동적으로 생성됨 -->
            </tbody>
        </table>

        <div id="emptyState" class="empty-state" style="display: none;">
            <h3>등록된 키워드가 없습니다</h3>
            <p>위의 "새 키워드 추가" 버튼을 클릭하여 시작하세요.</p>
            <div class="link-preview" style="max-width: 400px; margin: 20px auto;">
                <strong>예시:</strong><br>
                키워드: <strong>수영 고글</strong><br>
                링크: <strong>https://s.click.aliexpress.com/e/_ok6Lkf9</strong>
            </div>
        </div>
    </div>

    <!-- JSON 미리보기 모달 -->
    <div id="jsonModal" class="modal">
        <div class="modal-content">
            <h3>JSON 파일 미리보기</h3>
            <div id="jsonContent" class="json-preview"></div>
            <div class="form-actions">
                <button class="btn btn-primary" onclick="copyJson()">📋 복사</button>
                <button class="btn" onclick="closeModal()">닫기</button>
            </div>
        </div>
    </div>

    <!-- 토스트 메시지 -->
    <div id="toast" class="toast"></div>

    <script>
        // 전역 변수
        let keywordData = {};
        let editingKey = null;

        // 페이지 로드 시 초기화
        document.addEventListener('DOMContentLoaded', function() {
            loadData();
        });

        // 데이터 로드
        function loadData() {
            // PHP에서 전달받은 데이터 사용
            keywordData = <?php echo json_encode($keyword_links); ?>;
            renderTable();
            updateStats();
        }

        // 테이블 렌더링
        function renderTable() {
            const tbody = document.getElementById('tableBody');
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            tbody.innerHTML = '';

            const filteredData = Object.entries(keywordData).filter(([keyword, link]) => 
                keyword.toLowerCase().includes(searchTerm)
            );

            if (filteredData.length === 0) {
                document.getElementById('emptyState').style.display = 'block';
                document.getElementById('keywordTable').style.display = 'none';
                return;
            }

            document.getElementById('emptyState').style.display = 'none';
            document.getElementById('keywordTable').style.display = 'table';

            filteredData.forEach(([keyword, link]) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><input type="checkbox" class="row-check" value="${keyword}"></td>
                    <td class="keyword">${keyword}</td>
                    <td><a href="${link}" target="_blank" class="link">${link}</a></td>
                    <td class="actions">
                        <button class="btn btn-sm" onclick="editKeyword('${keyword.replace(/'/g, "\\'")}')">✏️</button>
                        <button class="btn btn-sm btn-danger" onclick="deleteKeyword('${keyword.replace(/'/g, "\\'")}')">🗑️</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });

            updateCheckboxes();
        }

        // 통계 업데이트
        function updateStats() {
            document.getElementById('totalCount').textContent = Object.keys(keywordData).length;
        }

        // 새 키워드 추가 폼 표시
        function showAddForm() {
            document.getElementById('formTitle').textContent = '새 키워드 추가';
            document.getElementById('keywordForm').reset();
            document.getElementById('keyword').disabled = false;
            document.getElementById('linkPreview').style.display = 'none';
            editingKey = null;
            document.getElementById('editForm').style.display = 'block';
            document.getElementById('keyword').focus();
        }

        // 키워드 수정
        function editKeyword(keyword) {
            document.getElementById('formTitle').textContent = '키워드 수정';
            document.getElementById('keyword').value = keyword;
            document.getElementById('keyword').disabled = true;
            document.getElementById('link').value = keywordData[keyword];
            document.getElementById('previewKeyword').textContent = keyword;
            document.getElementById('linkPreview').style.display = 'block';
            editingKey = keyword;
            document.getElementById('editForm').style.display = 'block';
            document.getElementById('link').focus();
        }

        // 폼 숨기기
        function hideForm() {
            document.getElementById('editForm').style.display = 'none';
            document.getElementById('keywordForm').reset();
            document.getElementById('linkPreview').style.display = 'none';
            editingKey = null;
        }

        // 링크 입력 시 미리보기 업데이트
        document.getElementById('link').addEventListener('input', function() {
            const keyword = document.getElementById('keyword').value;
            const linkPreview = document.getElementById('linkPreview');
            
            if (keyword && this.value) {
                document.getElementById('previewKeyword').textContent = keyword;
                linkPreview.style.display = 'block';
            } else {
                linkPreview.style.display = 'none';
            }
        });

        document.getElementById('keyword').addEventListener('input', function() {
            const link = document.getElementById('link').value;
            const linkPreview = document.getElementById('linkPreview');
            
            if (this.value && link) {
                document.getElementById('previewKeyword').textContent = this.value;
                linkPreview.style.display = 'block';
            } else {
                linkPreview.style.display = 'none';
            }
        });

        // 폼 제출
        document.getElementById('keywordForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const keyword = document.getElementById('keyword').value.trim();
            const link = document.getElementById('link').value.trim();

            if (!keyword || !link) {
                showToast('키워드와 링크를 모두 입력해주세요.', 'error');
                return;
            }

            if (!editingKey && keywordData[keyword]) {
                showToast('이미 존재하는 키워드입니다.', 'error');
                return;
            }

            // 링크 형식 검증
            if (!link.includes('s.click.aliexpress.com')) {
                if (!confirm('알리익스프레스 어필리에이트 링크가 아닌 것 같습니다. 계속하시겠습니까?')) {
                    return;
                }
            }

            keywordData[keyword] = link;
            hideForm();
            renderTable();
            updateStats();
            showToast(editingKey ? '수정되었습니다.' : '추가되었습니다.');
        });

        // 키워드 삭제
        function deleteKeyword(keyword) {
            if (confirm(`"${keyword}" 키워드를 삭제하시겠습니까?`)) {
                delete keywordData[keyword];
                renderTable();
                updateStats();
                showToast('삭제되었습니다.');
            }
        }

        // JSON 파일로 저장
        function saveToFile() {
            const formData = new FormData();
            formData.append('action', 'save');
            formData.append('data', JSON.stringify(keywordData));

            fetch('aliexpress_link_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('JSON 파일이 저장되었습니다.');
                    document.getElementById('lastSaved').textContent = new Date().toLocaleString();
                } else {
                    showToast('저장 실패: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showToast('저장 중 오류가 발생했습니다.', 'error');
                console.error(error);
            });
        }

        // JSON 미리보기
        function showJsonPreview() {
            const jsonStr = JSON.stringify(keywordData, null, 2);
            document.getElementById('jsonContent').textContent = jsonStr;
            document.getElementById('jsonModal').style.display = 'block';
        }

        // JSON 복사
        function copyJson() {
            const jsonStr = JSON.stringify(keywordData, null, 2);
            navigator.clipboard.writeText(jsonStr).then(() => {
                showToast('JSON이 클립보드에 복사되었습니다.');
            });
        }

        // 모달 닫기
        function closeModal() {
            document.getElementById('jsonModal').style.display = 'none';
        }

        // 검색
        document.getElementById('searchInput').addEventListener('input', renderTable);

        // 체크박스 관련
        function toggleCheckAll() {
            const checkAll = document.getElementById('checkAll').checked;
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = checkAll);
            updateCheckboxes();
        }

        function updateCheckboxes() {
            const checkboxes = document.querySelectorAll('.row-check');
            const checkedBoxes = document.querySelectorAll('.row-check:checked');
            
            document.getElementById('checkAll').checked = 
                checkboxes.length > 0 && checkboxes.length === checkedBoxes.length;
            
            document.getElementById('selectedCount').textContent = checkedBoxes.length;
            document.getElementById('bulkActions').style.display = 
                checkedBoxes.length > 0 ? 'block' : 'none';
        }

        // 선택 항목 삭제
        function deleteSelected() {
            const checkedBoxes = document.querySelectorAll('.row-check:checked');
            const keywords = Array.from(checkedBoxes).map(cb => cb.value);
            
            if (confirm(`선택한 ${keywords.length}개의 키워드를 삭제하시겠습니까?`)) {
                keywords.forEach(keyword => delete keywordData[keyword]);
                renderTable();
                updateStats();
                showToast(`${keywords.length}개의 키워드가 삭제되었습니다.`);
            }
        }

        // 토스트 메시지
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.style.background = type === 'error' ? '#dc3545' : '#28a745';
            toast.style.display = 'block';
            
            setTimeout(() => {
                toast.style.display = 'none';
            }, 3000);
        }

        // 테이블 클릭 이벤트 위임
        document.getElementById('tableBody').addEventListener('change', function(e) {
            if (e.target.classList.contains('row-check')) {
                updateCheckboxes();
            }
        });
    </script>
</body>
</html>