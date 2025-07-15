#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
상품 모니터링 및 텔레그램 알림 시스템
- 발행된 글의 상품 링크 상태 모니터링
- 품절/삭제된 상품 자동 감지
- 텔레그램으로 실시간 알림 발송
파일 위치: /var/www/novacents/tools/product_monitor.py
"""

import os
import sys
import time
import json
import requests
import re
from datetime import datetime, timedelta
from dotenv import load_dotenv

# SafeAPIManager 임포트
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.append(current_dir)

try:
    from safe_api_manager import SafeAPIManager
    print("[✅] SafeAPIManager 임포트 성공")
except ImportError as e:
    print(f"[❌] SafeAPIManager 임포트 실패: {e}")
    sys.exit(1)

class ProductMonitor:
    """
    상품 모니터링 시스템
    - WordPress API를 통한 발행된 글 조회
    - 상품 링크 상태 확인
    - 텔레그램 알림 발송
    """
    
    def __init__(self):
        """초기화"""
        self.config = self._load_config()
        self.api_manager = SafeAPIManager(mode="production")
        self.monitor_data_file = "/var/www/novacents/tools/cache/product_monitor.json"
        self.alert_log_file = "/var/www/novacents/tools/cache/alert_log.json"
        
        # 캐시 디렉토리 생성
        cache_dir = os.path.dirname(self.monitor_data_file)
        if not os.path.exists(cache_dir):
            os.makedirs(cache_dir)
        
        print(f"[⚙️] ProductMonitor 초기화 완료")
    
    def _load_config(self):
        """환경변수에서 설정 로드"""
        env_path = '/home/novacents/.env'
        load_dotenv(env_path)
        
        config = {
            "telegram_bot_token": os.getenv("TELEGRAM_BOT_TOKEN"),
            "telegram_chat_id": os.getenv("TELEGRAM_CHAT_ID"),
            "wp_url": os.getenv("NOVACENTS_WP_URL", "https://novacents.com"),
            "wp_user": os.getenv("NOVACENTS_WP_USER", "admin"),
            "wp_app_pass": os.getenv("NOVACENTS_WP_APP_PASS"),
        }
        
        # 필수 설정 확인
        missing_keys = [key for key, value in config.items() if not value]
        if missing_keys:
            raise ValueError(f"필수 설정이 누락되었습니다: {missing_keys}")
        
        print(f"[✅] 모든 설정 로드 성공")
        return config
    
    def _load_json_file(self, filepath, default=None):
        """JSON 파일 안전 로드"""
        if default is None:
            default = {}
        
        try:
            if os.path.exists(filepath):
                with open(filepath, 'r', encoding='utf-8') as f:
                    return json.load(f)
            return default
        except (json.JSONDecodeError, IOError) as e:
            print(f"[⚠️] JSON 파일 로드 실패 ({filepath}): {e}")
            return default
    
    def _save_json_file(self, filepath, data):
        """JSON 파일 안전 저장"""
        try:
            with open(filepath, 'w', encoding='utf-8') as f:
                json.dump(data, f, ensure_ascii=False, indent=2)
            return True
        except IOError as e:
            print(f"[❌] JSON 파일 저장 실패 ({filepath}): {e}")
            return False
    
    def send_telegram_message(self, message):
        """텔레그램 메시지 발송"""
        try:
            url = f"https://api.telegram.org/bot{self.config['telegram_bot_token']}/sendMessage"
            
            payload = {
                'chat_id': self.config['telegram_chat_id'],
                'text': message,
                'parse_mode': 'HTML',
                'disable_web_page_preview': True
            }
            
            response = requests.post(url, json=payload, timeout=30)
            
            if response.status_code == 200:
                print(f"[✅] 텔레그램 메시지 발송 성공")
                return True
            else:
                print(f"[❌] 텔레그램 메시지 발송 실패: {response.status_code}")
                return False
                
        except Exception as e:
            print(f"[❌] 텔레그램 메시지 발송 오류: {e}")
            return False
    
    def get_published_posts(self, days_back=7, limit=50):
        """
        최근 발행된 글 목록 조회
        :param days_back: 몇 일 전까지 조회할지
        :param limit: 최대 조회 개수
        :return: 글 목록
        """
        try:
            # WordPress REST API 엔드포인트
            api_url = f"{self.config['wp_url']}/wp-json/wp/v2/posts"
            
            # 기본 인증 헤더
            import base64
            credentials = f"{self.config['wp_user']}:{self.config['wp_app_pass']}"
            encoded_credentials = base64.b64encode(credentials.encode()).decode()
            headers = {
                'Authorization': f'Basic {encoded_credentials}',
                'Content-Type': 'application/json'
            }
            
            # 최근 글 조회 파라미터
            since_date = (datetime.now() - timedelta(days=days_back)).isoformat()
            params = {
                'after': since_date,
                'per_page': limit,
                'status': 'publish',
                'orderby': 'date',
                'order': 'desc'
            }
            
            response = requests.get(api_url, headers=headers, params=params, timeout=30)
            
            if response.status_code == 200:
                posts = response.json()
                print(f"[✅] WordPress 글 조회 성공: {len(posts)}개")
                return posts
            else:
                print(f"[❌] WordPress 글 조회 실패: {response.status_code}")
                return []
                
        except Exception as e:
            print(f"[❌] WordPress 글 조회 오류: {e}")
            return []
    
    def extract_product_links_from_content(self, content):
        """
        글 내용에서 상품 링크 추출
        :param content: 글 내용 (HTML)
        :return: 추출된 상품 링크 목록
        """
        product_links = []
        
        # 쿠팡 링크 패턴
        coupang_patterns = [
            r'https://(?:www\.)?coupang\.com/[^\s"\'<>]+',
            r'https://link\.coupang\.com/[^\s"\'<>]+',
            r'https://ads-partners\.coupang\.com/[^\s"\'<>]+'
        ]
        
        # 알리익스프레스 링크 패턴
        aliexpress_patterns = [
            r'https://(?:ko\.)?aliexpress\.com/[^\s"\'<>]+',
            r'https://s\.click\.aliexpress\.com/[^\s"\'<>]+',
            r'https://[a-z]+\.aliexpress\.com/[^\s"\'<>]+'
        ]
        
        all_patterns = coupang_patterns + aliexpress_patterns
        
        for pattern in all_patterns:
            matches = re.findall(pattern, content, re.IGNORECASE)
            for match in matches:
                # 링크 정리 (HTML 엔티티 등 제거)
                clean_link = match.replace('&amp;', '&').strip()
                if clean_link not in product_links:
                    product_links.append(clean_link)
        
        return product_links
    
    def check_product_link_status(self, product_url):
        """
        상품 링크 상태 확인
        :param product_url: 상품 URL
        :return: (platform, status, details)
        """
        try:
            # 플랫폼 식별
            if 'coupang.com' in product_url.lower():
                platform = "쿠팡"
                return self._check_coupang_status(product_url)
            elif 'aliexpress.com' in product_url.lower():
                platform = "알리익스프레스"
                return self._check_aliexpress_status(product_url)
            else:
                return "알 수 없음", "unknown", {"error": "지원하지 않는 플랫폼"}
                
        except Exception as e:
            return "오류", "error", {"error": str(e)}
    
    def _check_coupang_status(self, product_url):
        """쿠팡 상품 상태 확인"""
        try:
            # 쿠팡 API를 통한 상품 정보 조회 시도
            if '/products/' in product_url:
                # 상품 ID 추출
                product_id = self.api_manager.extract_coupang_product_id(product_url)
                if product_id:
                    # API로 상품 검색 시도
                    success, products = self.api_manager.search_coupang_safe(product_id, limit=1)
                    if success and products:
                        product = products[0]
                        return "쿠팡", "available", {
                            "title": product.get('title', 'N/A'),
                            "price": product.get('price', 'N/A'),
                            "product_id": product_id
                        }
                    else:
                        return "쿠팡", "unavailable", {
                            "reason": "API 검색 결과 없음",
                            "product_id": product_id
                        }
            
            # API로 확인할 수 없는 경우 HTTP 상태 확인
            response = requests.head(product_url, timeout=10, allow_redirects=True)
            if response.status_code == 200:
                return "쿠팡", "unknown", {"http_status": "200", "note": "HTTP는 정상이지만 상품 상태 미확인"}
            else:
                return "쿠팡", "http_error", {"http_status": response.status_code}
                
        except Exception as e:
            return "쿠팡", "error", {"error": str(e)}
    
    def _check_aliexpress_status(self, product_url):
        """알리익스프레스 상품 상태 확인"""
        try:
            # 알리익스프레스 상품 ID 추출
            product_id = self.api_manager.extract_aliexpress_product_id(product_url)
            if product_id:
                # API로 상품 상세 정보 조회 시도
                product_info = self.api_manager._get_aliexpress_product_details(product_id)
                if product_info:
                    return "알리익스프레스", "available", {
                        "title": product_info.get('title', 'N/A'),
                        "price": product_info.get('price', 'N/A'),
                        "product_id": product_id
                    }
                else:
                    return "알리익스프레스", "unavailable", {
                        "reason": "API 조회 결과 없음",
                        "product_id": product_id
                    }
            
            # HTTP 상태 확인
            response = requests.head(product_url, timeout=10, allow_redirects=True)
            if response.status_code == 200:
                return "알리익스프레스", "unknown", {"http_status": "200", "note": "HTTP는 정상이지만 상품 상태 미확인"}
            else:
                return "알리익스프레스", "http_error", {"http_status": response.status_code}
                
        except Exception as e:
            return "알리익스프레스", "error", {"error": str(e)}
    
    def monitor_posts(self, days_back=7):
        """
        발행된 글들의 상품 상태 모니터링
        :param days_back: 몇 일 전까지 모니터링할지
        :return: 모니터링 결과
        """
        print(f"\n[🔍] 상품 모니터링 시작 (최근 {days_back}일)")
        print("=" * 60)
        
        # 기존 모니터링 데이터 로드
        monitor_data = self._load_json_file(self.monitor_data_file, {})
        
        # 발행된 글 조회
        posts = self.get_published_posts(days_back=days_back)
        if not posts:
            print("[⚠️] 조회할 글이 없습니다")
            return
        
        alerts = []
        checked_posts = 0
        total_links = 0
        
        for post in posts:
            post_id = str(post['id'])
            post_title = post['title']['rendered']
            post_url = post['link']
            post_date = post['date']
            
            print(f"\n[📄] 글 분석: {post_title}")
            print(f"    ID: {post_id}, 날짜: {post_date}")
            
            # 글 내용에서 상품 링크 추출
            content = post['content']['rendered']
            product_links = self.extract_product_links_from_content(content)
            
            if not product_links:
                print(f"    ℹ️ 상품 링크가 없습니다")
                continue
            
            print(f"    🔗 발견된 상품 링크: {len(product_links)}개")
            checked_posts += 1
            total_links += len(product_links)
            
            # 각 상품 링크 상태 확인
            for i, link in enumerate(product_links, 1):
                print(f"    [{i}/{len(product_links)}] 상품 확인 중...")
                
                platform, status, details = self.check_product_link_status(link)
                
                # 모니터링 데이터 업데이트
                link_key = f"{post_id}_{i}"
                current_status = {
                    "post_id": post_id,
                    "post_title": post_title,
                    "post_url": post_url,
                    "link_index": i,
                    "product_url": link,
                    "platform": platform,
                    "status": status,
                    "details": details,
                    "last_checked": datetime.now().isoformat(),
                    "check_count": monitor_data.get(link_key, {}).get("check_count", 0) + 1
                }
                
                # 이전 상태와 비교
                previous_status = monitor_data.get(link_key, {}).get("status")
                
                if previous_status and previous_status != status:
                    # 상태 변경 감지
                    if status in ["unavailable", "http_error", "error"]:
                        alert = {
                            "type": "status_changed",
                            "post_title": post_title,
                            "post_url": post_url,
                            "product_url": link,
                            "platform": platform,
                            "previous_status": previous_status,
                            "current_status": status,
                            "details": details,
                            "detected_at": datetime.now().isoformat()
                        }
                        alerts.append(alert)
                        print(f"        🚨 상태 변경 감지: {previous_status} → {status}")
                
                # 새로운 문제 상품 감지
                elif not previous_status and status in ["unavailable", "http_error", "error"]:
                    alert = {
                        "type": "new_issue",
                        "post_title": post_title,
                        "post_url": post_url,
                        "product_url": link,
                        "platform": platform,
                        "status": status,
                        "details": details,
                        "detected_at": datetime.now().isoformat()
                    }
                    alerts.append(alert)
                    print(f"        ⚠️ 문제 상품 발견: {status}")
                
                # 모니터링 데이터 저장
                monitor_data[link_key] = current_status
                
                print(f"        ✅ {platform}: {status}")
                
                # API 제한 고려 대기
                time.sleep(1)
        
        # 모니터링 데이터 저장
        self._save_json_file(self.monitor_data_file, monitor_data)
        
        # 알림 처리
        if alerts:
            self._process_alerts(alerts)
        
        # 결과 요약
        print(f"\n{'='*60}")
        print(f"🏆 모니터링 완료")
        print(f"📊 결과 요약:")
        print(f"  📄 확인된 글: {checked_posts}개")
        print(f"  🔗 확인된 링크: {total_links}개")
        print(f"  🚨 발견된 알림: {len(alerts)}개")
        
        return {
            "checked_posts": checked_posts,
            "total_links": total_links,
            "alerts": alerts
        }
    
    def _process_alerts(self, alerts):
        """알림 처리 및 텔레그램 발송"""
        print(f"\n[🚨] 알림 처리 시작: {len(alerts)}개")
        
        for alert in alerts:
            message = self._format_alert_message(alert)
            
            # 텔레그램 발송
            if self.send_telegram_message(message):
                # 알림 로그 저장
                self._save_alert_log(alert)
            
            # 알림 간 대기
            time.sleep(2)
    
    def _format_alert_message(self, alert):
        """알림 메시지 포맷팅"""
        if alert['type'] == 'status_changed':
            message = f"""🚨 <b>상품 상태 변경 알림</b>

📄 <b>글 제목:</b> {alert['post_title']}
🔗 <b>글 링크:</b> {alert['post_url']}

🛒 <b>플랫폼:</b> {alert['platform']}
🔄 <b>상태 변경:</b> {alert['previous_status']} → {alert['current_status']}

🔗 <b>상품 URL:</b> {alert['product_url'][:100]}{'...' if len(alert['product_url']) > 100 else ''}

ℹ️ <b>상세 정보:</b> {alert['details'].get('reason', alert['details'])}

⏰ <b>감지 시간:</b> {alert['detected_at']}"""
        
        elif alert['type'] == 'new_issue':
            message = f"""⚠️ <b>문제 상품 발견</b>

📄 <b>글 제목:</b> {alert['post_title']}
🔗 <b>글 링크:</b> {alert['post_url']}

🛒 <b>플랫폼:</b> {alert['platform']}
❌ <b>상태:</b> {alert['status']}

🔗 <b>상품 URL:</b> {alert['product_url'][:100]}{'...' if len(alert['product_url']) > 100 else ''}

ℹ️ <b>상세 정보:</b> {alert['details'].get('reason', alert['details'])}

⏰ <b>감지 시간:</b> {alert['detected_at']}"""
        
        else:
            message = f"📢 알 수 없는 알림 유형: {alert['type']}"
        
        return message
    
    def _save_alert_log(self, alert):
        """알림 로그 저장"""
        alert_log = self._load_json_file(self.alert_log_file, [])
        alert_log.append(alert)
        
        # 최근 1000개 기록만 유지
        if len(alert_log) > 1000:
            alert_log = alert_log[-1000:]
        
        self._save_json_file(self.alert_log_file, alert_log)
    
    def get_monitoring_stats(self):
        """모니터링 통계 조회"""
        monitor_data = self._load_json_file(self.monitor_data_file, {})
        alert_log = self._load_json_file(self.alert_log_file, [])
        
        stats = {
            "total_monitored_links": len(monitor_data),
            "total_alerts": len(alert_log),
            "last_check": max([item.get("last_checked", "") for item in monitor_data.values()]) if monitor_data else None,
            "platform_breakdown": {},
            "status_breakdown": {}
        }
        
        # 플랫폼별 통계
        for item in monitor_data.values():
            platform = item.get("platform", "unknown")
            status = item.get("status", "unknown")
            
            stats["platform_breakdown"][platform] = stats["platform_breakdown"].get(platform, 0) + 1
            stats["status_breakdown"][status] = stats["status_breakdown"].get(status, 0) + 1
        
        return stats

def main():
    """메인 실행 함수"""
    print("🔍 상품 모니터링 시스템 시작")
    print("=" * 60)
    
    try:
        monitor = ProductMonitor()
        
        # 모니터링 실행 (최근 7일)
        result = monitor.monitor_posts(days_back=7)
        
        # 통계 출력
        stats = monitor.get_monitoring_stats()
        print(f"\n📈 전체 모니터링 통계:")
        print(f"  📊 모니터링 중인 링크: {stats['total_monitored_links']}개")
        print(f"  🚨 총 알림 발송: {stats['total_alerts']}개")
        print(f"  🕒 마지막 확인: {stats['last_check']}")
        
        if stats['platform_breakdown']:
            print(f"  🛒 플랫폼별:")
            for platform, count in stats['platform_breakdown'].items():
                print(f"    - {platform}: {count}개")
        
        if stats['status_breakdown']:
            print(f"  📊 상태별:")
            for status, count in stats['status_breakdown'].items():
                print(f"    - {status}: {count}개")
        
        print(f"\n✅ 모니터링 완료")
        
    except Exception as e:
        print(f"[❌] 모니터링 시스템 오류: {e}")
        return False
    
    return True

if __name__ == "__main__":
    main()