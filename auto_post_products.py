#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
알리익스프레스 전용 어필리에이트 상품 자동 등록 시스템 (사용자 상세 정보 활용 버전)
키워드 입력 → 알리익스프레스 API → AI 콘텐츠 생성 → 워드프레스 자동 발행

작성자: Claude AI
날짜: 2025-07-17
버전: v2.1 (사용자 상세 정보 활용 + E-E-A-T 최적화)
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
            self.gemini_model = genai.GenerativeModel('gemini-2.5-pro')
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
    
    def extract_aliexpress_product_id(self, url):
        """알리익스프레스 URL에서 상품 ID 추출"""
        import re
        
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
    
    def format_user_details_for_prompt(self, user_details):
        """사용자 상세 정보를 Gemini 프롬프트용으로 포맷팅"""
        if not user_details:
            return "사용자 상세 정보: 제공되지 않음"
        
        formatted_sections = []
        
        # 기능 및 스펙
        if 'specs' in user_details and user_details['specs']:
            specs_text = "**기능 및 스펙:**\n"
            for key, value in user_details['specs'].items():
                if key == 'main_function':
                    specs_text += f"- 주요 기능: {value}\n"
                elif key == 'size_capacity':
                    specs_text += f"- 크기/용량: {value}\n"
                elif key == 'color':
                    specs_text += f"- 색상: {value}\n"
                elif key == 'material':
                    specs_text += f"- 재질/소재: {value}\n"
                elif key == 'power_battery':
                    specs_text += f"- 전원/배터리: {value}\n"
            formatted_sections.append(specs_text)
        
        # 효율성 분석
        if 'efficiency' in user_details and user_details['efficiency']:
            efficiency_text = "**효율성 분석:**\n"
            for key, value in user_details['efficiency'].items():
                if key == 'problem_solving':
                    efficiency_text += f"- 해결하는 문제: {value}\n"
                elif key == 'time_saving':
                    efficiency_text += f"- 시간 절약 효과: {value}\n"
                elif key == 'space_efficiency':
                    efficiency_text += f"- 공간 활용: {value}\n"
                elif key == 'cost_saving':
                    efficiency_text += f"- 비용 절감: {value}\n"
            formatted_sections.append(efficiency_text)
        
        # 사용 시나리오
        if 'usage' in user_details and user_details['usage']:
            usage_text = "**사용 시나리오:**\n"
            for key, value in user_details['usage'].items():
                if key == 'usage_location':
                    usage_text += f"- 주요 사용 장소: {value}\n"
                elif key == 'usage_frequency':
                    usage_text += f"- 사용 빈도: {value}\n"
                elif key == 'target_users':
                    usage_text += f"- 적합한 사용자: {value}\n"
                elif key == 'usage_method':
                    usage_text += f"- 사용법 요약: {value}\n"
            formatted_sections.append(usage_text)
        
        # 장점 및 주의사항
        if 'benefits' in user_details and user_details['benefits']:
            benefits_text = "**장점 및 주의사항:**\n"
            if 'advantages' in user_details['benefits'] and user_details['benefits']['advantages']:
                benefits_text += "- 핵심 장점:\n"
                for i, advantage in enumerate(user_details['benefits']['advantages'], 1):
                    benefits_text += f"  {i}. {advantage}\n"
            if 'precautions' in user_details['benefits']:
                benefits_text += f"- 주의사항: {user_details['benefits']['precautions']}\n"
            formatted_sections.append(benefits_text)
        
        return "\n".join(formatted_sections) if formatted_sections else "사용자 상세 정보: 제공되지 않음"
    
    def generate_content_with_gemini(self, job_data, products):
        """🚀 Gemini API로 블로그 콘텐츠 생성 (사용자 상세 정보 활용 + E-E-A-T 최적화)"""
        try:
            print(f"[🤖] Gemini AI로 '{job_data['title']}' 콘텐츠를 생성합니다...")
            
            # 키워드 정보 정리
            keywords = [kw["name"] for kw in job_data["keywords"]]
            
            # 상품 정보 정리
            product_summaries = []
            for product in products:
                summary = f"- {product['title']} (가격: {product['price']}, 평점: {product['rating_display']}, 판매량: {product['lastest_volume']})"
                product_summaries.append(summary)
            
            # 🚀 사용자 상세 정보 포맷팅
            user_details_formatted = ""
            has_user_details = job_data.get('has_user_details', False)
            if has_user_details and job_data.get('user_details'):
                user_details_formatted = self.format_user_details_for_prompt(job_data['user_details'])
                print(f"[✅] 사용자 상세 정보를 Gemini 프롬프트에 포함합니다.")
            else:
                user_details_formatted = "사용자 상세 정보: 제공되지 않음 (일반적인 상품 분석 기반으로 작성)"
                print(f"[ℹ️] 사용자 상세 정보가 없어 일반적인 분석으로 진행합니다.")
            
            # 🚀 E-E-A-T 최적화 + 사용자 정보 활용 프롬프트 생성
            prompt = f"""당신은 대한민국 최고의 온라인 쇼핑 전문가이자 알리익스프레스 전문 상품 리뷰어입니다. 
15년 이상의 전자상거래 경험과 3,000건 이상의 상품 리뷰 경험을 보유하고 있으며, 특히 알리익스프레스 상품에 대한 전문 지식이 뛰어납니다.

아래 제공되는 정보를 바탕으로, 한국 소비자들을 위한 매우 실용적이고 신뢰할 수 있는 상품 추천 블로그 글을 작성해주세요.

### 📋 제공된 정보 ###
**글 제목:** {job_data['title']}
**핵심 키워드:** {', '.join(keywords)}

**알리익스프레스 상품 정보:**
{chr(10).join(product_summaries)}

**사용자 상세 정보 (중요!):**
{user_details_formatted}

### ✅ E-E-A-T 최적화 작성 요구사항 ###

1. **Experience (경험) 강조**:
   - 실제 사용 경험 기반의 구체적인 설명
   - 사용자 제공 정보를 바탕으로 한 현실적인 사용 시나리오 제시
   - "실제로 사용해본 결과", "3개월 사용 후기" 등의 경험 기반 표현 사용

2. **Expertise (전문성) 보여주기**:
   - 상품의 기술적 특징과 스펙에 대한 전문적 분석
   - 사용자 제공 스펙 정보를 바탕으로 한 심층 해석
   - 알리익스프레스 구매 노하우와 전문 팁 제공

3. **Authoritativeness (권위성) 구축**:
   - 구체적인 수치와 데이터 활용 (가격, 평점, 판매량)
   - 사용자 제공 효율성 데이터를 근거로 한 객관적 분석
   - 비교 분석과 검증된 정보 제공

4. **Trustworthiness (신뢰성) 확보**:
   - 장점과 단점을 균형있게 제시
   - 사용자 제공 주의사항을 포함한 솔직한 리뷰
   - 투명한 어필리에이트 관계 언급

### 📝 글 구조 (총 3000-4000자) ###

1. **🎯 도입부 (300-400자)**:
   - 2025년 트렌드와 함께 {keywords[0]} 키워드 강조
   - 왜 이 상품들이 필요한지에 대한 명확한 문제 제기
   - 사용자 제공 '해결하는 문제' 정보 적극 활용

2. **⭐ 각 키워드별 상품 전문 분석 (키워드당 600-800자)**:
   - 사용자 제공 기능/스펙 정보를 바탕으로 한 상세 분석
   - 효율성 분석 정보 활용한 구체적 효과 설명
   - 실제 사용 시나리오와 적합한 사용자 정보 반영
   - 장단점 균형있는 제시 (사용자 제공 장점과 주의사항 포함)

3. **🌏 알리익스프레스 스마트 쇼핑 가이드 (500-600자)**:
   - 배송, 관세, 환율 변동 대응법
   - 셀러 평가와 리뷰 확인 방법
   - 분쟁 해결과 환불 프로세스
   - 할인 쿠폰과 세일 시기 활용법

4. **💡 2025년 구매 전략 (400-500자)**:
   - 가격 비교와 최적 구매 시기
   - 사용자 상황별 맞춤 추천 (제공된 타겟 사용자 정보 활용)
   - 예산별 선택 가이드
   - 장기 사용 가치 분석

5. **✅ 결론 및 최종 추천 (300-400자)**:
   - 가장 추천하는 상품과 명확한 이유
   - 2025년 전망과 구매 결정 도움
   - 독자 행동 유도 (비교 검토 후 현명한 선택 강조)

### ⚠️ 중요 작성 원칙 ###

**콘텐츠 품질**:
- 각 키워드를 3-5회 자연스럽게 언급
- 사용자 제공 정보를 최대한 활용하여 개인화된 콘텐츠 생성
- 구체적인 수치와 실제 경험 기반 설명
- 알리익스프레스 특화 정보 (배송 기간, 관세, 환율 등) 강조

**HTML 포맷팅**:
- H2 태그로 주요 섹션 구분
- H3 태그로 각 키워드별 소제목
- 문단은 p 태그 사용
- 이모지 적절히 활용하여 가독성 향상

**구매 전환 최적화**:
- 구매 결정을 돕는 구체적 근거 제시
- 긴급성과 희소성 적절히 활용
- 신뢰감을 주는 전문적 톤앤매너 유지

### ⚠️ 절대 금지사항 ###
- 마크다운 문법(## ###) 사용 금지, 반드시 HTML 태그 사용
- 상품 링크나 버튼 관련 내용 포함하지 마세요 (별도로 삽입됩니다)
- 과장된 표현이나 허위 정보 금지
- 사용자 제공 정보와 모순되는 내용 작성 금지

**핵심 키워드 '{keywords[0]}'를 가장 중요하게 다루고, 사용자가 제공한 상세 정보를 최대한 활용하여 개인화되고 전문적인 고품질 글을 작성해주세요.**"""

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
            
            print(f"[✅] Gemini AI가 {len(base_content)}자의 사용자 맞춤형 콘텐츠를 생성했습니다.")
            return final_content
            
        except Exception as e:
            print(f"[❌] Gemini 콘텐츠 생성 중 오류: {e}")
            return None
    
    def insert_product_cards(self, content, products):
        """상품 카드를 콘텐츠에 삽입"""
        import re
        
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
            
            # 🚀 SEO 메타 설명 개선 (사용자 정보 반영)
            meta_description = f"{job_data['title']} - 2025년 알리익스프레스 최신 상품 추천 및 전문가 구매 가이드"
            if job_data.get('has_user_details') and job_data.get('user_details'):
                if 'efficiency' in job_data['user_details']:
                    meta_description += ". 실제 사용 경험 기반 상세 리뷰"
            
            # 게시물 데이터
            post_data = {
                "title": job_data["title"],
                "content": content,
                "status": "publish",
                "categories": [job_data["category_id"]],
                "meta": {
                    "yoast_wpseo_metadesc": meta_description,
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
        """단일 작업 처리 (사용자 상세 정보 활용)"""
        job_id = job_data["queue_id"]
        title = job_data["title"]
        has_user_details = job_data.get('has_user_details', False)
        
        self.log_message(f"[🚀] 작업 시작: {title} (ID: {job_id}) - 사용자 상세 정보: {'포함' if has_user_details else '없음'}")
        
        # 텔레그램 알림에 사용자 정보 포함 여부 표시
        telegram_start_msg = f"🚀 알리익스프레스 자동화 시작\n제목: {title}"
        if has_user_details:
            telegram_start_msg += "\n🎯 사용자 맞춤 정보 활용"
        
        self.send_telegram_notification(telegram_start_msg)
        
        try:
            # 작업 상태를 processing으로 변경
            self.update_job_status(job_id, "processing")
            
            # 1. 알리익스프레스 상품 처리
            products = self.process_aliexpress_products(job_data)
            
            if not products:
                raise Exception("알리익스프레스 상품 처리 실패")
                
            # 2. Gemini로 콘텐츠 생성 (사용자 상세 정보 활용)
            content = self.generate_content_with_gemini(job_data, products)
            
            if not content:
                raise Exception("콘텐츠 생성 실패")
                
            # 3. 워드프레스에 발행
            post_url = self.post_to_wordpress(job_data, content)
            
            if post_url:
                # 성공 처리
                self.update_job_status(job_id, "completed")
                self.log_message(f"[✅] 작업 완료: {title} -> {post_url}")
                
                # 성공 알림에 사용자 정보 활용 여부 표시
                success_msg = f"✅ 알리익스프레스 자동화 완료\n제목: {title}\nURL: {post_url}\n상품 수: {len(products)}개"
                if has_user_details:
                    success_msg += "\n🎯 사용자 맞춤 정보 반영"
                
                self.send_telegram_notification(success_msg)
                return True
            else:
                raise Exception("워드프레스 발행 실패")
                
        except Exception as e:
            # 실패 처리
            error_msg = str(e)
            self.update_job_status(job_id, "failed", error_msg)
            self.log_message(f"[❌] 작업 실패: {title} - {error_msg}")
            self.send_telegram_notification(
                f"❌ 알리익스프레스 자동화 실패\n"
                f"제목: {title}\n"
                f"오류: {error_msg}"
            )
            return False
            
    def run(self):
        """메인 실행 함수"""
        print("=" * 60)
        print("🌏 알리익스프레스 전용 어필리에이트 자동화 시스템 시작 (사용자 상세 정보 지원)")
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
        print("🌏 알리익스프레스 전용 어필리에이트 자동화 시스템 종료")
        print("=" * 60)


if __name__ == "__main__":
    try:
        system = AliExpressPostingSystem()
        system.run()
    except KeyboardInterrupt:
        print("\n[⏹️] 사용자에 의해 프로그램이 중단되었습니다.")
    except Exception as e:
        print(f"\n[❌] 예상치 못한 오류가 발생했습니다: {e}")
        print(traceback.format_exc())