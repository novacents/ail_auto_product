#!/bin/bash

# 상품 모니터링 시스템 cron 설정 스크립트
# 파일 위치: /var/www/novacents/tools/setup_monitoring_cron.sh

echo "🔧 상품 모니터링 시스템 cron 설정"
echo "=================================="

# 현재 crontab 백업
echo "[1/4] 현재 crontab 백업 중..."
crontab -l > /tmp/crontab_backup_$(date +%Y%m%d_%H%M%S).txt 2>/dev/null || echo "기존 crontab 없음"

# 모니터링 스크립트 경로
MONITOR_SCRIPT="/var/www/novacents/tools/product_monitor.py"
LOG_FILE="/var/www/novacents/tools/cache/monitor_cron.log"

# 로그 디렉토리 생성
mkdir -p /var/www/novacents/tools/cache

echo "[2/4] cron 작업 설정 중..."

# 기존 모니터링 관련 cron 제거
crontab -l 2>/dev/null | grep -v "product_monitor.py" | crontab - 2>/dev/null || true

# 새로운 cron 작업 추가
(crontab -l 2>/dev/null; echo "# 상품 모니터링 시스템 - 매일 오전 9시 실행") | crontab -
(crontab -l 2>/dev/null; echo "0 9 * * * cd /var/www/novacents/tools && /usr/bin/python3 $MONITOR_SCRIPT >> $LOG_FILE 2>&1") | crontab -

# 추가 옵션들 (주석 처리됨)
echo "[3/4] 추가 모니터링 옵션들..."
echo "다음 cron 설정들을 선택적으로 활성화할 수 있습니다:"
echo ""
echo "# 매일 오전 9시 실행:"
echo "# 0 9 * * * cd /var/www/novacents/tools && /usr/bin/python3 $MONITOR_SCRIPT >> $LOG_FILE 2>&1"
echo ""
echo "# 매 12시간마다 실행:"
echo "# 0 */12 * * * cd /var/www/novacents/tools && /usr/bin/python3 $MONITOR_SCRIPT >> $LOG_FILE 2>&1"
echo ""
echo "# 매 6시간마다 실행:"
echo "# 0 */6 * * * cd /var/www/novacents/tools && /usr/bin/python3 $MONITOR_SCRIPT >> $LOG_FILE 2>&1"
echo ""
echo "# 매 3시간마다 실행 (더 자주):"
echo "# 0 */3 * * * cd /var/www/novacents/tools && /usr/bin/python3 $MONITOR_SCRIPT >> $LOG_FILE 2>&1"

echo "[4/4] 설정 완료 확인..."

# 현재 crontab 확인
echo ""
echo "✅ 설정된 cron 작업:"
crontab -l | grep -A1 -B1 "product_monitor"

echo ""
echo "📋 설정 정보:"
echo "  - 실행 주기: 매일 오전 9시"
echo "  - 스크립트: $MONITOR_SCRIPT"
echo "  - 로그 파일: $LOG_FILE"
echo "  - 모니터링 대상: 최근 7일 발행 글의 상품 링크"
echo ""
echo "💡 관리 명령어:"
echo "  crontab -l          # 현재 cron 작업 확인"
echo "  crontab -e          # cron 작업 편집"
echo "  tail -f $LOG_FILE   # 실시간 로그 확인"
echo ""
echo "🎉 상품 모니터링 자동화 설정 완료!"