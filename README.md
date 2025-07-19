# 🚀 노바센트 어필리에이트 자동 발행 시스템

알리익스프레스 어필리에이트 상품을 AI로 분석하고 WordPress에 자동으로 발행하는 통합 시스템입니다.

## ✨ 주요 기능

### 📝 **컨텐츠 작성 및 관리**
- **affiliate_editor.php**: 직관적인 웹 인터페이스로 상품 정보 입력
- **상품 분석**: 알리익스프레스 상품 자동 분석 및 데이터 추출
- **4가지 프롬프트 템플릿**: 필수템형, 친구 추천형, 전문 분석형, 놀라움 발견형
- **사용자 상세 정보**: 상품별 맞춤 정보 입력 (스펙, 효율성, 사용법, 장점)

### 📋 **큐 관리 시스템**
- **queue_manager.php**: 저장된 작업들을 관리하는 웹 인터페이스
- **편집 기능**: 저장된 정보 수정, 추가, 삭제
- **즉시 발행**: 원하는 항목을 바로 WordPress에 발행
- **드래그 앤 드롭**: 발행 순서 조정
- **상태 관리**: pending → processing → completed

### 🤖 **자동 발행 시스템**
- **auto_post_products.py**: 큐의 대기 작업들을 자동으로 처리
- **AI 콘텐츠 생성**: Gemini AI 기반 고품질 콘텐츠 자동 생성
- **워드프레스 연동**: REST API를 통한 자동 발행
- **텔레그램 알림**: 작업 상태 실시간 알림

### ⏰ **스케줄링 시스템**
- **Cron 자동화**: 설정한 시간에 자동으로 큐 처리
- **유연한 스케줄**: 시간별, 일별, 사용자 정의 가능
- **로그 관리**: 모든 작업 과정 기록 및 추적

## 🏗️ 시스템 구조

```
📦 프로젝트 구조
├── 📝 프론트엔드 (웹 인터페이스)
│   ├── affiliate_editor.php      # 상품 정보 입력 페이지
│   ├── queue_manager.php         # 큐 관리 페이지
│   └── assets/                   # CSS, JS 파일들
│
├── ⚙️ 백엔드 (처리 로직)
│   ├── keyword_processor.php     # 데이터 처리 및 큐 저장
│   ├── auto_post_products.py     # 자동 발행 메인 스크립트
│   └── product_analyzer_v2.php   # 상품 분석 API
│
├── 📊 데이터
│   ├── product_queue.json        # 작업 큐 파일
│   └── *.log                     # 로그 파일들
│
└── 🔧 설정 및 유틸리티
    ├── setup_auto_publish_cron.sh # Cron 설정 스크립트
    └── .env                       # 환경 변수
```

## 🚀 빠른 시작

### 1. 기본 설정

```bash
# 저장소 클론
git clone https://github.com/novacents/ail_auto_product.git
cd ail_auto_product

# 권한 설정
chmod +x setup_auto_publish_cron.sh
```

### 2. 환경 변수 설정

`.env` 파일 생성:
```bash
# WordPress 설정
WORDPRESS_URL=https://novacents.com
WORDPRESS_USERNAME=your_username
WORDPRESS_APP_PASSWORD=your_app_password

# Gemini AI 설정
GEMINI_API_KEY=your_gemini_api_key

# 텔레그램 알림 설정
TELEGRAM_BOT_TOKEN=your_bot_token
TELEGRAM_CHAT_ID=your_chat_id

# 알리익스프레스 API 설정
ALIEXPRESS_API_KEY=your_api_key
ALIEXPRESS_SECRET=your_secret
ALIEXPRESS_TRACKING_ID=your_tracking_id
```

### 3. 자동 발행 Cron 설정

```bash
# Cron 설정 스크립트 실행
sudo ./setup_auto_publish_cron.sh
```

선택 가능한 스케줄:
- **매 2시간마다** (기본 권장)
- **활동 시간대만** (오전 9시~오후 9시, 3시간마다)
- **하루 1회** (오전 9시)
- **사용자 정의**

## 📖 사용법

### 1. 새 상품 등록

1. `affiliate_editor.php` 페이지 접속
2. 글 제목, 카테고리, 프롬프트 스타일 선택
3. 키워드 추가 및 알리익스프레스 상품 URL 입력
4. 상품 분석 실행 (자동으로 상품 정보 추출)
5. 상품별 상세 정보 입력 (선택사항)
6. "큐에 저장" 또는 "즉시 발행" 선택

### 2. 큐 관리

1. `queue_manager.php` 페이지 접속
2. 저장된 항목들 확인
3. 필요시 편집 버튼으로 수정
4. 드래그 앤 드롭으로 발행 순서 조정
5. "즉시 발행" 버튼으로 바로 발행 가능

### 3. 자동 발행 모니터링

```bash
# 실시간 로그 확인
tail -f /var/www/novacents/tools/auto_post_products.log

# 큐 상태 확인
cat /var/www/novacents/tools/product_queue.json

# Cron 작업 확인
crontab -l | grep auto_post_products
```

## 🎯 프롬프트 템플릿

### 1. 필수템형 🎯
주제별로 꼭 필요한 아이템들을 정리하여 소개하는 스타일

### 2. 친구 추천형 👫
친근한 톤으로 실제 사용 경험을 바탕으로 추천하는 스타일

### 3. 전문 분석형 📊
객관적인 데이터와 전문가적 관점에서 분석하는 스타일

### 4. 놀라움 발견형 ✨
새롭고 신기한 아이템들의 독특함을 강조하는 스타일

## 📊 데이터 구조

### 큐 항목 구조 (product_queue.json)

```json
{
  "queue_id": "20250719_12345",
  "title": "글 제목",
  "category_id": 356,
  "category_name": "스마트 리빙",
  "prompt_type": "essential_items",
  "prompt_type_name": "필수템형 🎯",
  "keywords": [
    {
      "name": "키워드명",
      "aliexpress": ["상품 URL"],
      "products_data": [
        {
          "url": "상품 URL",
          "analysis_data": {
            "title": "상품명",
            "price": "가격",
            "image_url": "이미지 URL",
            "rating_display": "평점",
            "lastest_volume": "판매량"
          },
          "generated_html": "생성된 HTML 코드",
          "user_details": {
            "specs": {...},
            "efficiency": {...},
            "usage": {...},
            "benefits": {...}
          }
        }
      ]
    }
  ],
  "status": "pending",
  "created_at": "2025-07-19 12:00:00"
}
```

## 🔧 고급 설정

### 수동 실행

```bash
# 즉시 발행 (특정 파일)
cd /var/www/novacents/tools
python3 auto_post_products.py --immediate-file=temp/immediate_file.json

# 큐 처리
python3 auto_post_products.py
```

### 로그 분석

```bash
# 발행 성공 로그
cat published_log.txt

# 에러 로그
grep "ERROR\|Exception" auto_post_products.log

# 처리 과정 로그
cat processor_log.txt
```

### 큐 상태별 필터링

```bash
# 대기 중인 작업만 확인
cat product_queue.json | jq '.[] | select(.status == "pending")'

# 완료된 작업 확인
cat product_queue.json | jq '.[] | select(.status == "completed")'
```

## 🚨 문제 해결

### 일반적인 문제들

1. **상품 분석 실패**
   - 알리익스프레스 API 키 확인
   - 상품 URL 유효성 검사

2. **WordPress 발행 실패**
   - WordPress 로그인 정보 확인
   - REST API 활성화 확인

3. **Cron 작동 안함**
   - Cron 서비스 상태: `systemctl status cron`
   - 로그 확인: `tail -f /var/log/syslog | grep CRON`

4. **권한 문제**
   ```bash
   # 파일 권한 수정
   chmod 644 /var/www/novacents/tools/product_queue.json
   chmod +x /var/www/novacents/tools/auto_post_products.py
   ```

### 로그 파일 위치

- **메인 로그**: `/var/www/novacents/tools/auto_post_products.log`
- **발행 로그**: `/var/www/novacents/tools/published_log.txt`
- **처리 로그**: `/var/www/novacents/tools/processor_log.txt`
- **디버그 로그**: `/var/www/novacents/tools/debug_processor.txt`

## 📞 지원

문제가 발생하거나 기능 요청이 있으시면:

1. **로그 확인**: 먼저 관련 로그 파일들을 확인해주세요
2. **GitHub Issues**: [이슈 페이지](https://github.com/novacents/ail_auto_product/issues)에서 문제 보고
3. **텔레그램 알림**: 시스템 상태를 실시간으로 모니터링

## 📝 라이센스

이 프로젝트는 개인 사용 목적으로 제작되었습니다.

---

**✨ 노바센트 어필리에이트 자동 발행 시스템으로 효율적인 컨텐츠 관리를 경험해보세요! ✨**