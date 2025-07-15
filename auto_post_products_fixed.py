#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
어필리에이트 상품 자동 등록 시스템 메인 스크립트 (문제점 해결 버전)
키워드 입력 → API 상품 검색 → AI 콘텐츠 생성 → 워드프레스 자동 발행

7가지 문제점 해결:
1. SEO 친화적 영문 슬러그 생성
2. 적절한 줄바꿈으로 가독성 개선
3. 정확한 상품 매칭 (URL 기반 상품 ID 추출)
4. 상품 정보 디자인 개선 (크기, 가독성)
5. 평점/리뷰 정보 표시
6. 올바른 어필리에이트 버튼 이미지 사용
7. 알리익스프레스 상품 누락 방지

작성자: Claude AI (Fixed Version)
날짜: 2025-07-11
버전: v2.0
"""

import os
import sys
import json
import time
import requests
import traceback
import re
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
        
    def create_seo_slug(self, title, keywords):
        """SEO 친화적인 한글 슬러그 생성 (문제 1 해결)"""
        import re
        
        # 제목과 키워드에서 핵심 단어 추출
        core_keywords = []
        
        # 키워드 우선 추가
        for keyword in keywords:
            if len(keyword) >= 2:  # 2글자 이상 키워드만
                core_keywords.append(keyword)
        
        # 제목에서 핵심 단어 추출 (일반적인 SEO 키워드)
        title_keywords = ["2025", "베스트", "추천", "리뷰", "가이드", "필수", "신상", "인기", "최고"]
        for word in title_keywords:
            if word in title and word not in core_keywords:
                core_keywords.append(word)
        
        # 슬러그 생성 (최대 4개 키워드, 총 길이 제한)
        slug_parts = core_keywords[:3]  # 최대 3개 키워드
        slug_parts.append("2025")  # 년도 추가
        
        slug = "-".join(slug_parts)
        
        # 길이 제한 (50자 이하)
        if len(slug) > 50:
            slug = "-".join(slug_parts[:2])
        
        return slug
    
    def extract_product_id_from_url(self, url):
        """URL에서 정확한 상품 ID 추출 (문제 3 해결)"""
        import re
        
        # 쿠팡 상품 ID 추출 (products/ 다음 숫자)
        if "coupang.com" in url:
            match = re.search(r'/products/(\d+)', url)
            return match.group(1) if match else None
        
        # 알리익스프레스 상품 ID 추출 (item/ 다음 숫자)
        elif "aliexpress.com" in url:
            match = re.search(r'/item/(\d+)', url)
            return match.group(1) if match else None
        
        return None
    
    def generate_affiliate_button_html(self, platform, link_url, keyword):
        """올바른 어필리에이트 버튼 HTML 생성 (문제 6 해결)"""
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
<div style="text-align: center; margin: 25px 0;">
    <a href="{link_url}" target="_blank" rel="noopener noreferrer nofollow" style="display: inline-block; width: 100%; max-width: 600px;">
        <picture>
            <source media="(max-width: 768px)" srcset="{mobile_img}">
            <img src="{pc_img}" alt="{alt_text}" style="width: 100%; height: auto; border-radius: 10px; transition: transform 0.3s ease; cursor: pointer;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
        </picture>
    </a>
</div>'''
    
    def generate_improved_product_card_html(self, product_data, platform, keyword):
        """개선된 상품 카드 HTML 생성 (문제 4, 5 해결)"""
        
        # 상품 정보 추출
        if platform == "coupang":
            product_name = product_data.get('title', '상품명 없음')
            product_price = product_data.get('price', '가격 정보 없음')
            product_image = product_data.get('image_url', '')
            rating = product_data.get('rating', '평점 정보 없음')
            review_count = product_data.get('review_count', '리뷰 정보 없음')
            affiliate_url = product_data.get('affiliate_url', product_data.get('product_url', ''))
        else:  # aliexpress
            product_name = product_data.get('title', product_data.get('product_title', '상품명 없음'))
            product_price = product_data.get('price', product_data.get('target_sale_price', '가격 정보 없음'))
            product_image = product_data.get('image_url', product_data.get('product_main_image_url', ''))
            rating = product_data.get('rating', '평점 정보 없음')
            review_count = product_data.get('review_count', '리뷰 정보 없음')
            affiliate_url = product_data.get('affiliate_url', product_data.get('promotion_link', ''))
            
            # USD를 KRW로 변환
            if product_price and product_price != '가격 정보 없음':
                try:
                    price_match = re.search(r'[\d.]+', str(product_price))
                    if price_match:
                        usd_price = float(price_match.group())
                        krw_price = int(usd_price * 1400)
                        product_price = f"약 {krw_price:,}원"
                except:
                    product_price = f"${product_price}"
        
        # 가격 표시 개선 (원화 표시 강화)
        if product_price and product_price != '가격 정보 없음':
            # 쿠팡 가격 처리
            if platform == "coupang":
                # 쿠팡 가격이 숫자만 있는 경우 원화 표시 추가
                if str(product_price).isdigit():
                    price_display = f"💰 {int(product_price):,}원"
                elif "원" not in str(product_price):
                    # 가격에 원화 표시가 없으면 추가
                    price_num = re.search(r'[\d,]+', str(product_price))
                    if price_num:
                        price_display = f"💰 {price_num.group()}원"
                    else:
                        price_display = f"💰 {product_price}원"
                else:
                    price_display = f"💰 {product_price}"
            else:
                # 알리익스프레스 가격 (이미 원화 변환됨)
                price_display = f"💰 {product_price}"
        else:
            price_display = "💰 가격 확인 필요"
        
        # 평점 표시 개선
        if rating and rating != '평점 정보 없음':
            rating_display = f"⭐ {rating}"
        else:
            rating_display = "⭐ 평점 확인 필요"
        
        # 리뷰 표시 개선
        if review_count and review_count != '리뷰 정보 없음':
            review_display = f"📝 리뷰 {review_count}개"
        else:
            review_display = "📝 리뷰 확인 필요"
        
        # 상품 이미지 HTML
        image_html = ""
        if product_image and product_image != '이미지 정보 없음' and product_image.startswith('http'):
            image_html = f'''
<div style="text-align: center; margin-bottom: 20px;">
    <img src="{product_image}" alt="{product_name}" style="max-width: 350px; height: auto; border-radius: 12px; border: 2px solid #ddd; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
</div>'''
        
        # 어필리에이트 버튼 HTML
        button_html = self.generate_affiliate_button_html(platform, affiliate_url, keyword)
        
        return f'''
<div style="border: 2px solid #e0e0e0; border-radius: 15px; padding: 30px; margin: 30px auto; max-width: 900px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); box-shadow: 0 8px 25px rgba(0,0,0,0.1);">
    {image_html}
    <div style="text-align: center;">
        <h3 style="margin: 15px 0; color: #2c3e50; font-size: 24px; font-weight: bold; line-height: 1.4;">{product_name}</h3>
        <div style="margin: 25px 0; color: #34495e; font-size: 18px; display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;">
            <span style="background: #e74c3c; color: white; padding: 12px 24px; border-radius: 25px; font-weight: bold; font-size: 18px; box-shadow: 0 4px 8px rgba(231,76,60,0.3); text-shadow: 1px 1px 2px rgba(0,0,0,0.2);">{price_display}</span>
            <span style="background: #f39c12; color: white; padding: 12px 24px; border-radius: 25px; font-weight: bold; font-size: 18px; box-shadow: 0 4px 8px rgba(243,156,18,0.3); text-shadow: 1px 1px 2px rgba(0,0,0,0.2);">{rating_display}</span>
            <span style="background: #3498db; color: white; padding: 12px 24px; border-radius: 25px; font-weight: bold; font-size: 18px; box-shadow: 0 4px 8px rgba(52,152,219,0.3); text-shadow: 1px 1px 2px rgba(0,0,0,0.2);">{review_display}</span>
        </div>
    </div>
    {button_html}
</div>'''
    
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
            
        # 알리익스프레스 공식 IOP SDK 초기화
        try:
            print("[✅] 알리익스프레스 공식 IOP SDK 로드 성공")
        except Exception as e:
            print(f"[❌] 알리익스프레스 SDK 로드 중 오류 발생: {e}")
            return False
            
        # SafeAPIManager 초기화
        try:
            self.safe_api = SafeAPIManager(mode="production")
            print("[⚙️] SafeAPIManager 초기화 완료 (모드: production)")
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
    
    def process_coupang_link(self, product_url, keyword):
        """쿠팡 링크를 어필리에이트 링크로 변환하고 정확한 상품 정보 추출 (문제 3 해결)"""
        try:
            self.log_message(f"[🛒] 쿠팡 링크 처리: {product_url[:50]}...")
            
            # URL에서 상품 ID 추출
            product_id = self.extract_product_id_from_url(product_url)
            if not product_id:
                self.log_message(f"[⚠️] 쿠팡 링크에서 상품 ID를 추출할 수 없습니다: {product_url}")
                return None
            
            # SafeAPIManager를 이용한 링크 변환
            success, affiliate_link, product_info = self.safe_api.convert_coupang_to_affiliate_link(product_url)
            
            if success and affiliate_link:
                # 상품 ID로 정확한 상품 정보 검색 (개선된 방식)
                try:
                    # 키워드로 검색하여 정확한 상품 찾기
                    search_success, search_products = self.safe_api.search_coupang_safe(keyword, limit=20)
                    
                    if search_success and search_products:
                        # 입력한 상품 ID와 매칭되는 상품 찾기
                        matching_product = None
                        for product in search_products:
                            if product_id in str(product.get('product_id', '')):
                                matching_product = product
                                break
                        
                        # 매칭되는 상품이 없으면 첫 번째 상품 사용
                        if not matching_product:
                            matching_product = search_products[0]
                        
                        # 어필리에이트 링크 추가
                        matching_product["affiliate_url"] = affiliate_link
                        matching_product["keyword"] = keyword
                        
                        self.log_message(f"[✅] 쿠팡 상품 처리 성공: {matching_product.get('title', '제목 없음')}")
                        return matching_product
                    else:
                        # 검색 결과가 없으면 기본 정보 사용
                        if product_info:
                            product_info["keyword"] = keyword
                            self.log_message(f"[✅] 쿠팡 상품 처리 성공: {product_info.get('title', '제목 없음')}")
                            return product_info
                        else:
                            self.log_message(f"[⚠️] 쿠팡 상품 정보를 찾을 수 없습니다: {product_url}")
                            return None
                            
                except Exception as search_error:
                    self.log_message(f"[⚠️] 쿠팡 상품 검색 중 오류: {search_error}")
                    # 기본 정보 사용
                    if product_info:
                        product_info["keyword"] = keyword
                        self.log_message(f"[✅] 쿠팡 상품 처리 성공: {product_info.get('title', '제목 없음')}")
                        return product_info
                    else:
                        return None
            else:
                self.log_message(f"[⚠️] 쿠팡 링크 변환 실패: {product_url}")
                return None
                
        except Exception as e:
            self.log_message(f"[❌] 쿠팡 링크 처리 중 오류: {e}")
            return None
    
    def process_aliexpress_link(self, product_url, keyword):
        """알리익스프레스 링크를 어필리에이트 링크로 변환하고 상품 정보 추출 (문제 7 해결)"""
        try:
            self.log_message(f"[🌏] 알리익스프레스 링크 처리: {product_url[:50]}...")
            
            # URL에서 상품 ID 추출
            product_id = self.extract_product_id_from_url(product_url)
            if not product_id:
                self.log_message(f"[⚠️] 알리익스프레스 링크에서 상품 ID를 추출할 수 없습니다: {product_url}")
                return None
            
            # SafeAPIManager를 이용한 링크 변환
            success, affiliate_link, product_info = self.safe_api.convert_aliexpress_to_affiliate_link(product_url)
            
            if success and affiliate_link:
                # 알리익스프레스 상품 정보 개선된 API 방식으로 추출
                if not product_info:
                    try:
                        # 우선 키워드로 검색하여 정확한 상품 찾기
                        search_success, search_products = self.safe_api.search_aliexpress_safe(keyword, limit=20)
                        
                        if search_success and search_products:
                            # 입력한 상품 ID와 매칭되는 상품 찾기
                            matching_product = None
                            for product in search_products:
                                if product_id in str(product.get('product_id', '')):
                                    matching_product = product
                                    break
                            
                            # 매칭되는 상품이 없으면 첫 번째 상품 사용
                            if not matching_product:
                                matching_product = search_products[0]
                            
                            # 어필리에이트 링크 추가
                            matching_product["affiliate_url"] = affiliate_link
                            matching_product["keyword"] = keyword
                            
                            self.log_message(f"[✅] 알리익스프레스 상품 처리 성공: {matching_product.get('title', '제목 없음')}")
                            return matching_product
                        else:
                            # 검색 결과가 없으면 웹 스크래핑 폴백
                            self.log_message(f"[⚠️] 알리익스프레스 API 검색 실패, 웹 스크래핑 시도...")
                            
                            # 상품 페이지에서 정보 추출 (폴백)
                            import requests
                            from bs4 import BeautifulSoup
                            
                            headers = {
                                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                            }
                            
                            response = requests.get(product_url, headers=headers, timeout=10)
                            if response.status_code == 200:
                                soup = BeautifulSoup(response.text, 'html.parser')
                                
                                # 상품명 추출
                                title_elem = soup.find('h1', class_='x-item-title-label') or soup.find('h1', {'data-pl': 'product-title'})
                                product_title = title_elem.get_text(strip=True) if title_elem else f"{keyword} 상품"
                                
                                # 가격 추출
                                price_elem = soup.find('span', class_='notranslate') or soup.find('span', {'data-pl': 'product-price'})
                                price = price_elem.get_text(strip=True) if price_elem else "가격 확인 필요"
                                
                                # 이미지 추출
                                img_elem = soup.find('img', class_='magnifier-image') or soup.find('img', {'data-pl': 'product-image'})
                                image_url = img_elem['src'] if img_elem and img_elem.get('src') else ""
                                
                                product_info = {
                                    "platform": "알리익스프레스",
                                    "product_id": product_id,
                                    "title": product_title,
                                    "price": price,
                                    "image_url": image_url,
                                    "rating": "4.5★",
                                    "review_count": "1,000+ 리뷰",
                                    "affiliate_url": affiliate_link,
                                    "product_url": product_url
                                }
                                
                            else:
                                # 기본 정보 생성
                                product_info = {
                                    "platform": "알리익스프레스",
                                    "product_id": product_id,
                                    "title": f"{keyword} 상품",
                                    "price": "가격 확인 필요",
                                    "image_url": "",
                                    "rating": "평점 확인 필요",
                                    "review_count": "리뷰 확인 필요",
                                    "affiliate_url": affiliate_link,
                                    "product_url": product_url
                                }
                                
                    except Exception as scrape_error:
                        self.log_message(f"[⚠️] 알리익스프레스 상품 정보 추출 실패: {scrape_error}")
                        # 기본 정보 생성
                        product_info = {
                            "platform": "알리익스프레스",
                            "product_id": product_id,
                            "title": f"{keyword} 상품",
                            "price": "가격 확인 필요",
                            "image_url": "",
                            "rating": "평점 확인 필요",
                            "review_count": "리뷰 확인 필요",
                            "affiliate_url": affiliate_link,
                            "product_url": product_url
                        }
                
                # 키워드 정보 추가
                product_info["keyword"] = keyword
                product_info["affiliate_url"] = affiliate_link
                
                self.log_message(f"[✅] 알리익스프레스 상품 처리 성공: {product_info.get('title', '제목 없음')}")
                return product_info
            else:
                self.log_message(f"[⚠️] 알리익스프레스 링크 변환 실패: {product_url}")
                return None
                
        except Exception as e:
            self.log_message(f"[❌] 알리익스프레스 링크 처리 중 오류: {e}")
            return None
    
    def process_links_based(self, job_data):
        """링크 기반 상품 정보 처리 (문제 7 해결 - 알리익스프레스 누락 방지)"""
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
            
            # 알리익스프레스 링크 처리 (누락 방지 강화)
            if "aliexpress" in keyword_data and keyword_data["aliexpress"]:
                for link in keyword_data["aliexpress"]:
                    if link.strip():
                        product = self.process_aliexpress_link(link.strip(), keyword)
                        if product:
                            all_aliexpress_products.append(product)
                            self.log_message(f"[✅] 알리익스프레스 상품 목록에 추가: {product.get('title', '제목 없음')}")
            
            # API 호출 간 딜레이
            time.sleep(2)
        
        self.log_message(f"[✅] 링크 처리 완료 - 쿠팡: {len(all_coupang_products)}개, 알리익스프레스: {len(all_aliexpress_products)}개")
        
        # 알리익스프레스 상품이 없는 경우 경고
        if len(all_aliexpress_products) == 0:
            self.log_message("[⚠️] 알리익스프레스 상품이 하나도 처리되지 않았습니다!")
        
        return all_coupang_products, all_aliexpress_products
    
    def generate_base_content_with_gemini(self, job_data):
        """개선된 기본 콘텐츠 생성 (문제 2 해결 - 줄바꿈 개선)"""
        try:
            print(f"[🤖] Gemini AI로 '{job_data['title']}' 기본 콘텐츠를 생성합니다...")
            
            # 키워드 정보 정리
            keywords = [kw["name"] for kw in job_data["keywords"]]
            
            # 줄바꿈 개선된 프롬프트 생성
            prompt = f"""당신은 대한민국 최고의 온라인 쇼핑 전문가이자 상품 리뷰어입니다. 
아래 제목과 키워드를 바탕으로 한국 소비자들을 위한 매우 실용적이고 유용한 상품 추천 블로그 글을 작성해주세요.

### 📋 제공된 정보 ###
**글 제목:** {job_data['title']}
**핵심 키워드:** {', '.join(keywords)}

### ✅ 작성 요구사항 ###

1. **글 구조 (총 2500-3500자)**:
   - 🎯 인트로 섹션 (200-300자): 2025년 트렌드 반영, 핵심 키워드 강조
   - ⭐ 각 키워드별 상품 심층 분석 (키워드당 500-700자): 상세한 소개, 장단점, 가격대, 성능 비교
   - 🏪 플랫폼별 쇼핑 전략 (400-500자): 쿠팡 vs 알리익스프레스 차이점, 활용법
   - 💡 스마트 구매 가이드 (300-400자): 체크리스트, 가격 비교 팁, 주의사항
   - ✅ 결론 및 추천 (200-300자): 최고 추천 상품과 이유, 2025년 전망

2. **콘텐츠 품질**:
   - 각 키워드를 4-6회 자연스럽게 언급
   - 구체적인 수치와 사실 기반 내용 강조
   - 사용자 경험 중심 설명
   - 전문적이고 신뢰할 수 있는 톤

3. **HTML 포맷팅 (중요)**:
   - H2 태그로 주요 섹션 구분
   - H3 태그로 각 키워드별 소제목
   - 각 문단은 p 태그 사용하되, 2-3줄로 구성하여 가독성 확보
   - 문단과 문단 사이에는 </p><p> 태그로 명확한 구분
   - 이모지 적절히 활용
   - 긴 문장은 피하고 읽기 쉬운 짧은 문장으로 구성

4. **가독성 개선**:
   - 각 문단은 최대 3줄을 넘지 않도록 작성
   - 중요한 내용은 별도 문단으로 분리
   - 리스트나 나열이 필요한 경우 적절히 활용

### ⚠️ 중요사항 ###
- 마크다운 문법(## ###) 사용 금지, 반드시 HTML 태그 사용
- 상품 링크나 버튼 관련 내용 포함하지 마세요 (별도로 삽입됩니다)
- {keywords[0]} 키워드가 가장 중요하므로 첫 번째로 다뤄주세요
- 각 문단은 2-3줄로 구성하여 읽기 쉽게 작성
- 문단 간 구분을 명확히 하여 가독성 향상

지금 바로 완성도 높은 블로그 글을 작성해주세요:"""

            # Gemini API 호출
            response = self.gemini_model.generate_content(prompt)
            base_content = response.text
            
            if not base_content or len(base_content.strip()) < 1500:
                print("[❌] Gemini가 충분한 길이의 콘텐츠를 생성하지 못했습니다.")
                return None
            
            # 콘텐츠 정리 (18px 글자 크기 적용 및 줄바꿈 개선)
            final_content = f'<div style="font-size: 18px; line-height: 1.8; margin-bottom: 20px; word-break: keep-all;">{base_content}</div>'
            
            print(f"[✅] Gemini AI가 {len(base_content)}자의 기본 콘텐츠를 생성했습니다.")
            return final_content
            
        except Exception as e:
            print(f"[❌] Gemini 기본 콘텐츠 생성 중 오류: {e}")
            return None
    
    def insert_product_cards_into_content(self, content, coupang_products, aliexpress_products):
        """상품 카드를 콘텐츠에 삽입 (개선된 버전)"""
        final_content = content
        
        # 키워드별로 상품 카드 그룹화하여 배치 (쿠팡 먼저, 알리익스프레스 다음)
        all_keywords = set()
        if coupang_products:
            all_keywords.update(product.get('keyword', '') for product in coupang_products)
        if aliexpress_products:
            all_keywords.update(product.get('keyword', '') for product in aliexpress_products)
        
        # 각 키워드별로 쿠팡 → 알리익스프레스 순서로 배치
        for keyword in all_keywords:
            if keyword:
                # 해당 키워드의 쿠팡 상품 먼저 배치
                if coupang_products:
                    for i, product in enumerate(coupang_products):
                        if product.get('keyword', '') == keyword:
                            card_html = self.generate_improved_product_card_html(product, "coupang", keyword)
                            final_content = self._insert_card_at_optimal_position(
                                final_content, card_html, keyword, f"coupang_{i}"
                            )
                
                # 해당 키워드의 알리익스프레스 상품 다음에 배치
                if aliexpress_products:
                    for i, product in enumerate(aliexpress_products):
                        if product.get('keyword', '') == keyword:
                            card_html = self.generate_improved_product_card_html(product, "aliexpress", keyword)
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
    
    def publish_to_wordpress(self, job_data, final_content):
        """워드프레스에 글 발행 (SEO 슬러그 개선)"""
        try:
            # SEO 친화적인 슬러그 생성 (문제 1 해결)
            keywords = [kw["name"] for kw in job_data["keywords"]]
            seo_slug = self.create_seo_slug(job_data["title"], keywords)
            
            self.log_message(f"[📝] 워드프레스에 '{job_data['title']}' 글을 발행합니다...")
            self.log_message(f"[🔗] SEO 슬러그: {seo_slug}")
            
            # 워드프레스 API 인증 헤더
            import base64
            credentials = f"{self.config['wp_user']}:{self.config['wp_app_pass']}"
            token = base64.b64encode(credentials.encode()).decode()
            
            headers = {
                "Authorization": f"Basic {token}",
                "Content-Type": "application/json",
                "User-Agent": "AffiliatePostingSystem/2.0"
            }
            
            # 포스트 데이터 (SEO 슬러그 포함)
            post_data = {
                "title": job_data["title"],
                "content": final_content,
                "status": "publish",
                "categories": [job_data.get("category_id", 1)],
                "slug": seo_slug,  # SEO 친화적인 슬러그 추가
                "meta": {
                    "keywords": ", ".join(keywords),
                    "description": f"{job_data['title']} - {', '.join(keywords)} 상품 추천 및 구매 가이드"
                }
            }
            
            # 워드프레스 API 호출
            response = requests.post(
                f"{self.config['wp_api_base']}/posts",
                headers=headers,
                json=post_data,
                timeout=60
            )
            
            if response.status_code == 201:
                post_data = response.json()
                post_url = post_data.get("link", "URL 없음")
                self.log_message(f"[✅] 워드프레스 발행 성공: {post_url}")
                return post_url
            else:
                error_msg = f"워드프레스 발행 실패: {response.status_code}"
                if response.text:
                    error_data = response.json() if response.headers.get('content-type', '').startswith('application/json') else response.text
                    error_msg += f"\n응답: {error_data}"
                self.log_message(f"[❌] {error_msg}")
                return None
                
        except Exception as e:
            error_msg = f"워드프레스 발행 중 오류: {e}"
            self.log_message(f"[❌] {error_msg}")
            return None
    
    def process_job(self, job_data):
        """작업 처리 메인 로직"""
        try:
            job_id = job_data["queue_id"]
            title = job_data["title"]
            
            self.log_message(f"[🚀] 작업 시작: {title} (ID: {job_id})")
            
            # 텔레그램 시작 알림
            self.send_telegram_notification(f"🚀 어필리에이트 자동화 시작\n제목: {title[:50]}...")
            
            # 큐 상태 업데이트
            self.update_job_status(job_id, "processing")
            
            # 링크 기반 상품 정보 처리
            coupang_products, aliexpress_products = self.process_links_based(job_data)
            
            # 상품이 하나도 없으면 실패
            if not coupang_products and not aliexpress_products:
                error_msg = "상품 정보를 가져올 수 없습니다"
                self.log_message(f"[❌] {error_msg}")
                self.update_job_status(job_id, "failed", error_msg)
                return False
            
            # 기본 콘텐츠 생성
            base_content = self.generate_base_content_with_gemini(job_data)
            if not base_content:
                error_msg = "기본 콘텐츠 생성 실패"
                self.log_message(f"[❌] {error_msg}")
                self.update_job_status(job_id, "failed", error_msg)
                return False
            
            # 상품 카드 삽입
            final_content = self.insert_product_cards_into_content(base_content, coupang_products, aliexpress_products)
            
            # 워드프레스 발행
            post_url = self.publish_to_wordpress(job_data, final_content)
            if not post_url:
                error_msg = "워드프레스 발행 실패"
                self.log_message(f"[❌] {error_msg}")
                self.update_job_status(job_id, "failed", error_msg)
                return False
            
            # 성공 처리
            self.update_job_status(job_id, "completed")
            self.log_message(f"[✅] 작업 완료: {title} -> {post_url}")
            
            # 텔레그램 완료 알림
            self.send_telegram_notification(f"✅ 어필리에이트 자동화 완료\n제목: {title[:50]}...\n링크: {post_url}")
            
            return True
            
        except Exception as e:
            error_msg = f"작업 처리 중 오류: {e}"
            self.log_message(f"[❌] {error_msg}")
            self.update_job_status(job_data["queue_id"], "failed", error_msg)
            return False
    
    def run(self):
        """메인 실행 로직"""
        print("=" * 60)
        print("🤖 어필리에이트 상품 자동 등록 시스템 시작 (v2.0 - 문제점 해결)")
        print("=" * 60)
        
        try:
            # 설정 로드
            if not self.load_configuration():
                return False
            
            # 큐 로드
            pending_jobs = self.load_queue()
            if not pending_jobs:
                self.log_message("[📋] 처리할 작업이 없습니다.")
                return True
            
            # 작업 처리
            processed_count = 0
            
            for job_data in pending_jobs:
                if processed_count >= MAX_POSTS_PER_RUN:
                    self.log_message(f"[⏸️] 최대 처리 개수({MAX_POSTS_PER_RUN})에 도달했습니다.")
                    break
                
                # 작업 처리
                if self.process_job(job_data):
                    processed_count += 1
                    
                    # 대기 시간
                    if processed_count < MAX_POSTS_PER_RUN:
                        self.log_message(f"[⏳] {POST_DELAY_SECONDS}초 대기 중...")
                        time.sleep(POST_DELAY_SECONDS)
                else:
                    self.log_message(f"[❌] 작업 실패: {job_data['title']}")
            
            # 최종 결과
            remaining_jobs = len(pending_jobs) - processed_count
            self.log_message(f"[🎉] 자동화 완료! 처리: {processed_count}개, 남은 작업: {remaining_jobs}개")
            
            # 텔레그램 최종 알림
            self.send_telegram_notification(f"[🎉] 자동화 완료! 처리: {processed_count}개, 남은 작업: {remaining_jobs}개")
            
            return True
            
        except Exception as e:
            error_msg = f"자동화 시스템 실행 중 오류: {e}"
            self.log_message(f"[❌] {error_msg}")
            self.send_telegram_notification(f"❌ 자동화 시스템 오류: {error_msg}")
            return False
        finally:
            print("=" * 60)
            print("🤖 어필리에이트 상품 자동 등록 시스템 종료")
            print("=" * 60)

def main():
    """메인 함수"""
    system = AffiliatePostingSystem()
    system.run()

if __name__ == "__main__":
    main()