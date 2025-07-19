#!/bin/bash

# 자동 발행 cron 설정 스크립트
# 사용법: sudo ./setup_auto_publish_cron.sh

# 색상 정의
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}📋 노바센트 자동 발행 Cron 설정 시작${NC}"
echo "========================================="

# 현재 사용자 확인
CURRENT_USER=$(whoami)
echo -e "${YELLOW}현재 사용자: ${CURRENT_USER}${NC}"

# 작업 디렉토리 및 파일 경로 설정
WORK_DIR="/var/www/novacents/tools"
PYTHON_SCRIPT="$WORK_DIR/auto_post_products.py"
LOG_FILE="$WORK_DIR/auto_post_products.log"
CRON_LOG="$WORK_DIR/cron_auto_publish.log"

# 필수 파일 존재 확인
echo -e "\n${BLUE}1. 필수 파일 존재 확인${NC}"
echo "-------------------"

if [ ! -f "$PYTHON_SCRIPT" ]; then
    echo -e "${RED}❌ Python 스크립트를 찾을 수 없습니다: $PYTHON_SCRIPT${NC}"
    exit 1
else
    echo -e "${GREEN}✅ Python 스크립트 확인: $PYTHON_SCRIPT${NC}"
fi

if [ ! -d "$WORK_DIR" ]; then
    echo -e "${RED}❌ 작업 디렉토리를 찾을 수 없습니다: $WORK_DIR${NC}"
    exit 1
else
    echo -e "${GREEN}✅ 작업 디렉토리 확인: $WORK_DIR${NC}"
fi

# Python3 확인
PYTHON3_PATH=$(which python3)
if [ -z "$PYTHON3_PATH" ]; then
    echo -e "${RED}❌ Python3를 찾을 수 없습니다${NC}"
    exit 1
else
    echo -e "${GREEN}✅ Python3 확인: $PYTHON3_PATH${NC}"
fi

# 스케줄 옵션 선택
echo -e "\n${BLUE}2. 자동 발행 스케줄 선택${NC}"
echo "------------------------"
echo "다음 중 원하는 스케줄을 선택하세요:"
echo "1) 매 2시간마다 (기본 권장)"
echo "2) 활동 시간대만 (오전 9시~오후 9시, 3시간마다)"
echo "3) 하루 1회 (오전 9시)"
echo "4) 매시간 (테스트용, 권장하지 않음)"
echo "5) 사용자 정의"

read -p "선택 (1-5): " SCHEDULE_CHOICE

case $SCHEDULE_CHOICE in
    1)
        CRON_SCHEDULE="0 */2 * * *"
        SCHEDULE_DESC="매 2시간마다"
        ;;
    2)
        CRON_SCHEDULE="0 9,12,15,18,21 * * *"
        SCHEDULE_DESC="오전 9시부터 오후 9시까지 3시간마다"
        ;;
    3)
        CRON_SCHEDULE="0 9 * * *"
        SCHEDULE_DESC="매일 오전 9시"
        ;;
    4)
        CRON_SCHEDULE="0 * * * *"
        SCHEDULE_DESC="매시간"
        ;;
    5)
        echo "Cron 표현식을 입력하세요 (예: 0 */2 * * *):"
        read -p "입력: " CRON_SCHEDULE
        SCHEDULE_DESC="사용자 정의: $CRON_SCHEDULE"
        ;;
    *)
        echo -e "${RED}❌ 잘못된 선택입니다${NC}"
        exit 1
        ;;
esac

echo -e "${GREEN}선택된 스케줄: $SCHEDULE_DESC${NC}"

# Cron job 생성
echo -e "\n${BLUE}3. Cron Job 생성${NC}"
echo "----------------"

CRON_COMMAND="cd $WORK_DIR && $PYTHON3_PATH auto_post_products.py >> $LOG_FILE 2>&1"
CRON_ENTRY="$CRON_SCHEDULE $CRON_COMMAND"

echo "생성될 Cron Job:"
echo "$CRON_ENTRY"

# 기존 자동 발행 cron 제거 (중복 방지)
echo -e "\n${YELLOW}기존 자동 발행 cron job 제거 중...${NC}"
crontab -l 2>/dev/null | grep -v "auto_post_products.py" | crontab -

# 새 cron job 추가
echo -e "${YELLOW}새 cron job 추가 중...${NC}"
(crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -

# 확인
echo -e "\n${BLUE}4. 설정 완료 확인${NC}"
echo "------------------"

if crontab -l | grep -q "auto_post_products.py"; then
    echo -e "${GREEN}✅ Cron job이 성공적으로 추가되었습니다!${NC}"
    echo ""
    echo "현재 등록된 cron jobs:"
    crontab -l | grep "auto_post_products.py"
else
    echo -e "${RED}❌ Cron job 추가에 실패했습니다${NC}"
    exit 1
fi

# 로그 파일 설정
echo -e "\n${BLUE}5. 로그 파일 설정${NC}"
echo "----------------"

# 로그 파일이 없으면 생성
if [ ! -f "$LOG_FILE" ]; then
    touch "$LOG_FILE"
    chmod 644 "$LOG_FILE"
    echo -e "${GREEN}✅ 메인 로그 파일 생성: $LOG_FILE${NC}"
fi

if [ ! -f "$CRON_LOG" ]; then
    touch "$CRON_LOG"
    chmod 644 "$CRON_LOG"
    echo -e "${GREEN}✅ Cron 로그 파일 생성: $CRON_LOG${NC}"
fi

# 테스트 실행 제안
echo -e "\n${BLUE}6. 설정 완료${NC}"
echo "============"
echo -e "${GREEN}✅ 자동 발행 시스템이 성공적으로 설정되었습니다!${NC}"
echo ""
echo "📋 설정 정보:"
echo "  • 스케줄: $SCHEDULE_DESC"
echo "  • 작업 디렉토리: $WORK_DIR"
echo "  • Python 스크립트: $PYTHON_SCRIPT"
echo "  • 로그 파일: $LOG_FILE"
echo "  • Cron 로그: $CRON_LOG"
echo ""
echo "🔍 상태 확인 명령어:"
echo "  • Cron 목록: crontab -l"
echo "  • 로그 확인: tail -f $LOG_FILE"
echo "  • 큐 상태: cat /var/www/novacents/tools/product_queue.json"
echo ""
echo "🧪 테스트 실행:"
echo "  • 수동 실행: cd $WORK_DIR && python3 auto_post_products.py"
echo ""
echo "⚠️  참고사항:"
echo "  • 첫 실행까지 최대 $SCHEDULE_DESC 대기"
echo "  • 큐에 pending 상태의 항목이 있어야 처리됨"
echo "  • affiliate_editor.php에서 새 항목 추가 가능"
echo "  • queue_manager.php에서 큐 관리 가능"

# 테스트 실행 여부 묻기
echo ""
read -p "지금 테스트 실행을 해보시겠습니까? (y/N): " TEST_RUN

if [[ $TEST_RUN =~ ^[Yy]$ ]]; then
    echo -e "\n${BLUE}테스트 실행 중...${NC}"
    echo "-------------------"
    cd "$WORK_DIR"
    python3 auto_post_products.py
    echo -e "\n${GREEN}테스트 실행 완료${NC}"
else
    echo -e "${YELLOW}테스트를 건너뛰었습니다.${NC}"
fi

echo -e "\n${GREEN}🎉 자동 발행 시스템 설정이 모두 완료되었습니다!${NC}"