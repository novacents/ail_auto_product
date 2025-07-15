# 상품 모니터링 + 텔레그램 알림 시스템 가이드

## 📋 개요

발행된 블로그 글에 포함된 쿠팡/알리익스프레스 상품 링크들을 자동으로 모니터링하여, 품절이나 삭제된 상품을 실시간으로 텔레그램으로 알려주는 시스템입니다.

## 🚀 주요 기능

### ✅ 완전히 구현된 기능들:

1. **자동 상품 모니터링**
   - WordPress API를 통한 최근 발행 글 자동 조회
   - 글 내용에서 상품 링크 자동 추출
   - 쿠팡/알리익스프레스 상품 상태 실시간 확인

2. **텔레그램 실시간 알림**
   - 품절/삭제된 상품 즉시 알림
   - 상품 상태 변경 감지 및 알림
   - 상세한 알림 메시지 (글 제목, 링크, 상품 정보 포함)

3. **스마트 모니터링**
   - API 제한 고려한 안전한 호출
   - 캐시 시스템으로 중복 확인 방지
   - 상태 변경 히스토리 추적

4. **자동화 시스템**
   - cron을 통한 정기 자동 실행 (매일 오전 9시)
   - 로그 파일 자동 관리
   - 에러 처리 및 복구

## 📁 파일 구조

```
/var/www/novacents/tools/
├── product_monitor.py              # 메인 모니터링 시스템
├── test_telegram_alert.py          # 시스템 테스트 도구
├── setup_monitoring_cron.sh        # cron 자동 설정 스크립트
├── safe_api_manager.py             # API 관리자 (기존)
└── cache/
    ├── product_monitor.json        # 모니터링 데이터
    ├── alert_log.json             # 알림 발송 로그
    └── monitor_cron.log           # cron 실행 로그
```

## 🔧 설정 정보

### 환경변수 (.env 파일)
```bash
# 텔레그램 봇 설정
TELEGRAM_BOT_TOKEN=7812855934:AAFg7NVECjGKDbwR7PbGaChvEjzoFUIhobc
TELEGRAM_CHAT_ID=1981054452

# WordPress API 설정
NOVACENTS_WP_USER=admin
NOVACENTS_WP_APP_PASS="bFZNBs3XGaAV8RCpSzjW6ffS"
NOVACENTS_WP_URL=https://novacents.com
NOVACENTS_WP_API_BASE=https://novacents.com/wp-json/wp/v2

# 쿠팡/알리익스프레스 API 키 (기존)
COUPANG_ACCESS_KEY=...
COUPANG_SECRET_KEY=...
ALIEXPRESS_APP_KEY=...
ALIEXPRESS_APP_SECRET=...
```

### cron 설정
```bash
# 매일 오전 9시 실행
0 9 * * * cd /var/www/novacents/tools && /usr/bin/python3 product_monitor.py >> cache/monitor_cron.log 2>&1
```

## 🖥️ 사용법

### 1. 수동 실행
```bash
# 기본 모니터링 (최근 7일)
cd /var/www/novacents/tools
python3 product_monitor.py

# 시스템 테스트
python3 test_telegram_alert.py
```

### 2. cron 관리
```bash
# cron 설정 확인
crontab -l

# cron 편집
crontab -e

# 실시간 로그 확인
tail -f /var/www/novacents/tools/cache/monitor_cron.log
```

### 3. 시스템 상태 확인
```bash
# 모니터링 데이터 확인
cat /var/www/novacents/tools/cache/product_monitor.json

# 알림 로그 확인
cat /var/www/novacents/tools/cache/alert_log.json
```

## 📱 알림 메시지 예시

### 문제 상품 발견 시:
```
⚠️ 문제 상품 발견

📄 글 제목: 2025년 여름휴가 필수템 - 무선 블루투스 이어폰 추천
🔗 글 링크: https://novacents.com/post-123

🛒 플랫폼: 쿠팡
❌ 상태: unavailable

🔗 상품 URL: https://www.coupang.com/vp/products/123456789

ℹ️ 상세 정보: 상품을 찾을 수 없습니다

⏰ 감지 시간: 2025-07-10T13:49:39
```

### 상품 상태 변경 시:
```
🚨 상품 상태 변경 알림

📄 글 제목: 여행용 스마트 액세서리 TOP 5
🔗 글 링크: https://novacents.com/post-456

🛒 플랫폼: 알리익스프레스
🔄 상태 변경: available → unavailable

🔗 상품 URL: https://ko.aliexpress.com/item/123456.html

ℹ️ 상세 정보: API 조회 결과 없음

⏰ 감지 시간: 2025-07-10T13:50:15
```

## 🔍 모니터링 동작 원리

### 1. 글 조회
- WordPress REST API로 최근 7일 발행 글 조회
- 글 내용에서 정규표현식으로 상품 링크 추출
- 쿠팡/알리익스프레스 링크 패턴 자동 인식

### 2. 상품 상태 확인
- **쿠팡**: SafeAPIManager를 통한 상품 검색 API 호출
- **알리익스프레스**: 공식 IOP SDK로 상품 상세 정보 조회
- HTTP 상태 코드 확인 (보조 수단)

### 3. 상태 변경 감지
- 이전 상태와 현재 상태 비교
- 새로운 문제 상품 감지
- 상태 변경 히스토리 기록

### 4. 알림 발송
- 문제 감지 시 즉시 텔레그램 알림
- 상세한 정보 포함 (글 정보, 상품 정보, 오류 내용)
- 알림 발송 로그 자동 기록

## 📊 지원하는 상품 상태

| 상태 | 설명 | 알림 여부 |
|------|------|-----------|
| `available` | 정상 구매 가능 | ❌ |
| `unavailable` | 품절/삭제됨 | ✅ |
| `http_error` | 링크 접근 불가 | ✅ |
| `error` | 시스템 오류 | ✅ |
| `unknown` | 상태 확인 불가 | ❌ |

## 🛠️ 관리 및 유지보수

### 정기 점검 항목:
1. **로그 파일 크기 확인**
   ```bash
   ls -lh /var/www/novacents/tools/cache/*.log
   ```

2. **cron 작업 실행 확인**
   ```bash
   grep "product_monitor" /var/log/syslog
   ```

3. **텔레그램 봇 상태 확인**
   ```bash
   python3 test_telegram_alert.py
   ```

### 문제 해결:

**Q: 알림이 오지 않아요**
- cron 작업이 정상 실행되는지 확인
- 텔레그램 봇 토큰과 채팅 ID 확인
- 로그 파일에서 오류 메시지 확인

**Q: 너무 많은 알림이 와요**
- cron 실행 주기를 늘리기 (매일 → 2일마다)
- 모니터링 대상 기간 줄이기 (7일 → 3일)

**Q: 특정 상품이 계속 문제로 나와요**
- 해당 상품 링크를 글에서 수정 또는 제거
- 모니터링 데이터에서 해당 항목 삭제

## 🎯 사용 사례

### 실제 업무 시나리오:

1. **블로그 글 발행**: 쿠팡/알리익스프레스 상품 링크 포함
2. **자동 모니터링**: 매일 오전 9시 상품 상태 자동 확인
3. **문제 발견**: 상품이 품절되거나 삭제됨
4. **즉시 알림**: 텔레그램으로 문제 상품 정보 수신
5. **빠른 대응**: 해당 글의 상품 링크 수정 또는 대체 상품 추가

### 예상 효과:
- ✅ **수익 손실 방지**: 품절된 상품으로 인한 클릭 손실 최소화
- ✅ **사용자 경험 개선**: 항상 구매 가능한 상품만 노출
- ✅ **운영 효율성 증대**: 수동 확인 불필요, 자동화된 관리
- ✅ **실시간 대응**: 문제 발견 즉시 알림으로 빠른 대응 가능

## 🔮 향후 확장 가능성

1. **다른 플랫폼 지원**: 네이버 쇼핑, 11번가 등
2. **더 정교한 모니터링**: 가격 변동, 할인율 변화 감지
3. **자동 대체 상품 추천**: 유사 상품 자동 검색 및 교체
4. **대시보드**: 웹 인터페이스로 모니터링 현황 확인
5. **다중 사이트 지원**: 여러 워드프레스 사이트 동시 모니터링

---

## 📞 문의 및 지원

시스템 관련 문의나 개선 사항이 있으시면 텔레그램으로 연락 주세요.

**🎉 상품 모니터링 + 텔레그램 알림 시스템이 성공적으로 구축되었습니다!**