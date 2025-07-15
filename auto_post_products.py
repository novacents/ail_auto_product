#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
어필리에이트 상품 자동 등록 시스템 메인 스크립트
키워드 입력 → API 상품 검색 → AI 콘텐츠 생성 → 워드프레스 자동 발행

작성자: Claude AI
날짜: 2025-07-09
버전: v1.0
"""

import os
import sys
import json
import time
import requests
import traceback
import google.generativeai as genai
from datetime import datetime
from dotenv import load_dotenv

# SafeAPIManager 경로 추가
sys.path.append('/home/novacents/server/var/www/novacents/tools')
from safe_api_manager import SafeAPIManager

# AliExpress SDK 경로 추가
sys.path.append('/home/novacents/aliexpress-sdk')
import iop

# ##############################################################################
# 사용자 설정
# ##############################################################################
MAX_POSTS_PER_RUN = 1
QUEUE_FILE = "/var/www/novacents/tools/product_queue.json"
LOG_FILE = "/var/www/novacents/tools/auto_post_products.log"
PUBLISHED_LOG_FILE = "/var/www/novacents/tools/published_log.txt"
POST_DELAY_SECONDS = 30
# ##############################################################################

class AffiliatePostingSystem:
    def __init__(self):
        self.config = None
        self.safe_api = None
        self.gemini_model = None
        
    def load_configuration(self):
        """환경 변수 및 API 키 로드"""
        print("[⚙️] 1. 설정을 로드합니다...")
        
        # .env 파일 로드
        env_path = "/home/novacents/.env"
        if os.path.exists(env_path):
            load_dotenv(env_path)
        else:
            print(f"[❌] .env 파일을 찾을 수 없습니다: {env_path}")
            return False
            
        self.config = {
            # 쿠팡 파트너스 API
            "coupang_access_key": os.getenv("COUPANG_ACCESS_KEY"),
            "coupang_secret_key": os.getenv("COUPANG_SECRET_KEY"),
            
            # 알리익스프레스 API
            "aliexpress_app_key": os.getenv("ALIEXPRESS_APP_KEY"),
            "aliexpress_app_secret": os.getenv("ALIEXPRESS_APP_SECRET"),
            
            # Gemini API
            "gemini_api_key": os.getenv("GEMINI_API_KEY"),
            
            # 워드프레스 API (novacents.com)
            "wp_user": os.getenv("NOVACENTS_WP_USER"),
            "wp_app_pass": os.getenv("NOVACENTS_WP_APP_PASS"),
            "wp_url": os.getenv("NOVACENTS_WP_URL"),
            "wp_api_base": os.getenv("NOVACENTS_WP_API_BASE"),
            
            # 텔레그램 봇
            "telegram_bot_token": os.getenv("TELEGRAM_BOT_TOKEN"),
            "telegram_chat_id": os.getenv("TELEGRAM_CHAT_ID"),
        }
        
        # 필수 환경 변수 확인
        required_keys = [
            "coupang_access_key", "coupang_secret_key",
            "aliexpress_app_key", "aliexpress_app_secret",
            "gemini_api_key", "wp_user", "wp_app_pass",
            "wp_url", "wp_api_base"
        ]
        
        missing_keys = [key for key in required_keys if not self.config.get(key)]
        if missing_keys:
            print(f"[❌] 필수 환경 변수가 누락되었습니다: {missing_keys}")
            return False
            
        # Gemini API 초기화
        try:
            genai.configure(api_key=self.config["gemini_api_key"])
            self.gemini_model = genai.GenerativeModel('gemini-2.5-pro')
            print("[✅] Gemini API가 성공적으로 구성되었습니다.")
        except Exception as e:
            print(f"[❌] Gemini API 구성 중 오류 발생: {e}")
            return False
            
        # SafeAPIManager 초기화
        try:
            self.safe_api = SafeAPIManager(mode="production")
            print("[✅] SafeAPIManager가 성공적으로 초기화되었습니다.")
        except Exception as e:
            print(f"[❌] SafeAPIManager 초기화 중 오류 발생: {e}")
            return False
            
        return True
        
    def send_telegram_notification(self, message):
        """텔레그램 알림 전송"""
        if not self.config.get("telegram_bot_token") or not self.config.get("telegram_chat_id"):
            return
            
        try:
            url = f"https://api.telegram.org/bot{self.config['telegram_bot_token']}/sendMessage"
            data = {
                "chat_id": self.config["telegram_chat_id"],
                "text": message,
                "parse_mode": "HTML"
            }
            response = requests.post(url, data=data, timeout=10)
            if response.status_code == 200:
                print(f"[📱] 텔레그램 알림 전송 성공: {message[:50]}...")
            else:
                print(f"[❌] 텔레그램 알림 전송 실패: {response.status_code}")
        except Exception as e:
            print(f"[❌] 텔레그램 알림 전송 중 오류: {e}")
            
    def log_message(self, message):
        """로그 메시지 저장"""
        timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        log_entry = f"[{timestamp}] {message}\n"
        
        try:
            with open(LOG_FILE, "a", encoding="utf-8") as f:
                f.write(log_entry)
        except Exception as e:
            print(f"[❌] 로그 저장 중 오류: {e}")
            
        print(message)
        
    def load_queue(self):
        """큐 파일에서 pending 작업 로드"""
        try:
            if not os.path.exists(QUEUE_FILE):
                print(f"[❌] 큐 파일을 찾을 수 없습니다: {QUEUE_FILE}")
                return []
                
            with open(QUEUE_FILE, "r", encoding="utf-8") as f:
                queue_data = json.load(f)
                
            # pending 상태인 작업만 필터링
            pending_jobs = [job for job in queue_data if job.get("status") == "pending"]
            
            print(f"[📋] 큐에서 {len(pending_jobs)}개의 대기 중인 작업을 발견했습니다.")
            return pending_jobs
            
        except Exception as e:
            print(f"[❌] 큐 로드 중 오류 발생: {e}")
            return []
            
    def save_queue(self, queue_data):
        """큐 파일 저장"""
        try:
            with open(QUEUE_FILE, "w", encoding="utf-8") as f:
                json.dump(queue_data, f, ensure_ascii=False, indent=4)
            print("[✅] 큐 파일이 성공적으로 저장되었습니다.")
        except Exception as e:
            print(f"[❌] 큐 저장 중 오류 발생: {e}")
            
    def update_job_status(self, job_id, status, error_message=None):
        """작업 상태 업데이트"""
        try:
            with open(QUEUE_FILE, "r", encoding="utf-8") as f:
                queue_data = json.load(f)
                
            for job in queue_data:
                if job.get("queue_id") == job_id:
                    job["status"] = status
                    job["processed_at"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                    if error_message:
                        job["last_error"] = error_message
                        job["attempts"] = job.get("attempts", 0) + 1
                    break
                    
            self.save_queue(queue_data)
            
        except Exception as e:
            print(f"[❌] 작업 상태 업데이트 중 오류: {e}")
    
    def process_links_based(self, job_data):
        """링크 기반 상품 정보 처리 (새로운 방식)"""
        self.log_message("[🔗] 링크 기반 상품 정보 처리를 시작합니다...")
        
        all_coupang_products = []
        all_aliexpress_products = []
        
        for keyword_data in job_data["keywords"]:
            keyword = keyword_data["name"]
            self.log_message(f"[📋] 키워드 '{keyword}' 처리 중...")
            
            # 쿠팡 링크 처리
            if "coupang" in keyword_data and keyword_data["coupang"]:
                for link in keyword_data["coupang"]:
                    if link.strip():
                        product = self.process_coupang_link(link.strip(), keyword)
                        if product:
                            all_coupang_products.append(product)
                            
            # 알리익스프레스 링크 처리
            if "aliexpress" in keyword_data and keyword_data["aliexpress"]:
                for link in keyword_data["aliexpress"]:
                    if link.strip():
                        product = self.process_aliexpress_link(link.strip(), keyword)
                        if product:
                            all_aliexpress_products.append(product)
                            
            # API 호출 간 딜레이
            time.sleep(2)
        
        self.log_message(f"[✅] 링크 처리 완료 - 쿠팡: {len(all_coupang_products)}개, 알리익스프레스: {len(all_aliexpress_products)}개")
        return all_coupang_products, all_aliexpress_products
    
    def process_keyword_based(self, job_data):
        """키워드 기반 상품 검색 (기존 방식)"""
        self.log_message("[🔍] 키워드 기반 상품 검색을 시작합니다...")
        
        all_coupang_products = []
        all_aliexpress_products = []
        
        for keyword_data in job_data["keywords"]:
            keyword = keyword_data["name"]
            
            # 쿠팡 상품 검색
            coupang_products = self.search_coupang_products(keyword, limit=2)
            all_coupang_products.extend(coupang_products)
            
            # 알리익스프레스 상품 검색
            aliexpress_products = self.search_aliexpress_products(keyword, limit=2)
            all_aliexpress_products.extend(aliexpress_products)
            
            # API 호출 간 딜레이
            time.sleep(2)
            
        return all_coupang_products, all_aliexpress_products
    
    def process_coupang_link(self, product_url, keyword):
        """쿠팡 링크를 어필리에이트 링크로 변환하고 상품 정보 추출"""
        try:
            self.log_message(f"[🛒] 쿠팡 링크 처리: {product_url[:50]}...")
            
            # SafeAPIManager를 이용한 링크 변환
            success, affiliate_link, product_info = self.safe_api.convert_coupang_to_affiliate_link(product_url)
            
            if success and product_info:
                # 키워드 정보 추가
                product_info["keyword"] = keyword
                self.log_message(f"[✅] 쿠팡 상품 처리 성공: {product_info.get('title', '제목 없음')}")
                return product_info
            else:
                self.log_message(f"[⚠️] 쿠팡 링크 변환 실패: {product_url}")
                return None
                
        except Exception as e:
            self.log_message(f"[❌] 쿠팡 링크 처리 중 오류: {e}")
            return None
    
    def process_aliexpress_link(self, product_url, keyword):
        """알리익스프레스 링크를 어필리에이트 링크로 변환하고 상품 정보 추출"""
        try:
            self.log_message(f"[🌏] 알리익스프레스 링크 처리: {product_url[:50]}...")
            
            # SafeAPIManager를 이용한 링크 변환
            success, affiliate_link, product_info = self.safe_api.convert_aliexpress_to_affiliate_link(product_url)
            
            if success and product_info:
                # 키워드 정보 추가
                product_info["keyword"] = keyword
                self.log_message(f"[✅] 알리익스프레스 상품 처리 성공: {product_info.get('title', '제목 없음')}")
                return product_info
            else:
                self.log_message(f"[⚠️] 알리익스프레스 링크 변환 실패: {product_url}")
                return None
                
        except Exception as e:
            self.log_message(f"[❌] 알리익스프레스 링크 처리 중 오류: {e}")
            return None
    
    def insert_product_cards_into_content(self, content, coupang_products, aliexpress_products):
        """
        링크 기반 시스템용 상품 카드 배치 로직
        상품 정보가 포함된 어필리에이트 링크 기반으로 적절한 위치에 카드 배치
        """
        final_content = content
        
        # 상품 카드 HTML 생성 헬퍼 함수
        def generate_product_card_html(product_data, platform):
            if platform == "coupang":
                product_name = product_data.get('title', '상품명 없음')
                product_price = product_data.get('price', '가격 정보 없음')
                product_image = product_data.get('image_url', '')
                rating = product_data.get('rating', '평점 없음')
                review_count = product_data.get('review_count', '리뷰 없음')
                affiliate_url = product_data.get('affiliate_url', product_data.get('product_url', ''))
                
                if product_price and product_price != '가격 정보 없음':
                    price_display = f"💰 {product_price}"
                else:
                    price_display = "💰 가격 확인 필요"
                    
                if rating and rating != '평점 없음':
                    rating_display = f"⭐ {rating}"
                else:
                    rating_display = "⭐ 평점 정보 없음"
                    
                if review_count and review_count != '리뷰 없음':
                    review_display = f"📝 리뷰 {review_count}개"
                else:
                    review_display = "📝 리뷰 정보 없음"
                    
            else:  # aliexpress
                product_name = product_data.get('title', '상품명 없음')
                product_price = product_data.get('price', '가격 정보 없음')
                product_image = product_data.get('image_url', '')
                rating = product_data.get('rating', '평점 정보 없음')
                review_count = product_data.get('review_count', '리뷰 정보 없음')
                affiliate_url = product_data.get('affiliate_url', product_data.get('product_url', ''))
                
                # 가격 표시 (이미 KRW 변환된 형태)
                price_display = f"💰 {product_price}" if product_price != '가격 정보 없음' else "💰 가격 확인 필요"
                rating_display = f"⭐ {rating}" if rating != '평점 정보 없음' else "⭐ 평점 정보 없음"
                review_display = f"📝 리뷰 {review_count}개" if review_count != '리뷰 정보 없음' else "📝 리뷰 정보 없음"
            
            # 상품 이미지 HTML
            image_html = ""
            if product_image and product_image != '이미지 정보 없음' and product_image.startswith('http'):
                image_html = f'''
                <div style="text-align: center; margin-bottom: 15px;">
                    <img src="{product_image}" alt="{product_name}" style="max-width: 300px; height: auto; border-radius: 8px; border: 1px solid #ddd;">
                </div>'''
            
            # 어필리에이트 버튼 HTML
            button_color = "#FF6B00" if platform == "coupang" else "#FF6A00"
            button_text = "쿠팡에서 확인" if platform == "coupang" else "알리익스프레스에서 확인"
            
            button_html = f'''
            <div style="text-align: center; margin: 20px 0;">
                <a href="{affiliate_url}" target="_blank" rel="noopener noreferrer" 
                   style="display: inline-block; background-color: {button_color}; color: white; 
                          padding: 12px 24px; text-decoration: none; border-radius: 8px; 
                          font-weight: bold; font-size: 16px; transition: all 0.3s;">
                    {button_text} 🛒
                </a>
            </div>'''
            
            return f'''
<div style="border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; margin: 20px auto; max-width: 800px; background-color: #f9f9f9;">
    {image_html}
    <div style="text-align: center;">
        <h4 style="margin: 10px 0; color: #333; font-size: 18px;">{product_name}</h4>
        <div style="margin: 10px 0; color: #666; font-size: 14px;">
            <span style="display: inline-block; margin: 0 10px;">{price_display}</span>
            <span style="display: inline-block; margin: 0 10px;">{rating_display}</span>
            <span style="display: inline-block; margin: 0 10px;">{review_display}</span>
        </div>
    </div>
    {button_html}
</div>'''
        
        # 쿠팡 상품 카드 배치
        if coupang_products:
            for i, product in enumerate(coupang_products):
                card_html = generate_product_card_html(product, "coupang")
                keyword = product.get('keyword', '')
                
                # 적절한 위치에 카드 삽입
                final_content = self._insert_card_at_optimal_position(
                    final_content, card_html, keyword, f"coupang_{i}"
                )
        
        # 알리익스프레스 상품 카드 배치 (쿠팡 카드 다음에)
        if aliexpress_products:
            for i, product in enumerate(aliexpress_products):
                card_html = generate_product_card_html(product, "aliexpress")
                keyword = product.get('keyword', '')
                
                # 적절한 위치에 카드 삽입
                final_content = self._insert_card_at_optimal_position(
                    final_content, card_html, keyword, f"aliexpress_{i}"
                )
        
        return final_content
    
    def _insert_card_at_optimal_position(self, content, card_html, keyword, card_id):
        """상품 카드를 최적의 위치에 삽입"""
        import re
        
        # 1순위: 키워드가 포함된 H2/H3 섹션의 첫 번째 단락 다음
        if keyword:
            pattern1 = rf'(<h[2-3][^>]*>[^<]*{re.escape(keyword)}[^<]*</h[2-3]>[^<]*<p[^>]*>.*?</p>)'
            if re.search(pattern1, content, re.IGNORECASE | re.DOTALL):
                content = re.sub(pattern1, rf'\1{card_html}', content, flags=re.IGNORECASE | re.DOTALL, count=1)
                print(f"[✅] {card_id} 상품 카드를 '{keyword}' H2/H3 섹션 다음에 삽입")
                return content
            
            # 2순위: 키워드가 언급된 첫 번째 단락 다음
            pattern2 = rf'(<p[^>]*>[^<]*{re.escape(keyword)}[^<]*</p>)'
            if re.search(pattern2, content, re.IGNORECASE | re.DOTALL):
                content = re.sub(pattern2, rf'\1{card_html}', content, flags=re.IGNORECASE | re.DOTALL, count=1)
                print(f"[✅] {card_id} 상품 카드를 '{keyword}' 언급 단락 다음에 삽입")
                return content
        
        # 3순위: 첫 번째 H2 섹션 다음
        pattern3 = r'(<h2[^>]*>.*?</h2>[^<]*<p[^>]*>.*?</p>)'
        if re.search(pattern3, content, re.IGNORECASE | re.DOTALL):
            content = re.sub(pattern3, rf'\1{card_html}', content, flags=re.IGNORECASE | re.DOTALL, count=1)
            print(f"[✅] {card_id} 상품 카드를 첫 번째 H2 섹션 다음에 삽입")
            return content
        
        # 4순위: 콘텐츠 중간 지점
        content_parts = content.split('</p>')
        if len(content_parts) > 3:
            mid_point = len(content_parts) // 2
            content_parts[mid_point] += card_html
            content = '</p>'.join(content_parts)
            print(f"[✅] {card_id} 상품 카드를 콘텐츠 중간에 삽입")
            return content
        
        # 마지막 방법: 콘텐츠 끝에 추가
        content += card_html
        print(f"[⚠️] {card_id} 상품 카드를 콘텐츠 끝에 삽입")
        return content
    
    def generate_base_content_with_gemini(self, job_data):
        """링크 기반 시스템용 기본 콘텐츠 생성 (상품 카드 제외)"""
        try:
            print(f"[🤖] Gemini AI로 '{job_data['title']}' 기본 콘텐츠를 생성합니다...")
            
            # 키워드 정보 정리
            keywords = [kw["name"] for kw in job_data["keywords"]]
            
            # 링크 기반 프롬프트 생성
            prompt = f"""당신은 대한민국 최고의 온라인 쇼핑 전문가이자 상품 리뷰어입니다. 
아래 제목과 키워드를 바탕으로 한국 소비자들을 위한 매우 실용적이고 유용한 상품 추천 블로그 글을 작성해주세요.

### 📋 제공된 정보 ###
**글 제목:** {job_data['title']}
**핵심 키워드:** {', '.join(keywords)}

### ✅ 작성 요구사항 ###

1. **글 구조 (총 2000-3000자)**:
   - 🎯 인트로 섹션 (150-250자): 2025년 트렌드 반영, 핵심 키워드 강조
   - ⭐ 각 키워드별 상품 심층 분석 (키워드당 400-600자): 상세한 소개, 장단점, 가격대, 성능 비교
   - 🏪 플랫폼별 쇼핑 전략 (300-400자): 쿠팡 vs 알리익스프레스 차이점, 활용법
   - 💡 스마트 구매 가이드 (250-350자): 체크리스트, 가격 비교 팁, 주의사항
   - ✅ 결론 및 추천 (150-200자): 최고 추천 상품과 이유, 2025년 전망

2. **콘텐츠 품질**:
   - 각 키워드를 3-5회 자연스럽게 언급
   - 구체적인 수치와 사실 기반 내용 강조
   - 사용자 경험 중심 설명
   - 전문적이고 신뢰할 수 있는 톤

3. **HTML 포맷팅**:
   - H2 태그로 주요 섹션 구분
   - H3 태그로 각 키워드별 소제목
   - 문단은 p 태그 사용
   - 이모지 적절히 활용

### ⚠️ 중요사항 ###
- 마크다운 문법(## ###) 사용 금지, 반드시 HTML 태그 사용
- 상품 링크나 버튼 관련 내용 포함하지 마세요 (별도로 삽입됩니다)
- {keywords[0]} 키워드가 가장 중요하므로 첫 번째로 다뤄주세요

지금 바로 완성도 높은 블로그 글을 작성해주세요:"""

            # Gemini API 호출
            response = self.gemini_model.generate_content(prompt)
            base_content = response.text
            
            if not base_content or len(base_content.strip()) < 1000:
                print("[❌] Gemini가 충분한 길이의 콘텐츠를 생성하지 못했습니다.")
                return None
            
            # 콘텐츠 정리 (18px 글자 크기 적용)
            final_content = f'<div style="font-size: 18px; line-height: 1.7;">{base_content}</div>'
            
            print(f"[✅] Gemini AI가 {len(base_content)}자의 기본 콘텐츠를 생성했습니다.")
            return final_content
            
        except Exception as e:
            print(f"[❌] Gemini 기본 콘텐츠 생성 중 오류: {e}")
            return None
            
    def search_coupang_products(self, keyword, limit=3):
        """쿠팡 파트너스 API로 상품 검색"""
        try:
            print(f"[🔍] 쿠팡에서 '{keyword}' 상품을 검색합니다...")
            
            # SafeAPIManager를 통해 안전하게 API 호출
            products = self.safe_api.search_coupang_safe(keyword, limit)
            
            if products:
                print(f"[✅] 쿠팡에서 {len(products)}개의 상품을 발견했습니다.")
                return products
            else:
                print(f"[⚠️] 쿠팡에서 '{keyword}' 상품을 찾을 수 없습니다.")
                return []
                
        except Exception as e:
            print(f"[❌] 쿠팡 상품 검색 중 오류: {e}")
            return []
            
    def search_aliexpress_products(self, keyword, limit=3):
        """알리익스프레스 API로 상품 검색"""
        try:
            print(f"[🔍] 알리익스프레스에서 '{keyword}' 상품을 검색합니다...")
            
            # 알리익스프레스 API 클라이언트 생성
            client = iop.IopClient(
                "https://api-sg.aliexpress.com/sync",
                self.config["aliexpress_app_key"],
                self.config["aliexpress_app_secret"]
            )
            
            # 상품 검색 요청 (integrated_api_test.py에서 성공한 설정 사용)
            request = iop.IopRequest('aliexpress.affiliate.product.query', 'POST')
            request.set_simplify()
            request.add_api_param('keywords', keyword)
            request.add_api_param('page_size', str(limit))
            request.add_api_param('target_currency', 'USD')
            request.add_api_param('target_language', 'EN')
            request.add_api_param('sort', 'SALE_PRICE_ASC')
            
            response = client.execute(request)
            
            if response.type == 'nil':
                print(f"[❌] 알리익스프레스 API 호출 실패")
                return []
                
            # response.body가 이미 딕셔너리인 경우 처리
            if isinstance(response.body, dict):
                response_data = response.body
            else:
                response_data = json.loads(response.body)
            
            # integrated_api_test.py 포맷에 맞춘 응답 처리
            if 'resp_result' in response_data and 'result' in response_data['resp_result']:
                result = response_data['resp_result']['result']
                if 'products' in result and result['products']:
                    products = result['products']
                    print(f"[✅] 알리익스프레스에서 {len(products)}개의 상품을 발견했습니다.")
                    
                    # 상품 정보 정리 (integrated_api_test.py 포맷 기준)
                    formatted_products = []
                    for product in products:
                        formatted_product = {
                            "platform": "알리익스프레스",
                            "product_id": product.get("product_id"),
                            "title": product.get("product_title"),
                            "price": product.get("target_sale_price"),
                            "currency": product.get("target_sale_price_currency", "USD"),
                            "original_price": product.get("target_original_price"),
                            "discount": product.get("discount"),
                            "image_url": product.get("product_main_image_url"),
                            "product_url": product.get("product_detail_url"),
                            "affiliate_url": product.get("promotion_link"),
                            "commission_rate": product.get("commission_rate"),
                            "shop_name": product.get("shop_name"),
                            "original_data": product
                        }
                        formatted_products.append(formatted_product)
                    
                    return formatted_products
                else:
                    print(f"[⚠️] 알리익스프레스에서 '{keyword}' 상품을 찾을 수 없습니다.")
                    return []
            elif response_data.get('result') and response_data['result'].get('products'):
                # 기존 포맷 지원 (호환성)
                products = response_data['result']['products']
                print(f"[✅] 알리익스프레스에서 {len(products)}개의 상품을 발견했습니다. (기존 포맷)")
                return products
            else:
                print(f"[⚠️] 알리익스프레스에서 '{keyword}' 상품을 찾을 수 없습니다.")
                return []
                
        except Exception as e:
            print(f"[❌] 알리익스프레스 상품 검색 중 오류: {e}")
            return []
            
    def generate_content_with_gemini(self, job_data, coupang_products, aliexpress_products):
        """Gemini API로 블로그 콘텐츠 생성"""
        try:
            print(f"[🤖] Gemini AI로 '{job_data['title']}' 콘텐츠를 생성합니다...")
            
            # 상품 정보 정리
            product_info = {
                "title": job_data["title"],
                "keywords": [kw["name"] for kw in job_data["keywords"]],
                "coupang_products": coupang_products,
                "aliexpress_products": aliexpress_products
            }
            
            # 사용자가 입력한 어필리에이트 링크 정리
            user_links = {}
            has_coupang_links = False
            
            for keyword_data in job_data["keywords"]:
                keyword_name = keyword_data["name"]
                coupang_links = keyword_data.get("coupang", [])
                aliexpress_links = keyword_data.get("aliexpress", [])
                
                if coupang_links:
                    has_coupang_links = True
                
                user_links[keyword_name] = {
                    "coupang": coupang_links,
                    "aliexpress": aliexpress_links
                }
            
            # 어필리에이트 버튼 HTML 생성 함수
            def generate_affiliate_button_html(platform, link_url, keyword):
                base_url = "https://novacents.com/tools/images/"
                
                if platform == "coupang":
                    pc_img = f"{base_url}coupang-button-pc.png"
                    mobile_img = f"{base_url}coupang-button-mobile.png"
                    alt_text = f"쿠팡에서 {keyword} 구매하기"
                else:  # aliexpress
                    pc_img = f"{base_url}aliexpress-button-pc.png"
                    mobile_img = f"{base_url}aliexpress-button-mobile.png"
                    alt_text = f"알리익스프레스에서 {keyword} 구매하기"
                
                return f'''
<div class="affiliate-button-container" style="width: 100%; max-width: 800px; margin: 15px auto; text-align: center;">
    <a href="{link_url}" target="_blank" rel="noopener" style="display: inline-block; width: 100%;">
        <picture>
            <source media="(max-width: 768px)" srcset="{mobile_img}">
            <img src="{pc_img}" alt="{alt_text}" style="width: 100%; height: auto; max-width: 800px; border-radius: 8px;">
        </picture>
    </a>
</div>'''

            # 상품 정보 카드 HTML 생성 함수
            def generate_product_card_html(product_data, platform, link_url, keyword):
                if platform == "coupang":
                    # 쿠팡 상품 정보 처리 (SafeAPIManager 포맷 기준)
                    product_name = product_data.get('title', '상품명 없음')
                    product_price = product_data.get('price', '가격 정보 없음')
                    product_image = product_data.get('image_url', '')
                    rating = product_data.get('rating', '평점 없음')
                    review_count = product_data.get('review_count', '리뷰 없음')
                    
                    if product_price and product_price != '가격 정보 없음' and product_price != '이미지 정보 없음':
                        price_display = f"💰 {product_price}"
                    else:
                        price_display = "💰 가격 확인 필요"
                        
                    if rating and rating != '평점 없음':
                        rating_display = f"⭐ {rating}"
                    else:
                        rating_display = "⭐ 평점 정보 없음"
                        
                    if review_count and review_count != '리뷰 없음':
                        review_display = f"📝 리뷰 {review_count}개"
                    else:
                        review_display = "📝 리뷰 정보 없음"
                        
                else:  # aliexpress
                    # 알리익스프레스 상품 정보 처리 (integrated_api_test.py 포맷 기준)
                    product_name = product_data.get('title', product_data.get('product_title', '상품명 없음'))
                    product_price = product_data.get('price', product_data.get('target_sale_price', '가격 정보 없음'))
                    product_image = product_data.get('image_url', product_data.get('product_main_image_url', ''))
                    rating = product_data.get('rating', '평점 정보 없음')
                    review_count = product_data.get('review_count', '리뷰 정보 없음')
                    
                    # USD를 KRW로 변환 (대략적인 환율 1400 적용)
                    if product_price and product_price != '가격 정보 없음':
                        try:
                            # 문자열에서 숫자만 추출
                            import re
                            price_match = re.search(r'[\d.]+', str(product_price))
                            if price_match:
                                usd_price = float(price_match.group())
                                krw_price = int(usd_price * 1400)
                                price_display = f"💰 약 {krw_price:,}원 (${usd_price})"
                            else:
                                price_display = f"💰 ${product_price}"
                        except (ValueError, TypeError):
                            price_display = f"💰 ${product_price}"
                    else:
                        price_display = "💰 가격 확인 필요"
                        
                    rating_display = "⭐ 평점 정보 없음"
                    review_display = "📝 리뷰 정보 없음"
                
                # 상품 이미지가 있는 경우에만 이미지 태그 생성
                image_html = ""
                if product_image and product_image != '이미지 정보 없음' and product_image.startswith('http'):
                    image_html = f'''
                    <div style="text-align: center; margin-bottom: 15px;">
                        <img src="{product_image}" alt="{product_name}" style="max-width: 300px; height: auto; border-radius: 8px; border: 1px solid #ddd;">
                    </div>'''
                
                button_html = generate_affiliate_button_html(platform, link_url, keyword)
                
                return f'''
<div style="border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; margin: 20px auto; max-width: 800px; background-color: #f9f9f9;">
    {image_html}
    <div style="text-align: center;">
        <h4 style="margin: 10px 0; color: #333; font-size: 18px;">{product_name}</h4>
        <div style="margin: 10px 0; color: #666; font-size: 14px;">
            <span style="display: inline-block; margin: 0 10px;">{price_display}</span>
            <span style="display: inline-block; margin: 0 10px;">{rating_display}</span>
            <span style="display: inline-block; margin: 0 10px;">{review_display}</span>
        </div>
    </div>
    {button_html}
</div>'''
            
            # 쿠팡 파트너스 이미지 HTML (쿠팡 링크가 있을 때만)
            coupang_partners_html = ""
            if has_coupang_links:
                coupang_partners_html = '''
<div style="text-align: center; margin: 30px auto;">
    <img src="https://novacents.com/tools/images/coupang_partners.png" alt="쿠팡 파트너스 활동의 일환으로, 이에 따른 일정액의 수수료를 제공받습니다." style="max-width: 100%; height: auto; border-radius: 8px;">
</div>'''
            
            # 프롬프트 생성 - auto_post_overseas.py 성공 패턴 적용
            prompt = f"""당신은 대한민국 최고의 온라인 쇼핑 전문가이자 상품 리뷰어입니다. 아래에 제공되는 상품 정보와 사용자 입력 키워드를 바탕으로, 한국 소비자들을 위한 매우 실용적이고 유용한 상품 추천 블로그 글을 작성해주세요.

### 📋 제공된 정보 ###
**글 제목:** {product_info['title']}
**핵심 키워드:** {', '.join(product_info['keywords'])}

**사용자 제공 어필리에이트 링크 (반드시 참고):**
{json.dumps(user_links, ensure_ascii=False, indent=2)}

**API 수집 상품 정보 (참고 및 활용):**
쿠팡 상품: {json.dumps(coupang_products, ensure_ascii=False, indent=2)}
알리익스프레스 상품: {json.dumps(aliexpress_products, ensure_ascii=False, indent=2)}

### 🎯 글 작성 지시사항 ###
1. **기본 설정**
   - 작성 기준: 2025년 현재
   - 글 분량: 1,800-2,500자 (충분히 상세하게)
   - 대상: 한국 소비자 (실용적이고 구체적인 정보 중심)

2. **필수 글 구조** (각 섹션을 명확히 구분하여 작성)
   - **🎯 인트로 섹션** (150-250자)
     → 왜 이 상품들이 2025년 현재 주목받는지 설명
     → 핵심 키워드들의 트렌드와 필요성 강조
   
   - **⭐ 각 키워드별 상품 심층 분석** (키워드당 400-600자)
     → 각 키워드에 대한 상세한 상품 소개
     → 실제 사용자 관점에서의 장단점 분석
     → 구체적인 가격대와 성능 비교
     → 어떤 상황에서 가장 유용한지 설명
   
   - **🏪 플랫폼별 쇼핑 전략** (300-400자)
     → 쿠팡 vs 알리익스프레스의 명확한 차이점
     → 각 플랫폼의 최적 활용법 (배송, 가격, 품질 등)
     → 플랫폼별 추천 상품 유형
   
   - **💡 스마트 구매 가이드** (250-350자)
     → 구매 전 반드시 확인할 체크리스트
     → 가격 비교 및 할인 시기 팁
     → 피해야 할 함정과 주의사항
   
   - **✅ 결론 및 추천** (150-200자)
     → 가장 추천하는 상품과 그 이유
     → 2025년 트렌드 전망

3. **필수 준수사항**
   - HTML 형식으로 작성 (본문만, <html>, <head>, <body> 태그 제외)
   - 각 섹션은 적절한 HTML 헤딩 태그(h2, h3) 사용 (마크다운 ## 표시 사용 금지)
   - 본문 텍스트는 18px 글자 크기로 표시되도록 스타일 적용
   - 핵심 키워드를 자연스럽게 본문에 3-5회 배치
   - 구체적인 수치와 데이터 활용 (가격, 평점, 리뷰 수 등)
   - 친근하면서도 전문적인 톤앤매너 유지
   - **중요: 텍스트 링크는 절대 사용하지 마세요. 상품 버튼이 별도로 삽입됩니다.**
   - **중요: 마크다운 문법(##, ###) 사용 금지. 반드시 HTML 태그만 사용하세요.**

4. **품질 강화 요소**
   - 각 문단은 읽기 쉽게 적절한 길이로 구성
   - 불필요한 과장 표현 지양, 객관적 정보 중심
   - 사용자 입장에서 실제 도움이 되는 정보만 포함
   - 2025년 최신 트렌드와 시장 상황 반영

이제 위 지시사항을 바탕으로 한국 소비자들이 실제로 구매 결정에 도움이 될 수 있는 유용한 상품 추천 글을 작성해 주세요."""
            
            # Gemini API 호출
            try:
                response = self.gemini_model.generate_content(prompt)
                
                if hasattr(response, 'text') and response.text:
                    base_content = response.text
                    print(f"[✅] Gemini 응답 텍스트 길이: {len(base_content)}")
                else:
                    print(f"[❌] Gemini 응답에 text 속성이 없거나 비어있습니다.")
                    print(f"[❌] 응답 내용: {response}")
                    return None
                    
            except Exception as e:
                print(f"[❌] Gemini API 호출 중 오류: {e}")
                return None
                
            # HTML 코드 블록 표시 제거 (```html, ``` 등)
            base_content = base_content.replace('```html', '').replace('```', '').strip()
            
            # 본문 글자 크기 18px 적용을 위한 스타일 래퍼 추가
            base_content = f'<div style="font-size: 18px; line-height: 1.6;">{base_content}</div>'
                
            # 쿠팡 파트너스 이미지를 콘텐츠 최상단에 추가
            final_content = coupang_partners_html + base_content
            print(f"[✅] 기본 콘텐츠 처리 완료")
                
            # 각 키워드별로 해당 키워드 섹션 뒤에 상품 카드 삽입
            try:
                for keyword_data in job_data["keywords"]:
                    keyword_name = keyword_data["name"]
                    print(f"[🔍] 키워드 '{keyword_name}' 처리 중...")
                    
                    # 키워드 관련 섹션을 찾아서 그 뒤에 상품 카드 삽입
                    keyword_cards = ""
                    
                    # 쿠팡 상품 카드 추가 (API에서 가져온 상품 데이터 사용)
                    # 쿠팡 API 응답 구조 처리: [True, [상품데이터]] 형태인 경우 실제 상품 데이터 추출
                    actual_coupang_products = []
                    if coupang_products and isinstance(coupang_products, list):
                        if len(coupang_products) > 1 and isinstance(coupang_products[1], list):
                            actual_coupang_products = coupang_products[1]
                        else:
                            actual_coupang_products = coupang_products
                    
                    for i, coupang_link in enumerate(keyword_data.get("coupang", [])):
                        if actual_coupang_products and isinstance(actual_coupang_products, list) and i < len(actual_coupang_products):
                            product_data = actual_coupang_products[i]
                            card_html = generate_product_card_html(product_data, "coupang", coupang_link, keyword_name)
                            keyword_cards += card_html
                        else:
                            # 상품 정보가 없는 경우 기본 버튼만 표시
                            button_html = generate_affiliate_button_html("coupang", coupang_link, keyword_name)
                            keyword_cards += button_html
                
                # 알리익스프레스 상품 카드 추가 (API에서 가져온 상품 데이터 사용)
                for i, aliexpress_link in enumerate(keyword_data.get("aliexpress", [])):
                    if aliexpress_products and isinstance(aliexpress_products, list) and i < len(aliexpress_products):
                        product_data = aliexpress_products[i]
                        card_html = generate_product_card_html(product_data, "aliexpress", aliexpress_link, keyword_name)
                        keyword_cards += card_html
                    else:
                        # 상품 정보가 없는 경우 기본 버튼만 표시
                        button_html = generate_affiliate_button_html("aliexpress", aliexpress_link, keyword_name)
                        keyword_cards += button_html
                
                # 키워드가 포함된 섹션의 끝에 상품 카드 삽입 (글 중간에 자연스럽게 배치)
                if keyword_cards:
                    import re
                    # 1차 시도: 키워드가 포함된 H2/H3 섹션 뒤의 첫 번째 문단 끝에 삽입
                    pattern1 = rf'(<h[2-3][^>]*>[^<]*{re.escape(keyword_name)}[^<]*</h[2-3]>[^<]*<p[^>]*>.*?</p>)'
                    if re.search(pattern1, final_content, re.IGNORECASE | re.DOTALL):
                        final_content = re.sub(pattern1, rf'\1{keyword_cards}', final_content, flags=re.IGNORECASE | re.DOTALL, count=1)
                        print(f"[✅] '{keyword_name}' 상품 카드를 H2/H3 섹션 첫 문단 뒤에 삽입")
                    else:
                        # 2차 시도: 키워드가 본문에 언급된 첫 번째 문단 뒤에 삽입
                        pattern2 = rf'(<p[^>]*>[^<]*{re.escape(keyword_name)}[^<]*</p>)'
                        if re.search(pattern2, final_content, re.IGNORECASE | re.DOTALL):
                            final_content = re.sub(pattern2, rf'\1{keyword_cards}', final_content, flags=re.IGNORECASE | re.DOTALL, count=1)
                            print(f"[✅] '{keyword_name}' 상품 카드를 키워드 언급 문단 뒤에 삽입")
                        else:
                            # 3차 시도: 첫 번째 H2 섹션 뒤에 삽입
                            pattern3 = r'(<h2[^>]*>.*?</h2>[^<]*<p[^>]*>.*?</p>)'
                            if re.search(pattern3, final_content, re.IGNORECASE | re.DOTALL):
                                final_content = re.sub(pattern3, rf'\1{keyword_cards}', final_content, flags=re.IGNORECASE | re.DOTALL, count=1)
                                print(f"[✅] '{keyword_name}' 상품 카드를 첫 번째 H2 섹션 뒤에 삽입")
                            else:
                                # 마지막 방법: 콘텐츠 중간 지점에 삽입
                                content_parts = final_content.split('</p>')
                                if len(content_parts) > 2:
                                    mid_point = len(content_parts) // 2
                                    content_parts[mid_point] = content_parts[mid_point] + '</p>' + keyword_cards
                                    final_content = '</p>'.join(content_parts)
                                    print(f"[✅] '{keyword_name}' 상품 카드를 콘텐츠 중간에 삽입")
                                else:
                                    # 최후의 방법: 콘텐츠 끝에 추가
                                    final_content += keyword_cards
                                    print(f"[⚠️] '{keyword_name}' 상품 카드를 콘텐츠 끝에 삽입")
                            
            except Exception as e:
                print(f"[❌] 상품 카드 삽입 중 오류: {e}")
                import traceback
                traceback.print_exc()
                return None
            
            print(f"[✅] Gemini AI가 {len(base_content)}자의 콘텐츠를 생성했습니다.")
            print(f"[✅] 어필리에이트 이미지 버튼이 추가되었습니다.")
            return final_content
                
        except Exception as e:
            print(f"[❌] Gemini 콘텐츠 생성 중 오류: {e}")
            return None
            
    def post_to_wordpress(self, job_data, content):
        """워드프레스에 글 발행"""
        try:
            print(f"[📝] 워드프레스에 '{job_data['title']}' 글을 발행합니다...")
            
            # 워드프레스 API 엔드포인트
            api_url = f"{self.config['wp_api_base']}/posts"
            
            # 인증 헤더
            import base64
            credentials = f"{self.config['wp_user']}:{self.config['wp_app_pass']}"
            encoded_credentials = base64.b64encode(credentials.encode()).decode()
            
            headers = {
                "Authorization": f"Basic {encoded_credentials}",
                "Content-Type": "application/json"
            }
            
            # 게시물 데이터 (tags 제거 - 태그 ID가 필요하므로 일단 제외)
            post_data = {
                "title": job_data["title"],
                "content": content,
                "status": "publish",
                "categories": [job_data["category_id"]],
                "meta": {
                    "yoast_wpseo_metadesc": f"{job_data['title']} - 2025년 최신 상품 추천 및 구매 가이드",
                    "yoast_wpseo_focuskw": job_data["keywords"][0]["name"] if job_data["keywords"] else ""
                }
            }
            
            # API 호출
            response = requests.post(api_url, json=post_data, headers=headers, timeout=30)
            
            if response.status_code == 201:
                post_info = response.json()
                post_url = post_info.get("link", "")
                print(f"[✅] 워드프레스 발행 성공: {post_url}")
                
                # 발행 로그 저장
                self.save_published_log(job_data, post_url)
                
                return post_url
            else:
                print(f"[❌] 워드프레스 발행 실패: {response.status_code}")
                print(f"응답: {response.text}")
                return None
                
        except Exception as e:
            print(f"[❌] 워드프레스 발행 중 오류: {e}")
            return None
            
    def save_published_log(self, job_data, post_url):
        """발행 로그 저장"""
        try:
            timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            log_entry = f"[{timestamp}] {job_data['title']} - {post_url}\n"
            
            with open(PUBLISHED_LOG_FILE, "a", encoding="utf-8") as f:
                f.write(log_entry)
                
        except Exception as e:
            print(f"[❌] 발행 로그 저장 중 오류: {e}")
            
    def process_job(self, job_data):
        """단일 작업 처리"""
        job_id = job_data["queue_id"]
        title = job_data["title"]
        
        self.log_message(f"[🚀] 작업 시작: {title} (ID: {job_id})")
        self.send_telegram_notification(f"🚀 어필리에이트 자동화 시작\n제목: {title}")
        
        try:
            # 작업 상태를 processing으로 변경
            self.update_job_status(job_id, "processing")
            
            # 1. 처리 모드 확인 (링크 기반 vs 키워드 기반)
            processing_mode = job_data.get("processing_mode", "keyword_based")
            
            if processing_mode == "link_based":
                # 새로운 링크 기반 처리
                all_coupang_products, all_aliexpress_products = self.process_links_based(job_data)
            else:
                # 기존 키워드 기반 처리 (하위 호환성)
                all_coupang_products, all_aliexpress_products = self.process_keyword_based(job_data)
                
            # 2. Gemini로 콘텐츠 생성
            if processing_mode == "link_based":
                # 링크 기반: 기본 콘텐츠 생성 후 상품 카드 삽입
                base_content = self.generate_base_content_with_gemini(job_data)
                if base_content:
                    content = self.insert_product_cards_into_content(
                        base_content, all_coupang_products, all_aliexpress_products
                    )
                else:
                    content = None
            else:
                # 기존 키워드 기반 처리
                content = self.generate_content_with_gemini(
                    job_data, 
                    all_coupang_products, 
                    all_aliexpress_products
                )
            
            if not content:
                raise Exception("콘텐츠 생성 실패")
                
            # 3. 워드프레스에 발행
            post_url = self.post_to_wordpress(job_data, content)
            
            if post_url:
                # 성공 처리
                self.update_job_status(job_id, "completed")
                self.log_message(f"[✅] 작업 완료: {title} -> {post_url}")
                self.send_telegram_notification(
                    f"✅ 어필리에이트 자동화 완료\n"
                    f"제목: {title}\n"
                    f"URL: {post_url}"
                )
                return True
            else:
                raise Exception("워드프레스 발행 실패")
                
        except Exception as e:
            # 실패 처리
            error_msg = str(e)
            self.update_job_status(job_id, "failed", error_msg)
            self.log_message(f"[❌] 작업 실패: {title} - {error_msg}")
            self.send_telegram_notification(
                f"❌ 어필리에이트 자동화 실패\n"
                f"제목: {title}\n"
                f"오류: {error_msg}"
            )
            return False
            
    def run(self):
        """메인 실행 함수"""
        print("=" * 60)
        print("🤖 어필리에이트 상품 자동 등록 시스템 시작")
        print("=" * 60)
        
        # 1. 설정 로드
        if not self.load_configuration():
            print("[❌] 설정 로드 실패. 프로그램을 종료합니다.")
            return
            
        # 2. 큐에서 작업 로드
        pending_jobs = self.load_queue()
        
        if not pending_jobs:
            print("[📋] 처리할 작업이 없습니다.")
            return
            
        # 3. 작업 처리
        processed_count = 0
        
        for job in pending_jobs:
            if processed_count >= MAX_POSTS_PER_RUN:
                print(f"[⏸️] 최대 처리 개수({MAX_POSTS_PER_RUN})에 도달했습니다.")
                break
                
            success = self.process_job(job)
            processed_count += 1
            
            if success and processed_count < len(pending_jobs):
                print(f"[⏳] {POST_DELAY_SECONDS}초 대기 중...")
                time.sleep(POST_DELAY_SECONDS)
                
        # 4. 완료 메시지
        remaining_jobs = len(pending_jobs) - processed_count
        completion_message = f"[🎉] 자동화 완료! 처리: {processed_count}개, 남은 작업: {remaining_jobs}개"
        
        self.log_message(completion_message)
        self.send_telegram_notification(completion_message)
        
        print("=" * 60)
        print("🤖 어필리에이트 상품 자동 등록 시스템 종료")
        print("=" * 60)


if __name__ == "__main__":
    try:
        system = AffiliatePostingSystem()
        system.run()
    except KeyboardInterrupt:
        print("\n[⏹️] 사용자에 의해 프로그램이 중단되었습니다.")
    except Exception as e:
        print(f"\n[❌] 예상치 못한 오류가 발생했습니다: {e}")
        print(traceback.format_exc())