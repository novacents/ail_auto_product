<?php
/**
 * 상품 발굴 저장 시스템 - 백엔드 처리 핸들러 (구글 시트 전용)
 * JSON 파일 의존성 제거, 구글 시트만을 단일 데이터 소스로 사용
 */
require_once($_SERVER['DOCUMENT_ROOT'].'/wp-config.php');
if(!current_user_can('manage_options'))wp_die('접근 권한이 없습니다.');

// 구글 시트 매니저 포함
require_once(__DIR__ . '/google_sheets_manager.php');

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
    
    case 'export_to_sheets':
        exportSelectedToGoogleSheets($data['ids'] ?? []);
        break;
    
    case 'get_sheets_url':
        getGoogleSheetsUrl();
        break;
        
    case 'sync_to_sheets':
        syncAllToGoogleSheets();
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => '알 수 없는 액션입니다.']);
        break;
}

/**
 * 상품 저장 함수 (구글 시트 직접 저장)
 */
function saveProduct($productData) {
    try {
        $sheetsManager = new GoogleSheetsManager();
        
        // 구글 시트 생성/확인
        $spreadsheetResult = $sheetsManager->getOrCreateSpreadsheet();
        if (!$spreadsheetResult['success']) {
            throw new Exception('구글 시트 생성/접근 실패: ' . $spreadsheetResult['error']);
        }
        
        // 상품 추가
        $result = $sheetsManager->addProduct($productData);
        
        if (!$result['success']) {
            throw new Exception('구글 시트 저장 실패: ' . $result['error']);
        }
        
        echo json_encode([
            'success' => true, 
            'message' => '상품이 구글 시트에 성공적으로 저장되었습니다.',
            'id' => $productData['id'],
            'spreadsheet_url' => $result['spreadsheet_url'],
            'row' => $result['row']
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => '저장 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 상품 목록 로드 함수 (구글 시트에서 직접 읽기)
 */
function loadProducts() {
    try {
        $sheetsManager = new GoogleSheetsManager();
        
        // 구글 시트에서 모든 데이터 읽기
        $result = $sheetsManager->getAllProducts();
        
        if (!$result['success']) {
            throw new Exception('구글 시트 읽기 실패: ' . $result['error']);
        }
        
        // 시트 데이터를 상품 객체로 변환
        $products = [];
        foreach ($result['data'] as $row) {
            $product = $sheetsManager->convertRowToProduct($row);
            if ($product) {
                $products[] = $product;
            }
        }
        
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
 * 상품 삭제 함수 (구글 시트에서 직접 삭제)
 */
function deleteProduct($id) {
    try {
        $sheetsManager = new GoogleSheetsManager();
        
        $result = $sheetsManager->deleteProduct($id);
        
        if (!$result['success']) {
            throw new Exception('구글 시트 삭제 실패: ' . $result['error']);
        }
        
        echo json_encode([
            'success' => true,
            'message' => '상품이 구글 시트에서 삭제되었습니다.',
            'deleted_row' => $result['deleted_row']
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '삭제 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 상품 업데이트 함수 (구글 시트에서 직접 업데이트)
 * 참고: 현재는 삭제 후 재추가 방식으로 구현
 */
function updateProduct($id, $newData) {
    try {
        $sheetsManager = new GoogleSheetsManager();
        
        // 기존 데이터 삭제
        $deleteResult = $sheetsManager->deleteProduct($id);
        if (!$deleteResult['success']) {
            throw new Exception('기존 데이터 삭제 실패: ' . $deleteResult['error']);
        }
        
        // 새 데이터로 추가
        $newData['updated_at'] = date('Y-m-d H:i:s');
        $addResult = $sheetsManager->addProduct($newData);
        
        if (!$addResult['success']) {
            throw new Exception('새 데이터 추가 실패: ' . $addResult['error']);
        }
        
        echo json_encode([
            'success' => true,
            'message' => '상품이 업데이트되었습니다.',
            'new_row' => $addResult['row']
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '업데이트 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 선택된 상품들을 구글 시트로 내보내기 (이미 구글 시트에 있으므로 의미 없음)
 * 호환성을 위해 성공 메시지만 반환
 */
function exportSelectedToGoogleSheets($productIds) {
    try {
        echo json_encode([
            'success' => true,
            'message' => '모든 데이터가 이미 구글 시트에 저장되어 있습니다.',
            'note' => '단일 데이터 소스 시스템으로 별도 내보내기가 불필요합니다.'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 구글 시트 URL 가져오기
 */
function getGoogleSheetsUrl() {
    try {
        $sheetsManager = new GoogleSheetsManager();
        
        // getOrCreateSpreadsheet() 메서드를 사용하여 시트 생성 또는 찾기
        $result = $sheetsManager->getOrCreateSpreadsheet();
        
        if (!$result['success']) {
            throw new Exception($result['error']);
        }
        
        echo json_encode([
            'success' => true,
            'spreadsheet_url' => $result['spreadsheet_url']
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '구글 시트 URL을 가져올 수 없습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 모든 데이터를 구글 시트와 동기화 (이미 구글 시트에 있으므로 의미 없음)
 * 호환성을 위해 성공 메시지만 반환
 */
function syncAllToGoogleSheets() {
    try {
        $sheetsManager = new GoogleSheetsManager();
        
        // 현재 데이터 확인
        $result = $sheetsManager->getAllProducts();
        
        if (!$result['success']) {
            throw new Exception($result['error']);
        }
        
        $spreadsheetResult = $sheetsManager->getOrCreateSpreadsheet();
        
        echo json_encode([
            'success' => true,
            'message' => '모든 데이터가 이미 구글 시트에 저장되어 있습니다.',
            'data_count' => $result['count'],
            'spreadsheet_url' => $spreadsheetResult['spreadsheet_url'],
            'note' => '단일 데이터 소스 시스템으로 별도 동기화가 불필요합니다.'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '동기화 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
}

/**
 * 자동 백업 함수 (구글 시트 자체가 백업이므로 비활성화)
 * 호환성을 위해 함수는 유지하지만 실제로는 아무것도 하지 않음
 */
function backupData() {
    // Google Sheets 자체가 자동 백업되므로 별도 백업 불필요
    return true;
}

// 이전 버전과의 호환성을 위해 백업 코드는 유지하지만 실행하지 않음
// Google Sheets는 자동으로 버전 관리되므로 별도 백업이 불필요
?>