#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
알리익스프레스 전용 어필리에이트 상품 자동 등록 시스템 (4가지 프롬프트 템플릿 시스템 + 즉시 발행 지원)
키워드 입력 → 알리익스프레스 API → AI 콘텐츠 생성 → 워드프레스 자동 발행

작성자: Claude AI
날짜: 2025-07-22
버전: v5.0 (FIFU, YoastSEO, 태그 기능 추가)
"""

import os
import sys
import json
import time
import requests
import traceback
import argparse
import re
import google.generativeai as genai
from datetime import datetime
from dotenv import load_dotenv
from prompt_templates import PromptTemplates

# 알리익스프레스 SDK 경로 추가
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

class AliExpressPostingSystem:
    def __init__(self):
        self.config = None
        self.gemini_model = None
        self.aliexpress_client = None
        self.immediate_mode = False
        
    def load_configuration(self):
        """환경 변수 및 API 키 로드 (알리익스프레스 전용)"""
        print("[⚙️] 설정을 로드합니다...")
        
        # .env 파일 로드
        env_path = "/home/novacents/.env"
        if os.path.exists(env_path):
            load_dotenv(env_path)
        else:
            print(f"[❌] .env 파일을 찾을 수 없습니다: {env_path}")
            return False
            
        self.config = {
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
            self.gemini_model = genai.GenerativeModel('gemini-1.5-pro-latest')
            print("[✅] Gemini API가 성공적으로 구성되었습니다.")
        except Exception as e:
            print(f"[❌] Gemini API 구성 중 오류 발생: {e}")
            return False
            
        # 알리익스프레스 클라이언트 초기화
        try:
            self.aliexpress_client = iop.IopClient(
                'https://api-sg.aliexpress.com/sync',
                self.config["aliexpress_app_key"], 
                self.config["aliexpress_app_secret"]
            )
            print("[✅] 알리익스프레스 API 클라이언트가 성공적으로 초기화되었습니다.")
        except Exception as e:
            print(f"[❌] 알리익스프레스 API 초기화 중 오류 발생: {e}")
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
    
    def remove_job_from_queue(self, job_id):
        """즉시 발행 후 큐에서 작업 제거"""
        try:
            with open(QUEUE_FILE, "r", encoding="utf-8") as f:
                queue_data = json.load(f)
            
            # 해당 job_id를 가진 항목 제거
            queue_data = [job for job in queue_data if job.get("queue_id") != job_id]
            
            self.save_queue(queue_data)
            print(f"[🗑️] 작업 ID {job_id}를 큐에서 제거했습니다.")
            
        except Exception as e:
            print(f"[❌] 큐에서 작업 제거 중 오류: {e}")
    
    # 🚀 즉시 발행 전용 함수들
    def load_immediate_job(self, temp_file):
        """즉시 발행용 임시 파일에서 작업 로드"""
        try:
            print(f"[📄] 즉시 발행 임시 파일 로드: {temp_file}")
            
            if not os.path.exists(temp_file):
                print(f"[❌] 임시 파일을 찾을 수 없습니다: {temp_file}")
                return None
                
            with open(temp_file, "r", encoding="utf-8") as f:
                temp_data = json.load(f)
                
            # 데이터 구조 검증
            if temp_data.get('mode') != 'immediate':
                print(f"[❌] 잘못된 임시 파일 모드: {temp_data.get('mode')}")
                return None
                
            job_data = temp_data.get('job_data')
            if not job_data:
                print(f"[❌] 임시 파일에 작업 데이터가 없습니다.")
                return None
                
            print(f"[✅] 즉시 발행 작업 로드 성공: {job_data.get('title', 'N/A')}")
            return job_data
            
        except Exception as e:
            print(f"[❌] 즉시 발행 임시 파일 로드 중 오류: {e}")
            return None
            
    def cleanup_temp_file(self, temp_file):
        """임시 파일 정리 (선택사항)"""
        try:
            if os.path.exists(temp_file):
                # 임시 파일을 바로 삭제하지 않고 유지 (사용자가 수동 삭제)
                print(f"[🗂️] 임시 파일 유지: {temp_file}")
                print(f"[💡] 수동 삭제 필요: rm {temp_file}")
        except Exception as e:
            print(f"[❌] 임시 파일 정리 중 오류: {e}")
    
    def extract_aliexpress_product_id(self, url):
        """알리익스프레스 URL에서 상품 ID 추출"""
        patterns = [
            r'/item/(\d+)\.html',  # 기본 패턴
            r'/item/(\d+)$',       # .html 없는 경우
            r'productId=(\d+)',    # 쿼리 파라미터
            r'/(\d+)\.html',       # 간단한 형태
        ]
        
        for pattern in patterns:
            match = re.search(pattern, url)
            if match:
                return match.group(1)
        
        return None
    
    def convert_aliexpress_to_affiliate_link(self, product_url):
        """알리익스프레스 일반 상품 링크를 어필리에이트 링크로 변환"""
        try:
            print(f"[🔗] 알리익스프레스 링크 변환: {product_url[:50]}...")
            
            # URL 정리 (쿼리 파라미터 제거)
            clean_url = product_url.split('?')[0]
            
            # 링크 변환 요청 생성
            request = iop.IopRequest('aliexpress.affiliate.link.generate', 'POST')
            request.set_simplify()
            request.add_api_param('source_values', clean_url)
            request.add_api_param('promotion_link_type', '0')
            request.add_api_param('tracking_id', 'default')
            
            # API 실행
            response = self.aliexpress_client.execute(request)
            
            # 응답 처리
            if response.body and 'resp_result' in response.body:
                result = response.body['resp_result'].get('result', {})
                promotion_links = result.get('promotion_links', [])
                
                if promotion_links:
                    affiliate_link = promotion_links[0]['promotion_link']
                    print(f"[✅] 알리익스프레스 링크 변환 성공")
                    return affiliate_link
                else:
                    print(f"[⚠️] 알리익스프레스 링크 변환 응답에 링크가 없음")
                    return None
            else:
                print(f"[⚠️] 알리익스프레스 API 응답 오류")
                return None
                
        except Exception as e:
            print(f"[❌] 알리익스프레스 링크 변환 중 오류: {e}")
            return None
    
    def get_aliexpress_product_details(self, product_id):
        """알리익스프레스 상품 상세 정보 조회"""
        try:
            # 상품 상세 API 호출
            request = iop.IopRequest('aliexpress.affiliate.productdetail.get', 'GET')
            request.set_simplify()
            request.add_api_param('product_ids', str(product_id))
            request.add_api_param('tracking_id', 'default')
            
            response = self.aliexpress_client.execute(request)
            
            if response.body and 'resp_result' in response.body:
                result = response.body['resp_result'].get('result', {})
                products = result.get('products', [])
                
                if products:
                    product = products[0]
                    
                    # USD를 KRW로 변환 (환율 1400원 적용)
                    usd_price = float(product.get('target_sale_price', 0))
                    krw_price = int(usd_price * 1400)
                    
                    # 평점 정보 처리
                    rating_value = product.get("evaluate_rate", "0")
                    try:
                        rating_float = float(rating_value)
                        if rating_float >= 90:
                            rating_display = f"⭐⭐⭐⭐⭐ ({rating_float}%)"
                        elif rating_float >= 70:
                            rating_display = f"⭐⭐⭐⭐ ({rating_float}%)"
                        elif rating_float >= 50:
                            rating_display = f"⭐⭐⭐ ({rating_float}%)"
                        elif rating_float >= 30:
                            rating_display = f"⭐⭐ ({rating_float}%)"
                        else:
                            rating_display = f"⭐ ({rating_float}%)"
                    except:
                        rating_display = "평점 정보 없음"
                    
                    # 판매량 정보 처리
                    volume = product.get("lastest_volume", "0")
                    try:
                        volume_int = int(str(volume))
                        volume_display = f"{volume_int}개 판매" if volume_int > 0 else "판매량 정보 없음"
                    except:
                        volume_display = "판매량 정보 없음"
                    
                    formatted_product = {
                        "product_id": product_id,
                        "title": product.get("product_title", "상품명 없음"),
                        "price": f"₩{krw_price:,}",
                        "image_url": product.get("product_main_image_url", ""),
                        "rating_display": rating_display,
                        "lastest_volume": volume_display,
                        "original_data": product
                    }
                    
                    print(f"[✅] 상품 정보 조회 성공: {formatted_product['title']}")
                    return formatted_product
            
            print(f"[⚠️] 상품 정보를 찾을 수 없습니다")
            return None
            
        except Exception as e:
            print(f"[❌] 상품 정보 조회 중 오류: {e}")
            return None
    
    def process_aliexpress_products(self, job_data):
        """알리익스프레스 상품 처리"""
        print("[🌏] 알리익스프레스 상품 처리를 시작합니다...")
        
        processed_products = []
        
        for keyword_data in job_data["keywords"]:
            keyword = keyword_data["name"]
            aliexpress_links = keyword_data.get("aliexpress", [])
            
            print(f"[📋] 키워드 '{keyword}' 처리 중...")
            
            for link in aliexpress_links:
                if link.strip():
                    # 링크를 어필리에이트 링크로 변환
                    affiliate_link = self.convert_aliexpress_to_affiliate_link(link.strip())
                    
                    if affiliate_link:
                        # 상품 ID 추출
                        product_id = self.extract_aliexpress_product_id(link.strip())
                        
                        if product_id:
                            # 상품 상세 정보 조회
                            product_info = self.get_aliexpress_product_details(product_id)
                            
                            if product_info:
                                product_info["affiliate_url"] = affiliate_link
                                product_info["keyword"] = keyword
                                processed_products.append(product_info)
                        else:
                            # 상품 ID를 찾을 수 없는 경우 기본 정보만
                            basic_product = {
                                "title": f"{keyword} 관련 상품",
                                "price": "가격 확인 필요",
                                "image_url": "",
                                "rating_display": "평점 정보 없음",
                                "lastest_volume": "판매량 정보 없음",
                                "affiliate_url": affiliate_link,
                                "keyword": keyword
                            }
                            processed_products.append(basic_product)
                    
                    # API 호출 간 딜레이
                    time.sleep(2)
        
        print(f"[✅] 알리익스프레스 상품 처리 완료: {len(processed_products)}개")
        return processed_products
    
    def generate_content_with_gemini(self, job_data, products):
        """🚀 Gemini API로 4가지 프롬프트 템플릿 기반 블로그 콘텐츠 생성"""
        try:
            # 프롬프트 타입 추출 (기본값: essential_items)
            prompt_type = job_data.get('prompt_type', 'essential_items')
            title = job_data.get('title', '')
            
            # 키워드 정보 정리
            keywords = [kw["name"] for kw in job_data.get("keywords", [])]
            
            # 사용자 상세 정보 추출
            user_details = job_data.get('user_details', {})
            has_user_details = job_data.get('has_user_details', False)
            
            mode_text = "즉시 발행" if self.immediate_mode else "큐 처리"
            print(f"[🤖] Gemini AI로 '{title}' 콘텐츠를 생성합니다... ({mode_text})")
            print(f"[🎯] 프롬프트 타입: {prompt_type}")
            print(f"[📝] 사용자 상세 정보: {'포함' if has_user_details else '없음'}")
            
            # 상품 정보 추가 (프롬프트에 포함할 상품 요약)
            product_summaries = []
            for product in products:
                summary = f"- {product['title']} (가격: {product['price']}, 평점: {product['rating_display']}, 판매량: {product['lastest_volume']})"
                product_summaries.append(summary)
            
            # 상품 정보를 포함한 상세 정보 구성
            enhanced_user_details = user_details.copy() if user_details else {}
            enhanced_user_details['product_summaries'] = product_summaries
            
            # 🚀 4가지 프롬프트 템플릿 시스템 활용
            prompt = PromptTemplates.get_prompt_by_type(
                prompt_type=prompt_type,
                title=title,
                keywords=keywords,
                user_details=enhanced_user_details
            )
            
            # 상품 정보를 프롬프트에 추가
            if product_summaries:
                prompt += f"\n\n**알리익스프레스 상품 정보:**\n{chr(10).join(product_summaries)}\n\n"
            
            # 프롬프트 마지막에 공통 요구사항 추가
            prompt += """
### ⚠️ 중요한 공통 요구사항 ###

**HTML 포맷팅 필수:**
- 마크다운 문법(## ###) 사용 절대 금지
- 반드시 HTML 태그 사용: <h2>, <h3>, <p>
- 글 길이: 2500-3000자 (충분한 정보 제공)
- 이모지 적절 활용으로 가독성 향상

**SEO 최적화 필수:**
- 키워드 자연스럽게 3-5회 배치
- 키워드 밀도 2-3% 유지
- 제목 태그와 소제목 활용
- 구조화된 정보 제공

**절대 금지사항:**
- 상품 링크나 버튼 HTML 코드 포함 금지 (별도 삽입)
- 허위 정보나 과장된 표현 금지
- 다른 쇼핑몰 언급 금지 (알리익스프레스 전용)

위 조건을 모두 준수하여 높은 품질의 블로그 글을 작성해주세요.
"""
            
            # Gemini API 호출
            response = self.gemini_model.generate_content(prompt)
            base_content = response.text
            
            if not base_content or len(base_content.strip()) < 1500:
                print("[❌] Gemini가 충분한 길이의 콘텐츠를 생성하지 못했습니다.")
                return None
            
            # HTML 코드 블록 표시 제거
            base_content = base_content.replace('```html', '').replace('```', '').strip()
            
            # 본문 글자 크기 18px 적용
            base_content = f'<div style="font-size: 18px; line-height: 1.6;">{base_content}</div>'
            
            # 상품 카드 삽입
            final_content = self.insert_product_cards(base_content, products)
            
            print(f"[✅] Gemini AI가 {len(base_content)}자의 {prompt_type} 스타일 콘텐츠를 생성했습니다.")
            return final_content
            
        except Exception as e:
            print(f"[❌] Gemini 콘텐츠 생성 중 오류: {e}")
            return None
    
    def insert_product_cards(self, content, products):
        """상품 카드를 콘텐츠에 삽입"""
        final_content = content
        
        # 각 상품에 대해 카드 생성 및 삽입
        for i, product in enumerate(products):
            # 상품 카드 HTML 생성
            card_html = self.generate_product_card_html(product)
            keyword = product.get('keyword', '')
            
            # 키워드가 포함된 섹션 뒤에 카드 삽입
            if keyword:
                # 1순위: 키워드가 포함된 H2/H3 섹션의 첫 번째 문단 다음
                pattern1 = rf'(<h[2-3][^>]*>[^<]*{re.escape(keyword)}[^<]*</h[2-3]>[^<]*<p[^>]*>.*?</p>)'
                if re.search(pattern1, final_content, re.IGNORECASE | re.DOTALL):
                    final_content = re.sub(pattern1, rf'\1{card_html}', final_content, flags=re.IGNORECASE | re.DOTALL, count=1)
                    print(f"[✅] '{keyword}' 상품 카드를 H2/H3 섹션 다음에 삽입")
                    continue
                
                # 2순위: 키워드가 언급된 첫 번째 문단 다음
                pattern2 = rf'(<p[^>]*>[^<]*{re.escape(keyword)}[^<]*</p>)'
                if re.search(pattern2, final_content, re.IGNORECASE | re.DOTALL):
                    final_content = re.sub(pattern2, rf'\1{card_html}', final_content, flags=re.IGNORECASE | re.DOTALL, count=1)
                    print(f"[✅] '{keyword}' 상품 카드를 키워드 언급 문단 다음에 삽입")
                    continue
            
            # 3순위: 첫 번째 H2 섹션 다음
            pattern3 = r'(<h2[^>]*>.*?</h2>[^<]*<p[^>]*>.*?</p>)'
            if re.search(pattern3, final_content, re.IGNORECASE | re.DOTALL):
                final_content = re.sub(pattern3, rf'\1{card_html}', final_content, flags=re.IGNORECASE | re.DOTALL, count=1)
                print(f"[✅] 상품 카드를 첫 번째 H2 섹션 다음에 삽입")
                continue
            
            # 4순위: 콘텐츠 중간에 삽입
            content_parts = final_content.split('</p>')
            if len(content_parts) > 3:
                mid_point = len(content_parts) // 2
                content_parts[mid_point] += card_html
                final_content = '</p>'.join(content_parts)
                print(f"[✅] 상품 카드를 콘텐츠 중간에 삽입")
        
        return final_content
    
    def generate_product_card_html(self, product):
        """개별 상품 카드 HTML 생성"""
        # 상품 이미지 처리
        image_html = ""
        if product.get('image_url') and product['image_url'].startswith('http'):
            image_html = f'''
            <div style="text-align: center; margin-bottom: 15px;">
                <img src="{product['image_url']}" alt="{product['title']}" style="max-width: 400px; height: auto; border-radius: 8px; border: 1px solid #ddd;">
            </div>'''
        
        # 어필리에이트 버튼 HTML (반응형)
        button_html = f'''
        <div class="affiliate-button-container" style="width: 100%; max-width: 800px; margin: 15px auto; text-align: center;">
            <a href="{product['affiliate_url']}" target="_blank" rel="noopener" style="display: inline-block; width: 100%;">
                <picture>
                    <source media="(max-width: 768px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png">
                    <img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 {product.get('keyword', '상품')} 구매하기" style="width: 100%; height: auto; max-width: 800px; border-radius: 8px;">
                </picture>
            </a>
        </div>'''
        
        return f'''
<div style="border: 2px solid #eee; padding: 25px; margin: 25px 0; border-radius: 15px; background: #f9f9f9; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
    <h3 style="color: #333; margin-bottom: 15px; font-size: 1.3em;">{product['title']}</h3>
    {image_html}
    <p style="color: #e74c3c; font-size: 1.2em; font-weight: bold; margin: 15px 0;"><strong>💰 가격: {product['price']}</strong></p>
    <p style="margin: 10px 0;"><strong>⭐ 평점: {product['rating_display']}</strong></p>
    <p style="margin: 10px 0;"><strong>📦 판매량: {product['lastest_volume']}</strong></p>
    {button_html}
</div>'''
    
    def generate_focus_keyphrase(self, title, keywords):
        """YoastSEO 초점 키프레이즈 생성"""
        print(f"[🤖] 초점 키프레이즈를 생성합니다...")
        
        # 첫 번째 키워드를 기본 키프레이즈로 사용
        if keywords and len(keywords) > 0:
            base_keyword = keywords[0]
            # 롱테일 키프레이즈 생성
            if "추천" not in base_keyword and "가이드" not in base_keyword:
                focus_keyphrase = f"{base_keyword} 추천"
            else:
                focus_keyphrase = base_keyword
        else:
            # 제목에서 키워드 추출
            focus_keyphrase = title.split()[0] if title else "알리익스프레스 추천"
        
        print(f"[✅] 초점 키프레이즈 생성 완료: {focus_keyphrase}")
        return focus_keyphrase
    
    def generate_slug(self, title):
        """URL 슬러그 생성 (한글을 영문으로 변환)"""
        print(f"[🤖] URL 슬러그를 생성합니다...")
        
        # 한글을 간단한 영문으로 변환하는 매핑
        korean_to_english = {
            "추천": "recommendation",
            "가이드": "guide",
            "리뷰": "review",
            "제품": "product",
            "상품": "item",
            "베스트": "best",
            "인기": "popular",
            "필수": "essential",
            "여행": "travel",
            "용품": "goods",
            "아이템": "items"
        }
        
        # 제목을 소문자로 변환
        slug = title.lower()
        
        # 한글 키워드를 영문으로 변환
        for korean, english in korean_to_english.items():
            slug = slug.replace(korean, english)
        
        # 특수문자 제거 및 공백을 하이픈으로 변환
        slug = re.sub(r'[^a-zA-Z0-9가-힣\s-]', '', slug)
        slug = re.sub(r'\s+', '-', slug.strip())
        
        # 연속된 하이픈 제거
        slug = re.sub(r'-+', '-', slug)
        
        # 시작과 끝의 하이픈 제거
        slug = slug.strip('-')
        
        # 슬러그가 비어있거나 너무 길면 기본값 사용
        if not slug or len(slug) > 50:
            slug = f"aliexpress-{datetime.now().strftime('%Y%m%d%H%M%S')}"
        
        print(f"[✅] URL 슬러그 생성 완료: {slug}")
        return slug
    
    def generate_tags(self, title, keywords):
        """키워드 기반 태그 생성"""
        print(f"[🤖] 게시물 태그를 생성합니다...")
        
        tags = []
        
        # 키워드를 태그로 추가
        for keyword in keywords:
            if keyword and keyword not in tags:
                tags.append(keyword)
        
        # 제목에서 추가 태그 추출
        title_words = title.split()
        for word in title_words:
            if len(word) > 2 and word not in tags and not word.isdigit():
                tags.append(word)
        
        # 공통 태그 추가
        common_tags = ["알리익스프레스", "추천", "구매가이드", "해외직구"]
        for tag in common_tags:
            if tag not in tags and len(tags) < 10:
                tags.append(tag)
        
        # 최대 10개로 제한
        tags = tags[:10]
        
        print(f"[✅] 태그 {len(tags)}개 생성 완료: {', '.join(tags)}")
        return tags
    
    def ensure_tags_on_wordpress(self, tags):
        """워드프레스에 태그 확인 및 등록"""
        print(f"[☁️] 워드프레스에 태그를 확인하고 등록합니다...")
        
        auth = (self.config["wp_user"], self.config["wp_app_pass"])
        headers = {'Content-Type': 'application/json'}
        tag_ids = []
        
        for tag_name in tags:
            if not tag_name:
                continue
            
            try:
                # 기존 태그 검색
                res = requests.get(
                    f"{self.config['wp_api_base']}/tags",
                    auth=auth,
                    params={"search": tag_name},
                    headers=headers,
                    timeout=10
                )
                res.raise_for_status()
                existing_tags = res.json()
                
                found = False
                if isinstance(existing_tags, list):
                    for tag_data in existing_tags:
                        if isinstance(tag_data, dict) and tag_data.get('name', '').lower() == tag_name.lower():
                            tag_ids.append(tag_data['id'])
                            found = True
                            break
                
                # 태그가 없으면 새로 생성
                if not found:
                    print(f"[⚙️] 태그 '{tag_name}'을(를) 새로 생성합니다...")
                    create_res = requests.post(
                        f"{self.config['wp_api_base']}/tags",
                        auth=auth,
                        json={"name": tag_name},
                        headers=headers,
                        timeout=10
                    )
                    create_res.raise_for_status()
                    if create_res.status_code == 201:
                        tag_ids.append(create_res.json()['id'])
                
            except requests.exceptions.RequestException as e:
                print(f"[❌] 태그 API 요청 중 오류 ('{tag_name}'): {e}")
        
        print(f"[✅] {len(tag_ids)}개의 태그 ID를 확보했습니다.")
        return tag_ids
    
    def post_to_wordpress(self, job_data, content):
        """워드프레스에 글 발행 (FIFU, YoastSEO, 태그 포함)"""
        try:
            mode_text = "즉시 발행" if self.immediate_mode else "큐 처리"
            print(f"[📝] 워드프레스에 '{job_data['title']}' 글을 발행합니다... ({mode_text})")
            
            # 워드프레스 API 엔드포인트
            api_url = f"{self.config['wp_api_base']}/posts"
            
            # 인증 정보
            auth = (self.config["wp_user"], self.config["wp_app_pass"])
            headers = {"Content-Type": "application/json"}
            
            # 키워드 추출
            keywords = [kw["name"] for kw in job_data.get("keywords", [])]
            
            # SEO 데이터 생성
            focus_keyphrase = self.generate_focus_keyphrase(job_data['title'], keywords)
            slug = self.generate_slug(job_data['title'])
            
            # 메타 설명 생성
            prompt_type = job_data.get('prompt_type', 'essential_items')
            prompt_type_names = {
                'essential_items': '필수 아이템',
                'friend_review': '실제 후기',
                'professional_analysis': '전문 분석',
                'amazing_discovery': '혁신 제품'
            }
            
            meta_description = f"{focus_keyphrase} - {prompt_type_names.get(prompt_type, '상품')} 추천 및 2025년 알리익스프레스 구매 가이드"
            if job_data.get('has_user_details'):
                meta_description += ". 사용자 맞춤 정보 기반 상세 리뷰"
            
            # 태그 생성 및 등록
            tags = self.generate_tags(job_data['title'], keywords)
            tag_ids = self.ensure_tags_on_wordpress(tags)
            
            # 게시물 데이터
            post_data = {
                "title": job_data["title"],
                "content": content,
                "status": "publish",
                "categories": [job_data["category_id"]],
                "tags": tag_ids,  # 태그 추가
                "slug": slug  # 슬러그 추가
            }
            
            # 1단계: 게시물 생성
            print(f"[⚙️] 1단계 - 게시물을 생성합니다...")
            response = requests.post(api_url, json=post_data, headers=headers, auth=auth, timeout=30)
            
            if response.status_code == 201:
                post_info = response.json()
                post_id = post_info.get("id")
                post_url = post_info.get("link", "")
                print(f"[✅] 워드프레스 게시물 생성 성공! (ID: {post_id})")
                
                # 2단계: FIFU 썸네일 설정
                if job_data.get('thumbnail_url'):
                    print(f"[⚙️] 2단계 - FIFU 썸네일을 설정합니다...")
                    fifu_payload = {
                        "meta": {
                            "_fifu_image_url": job_data['thumbnail_url']
                        }
                    }
                    fifu_response = requests.post(
                        f"{self.config['wp_api_base']}/posts/{post_id}",
                        auth=auth,
                        json=fifu_payload,
                        headers=headers,
                        timeout=20
                    )
                    if fifu_response.status_code in [200, 201]:
                        print("[✅] FIFU 썸네일 설정 완료.")
                    else:
                        print(f"[⚠️] FIFU 썸네일 설정 실패: {fifu_response.status_code}")
                
                # 3단계: YoastSEO 메타데이터 설정
                print(f"[⚙️] 3단계 - Yoast SEO 메타데이터를 설정합니다...")
                yoast_payload = {
                    "post_id": post_id,
                    "focus_keyphrase": focus_keyphrase,
                    "meta_description": meta_description
                }
                yoast_url = f"{self.config['wp_url'].rstrip('/')}/wp-json/my-api/v1/update-seo"
                
                try:
                    yoast_response = requests.post(
                        yoast_url,
                        auth=auth,
                        json=yoast_payload,
                        headers=headers,
                        timeout=20
                    )
                    if yoast_response.status_code in [200, 201]:
                        print("[✅] Yoast SEO 메타데이터 설정 완료.")
                    else:
                        print(f"[⚠️] Yoast SEO 설정 응답: {yoast_response.status_code}")
                except Exception as e:
                    print(f"[⚠️] Yoast SEO 설정 중 오류 (무시하고 계속): {e}")
                
                # 발행 로그 저장
                self.save_published_log(job_data, post_url)
                
                print(f"[🎉] 모든 작업 완료! 발행된 글 주소: {post_url}")
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
            prompt_type = job_data.get('prompt_type', 'essential_items')
            mode_text = "[즉시발행]" if self.immediate_mode else "[큐처리]"
            log_entry = f"[{timestamp}] {mode_text} {job_data['title']} ({prompt_type}) - {post_url}\n"
            
            with open(PUBLISHED_LOG_FILE, "a", encoding="utf-8") as f:
                f.write(log_entry)
                
        except Exception as e:
            print(f"[❌] 발행 로그 저장 중 오류: {e}")
            
    def process_job(self, job_data):
        """단일 작업 처리 (4가지 프롬프트 템플릿 시스템 + 즉시 발행 지원)"""
        job_id = job_data["queue_id"]
        title = job_data["title"]
        prompt_type = job_data.get('prompt_type', 'essential_items')
        has_user_details = job_data.get('has_user_details', False)
        
        # 프롬프트 타입명 매핑
        prompt_type_names = {
            'essential_items': '필수템형 🎯',
            'friend_review': '친구 추천형 👫',
            'professional_analysis': '전문 분석형 📊',
            'amazing_discovery': '놀라움 발견형 ✨'
        }
        
        prompt_name = prompt_type_names.get(prompt_type, '기본형')
        mode_text = "즉시 발행" if self.immediate_mode else "큐 처리"
        
        self.log_message(f"[🚀] 작업 시작: {title} (ID: {job_id}) - {mode_text}")
        self.log_message(f"[🎯] 프롬프트: {prompt_name}")
        self.log_message(f"[📝] 사용자 정보: {'포함' if has_user_details else '없음'}")
        
        # 텔레그램 알림
        telegram_start_msg = f"🚀 알리익스프레스 자동화 시작 ({mode_text})\n제목: {title}\n프롬프트: {prompt_name}"
        if has_user_details:
            telegram_start_msg += "\n🎯 사용자 맞춤 정보 활용"
        
        self.send_telegram_notification(telegram_start_msg)
        
        try:
            # 즉시 발행 모드가 아닌 경우에만 작업 상태 업데이트
            if not self.immediate_mode:
                self.update_job_status(job_id, "processing")
            
            # 1. 알리익스프레스 상품 처리
            products = self.process_aliexpress_products(job_data)
            
            if not products:
                raise Exception("알리익스프레스 상품 처리 실패")
                
            # 2. Gemini로 콘텐츠 생성 (4가지 프롬프트 템플릿 시스템)
            content = self.generate_content_with_gemini(job_data, products)
            
            if not content:
                raise Exception("콘텐츠 생성 실패")
                
            # 3. 워드프레스에 발행
            post_url = self.post_to_wordpress(job_data, content)
            
            if post_url:
                # 성공 처리
                if self.immediate_mode:
                    # 즉시 발행인 경우 큐에서 제거
                    self.remove_job_from_queue(job_id)
                else:
                    # 일반 큐 처리인 경우 상태 업데이트
                    self.update_job_status(job_id, "completed")
                
                self.log_message(f"[✅] 작업 완료: {title} -> {post_url}")
                
                # 성공 알림
                success_msg = f"✅ 알리익스프레스 자동화 완료 ({mode_text})\n제목: {title}\n프롬프트: {prompt_name}\nURL: {post_url}\n상품 수: {len(products)}개"
                if has_user_details:
                    success_msg += "\n🎯 사용자 맞춤 정보 반영"
                
                self.send_telegram_notification(success_msg)
                return True
            else:
                raise Exception("워드프레스 발행 실패")
                
        except Exception as e:
            # 실패 처리
            error_msg = str(e)
            if not self.immediate_mode:
                self.update_job_status(job_id, "failed", error_msg)
            self.log_message(f"[❌] 작업 실패: {title} - {error_msg}")
            self.send_telegram_notification(
                f"❌ 알리익스프레스 자동화 실패 ({mode_text})\n"
                f"제목: {title}\n"
                f"프롬프트: {prompt_name}\n"
                f"오류: {error_msg}"
            )
            return False
            
    def run_immediate_mode(self, temp_file):
        """🚀 즉시 발행 모드 실행"""
        print("=" * 60)
        print("🚀 알리익스프레스 즉시 발행 모드 시작")
        print("=" * 60)
        
        self.immediate_mode = True
        
        # 1. 설정 로드
        if not self.load_configuration():
            print("[❌] 설정 로드 실패. 프로그램을 종료합니다.")
            return False
            
        # 2. 임시 파일에서 작업 로드
        job_data = self.load_immediate_job(temp_file)
        if not job_data:
            print("[❌] 즉시 발행 작업 로드 실패.")
            return False
            
        # 3. 단일 작업 처리
        success = self.process_job(job_data)
        
        # 4. 임시 파일 정리 (선택사항)
        self.cleanup_temp_file(temp_file)
        
        # 5. 완료 메시지
        if success:
            completion_message = f"[🎉] 즉시 발행 완료! 제목: {job_data.get('title', 'N/A')}"
            self.log_message(completion_message)
            print("=" * 60)
            print("🚀 알리익스프레스 즉시 발행 성공")
            print("=" * 60)
            return True
        else:
            error_message = f"[❌] 즉시 발행 실패! 제목: {job_data.get('title', 'N/A')}"
            self.log_message(error_message)
            print("=" * 60)
            print("❌ 알리익스프레스 즉시 발행 실패")
            print("=" * 60)
            return False
            
    def run(self):
        """메인 실행 함수 (큐 모드)"""
        print("=" * 60)
        print("🌏 알리익스프레스 전용 어필리에이트 자동화 시스템 시작 (4가지 프롬프트 템플릿)")
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
        completion_message = f"[🎉] 4가지 프롬프트 템플릿 자동화 완료! 처리: {processed_count}개, 남은 작업: {remaining_jobs}개"
        
        self.log_message(completion_message)
        self.send_telegram_notification(completion_message)
        
        print("=" * 60)
        print("🌏 알리익스프레스 전용 어필리에이트 자동화 시스템 종료")
        print("=" * 60)


def main():
    """메인 함수 - 명령줄 인수 처리"""
    parser = argparse.ArgumentParser(description='알리익스프레스 어필리에이트 자동화 시스템')
    parser.add_argument('--immediate-file', help='즉시 발행용 임시 파일 경로')
    
    args = parser.parse_args()
    
    try:
        system = AliExpressPostingSystem()
        
        if args.immediate_file:
            # 🚀 즉시 발행 모드
            success = system.run_immediate_mode(args.immediate_file)
            sys.exit(0 if success else 1)
        else:
            # 기존 큐 모드
            system.run()
            
    except KeyboardInterrupt:
        print("\n[⏹️] 사용자에 의해 프로그램이 중단되었습니다.")
        sys.exit(1)
    except Exception as e:
        print(f"\n[❌] 예상치 못한 오류가 발생했습니다: {e}")
        print(traceback.format_exc())
        sys.exit(1)


if __name__ == "__main__":
    main()