# 구글 시트 API 설정 가이드

## 📋 개요
상품 발굴 저장 시스템에서 구글 시트 연동을 위한 설정 가이드입니다.
**기존 Google Drive API와 동일한 방식으로 Python 스크립트를 사용**합니다.

## 🔧 기존 설정 정보 (재사용)
- **Google Sheets API**: 활성화 완료 ✅
- **OAuth 2.0 클라이언트 ID**: `558249385120-ohflnjvcjm3uelsmibhq8ud1j3folgb5.apps.googleusercontent.com` ✅
- **승인된 리디렉션 URI**: `https://novacents.com/tools/oauth_setup.php` ✅
- **구글 시트 이름**: `상품 발굴 데이터` ✅
- **기존 토큰 파일**: `/var/www/novacents/tools/google_token.json` ✅

## 📦 필요한 추가 설정

### 1. Python 라이브러리 설치 확인

**기존 Google Drive API용 라이브러리 재사용:**
```bash
# 이미 설치된 라이브러리 확인
python3 -c "import google.auth, googleapiclient.discovery; print('Google API 라이브러리 사용 가능')"
```

**필요한 경우에만 설치:**
```bash
pip3 install google-auth google-auth-oauthlib google-auth-httplib2 google-api-python-client
```

### 2. OAuth 토큰 권한 확장

기존 `google_token.json`에 Google Sheets API 권한이 포함되어 있는지 확인이 필요합니다.

**확인 방법:**
1. 브라우저에서 `https://novacents.com/tools/oauth_setup.php` 접속
2. 기존 토큰이 있어도 재인증 진행 (권한 확장을 위해)
3. Google 로그인 시 **Google Sheets** 권한도 함께 허용

### 3. 토큰 파일 권한 확인

```bash
# 토큰 파일 권한 확인
ls -la /var/www/novacents/tools/google_token.json

# 필요한 경우 권한 조정
chmod 600 /var/www/novacents/tools/google_token.json
```

## 📊 구글 시트 구조

시스템은 다음 구조로 구글 시트를 생성합니다:

### 27개 열 구조
| A-J (기본 10개) | K-O (기능스펙 5개) | P-S (효율성 4개) | T-W (사용법 4개) | X-AA (장점주의 4개) |
|---|---|---|---|---|
| ID, 키워드, 상품명, 가격, 평점, 판매량, 이미지URL, 상품URL, 어필리에이트링크, 생성일시 | 주요기능, 크기/용량, 색상, 재질/소재, 전원/배터리 | 해결하는문제, 시간절약, 공간활용, 비용절감 | 사용장소, 사용빈도, 적합한사용자, 사용법 | 장점1, 장점2, 장점3, 주의사항 |

## 🔄 사용법

### 1. 자동 시트 생성
- 첫 번째 상품 저장 시 **"상품 발굴 데이터"** 시트 자동 생성
- 헤더 행 자동 추가 및 스타일링

### 2. 상품 저장 시 자동 연동
- `product_save.php`에서 상품 저장 시 JSON + 구글 시트 동시 저장
- 저장 실패 시에도 JSON 저장은 유지 (백업 보장)

### 3. 일괄 내보내기
- `product_save_list.php`에서 선택된 상품들을 구글 시트로 내보내기
- 내보내기 후 구글 시트 URL 제공

## 🚨 문제 해결

### 일반적인 오류 상황

**1. "OAuth 토큰을 찾을 수 없습니다" 오류**
```bash
# 토큰 파일 존재 확인
ls -la /var/www/novacents/tools/google_token.json

# 없으면 oauth_setup.php에서 재인증
# https://novacents.com/tools/oauth_setup.php
```

**2. "Google API 라이브러리 없음" 오류**
```bash
# Python 라이브러리 설치 확인
python3 -c "import googleapiclient.discovery"

# 설치 필요 시
pip3 install google-api-python-client google-auth
```

**3. "권한 부족" 오류**
- oauth_setup.php에서 재인증 필요
- Google Sheets API 권한이 포함되지 않은 기존 토큰

**4. "스프레드시트 생성/조회 실패" 오류**
- Google Sheets API가 활성화되어 있는지 확인
- 구글 계정에 시트 생성 권한이 있는지 확인

**5. Python 스크립트 실행 오류**
```bash
# Python 경로 확인
which python3

# 권한 확인
ls -la /usr/bin/python3
```

### 로그 파일 및 디버그

```bash
# 주요 확인 사항
ls -la /var/www/novacents/tools/google_token.json    # OAuth 토큰
ls -la /var/www/novacents/tools/product_save_data.json # JSON 데이터
tail -f /var/log/apache2/error.log                   # 웹 서버 오류 로그

# Python 모듈 확인
python3 -c "
import sys
print('Python version:', sys.version)
try:
    import google.auth
    print('✅ google.auth 사용 가능')
except ImportError:
    print('❌ google.auth 설치 필요')
    
try:
    import googleapiclient.discovery
    print('✅ googleapiclient 사용 가능')  
except ImportError:
    print('❌ googleapiclient 설치 필요')
"
```

## 📁 생성되는 파일들

- `product_save_data.json` - 로컬 데이터 저장
- `google_token.json` - Google API 인증 토큰 (기존 재사용)
- 임시 Python 스크립트 - 자동 생성/삭제

## ⚡ 성능 최적화

- Python 스크립트 기반으로 안정적인 API 호출
- 기존 Google Drive API 토큰 재사용으로 설정 간소화
- 로컬 JSON 우선 저장으로 속도 보장
- 배치 처리로 Google Sheets API 호출 최적화

## 🔐 보안 고려사항

- google_token.json 파일은 600 권한으로 설정
- 기존 Google Drive API와 동일한 보안 수준
- 임시 Python 스크립트는 실행 후 자동 삭제
- 구글 시트 공유 설정 주의 (개인용으로 설정 권장)

## 🔄 기존 시스템과의 호환성

**장점:**
- ✅ 기존 oauth_setup.php 재사용
- ✅ 기존 google_token.json 재사용  
- ✅ 추가 라이브러리 설치 불필요 (Python 기본 환경 사용)
- ✅ gdrive_config.php와 동일한 안정적인 구조
- ✅ 에러 처리 및 디버깅 용이

**차이점:**
- Google Drive API → Google Sheets API (동일한 인증 방식)
- 이미지 업로드 → 데이터 저장 (동일한 Python 스크립트 패턴)

---

**🚀 설정 완료 후 `product_save.php`와 `product_save_list.php`에서 구글 시트 연동 기능을 사용할 수 있습니다!**

**추가 설치 없이 기존 Google Drive API 환경을 그대로 활용합니다.**