# 구글 시트 API 설정 가이드

## 📋 개요
상품 발굴 저장 시스템에서 구글 시트 연동을 위한 설정 가이드입니다.
계획서에 따르면 이미 Google Sheets API 설정이 완료되어 있으므로, 추가 설정만 필요합니다.

## 🔧 기존 설정 정보
- **Google Sheets API**: 활성화 완료 ✅
- **OAuth 2.0 클라이언트 ID**: `558249385120-ohflnjvcjm3uelsmibhq8ud1j3folgb5.apps.googleusercontent.com` ✅
- **승인된 리디렉션 URI**: `https://novacents.com/tools/oauth_setup.php` ✅
- **구글 시트 이름**: `상품 발굴 데이터` ✅

## 📦 필요한 추가 설정

### 1. Google API PHP Client 라이브러리 설치

```bash
# Composer를 사용하여 Google API Client 라이브러리 설치
cd /home/novacents/ail_auto_product
composer require google/apiclient:^2.0
```

### 2. credentials.json 파일 생성

다음 위치 중 하나에 `credentials.json` 파일을 생성하세요:
- `/home/novacents/ail_auto_product/credentials.json` (권장)
- `/home/novacents/credentials.json`
- `/home/novacents/tools/credentials.json`

**credentials.json 파일 내용:**
```json
{
  "web": {
    "client_id": "558249385120-ohflnjvcjm3uelsmibhq8ud1j3folgb5.apps.googleusercontent.com",
    "project_id": "YOUR_PROJECT_ID",
    "auth_uri": "https://accounts.google.com/o/oauth2/auth",
    "token_uri": "https://oauth2.googleapis.com/token",
    "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
    "client_secret": "YOUR_CLIENT_SECRET",
    "redirect_uris": [
      "https://novacents.com/tools/oauth_setup.php"
    ]
  }
}
```

**주의:** `YOUR_PROJECT_ID`와 `YOUR_CLIENT_SECRET`을 실제 값으로 교체하세요.

### 3. OAuth 인증 설정

기존 `oauth_setup.php` 파일을 사용하여 초기 인증을 완료하세요:

1. 브라우저에서 `https://novacents.com/tools/oauth_setup.php` 접속
2. 구글 계정으로 로그인 및 권한 승인
3. 생성된 `token.json` 파일 확인

### 4. 파일 권한 설정

```bash
# 적절한 파일 권한 설정
chmod 600 /home/novacents/ail_auto_product/credentials.json
chmod 600 /home/novacents/ail_auto_product/token.json
chmod 755 /home/novacents/ail_auto_product/google_sheets_manager.php
```

## 📊 구글 시트 구조

시스템은 다음 구조로 구글 시트를 생성합니다:

| A | B | C | D | E | F | G | H | I | J |
|---|---|---|---|---|---|---|---|---|---|
| ID | 키워드 | 상품명 | 가격 | 평점 | 판매량 | 이미지URL | 상품URL | 어필리에이트링크 | 생성일시 |

## 🔄 사용법

### 1. 상품 저장 시 자동 연동
- `product_save.php`에서 상품 저장 시 JSON + 구글 시트 동시 저장
- 저장 실패 시에도 JSON 저장은 유지 (백업 보장)

### 2. 일괄 내보내기
- `product_save_list.php`에서 선택된 상품들을 구글 시트로 내보내기
- 내보내기 후 구글 시트 URL 제공

### 3. 전체 동기화
- 모든 로컬 데이터를 구글 시트와 동기화
- 기존 데이터 중복 방지

## 🚨 문제 해결

### 1. "Google API Client 라이브러리가 설치되지 않았습니다" 오류
```bash
cd /home/novacents/ail_auto_product
composer require google/apiclient:^2.0
```

### 2. "credentials.json 파일을 찾을 수 없습니다" 오류
- credentials.json 파일이 올바른 위치에 있는지 확인
- 파일 권한이 올바른지 확인 (600)

### 3. "구글 시트 인증이 필요합니다" 오류
- `oauth_setup.php`를 통해 재인증 필요
- token.json 파일이 만료되었을 수 있음

### 4. "스프레드시트 생성/조회 실패" 오류
- Google Sheets API가 활성화되어 있는지 확인
- 구글 계정에 시트 생성 권한이 있는지 확인

## 📁 생성되는 파일들

- `product_save_data.json` - 로컬 데이터 저장
- `google_sheets_config.json` - 구글 시트 설정 (스프레드시트 ID 등)
- `token.json` - 구글 API 인증 토큰
- `backups/` - 일일 자동 백업 폴더

## ⚡ 성능 최적화

- 구글 API 호출 최소화를 위한 배치 처리
- 로컬 JSON 우선 저장으로 속도 보장
- 일일 자동 백업으로 데이터 안전성 확보

## 🔐 보안 고려사항

- credentials.json과 token.json 파일은 600 권한으로 설정
- API 키와 시크릿은 환경변수 또는 별도 파일로 관리
- 구글 시트 공유 설정 주의 (개인용으로 설정 권장)

---

**🚀 설정 완료 후 `product_save.php`와 `product_save_list.php`에서 구글 시트 연동 기능을 사용할 수 있습니다!**