<?php
/**
 * 상품 정보 분석 API v2 (로그 최적화 버전)
 * 올바른 워크플로: 링크 변환 먼저 → 상품 정보 추출
 * 공식 가이드 기반 정확한 구현 + 완벽가이드 핵심 해결책 적용
 * 🔥 조용한 모드로 Python 호출하여 순수 JSON만 받음
 */

// 워드프레스 환경 로드
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-config.php');

// 관리자 권한 확인
if (!current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '접근 권한이 없습니다.']);
    exit;
}

// AJAX 요청만 처리
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '잘못된 요청 방식입니다.']);
    exit;
}

// 요청 데이터 파싱
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '잘못된 요청 데이터입니다.']);
    exit;
}

$action = $input['action'] ?? '';
$url = $input['url'] ?? '';
$platform = $input['platform'] ?? '';

if (!$action || !$url || !$platform) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '필수 파라미터가 누락되었습니다.']);
    exit;
}

// 헤더 설정
header('Content-Type: application/json');

if ($action === 'analyze_product') {
    analyzeProduct($url, $platform);
} else {
    echo json_encode(['success' => false, 'message' => '지원하지 않는 액션입니다.']);
}

/**
 * 상품 정보 분석 함수 (로그 최적화 적용)
 */
function analyzeProduct($url, $platform) {
    try {
        // 🔥 로그 최적화: 조용한 모드로 Python 스크립트 호출
        if ($platform === 'aliexpress') {
            $script_path = '/var/www/novacents/tools/official_guide_analyzer.py';
            
            // 🎯 핵심 개선: QUIET_MODE=1 환경변수 설정 + stderr 제거
            $command = "QUIET_MODE=1 " . escapeshellcmd("/usr/bin/python3 $script_path") . " " . 
                       escapeshellarg($platform) . " " . 
                       escapeshellarg($url) . " 2>/dev/null";  // stderr 완전 제거
        } else {
            // 쿠팡은 기존 방식 유지
            $script_path = '/var/www/novacents/tools/coupang_analyzer.py';
            $command = escapeshellcmd("/usr/bin/python3 $script_path") . " " . escapeshellarg($url);
        }
        
        // Python 스크립트가 없으면 오류 반환
        if (!file_exists($script_path)) {
            throw new Exception('분석 스크립트를 찾을 수 없습니다: ' . $script_path);
        }
        
        // 🚀 로그 최적화된 Python 스크립트 실행
        error_log("🔥 로그 최적화 스크립트 실행: " . $command);
        $output = shell_exec($command);
        
        if (empty($output)) {
            throw new Exception('Python 스크립트 실행 결과가 없습니다.');
        }
        
        // 🎯 순수 JSON 출력 확인
        $trimmed_output = trim($output);
        error_log("📨 순수 JSON 출력: " . substr($trimmed_output, 0, 200) . "...");
        
        // JSON 응답 파싱 (이제 순수 JSON만 들어옴)
        $result = json_decode($trimmed_output, true);
        
        if ($result === null) {
            error_log("❌ JSON 파싱 실패. 원본 출력: " . $trimmed_output);
            throw new Exception('Python 스크립트 응답 파싱 실패. 출력: ' . $trimmed_output);
        }
        
        // 🎯 알리익스프레스 로그 최적화 결과 처리
        if ($platform === 'aliexpress' && $result['success']) {
            $product_data = $result['data'];
            
            // 한국어 상품명 확인
            $hasKorean = preg_match('/[가-힣]/', $product_data['title']);
            
            // 로그 최적화 응답 포맷 (기존 형식 호환)
            $formatted_result = [
                'success' => true,
                'data' => [
                    'platform' => 'AliExpress',
                    'product_id' => $product_data['product_id'],
                    'title' => $product_data['title'],  // 🔥 한국어 상품명 (완벽가이드 적용)
                    'price' => $product_data['price'],  // 🔥 정확한 KRW 가격
                    'image_url' => $product_data['image_url'],
                    'category_name' => '알리익스프레스 상품',
                    'rating' => $product_data['rating'],
                    'review_count' => $product_data['review_count'],
                    'original_url' => $product_data['original_url'],
                    'affiliate_link' => $product_data['affiliate_link'],  // 🔥 어필리에이트 링크
                    'brand_name' => '',
                    'original_price' => '원가 정보 없음',
                    'discount_rate' => '할인율 정보 없음',
                    'method_used' => $product_data['method_used'] ?? 'unknown',
                    'korean_status' => $hasKorean ? '✅ 한국어 성공' : '❌ 영어 표시',
                    'perfect_guide_applied' => true,  // 🔥 완벽가이드 적용 표시
                    'log_optimized' => true  // 🔥 로그 최적화 적용 표시
                ],
                'debug_info' => [
                    'has_korean_title' => $hasKorean,
                    'title_preview' => mb_substr($product_data['title'], 0, 50) . '...',
                    'api_method' => $product_data['method_used'] ?? 'unknown',
                    'target_language' => 'ko',  // 완벽가이드 핵심
                    'guide_version' => 'perfect_guide_v1.0',
                    'log_optimization' => 'quiet_mode_enabled',  // 🔥 로그 최적화 정보
                    'json_parsing' => 'pure_json_success'  // 🔥 순수 JSON 파싱 성공
                ]
            ];
            
            error_log("✅ 로그 최적화 포맷팅 완료:");
            error_log("  한국어 상품명: " . ($hasKorean ? 'YES' : 'NO'));
            error_log("  제목: " . mb_substr($product_data['title'], 0, 50));
            error_log("  가격: " . $product_data['price']);
            error_log("  어필리에이트 링크: " . (strpos($product_data['affiliate_link'], 's.click.aliexpress.com') !== false ? 'YES' : 'NO'));
            error_log("  로그 최적화: ENABLED");
            
            echo json_encode($formatted_result);
        } 
        // 쿠팡 분석 결과 처리 (기존 방식)
        else if ($platform === 'coupang' && isset($result['success']) && $result['success']) {
            $product_info = $result['product_info'];
            $formatted_result = [
                'success' => true,
                'data' => [
                    'platform' => 'Coupang',
                    'product_id' => $product_info['product_id'],
                    'title' => $product_info['title'],
                    'price' => $product_info['price_formatted'],
                    'image_url' => $product_info['image_url'],
                    'category_name' => $product_info['category'],
                    'rating' => '정보 없음',
                    'review_count' => '정보 없음',
                    'is_rocket' => $product_info['is_rocket'],
                    'is_free_shipping' => $product_info['is_free_shipping'],
                    'original_url' => $result['original_url'],
                    'affiliate_link' => $product_info['affiliate_url'],
                    'brand_name' => '',
                    'original_price' => '원가 정보 없음',
                    'discount_rate' => '할인율 정보 없음'
                ]
            ];
            echo json_encode($formatted_result);
        } 
        // 일반적인 성공 응답
        else if (isset($result['success']) && $result['success']) {
            echo json_encode($result);
        }
        // 오류 응답
        else {
            $error_message = $result['message'] ?? '알 수 없는 오류가 발생했습니다.';
            throw new Exception($error_message);
        }
        
    } catch (Exception $e) {
        error_log("❌ 상품 분석 오류: " . $e->getMessage());
        
        echo json_encode([
            'success' => false,
            'message' => '상품 분석 중 오류가 발생했습니다: ' . $e->getMessage(),
            'raw_output' => $output ?? null,
            'debug_info' => [
                'platform' => $platform,
                'url' => $url,
                'script_path' => $script_path ?? 'undefined',
                'command' => $command ?? 'undefined',
                'perfect_guide_applied' => $platform === 'aliexpress',
                'log_optimization' => 'attempted',  // 🔥 로그 최적화 시도 표시
                'error_type' => 'analysis_error'
            ]
        ]);
    }
}

/**
 * 로그 최적화된 상품 정보 검증 함수
 */
function validateOptimizedResult($data) {
    $validation = [
        'has_korean_title' => false,
        'has_krw_price' => false,
        'has_affiliate_link' => false,
        'log_optimized' => false,
        'score' => 0
    ];
    
    // 한국어 상품명 검증
    if (isset($data['title']) && preg_match('/[가-힣]/', $data['title'])) {
        $validation['has_korean_title'] = true;
        $validation['score'] += 30;
    }
    
    // KRW 가격 검증
    if (isset($data['price']) && strpos($data['price'], '원') !== false) {
        $validation['has_krw_price'] = true;
        $validation['score'] += 30;
    }
    
    // 어필리에이트 링크 검증
    if (isset($data['affiliate_link']) && strpos($data['affiliate_link'], 's.click.aliexpress.com') !== false) {
        $validation['has_affiliate_link'] = true;
        $validation['score'] += 30;
    }
    
    // 로그 최적화 확인
    if (isset($data['log_optimized']) && $data['log_optimized'] === true) {
        $validation['log_optimized'] = true;
        $validation['score'] += 10;
    }
    
    return $validation;
}

/**
 * 디버그 정보 로깅 함수 (로그 최적화 버전)
 */
function logOptimizedDebugInfo($platform, $result, $url) {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'platform' => $platform,
        'url' => $url,
        'success' => $result['success'] ?? false,
        'has_korean' => false,
        'method_used' => 'unknown',
        'log_optimized' => false
    ];
    
    if ($platform === 'aliexpress' && isset($result['data'])) {
        $log_entry['has_korean'] = preg_match('/[가-힣]/', $result['data']['title'] ?? '');
        $log_entry['method_used'] = $result['data']['method_used'] ?? 'unknown';
        $log_entry['title_preview'] = mb_substr($result['data']['title'] ?? '', 0, 30);
        $log_entry['log_optimized'] = $result['data']['log_optimized'] ?? false;
    }
    
    $log_file = '/var/www/novacents/tools/cache/analysis_optimized.log';
    file_put_contents($log_file, json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);
}

// 🎯 로그 최적화 완료!
// 이제 Python에서 QUIET_MODE=1일 때 순수 JSON만 출력하고,
// PHP에서는 깨끗한 JSON을 파싱할 수 있습니다.
?>
