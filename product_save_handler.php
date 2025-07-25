<?php
/**
 * 상품 발굴 저장 시스템 - 백엔드 처리 핸들러
 * JSON 파일 저장 및 구글 시트 연동 처리
 */
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
if(!current_user_can('manage_options'))wp_die('접근 권한이 없습니다.');

// JSON 데이터 파일 경로
define('PRODUCT_SAVE_DATA_FILE', __DIR__ . '/product_save_data.json');

// CORS 헤더 설정
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// OPTIONS 요청 처리
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// POST 데이터 받기
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['action'])) {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$action = $data['action'];

switch ($action) {
    case 'save':
        saveProduct($data['data']);
        break;
    
    case 'load':
        loadProducts();
        break;
    
    case 'delete':
        deleteProduct($data['id']);
        break;
    
    case 'update':
        updateProduct($data['id'], $data['data']);
        break;
    
    case 'export':
        exportToGoogleSheets($data['ids']);
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => '알 수 없는 액션입니다.']);
        break;
}

/**
 * 상품 저장 함수
 */
function saveProduct($productData) {
    try {
        // 기존 데이터 로드
        $products = loadProductsData();
        
        // 새 상품 추가
        $products[] = $productData;
        
        // 파일에 저장
        if (saveProductsData($products)) {
            // 구글 시트에도 저장 시도 (옵션)
            // saveToGoogleSheets($productData);
            
            echo json_encode([
                'success' => true, 
                'message' => '상품이 성공적으로 저장되었습니다.',
                'id' => $productData['id']
            ]);
        } else {
            throw new Exception('파일 저장 실패');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => '저장 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 상품 목록 로드 함수
 */
function loadProducts() {
    try {
        $products = loadProductsData();
        
        // 날짜 역순으로 정렬 (최신 먼저)
        usort($products, function($a, $b) {
            return strtotime($b['created_at']) - strtotime($a['created_at']);
        });
        
        echo json_encode([
            'success' => true,
            'data' => $products,
            'count' => count($products)
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '데이터 로드 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 상품 삭제 함수
 */
function deleteProduct($id) {
    try {
        $products = loadProductsData();
        
        // ID로 상품 찾아서 삭제
        $products = array_filter($products, function($product) use ($id) {
            return $product['id'] !== $id;
        });
        
        // 배열 재색인
        $products = array_values($products);
        
        if (saveProductsData($products)) {
            echo json_encode([
                'success' => true,
                'message' => '상품이 삭제되었습니다.'
            ]);
        } else {
            throw new Exception('파일 저장 실패');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '삭제 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 상품 업데이트 함수
 */
function updateProduct($id, $newData) {
    try {
        $products = loadProductsData();
        
        // ID로 상품 찾아서 업데이트
        $updated = false;
        foreach ($products as &$product) {
            if ($product['id'] === $id) {
                // 기존 데이터에 새 데이터 병합
                $product = array_merge($product, $newData);
                $product['updated_at'] = date('Y-m-d H:i:s');
                $updated = true;
                break;
            }
        }
        
        if (!$updated) {
            throw new Exception('상품을 찾을 수 없습니다.');
        }
        
        if (saveProductsData($products)) {
            echo json_encode([
                'success' => true,
                'message' => '상품이 업데이트되었습니다.'
            ]);
        } else {
            throw new Exception('파일 저장 실패');
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '업데이트 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 구글 시트로 내보내기 함수
 */
function exportToGoogleSheets($productIds) {
    try {
        $products = loadProductsData();
        
        // 선택된 상품만 필터링
        if (!empty($productIds)) {
            $products = array_filter($products, function($product) use ($productIds) {
                return in_array($product['id'], $productIds);
            });
        }
        
        // 구글 시트 형식으로 데이터 변환
        $sheetData = [];
        foreach ($products as $product) {
            $sheetData[] = [
                $product['id'],
                $product['keyword'],
                $product['product_data']['title'] ?? '',
                $product['product_data']['price'] ?? '',
                $product['product_data']['rating_display'] ?? '',
                $product['product_data']['lastest_volume'] ?? '',
                $product['product_data']['image_url'] ?? '',
                $product['product_url'],
                $product['product_data']['affiliate_link'] ?? '',
                $product['created_at']
            ];
        }
        
        // TODO: 실제 구글 시트 API 연동 구현
        // 현재는 CSV 형식으로 반환
        echo json_encode([
            'success' => true,
            'message' => '구글 시트 내보내기 준비 완료',
            'data' => $sheetData,
            'count' => count($sheetData)
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '내보내기 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * JSON 파일에서 데이터 로드
 */
function loadProductsData() {
    if (!file_exists(PRODUCT_SAVE_DATA_FILE)) {
        return [];
    }
    
    $content = file_get_contents(PRODUCT_SAVE_DATA_FILE);
    $data = json_decode($content, true);
    
    return is_array($data) ? $data : [];
}

/**
 * JSON 파일에 데이터 저장
 */
function saveProductsData($products) {
    $json = json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // 파일 쓰기 권한 확인
    $dir = dirname(PRODUCT_SAVE_DATA_FILE);
    if (!is_writable($dir)) {
        throw new Exception('디렉토리에 쓰기 권한이 없습니다: ' . $dir);
    }
    
    // 원자적 쓰기를 위해 임시 파일 사용
    $tempFile = PRODUCT_SAVE_DATA_FILE . '.tmp';
    if (file_put_contents($tempFile, $json) === false) {
        return false;
    }
    
    // 원자적 이동
    return rename($tempFile, PRODUCT_SAVE_DATA_FILE);
}

/**
 * 구글 시트에 단일 상품 저장 (미구현)
 */
function saveToGoogleSheets($productData) {
    // TODO: 구글 시트 API 연동 구현
    // 1. OAuth 인증
    // 2. 시트 ID 및 범위 설정
    // 3. 데이터 추가
    
    // 현재는 로그만 남김
    error_log('Google Sheets 저장 대기: ' . json_encode($productData));
}

/**
 * 데이터 백업 함수 (일일 백업)
 */
function backupData() {
    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $backupFile = $backupDir . '/product_save_data_' . date('Y-m-d') . '.json';
    copy(PRODUCT_SAVE_DATA_FILE, $backupFile);
    
    // 30일 이상 된 백업 파일 삭제
    $files = glob($backupDir . '/*.json');
    $now = time();
    foreach ($files as $file) {
        if ($now - filemtime($file) >= 30 * 24 * 60 * 60) {
            unlink($file);
        }
    }
}

// 자동 백업 실행 (하루에 한 번)
$lastBackup = __DIR__ . '/.last_backup';
if (!file_exists($lastBackup) || date('Y-m-d', filemtime($lastBackup)) !== date('Y-m-d')) {
    backupData();
    touch($lastBackup);
}
?>