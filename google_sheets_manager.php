<?php
/**
 * Google Sheets API 연동 관리자
 * 상품 발굴 데이터를 구글 시트에 저장하고 관리
 */

class GoogleSheetsManager {
    private $client;
    private $service;
    private $spreadsheetId;
    private $spreadsheetName = '상품 발굴 데이터';
    
    // 구글 시트 열 구조
    private $headers = [
        'ID', '키워드', '상품명', '가격', '평점', '판매량', 
        '이미지URL', '상품URL', '어필리에이트링크', '생성일시'
    ];
    
    public function __construct() {
        $this->initializeClient();
    }
    
    /**
     * Google API 클라이언트 초기화
     */
    private function initializeClient() {
        try {
            // Composer autoload (Google API Client 라이브러리 필요)
            if (file_exists(__DIR__ . '/vendor/autoload.php')) {
                require_once __DIR__ . '/vendor/autoload.php';
            } else {
                throw new Exception('Google API Client 라이브러리가 설치되지 않았습니다.');
            }
            
            $this->client = new Google_Client();
            $this->client->setApplicationName('Product Save System');
            $this->client->setScopes([Google_Service_Sheets::SPREADSHEETS]);
            $this->client->setAuthConfig($this->getCredentialsPath());
            $this->client->setAccessType('offline');
            $this->client->setPrompt('select_account consent');
            
            // 토큰 설정
            $tokenPath = $this->getTokenPath();
            if (file_exists($tokenPath)) {
                $accessToken = json_decode(file_get_contents($tokenPath), true);
                $this->client->setAccessToken($accessToken);
            }
            
            // 토큰 갱신 확인
            if ($this->client->isAccessTokenExpired()) {
                if ($this->client->getRefreshToken()) {
                    $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                    file_put_contents($tokenPath, json_encode($this->client->getAccessToken()));
                } else {
                    throw new Exception('구글 시트 인증이 필요합니다. oauth_setup.php를 통해 인증을 완료해주세요.');
                }
            }
            
            $this->service = new Google_Service_Sheets($this->client);
            
        } catch (Exception $e) {
            throw new Exception('Google API 초기화 실패: ' . $e->getMessage());
        }
    }
    
    /**
     * 인증 정보 파일 경로
     */
    private function getCredentialsPath() {
        $possiblePaths = [
            __DIR__ . '/credentials.json',
            '/home/novacents/credentials.json',
            $_SERVER['DOCUMENT_ROOT'] . '/tools/credentials.json'
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        throw new Exception('credentials.json 파일을 찾을 수 없습니다.');
    }
    
    /**
     * 토큰 파일 경로
     */
    private function getTokenPath() {
        return __DIR__ . '/token.json';
    }
    
    /**
     * 스프레드시트 생성 또는 기존 시트 찾기
     */
    public function getOrCreateSpreadsheet() {
        try {
            // 기존 스프레드시트 ID가 있는지 확인
            $configFile = __DIR__ . '/google_sheets_config.json';
            if (file_exists($configFile)) {
                $config = json_decode(file_get_contents($configFile), true);
                if (isset($config['spreadsheet_id'])) {
                    $this->spreadsheetId = $config['spreadsheet_id'];
                    
                    // 스프레드시트가 실제로 존재하는지 확인
                    try {
                        $this->service->spreadsheets->get($this->spreadsheetId);
                        return $this->spreadsheetId;
                    } catch (Exception $e) {
                        // 스프레드시트가 존재하지 않으면 새로 생성
                        unset($this->spreadsheetId);
                    }
                }
            }
            
            // 새 스프레드시트 생성
            $spreadsheet = new Google_Service_Sheets_Spreadsheet([
                'properties' => [
                    'title' => $this->spreadsheetName
                ],
                'sheets' => [
                    [
                        'properties' => [
                            'title' => 'Sheet1'
                        ]
                    ]
                ]
            ]);
            
            $spreadsheet = $this->service->spreadsheets->create($spreadsheet, [
                'fields' => 'spreadsheetId'
            ]);
            
            $this->spreadsheetId = $spreadsheet->spreadsheetId;
            
            // 설정 파일에 스프레드시트 ID 저장
            file_put_contents($configFile, json_encode([
                'spreadsheet_id' => $this->spreadsheetId,
                'created_at' => date('Y-m-d H:i:s')
            ], JSON_PRETTY_PRINT));
            
            // 헤더 추가
            $this->addHeaders();
            
            return $this->spreadsheetId;
            
        } catch (Exception $e) {
            throw new Exception('스프레드시트 생성/조회 실패: ' . $e->getMessage());
        }
    }
    
    /**
     * 헤더 행 추가
     */
    private function addHeaders() {
        try {
            $range = 'Sheet1!A1:J1';
            $values = [$this->headers];
            
            $body = new Google_Service_Sheets_ValueRange([
                'values' => $values
            ]);
            
            $params = [
                'valueInputOption' => 'RAW'
            ];
            
            $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );
            
            // 헤더 행 스타일 설정
            $this->formatHeaders();
            
        } catch (Exception $e) {
            throw new Exception('헤더 추가 실패: ' . $e->getMessage());
        }
    }
    
    /**
     * 헤더 행 포맷팅
     */
    private function formatHeaders() {
        try {
            $requests = [
                [
                    'repeatCell' => [
                        'range' => [
                            'sheetId' => 0,
                            'startRowIndex' => 0,
                            'endRowIndex' => 1
                        ],
                        'cell' => [
                            'userEnteredFormat' => [
                                'backgroundColor' => [
                                    'red' => 0.9,
                                    'green' => 0.9,
                                    'blue' => 0.9
                                ],
                                'textFormat' => [
                                    'bold' => true
                                ]
                            ]
                        ],
                        'fields' => 'userEnteredFormat(backgroundColor,textFormat)'
                    ]
                ]
            ];
            
            $batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest([
                'requests' => $requests
            ]);
            
            $this->service->spreadsheets->batchUpdate(
                $this->spreadsheetId,
                $batchUpdateRequest
            );
            
        } catch (Exception $e) {
            // 포맷팅 실패는 무시 (데이터 저장에는 영향 없음)
            error_log('헤더 포맷팅 실패: ' . $e->getMessage());
        }
    }
    
    /**
     * 단일 상품 데이터 추가
     */
    public function addProduct($productData) {
        try {
            if (!$this->spreadsheetId) {
                $this->getOrCreateSpreadsheet();
            }
            
            // 데이터 변환
            $row = $this->convertProductToRow($productData);
            
            // 다음 빈 행 찾기
            $range = 'Sheet1!A:A';
            $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $range);
            $values = $response->getValues();
            $nextRow = count($values) + 1;
            
            // 데이터 추가
            $range = 'Sheet1!A' . $nextRow . ':J' . $nextRow;
            $body = new Google_Service_Sheets_ValueRange([
                'values' => [$row]
            ]);
            
            $params = [
                'valueInputOption' => 'RAW'
            ];
            
            $result = $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );
            
            return [
                'success' => true,
                'row' => $nextRow,
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/' . $this->spreadsheetId
            ];
            
        } catch (Exception $e) {
            throw new Exception('상품 데이터 추가 실패: ' . $e->getMessage());
        }
    }
    
    /**
     * 여러 상품 데이터 일괄 추가
     */
    public function addProducts($productsData) {
        try {
            if (!$this->spreadsheetId) {
                $this->getOrCreateSpreadsheet();
            }
            
            // 데이터 변환
            $rows = [];
            foreach ($productsData as $productData) {
                $rows[] = $this->convertProductToRow($productData);
            }
            
            // 다음 빈 행 찾기
            $range = 'Sheet1!A:A';
            $response = $this->service->spreadsheets_values->get($this->spreadsheetId, $range);
            $values = $response->getValues();
            $nextRow = count($values) + 1;
            
            // 데이터 추가
            $endRow = $nextRow + count($rows) - 1;
            $range = 'Sheet1!A' . $nextRow . ':J' . $endRow;
            
            $body = new Google_Service_Sheets_ValueRange([
                'values' => $rows
            ]);
            
            $params = [
                'valueInputOption' => 'RAW'
            ];
            
            $result = $this->service->spreadsheets_values->update(
                $this->spreadsheetId,
                $range,
                $body,
                $params
            );
            
            return [
                'success' => true,
                'rows_added' => count($rows),
                'start_row' => $nextRow,
                'end_row' => $endRow,
                'spreadsheet_url' => 'https://docs.google.com/spreadsheets/d/' . $this->spreadsheetId
            ];
            
        } catch (Exception $e) {
            throw new Exception('상품 데이터 일괄 추가 실패: ' . $e->getMessage());
        }
    }
    
    /**
     * 상품 데이터를 시트 행으로 변환
     */
    private function convertProductToRow($productData) {
        return [
            $productData['id'] ?? '',
            $productData['keyword'] ?? '',
            $productData['product_data']['title'] ?? '',
            $productData['product_data']['price'] ?? '',
            $productData['product_data']['rating_display'] ?? '',
            $productData['product_data']['lastest_volume'] ?? '',
            $productData['product_data']['image_url'] ?? '',
            $productData['product_url'] ?? '',
            $productData['product_data']['affiliate_link'] ?? '',
            $productData['created_at'] ?? date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * 스프레드시트 URL 가져오기
     */
    public function getSpreadsheetUrl() {
        if (!$this->spreadsheetId) {
            $this->getOrCreateSpreadsheet();
        }
        
        return 'https://docs.google.com/spreadsheets/d/' . $this->spreadsheetId;
    }
    
    /**
     * 스프레드시트 ID 가져오기
     */
    public function getSpreadsheetId() {
        if (!$this->spreadsheetId) {
            $this->getOrCreateSpreadsheet();
        }
        
        return $this->spreadsheetId;
    }
    
    /**
     * 인증 URL 생성 (최초 설정용)
     */
    public function getAuthUrl() {
        return $this->client->createAuthUrl();
    }
    
    /**
     * 인증 코드로 토큰 교환
     */
    public function exchangeAuthCode($authCode) {
        try {
            $accessToken = $this->client->fetchAccessTokenWithAuthCode($authCode);
            
            if (isset($accessToken['error'])) {
                throw new Exception('인증 실패: ' . $accessToken['error_description']);
            }
            
            // 토큰 저장
            $tokenPath = $this->getTokenPath();
            file_put_contents($tokenPath, json_encode($accessToken));
            
            return true;
            
        } catch (Exception $e) {
            throw new Exception('토큰 교환 실패: ' . $e->getMessage());
        }
    }
    
    /**
     * 인증 상태 확인
     */
    public function isAuthenticated() {
        try {
            return !$this->client->isAccessTokenExpired();
        } catch (Exception $e) {
            return false;
        }
    }
}
?>