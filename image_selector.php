<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>이미지 선택 - Google Drive 이미지 자동화</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 16px;
        }
        
        /* 탭 메뉴 스타일 */
        .tab-menu {
            display: flex;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        
        .tab-button {
            flex: 1;
            padding: 15px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.3s;
            border-bottom: 3px solid transparent;
        }
        
        .tab-button:hover {
            background: #e9ecef;
            color: #495057;
        }
        
        .tab-button.active {
            background: white;
            color: #007bff;
            border-bottom-color: #007bff;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .controls {
            padding: 20px 30px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .control-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
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
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .status-info {
            color: #666;
            font-size: 14px;
        }
        
        .loading {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px 20px;
            margin: 20px 30px;
            border-radius: 6px;
            border: 1px solid #f5c6cb;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px 20px;
            margin: 20px 30px;
            border-radius: 6px;
            border: 1px solid #c3e6cb;
        }
        
        .image-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            padding: 30px;
        }
        
        .image-item {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        
        .image-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            border-color: #007bff;
        }
        
        .image-item.processing {
            pointer-events: none;
            opacity: 0.6;
            border-color: #ffc107;
        }
        
        .image-item.completed {
            border-color: #28a745;
            background: #f8f9fa;
        }
        
        .image-preview {
            width: 100%;
            height: 200px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        
        .image-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        .processing-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
        }
        
        .image-item.processing .processing-overlay {
            display: flex;
        }
        
        .image-info {
            padding: 15px;
        }
        
        .image-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            word-break: break-word;
        }
        
        .image-meta {
            font-size: 12px;
            color: #666;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        /* 히스토리 탭 전용 스타일 */
        .history-list {
            padding: 30px;
        }
        
        .history-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        
        .history-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }
        
        .history-thumbnail {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .history-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .history-info {
            flex: 1;
        }
        
        .history-filename {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            word-break: break-word;
        }
        
        .history-url {
            font-size: 12px;
            color: #666;
            margin-bottom: 5px;
            word-break: break-all;
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .history-date {
            font-size: 11px;
            color: #999;
        }
        
        .history-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }
        
        .processing-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        
        .processing-content {
            background: white;
            padding: 40px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 400px;
            width: 90%;
        }
        
        .processing-content .loading-spinner {
            width: 60px;
            height: 60px;
            border-width: 6px;
        }
        
        .processing-steps {
            margin-top: 20px;
            text-align: left;
        }
        
        .processing-step {
            padding: 8px 0;
            color: #666;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .processing-step.active {
            color: #007bff;
            font-weight: 600;
        }
        
        .processing-step.completed {
            color: #28a745;
        }
        
        .step-icon {
            width: 20px;
            text-align: center;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 30px;
            color: #666;
        }
        
        .empty-state h3 {
            margin-bottom: 15px;
            color: #999;
        }
        
        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .control-group {
                justify-content: center;
            }
            
            .image-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 15px;
                padding: 20px;
            }
            
            .history-item {
                flex-direction: column;
                text-align: center;
            }
            
            .history-thumbnail {
                width: 100px;
                height: 100px;
            }
            
            .history-url {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🖼️ 이미지 선택</h1>
            <p>Google Drive에서 이미지를 선택하거나 이전에 생성한 URL을 재사용하세요</p>
        </div>
        
        <!-- 탭 메뉴 -->
        <div class="tab-menu">
            <button class="tab-button active" onclick="switchTab('drive')" id="driveTab">
                🗂️ Google Drive
            </button>
            <button class="tab-button" onclick="switchTab('history')" id="historyTab">
                📜 URL 히스토리 (<span id="historyCount">0</span>)
            </button>
        </div>
        
        <!-- Google Drive 탭 -->
        <div id="driveTabContent" class="tab-content active">
            <div class="controls">
                <div class="control-group">
                    <button class="btn btn-primary" onclick="loadImages()" id="refreshBtn">
                        🔄 새로고침
                    </button>
                    <button class="btn btn-secondary" onclick="testConnection()" id="testBtn">
                        🔧 연결 테스트
                    </button>
                </div>
                <div class="control-group">
                    <span class="status-info" id="statusInfo">이미지를 불러오는 중...</span>
                </div>
            </div>
            
            <div id="messageContainer"></div>
            
            <div id="loadingContainer" class="loading">
                <div class="loading-spinner"></div>
                <p>Google Drive 이미지 목록을 불러오는 중...</p>
            </div>
            
            <div id="imageContainer" class="image-grid" style="display: none;"></div>
            
            <div id="emptyContainer" class="empty-state" style="display: none;">
                <h3>📦 이미지가 없습니다</h3>
                <p>Google Drive의 AI_Generated_Images/original 폴더에<br>이미지를 업로드한 후 새로고침 해주세요.</p>
            </div>
        </div>
        
        <!-- 히스토리 탭 -->
        <div id="historyTabContent" class="tab-content">
            <div class="controls">
                <div class="control-group">
                    <button class="btn btn-secondary" onclick="loadHistory()" id="historyRefreshBtn">
                        🔄 새로고침
                    </button>
                    <button class="btn btn-danger" onclick="clearHistory()" id="clearHistoryBtn">
                        🗑️ 히스토리 삭제
                    </button>
                </div>
                <div class="control-group">
                    <span class="status-info" id="historyStatusInfo">히스토리를 불러오는 중...</span>
                </div>
            </div>
            
            <div id="historyContainer" class="history-list"></div>
            
            <div id="historyEmptyContainer" class="empty-state" style="display: none;">
                <h3>📂 히스토리가 없습니다</h3>
                <p>Google Drive에서 이미지를 선택하여 URL을 생성하면<br>이곳에 히스토리가 저장됩니다.</p>
            </div>
        </div>
    </div>
    
    <!-- 처리 진행 모달 -->
    <div id="processingModal" class="processing-modal">
        <div class="processing-content">
            <div class="loading-spinner"></div>
            <h3>이미지 처리 중</h3>
            <p>잠시만 기다려주세요...</p>
            <div class="processing-steps">
                <div class="processing-step" id="step1">
                    <span class="step-icon">⬇️</span>
                    <span>이미지 다운로드</span>
                </div>
                <div class="processing-step" id="step2">
                    <span class="step-icon">🔄</span>
                    <span>WebP 변환</span>
                </div>
                <div class="processing-step" id="step3">
                    <span class="step-icon">⬆️</span>
                    <span>Google Drive 업로드</span>
                </div>
                <div class="processing-step" id="step4">
                    <span class="step-icon">🔗</span>
                    <span>공개 URL 생성</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentImages = [];
        let isProcessing = false;
        let imageHistory = [];
        
        // 페이지 로드 시 자동으로 데이터 로드
        document.addEventListener('DOMContentLoaded', function() {
            loadHistory();
            loadImages();
        });
        
        /**
         * 탭 전환
         */
        function switchTab(tabName) {
            // 탭 버튼 활성화 상태 변경
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            document.getElementById(tabName + 'Tab').classList.add('active');
            
            // 탭 내용 표시/숨김
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById(tabName + 'TabContent').classList.add('active');
            
            // 히스토리 탭 선택 시 데이터 새로고침
            if (tabName === 'history') {
                loadHistory();
            }
        }
        
        /**
         * 이미지 목록 로드
         */
        async function loadImages() {
            if (isProcessing) {
                showMessage('현재 이미지를 처리 중입니다. 잠시 기다려주세요.', 'warning');
                return;
            }
            
            showLoading(true);
            clearMessages();
            
            try {
                const response = await fetch('image_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'list_images',
                        limit: 50
                    })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || '이미지 목록을 불러올 수 없습니다');
                }
                
                currentImages = result.images || [];
                displayImages(currentImages);
                
                updateStatus(`총 ${currentImages.length}개의 이미지를 찾았습니다`);
                
            } catch (error) {
                console.error('이미지 로드 실패:', error);
                showMessage('이미지 목록을 불러오는데 실패했습니다: ' + error.message, 'error');
                showEmptyState();
            } finally {
                showLoading(false);
            }
        }
        
        /**
         * 이미지 목록 표시
         */
        function displayImages(images) {
            const container = document.getElementById('imageContainer');
            const emptyContainer = document.getElementById('emptyContainer');
            
            if (!images || images.length === 0) {
                showEmptyState();
                return;
            }
            
            container.innerHTML = '';
            container.style.display = 'grid';
            emptyContainer.style.display = 'none';
            
            images.forEach(image => {
                const imageItem = createImageItem(image);
                container.appendChild(imageItem);
            });
        }
        
        /**
         * 이미지 아이템 HTML 생성
         */
        function createImageItem(image) {
            const item = document.createElement('div');
            item.className = 'image-item';
            item.dataset.fileId = image.id;
            
            const thumbnailUrl = image.thumbnailLink || 'data:image/svg+xml;base64,' + btoa(`
                <svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
                    <rect width="200" height="200" fill="#f0f0f0"/>
                    <text x="100" y="100" text-anchor="middle" dy=".3em" font-family="Arial" font-size="16" fill="#666">이미지</text>
                </svg>
            `);
            
            item.innerHTML = `
                <div class="image-preview">
                    <img src="${thumbnailUrl}" alt="${image.name}" onerror="this.style.display='none'">
                    <div class="processing-overlay">
                        <div>
                            <div class="loading-spinner" style="width: 30px; height: 30px; border-width: 3px; margin: 0 auto 10px;"></div>
                            <div>처리 중...</div>
                        </div>
                    </div>
                </div>
                <div class="image-info">
                    <div class="image-name">${image.name}</div>
                    <div class="image-meta">
                        <span>${image.formatted_size || '크기 정보 없음'}</span>
                        <span>${image.formatted_date || '날짜 정보 없음'}</span>
                    </div>
                </div>
            `;
            
            item.addEventListener('click', () => processImage(image.id, image.name, thumbnailUrl));
            
            return item;
        }
        
        /**
         * 이미지 처리 (선택 시 실행)
         */
        async function processImage(fileId, fileName, thumbnailUrl) {
            if (isProcessing) {
                showMessage('현재 다른 이미지를 처리 중입니다. 잠시 기다려주세요.', 'warning');
                return;
            }
            
            isProcessing = true;
            
            // UI 상태 업데이트
            const item = document.querySelector(`[data-file-id="${fileId}"]`);
            if (item) {
                item.classList.add('processing');
            }
            
            showProcessingModal(true);
            updateProcessingStep(1);
            
            try {
                const response = await fetch('image_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'process_image',
                        file_id: fileId,
                        quality: 85
                    })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.error || '이미지 처리에 실패했습니다');
                }
                
                // 처리 단계 순차적 업데이트
                await simulateProcessingSteps();
                
                // 히스토리에 추가
                addToHistory(fileName, result.public_url, thumbnailUrl);
                
                // 성공 시 localStorage에 URL 저장
                localStorage.setItem('selected_image_url', result.public_url);
                
                showMessage(`이미지 처리가 완료되었습니다! URL이 생성되었습니다.`, 'success');
                
                // 처리 완료 표시
                if (item) {
                    item.classList.remove('processing');
                    item.classList.add('completed');
                }
                
                // 2초 후 자동으로 창 닫기
                setTimeout(() => {
                    showMessage('URL이 원래 페이지에 자동 입력됩니다. 창을 닫습니다...', 'success');
                    setTimeout(() => {
                        window.close();
                    }, 1000);
                }, 2000);
                
            } catch (error) {
                console.error('이미지 처리 실패:', error);
                showMessage('이미지 처리에 실패했습니다: ' + error.message, 'error');
                
                // 실패 시 원래 상태로 복원
                if (item) {
                    item.classList.remove('processing');
                }
            } finally {
                isProcessing = false;
                showProcessingModal(false);
            }
        }
        
        /**
         * 히스토리에 추가
         */
        function addToHistory(fileName, url, thumbnailUrl) {
            const historyItem = {
                id: Date.now(),
                fileName: fileName,
                url: url,
                thumbnailUrl: thumbnailUrl,
                createdAt: new Date().toISOString()
            };
            
            // 중복 URL 제거 (같은 URL이 있으면 기존 것 삭제)
            imageHistory = imageHistory.filter(item => item.url !== url);
            
            // 새 항목을 맨 앞에 추가
            imageHistory.unshift(historyItem);
            
            // 최대 50개까지만 보관
            if (imageHistory.length > 50) {
                imageHistory = imageHistory.slice(0, 50);
            }
            
            // 로컬 스토리지에 저장
            localStorage.setItem('image_url_history', JSON.stringify(imageHistory));
            
            // 히스토리 카운트 업데이트
            updateHistoryCount();
        }
        
        /**
         * 히스토리 로드
         */
        function loadHistory() {
            try {
                const stored = localStorage.getItem('image_url_history');
                imageHistory = stored ? JSON.parse(stored) : [];
                displayHistory();
                updateHistoryCount();
                updateHistoryStatus(`총 ${imageHistory.length}개의 히스토리가 있습니다`);
            } catch (error) {
                console.error('히스토리 로드 실패:', error);
                imageHistory = [];
                displayHistory();
                updateHistoryCount();
            }
        }
        
        /**
         * 히스토리 표시
         */
        function displayHistory() {
            const container = document.getElementById('historyContainer');
            const emptyContainer = document.getElementById('historyEmptyContainer');
            
            if (!imageHistory || imageHistory.length === 0) {
                container.style.display = 'none';
                emptyContainer.style.display = 'block';
                return;
            }
            
            container.style.display = 'block';
            emptyContainer.style.display = 'none';
            
            container.innerHTML = '';
            
            imageHistory.forEach((item, index) => {
                const historyItem = createHistoryItem(item, index);
                container.appendChild(historyItem);
            });
        }
        
        /**
         * 히스토리 아이템 HTML 생성
         */
        function createHistoryItem(item, index) {
            const div = document.createElement('div');
            div.className = 'history-item';
            
            const createdDate = new Date(item.createdAt);
            const formattedDate = createdDate.toLocaleString('ko-KR', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            div.innerHTML = `
                <div class="history-thumbnail">
                    <img src="${item.thumbnailUrl}" alt="${item.fileName}" onerror="this.style.display='none'">
                </div>
                <div class="history-info">
                    <div class="history-filename">${item.fileName}</div>
                    <div class="history-url" title="${item.url}">${item.url}</div>
                    <div class="history-date">📅 ${formattedDate}</div>
                </div>
                <div class="history-actions">
                    <button class="btn btn-success btn-small" onclick="selectFromHistory('${item.url}', '${item.fileName}')">
                        ✅ 선택
                    </button>
                    <button class="btn btn-primary btn-small" onclick="copyUrl('${item.url}')">
                        📋 복사
                    </button>
                    <button class="btn btn-danger btn-small" onclick="deleteHistoryItem(${index})">
                        🗑️
                    </button>
                </div>
            `;
            
            return div;
        }
        
        /**
         * 히스토리에서 URL 선택
         */
        function selectFromHistory(url, fileName) {
            localStorage.setItem('selected_image_url', url);
            showMessage(`"${fileName}" URL이 선택되었습니다!`, 'success');
            
            // 2초 후 자동으로 창 닫기
            setTimeout(() => {
                showMessage('URL이 원래 페이지에 자동 입력됩니다. 창을 닫습니다...', 'success');
                setTimeout(() => {
                    window.close();
                }, 1000);
            }, 1500);
        }
        
        /**
         * URL 클립보드 복사
         */
        async function copyUrl(url) {
            try {
                await navigator.clipboard.writeText(url);
                showMessage('URL이 클립보드에 복사되었습니다! 📋', 'success');
            } catch (error) {
                // 폴백: 텍스트 선택
                const textArea = document.createElement('textarea');
                textArea.value = url;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showMessage('URL이 선택되었습니다. Ctrl+C로 복사하세요.', 'success');
            }
        }
        
        /**
         * 히스토리 항목 삭제
         */
        function deleteHistoryItem(index) {
            if (confirm('이 히스토리 항목을 삭제하시겠습니까?')) {
                imageHistory.splice(index, 1);
                localStorage.setItem('image_url_history', JSON.stringify(imageHistory));
                displayHistory();
                updateHistoryCount();
                showMessage('히스토리 항목이 삭제되었습니다.', 'success');
            }
        }
        
        /**
         * 전체 히스토리 삭제
         */
        function clearHistory() {
            if (confirm('모든 히스토리를 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.')) {
                imageHistory = [];
                localStorage.removeItem('image_url_history');
                displayHistory();
                updateHistoryCount();
                showMessage('모든 히스토리가 삭제되었습니다.', 'success');
            }
        }
        
        /**
         * 히스토리 카운트 업데이트
         */
        function updateHistoryCount() {
            document.getElementById('historyCount').textContent = imageHistory.length;
        }
        
        /**
         * 처리 단계 시뮬레이션
         */
        async function simulateProcessingSteps() {
            const steps = [2, 3, 4];
            
            for (const step of steps) {
                await new Promise(resolve => setTimeout(resolve, 1000));
                updateProcessingStep(step);
            }
        }
        
        /**
         * 연결 테스트
         */
        async function testConnection() {
            const testBtn = document.getElementById('testBtn');
            const originalText = testBtn.textContent;
            
            testBtn.textContent = '테스트 중...';
            testBtn.disabled = true;
            clearMessages();
            
            try {
                const response = await fetch('image_processor.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'test_connection'
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage('Google Drive API 연결이 정상입니다! ✅', 'success');
                } else {
                    showMessage('연결 테스트 실패: ' + result.error, 'error');
                }
                
            } catch (error) {
                console.error('연결 테스트 실패:', error);
                showMessage('연결 테스트 중 오류가 발생했습니다: ' + error.message, 'error');
            } finally {
                testBtn.textContent = originalText;
                testBtn.disabled = false;
            }
        }
        
        // UI 헬퍼 함수들
        
        function showLoading(show) {
            const loading = document.getElementById('loadingContainer');
            const container = document.getElementById('imageContainer');
            const empty = document.getElementById('emptyContainer');
            
            if (show) {
                loading.style.display = 'block';
                container.style.display = 'none';
                empty.style.display = 'none';
            } else {
                loading.style.display = 'none';
            }
        }
        
        function showEmptyState() {
            const loading = document.getElementById('loadingContainer');
            const container = document.getElementById('imageContainer');
            const empty = document.getElementById('emptyContainer');
            
            loading.style.display = 'none';
            container.style.display = 'none';
            empty.style.display = 'block';
        }
        
        function showProcessingModal(show) {
            const modal = document.getElementById('processingModal');
            modal.style.display = show ? 'flex' : 'none';
            
            if (show) {
                // 모든 단계 초기화
                for (let i = 1; i <= 4; i++) {
                    const step = document.getElementById(`step${i}`);
                    step.classList.remove('active', 'completed');
                }
            }
        }
        
        function updateProcessingStep(currentStep) {
            for (let i = 1; i <= 4; i++) {
                const step = document.getElementById(`step${i}`);
                step.classList.remove('active', 'completed');
                
                if (i < currentStep) {
                    step.classList.add('completed');
                } else if (i === currentStep) {
                    step.classList.add('active');
                }
            }
        }
        
        function showMessage(message, type = 'info') {
            const container = document.getElementById('messageContainer');
            const className = type === 'error' ? 'error-message' : 
                             type === 'success' ? 'success-message' : 
                             'success-message';
            
            container.innerHTML = `<div class="${className}">${message}</div>`;
            
            // 5초 후 자동 제거 (에러가 아닌 경우)
            if (type !== 'error') {
                setTimeout(() => {
                    container.innerHTML = '';
                }, 5000);
            }
        }
        
        function clearMessages() {
            document.getElementById('messageContainer').innerHTML = '';
        }
        
        function updateStatus(status) {
            document.getElementById('statusInfo').textContent = status;
        }
        
        function updateHistoryStatus(status) {
            document.getElementById('historyStatusInfo').textContent = status;
        }
    </script>
</body>
</html>