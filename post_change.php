<?php
/**
 * WordPress 글 상품 링크 편집기
 * 글 URL을 입력하면 기존 상품 정보를 보여주고 편집/삭제/추가 가능
 * 파일 위치: /var/www/novacents/tools/post_change.php
 */

// 워드프레스 환경 로드 - 워드프레스 관리자 권한 확인을 위해 필요합니다.
require_once('/var/www/novacents/wp-config.php');

// 관리자 권한 확인
if (!current_user_can('manage_options')) {
    wp_die('접근 권한이 없습니다.');
}

// 환경변수 로드
$env_file = '/home/novacents/.env';
$env_vars = [];
if (file_exists($env_file)) {
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && !str_starts_with(trim($line), '#')) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, '"\'');
            $env_vars[$key] = $value;
        }
    }
}

// WordPress API 설정
$wp_url = $env_vars['NOVACENTS_WP_URL'] ?? 'https://novacents.com';
$wp_user = $env_vars['NOVACENTS_WP_USER'] ?? 'admin';
$wp_pass = $env_vars['NOVACENTS_WP_APP_PASS'] ?? '';

// 에러 로그 함수
function logError($message) {
    $log_file = '/var/www/novacents/tools/cache/post_change_error.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// WordPress API 호출 함수
function callWordPressAPI($endpoint, $method = 'GET', $data = null) {
    global $wp_url, $wp_user, $wp_pass;
    
    $url = $wp_url . '/wp-json/wp/v2/' . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($wp_user . ':' . $wp_pass),
        'Content-Type: application/json'
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200) {
        return json_decode($response, true);
    } else {
        logError("WordPress API Error: HTTP $http_code - $response");
        return null;
    }
}

// 상품 링크 추출 함수
function extractProductLinks($content) {
    $product_links = [];
    
    // 쿠팡 링크 패턴
    $coupang_patterns = [
        '/https:\/\/(?:www\.)?coupang\.com\/[^\s"\'<>]+/i',
        '/https:\/\/link\.coupang\.com\/[^\s"\'<>]+/i',
        '/https:\/\/ads-partners\.coupang\.com\/[^\s"\'<>]+/i'
    ];
    
    // 알리익스프레스 링크 패턴
    $aliexpress_patterns = [
        '/https:\/\/(?:ko\.)?aliexpress\.com\/[^\s"\'<>]+/i',
        '/https:\/\/s\.click\.aliexpress\.com\/[^\s"\'<>]+/i',
        '/https:\/\/[a-z]+\.aliexpress\.com\/[^\s"\'<>]+/i'
    ];
    
    $all_patterns = array_merge($coupang_patterns, $aliexpress_patterns);
    
    foreach ($all_patterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[0] as $match) {
                $clean_link = html_entity_decode(trim($match));
                if (!in_array($clean_link, $product_links)) {
                    $product_links[] = $clean_link;
                }
            }
        }
    }
    
    return $product_links;
}

// Python API 호출 함수 (상품 정보 조회용)
function getProductInfo($product_url) {
    $python_script = '/var/www/novacents/tools/get_product_info.py';
    $command = "cd /var/www/novacents/tools && python3 $python_script " . escapeshellarg($product_url);
    $output = shell_exec($command);
    
    if ($output) {
        return json_decode(trim($output), true);
    }
    
    return null;
}

// 글 ID 추출 함수
function extractPostIdFromUrl($url) {
    // URL에서 post ID 추출 시도
    if (preg_match('/\/(\d+)\/?$/', $url, $matches)) {
        return intval($matches[1]);
    }
    
    // ?p=123 형태
    if (preg_match('/[?&]p=(\d+)/', $url, $matches)) {
        return intval($matches[1]);
    }
    
    // post_id=123 형태
    if (preg_match('/[?&]post_id=(\d+)/', $url, $matches)) {
        return intval($matches[1]);
    }
    
    return null;
}

// 메인 처리 로직
$post_data = null;
$product_links = [];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'load_post' && !empty($_POST['post_url'])) {
            // 글 로드
            $post_url = trim($_POST['post_url']);
            $post_id = extractPostIdFromUrl($post_url);
            
            if ($post_id) {
                $post_data = callWordPressAPI("posts/$post_id");
                if ($post_data) {
                    $content = $post_data['content']['rendered'];
                    $product_links = extractProductLinks($content);
                    $message = "글을 성공적으로 로드했습니다. 상품 링크 " . count($product_links) . "개를 발견했습니다.";
                } else {
                    $error = "글을 찾을 수 없습니다.";
                }
            } else {
                $error = "올바른 글 URL을 입력해주세요.";
            }
        } elseif ($_POST['action'] === 'update_post' && !empty($_POST['post_id'])) {
            // 글 업데이트
            $post_id = intval($_POST['post_id']);
            $new_links = array_filter(explode("\n", trim($_POST['product_links'])));
            
            // Python 스크립트를 통해 상품 정보 및 어필리에이트 링크 생성
            $update_command = sprintf(
                "cd /var/www/novacents/tools && python3 update_post_products.py %d %s",
                $post_id,
                escapeshellarg(implode(',', $new_links))
            );
            
            $update_result = shell_exec($update_command);
            
            if ($update_result) {
                $result = json_decode(trim($update_result), true);
                if ($result && $result['success']) {
                    $message = "글이 성공적으로 업데이트되었습니다!";
                    // 글 다시 로드
                    $post_data = callWordPressAPI("posts/$post_id");
                    if ($post_data) {
                        $content = $post_data['content']['rendered'];
                        $product_links = extractProductLinks($content);
                    }
                } else {
                    $error = "글 업데이트 중 오류가 발생했습니다: " . ($result['error'] ?? '알 수 없는 오류');
                }
            } else {
                $error = "Python 스크립트 실행 실패";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>글 상품 링크 편집기 - Novacents</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #ff6b35 0%, #ff8e53 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .header p {
            font-size: 1.1em;
            opacity: 0.9;
        }

        .content {
            padding: 40px;
        }

        .form-section {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 5px solid #ff6b35;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
            font-size: 1.1em;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #ff6b35;
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ff6b35 0%, #ff8e53 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 53, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
            font-size: 14px;
            padding: 8px 16px;
            margin-left: 10px;
        }

        .btn-danger:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(220, 53, 69, 0.4);
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .post-info {
            background: #e3f2fd;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 5px solid #2196f3;
        }

        .post-info h3 {
            color: #1976d2;
            margin-bottom: 10px;
        }

        .post-info p {
            margin-bottom: 5px;
            color: #555;
        }

        .product-item {
            background: white;
            border: 2px solid #eee;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            position: relative;
        }

        .product-item:hover {
            border-color: #ff6b35;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .product-link {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            word-break: break-all;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .product-actions {
            text-align: right;
            margin-top: 10px;
        }

        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .platform-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            margin-right: 10px;
        }

        .platform-coupang {
            background: #ff6b35;
            color: white;
        }

        .platform-aliexpress {
            background: #ff4757;
            color: white;
        }

        textarea {
            min-height: 200px;
            resize: vertical;
        }

        .help-text {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .header h1 {
                font-size: 2em;
            }
            
            .content {
                padding: 20px;
            }
            
            .form-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 글 상품 링크 편집기</h1>
            <p>WordPress 글의 상품 링크를 쉽게 편집하고 업데이트하세요</p>
        </div>

        <div class="content">
            <?php if ($message): ?>
                <div class="alert alert-success">
                    ✅ <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    ❌ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- 글 로드 섹션 -->
            <div class="form-section">
                <h2>📄 1단계: 편집할 글 로드</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="load_post">
                    <div class="form-group">
                        <label for="post_url">글 URL:</label>
                        <input type="url" 
                               name="post_url" 
                               id="post_url" 
                               class="form-control" 
                               placeholder="https://novacents.com/post-title/"
                               value="<?= htmlspecialchars($_POST['post_url'] ?? '') ?>"
                               required>
                        <div class="help-text">
                            편집하고 싶은 WordPress 글의 URL을 입력하세요
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        🔍 글 로드하기
                    </button>
                </form>
            </div>

            <?php if ($post_data): ?>
                <!-- 글 정보 표시 -->
                <div class="post-info">
                    <h3>📝 로드된 글 정보</h3>
                    <p><strong>제목:</strong> <?= htmlspecialchars($post_data['title']['rendered']) ?></p>
                    <p><strong>URL:</strong> <a href="<?= htmlspecialchars($post_data['link']) ?>" target="_blank"><?= htmlspecialchars($post_data['link']) ?></a></p>
                    <p><strong>발행일:</strong> <?= date('Y-m-d H:i:s', strtotime($post_data['date'])) ?></p>
                    <p><strong>글 ID:</strong> <?= $post_data['id'] ?></p>
                </div>

                <!-- 통계 -->
                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-number"><?= count($product_links) ?></div>
                        <div class="stat-label">발견된 상품 링크</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= count(array_filter($product_links, function($link) { 
                            return stripos($link, 'coupang.com') !== false; 
                        })) ?></div>
                        <div class="stat-label">쿠팡 상품</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= count(array_filter($product_links, function($link) { 
                            return stripos($link, 'aliexpress.com') !== false; 
                        })) ?></div>
                        <div class="stat-label">알리익스프레스 상품</div>
                    </div>
                </div>

                <!-- 상품 링크 편집 섹션 -->
                <div class="form-section">
                    <h2>🛒 2단계: 상품 링크 편집</h2>
                    
                    <?php if (!empty($product_links)): ?>
                        <h3>현재 상품 링크들:</h3>
                        <?php foreach ($product_links as $index => $link): ?>
                            <div class="product-item">
                                <div class="platform-badge <?= stripos($link, 'coupang.com') !== false ? 'platform-coupang' : 'platform-aliexpress' ?>">
                                    <?= stripos($link, 'coupang.com') !== false ? '쿠팡' : '알리익스프레스' ?>
                                </div>
                                <div class="product-link"><?= htmlspecialchars($link) ?></div>
                                <div class="product-actions">
                                    <button type="button" class="btn btn-danger" onclick="removeProductLink(<?= $index ?>)">
                                        🗑️ 삭제
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p>이 글에서 상품 링크를 찾을 수 없습니다.</p>
                    <?php endif; ?>

                    <form method="POST" id="updateForm">
                        <input type="hidden" name="action" value="update_post">
                        <input type="hidden" name="post_id" value="<?= $post_data['id'] ?>">
                        
                        <div class="form-group">
                            <label for="product_links">상품 링크 목록 (한 줄에 하나씩):</label>
                            <textarea name="product_links" 
                                      id="product_links" 
                                      class="form-control"
                                      placeholder="새로운 상품 링크를 입력하세요&#10;예:&#10;https://www.coupang.com/vp/products/123456&#10;https://ko.aliexpress.com/item/123456.html"><?= implode("\n", $product_links) ?></textarea>
                            <div class="help-text">
                                • 상품 링크를 수정, 삭제, 추가할 수 있습니다<br>
                                • 일반 상품 링크를 입력하면 자동으로 어필리에이트 링크로 변환됩니다<br>
                                • 상품 정보(이미지, 가격, 평점)도 자동으로 추출됩니다
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-success">
                            💾 글 업데이트하기
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- 도움말 섹션 -->
            <div class="form-section">
                <h2>💡 사용법</h2>
                <ol>
                    <li><strong>글 URL 입력:</strong> 편집하고 싶은 WordPress 글의 URL을 입력합니다</li>
                    <li><strong>상품 링크 확인:</strong> 현재 글에 있는 상품 링크들을 확인합니다</li>
                    <li><strong>링크 편집:</strong> 필요에 따라 상품 링크를 수정, 삭제, 추가합니다</li>
                    <li><strong>자동 처리:</strong> 일반 상품 링크가 어필리에이트 링크로 자동 변환됩니다</li>
                    <li><strong>글 업데이트:</strong> 변경사항이 WordPress 글에 바로 반영됩니다</li>
                </ol>
                
                <h3>✨ 자동 처리 기능</h3>
                <ul>
                    <li>🔗 일반 상품 링크 → 어필리에이트 링크 자동 변환</li>
                    <li>📦 상품 정보 자동 추출 (제목, 가격, 이미지, 평점)</li>
                    <li>🎨 예쁜 상품 박스 자동 생성</li>
                    <li>🛒 구매 버튼 자동 생성 및 링크 설정</li>
                    <li>📱 텔레그램 업데이트 알림 자동 발송</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // 상품 링크 삭제 함수
        function removeProductLink(index) {
            const textarea = document.getElementById('product_links');
            const lines = textarea.value.split('\n');
            lines.splice(index, 1);
            textarea.value = lines.join('\n');
            
            // 페이지 새로고침 (실제로는 AJAX로 처리하는 것이 더 좋음)
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="load_post">
                <input type="hidden" name="post_url" value="<?= htmlspecialchars($_POST['post_url'] ?? '') ?>">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        // 폼 제출 시 확인
        document.getElementById('updateForm')?.addEventListener('submit', function(e) {
            if (!confirm('정말로 글을 업데이트하시겠습니까?\n\n변경사항:\n- 상품 링크가 어필리에이트 링크로 변환됩니다\n- 상품 정보가 자동으로 추출됩니다\n- 글 내용이 업데이트됩니다')) {
                e.preventDefault();
            }
        });

        // 텍스트 영역 자동 높이 조절
        const textarea = document.getElementById('product_links');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.max(200, this.scrollHeight) + 'px';
            });
        }
    </script>
</body>
</html>