#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
알리익스프레스 전용 어필리에이트 상품 자동 등록 시스템 (4가지 프롬프트 템플릿 시스템 + 즉시 발행 지원)
키워드 입력 → 알리익스프레스 API → AI 콘텐츠 생성 → 워드프레스 자동 발행

작성자: Claude AI
날짜: 2025-07-24
버전: v5.5 (알리익스프레스 '관련 상품 더보기' 버튼 자동 삽입 기능 추가)
"""

import os
import sys
import json
import time
import requests
import traceback
import argparse
import re
import gc  # 가비지 컬렉션 추가
import subprocess
import glob
import google.generativeai as genai
from datetime import datetime
from dotenv import load_dotenv
from prompt_templates import PromptTemplates

# 🔧 AliExpress SDK 로그 경로 수정 (import 전에 환경변수 설정)
os.environ['IOP_LOG_PATH'] = '/var/www/logs'
os.makedirs('/var/www/logs', exist_ok=True)

# 알리익스프레스 SDK 경로 추가
sys.path.append('/home/novacents/aliexpress-sdk')
import iop

# ##############################################################################
# 사용자 설정
# ##############################################################################
MAX_POSTS_PER_RUN = 1
QUEUE_FILE = "/var/www/product_queue.json"  # 레거시 큐 파일 (백업용)
QUEUES_DIR = "/var/www/queues"  # 새로운 분할 큐 디렉토리
LOG_FILE = "/var/www/auto_post_products.log"
PUBLISHED_LOG_FILE = "/var/www/published_log.txt"
POST_DELAY_SECONDS = 30
# ##############################################################################

def load_aliexpress_keyword_links():
    """알리익스프레스 키워드 링크 매핑 파일 로드"""
    keyword_links_path = '/var/www/novacents/tools/aliexpress_keyword_links.json'
    try:
        if os.path.exists(keyword_links_path):
            with open(keyword_links_path, 'r', encoding='utf-8') as f:
                keyword_links = json.load(f)
                print(f"[✅] 알리익스프레스 키워드 링크 매핑 로드 성공: {len(keyword_links)}개")
                return keyword_links
        else:
            print(f"[⚠️] 알리익스프레스 키워드 링크 파일이 없습니다: {keyword_links_path}")
    except Exception as e:
        print(f"[❌] 키워드 링크 파일 로드 실패: {e}")
    return {}

def normalize_url(url):
    """URL 정규화 함수 - 매칭 정확도 향상을 위해"""
    if not url:
        return ""
    
    # URL에서 상품 ID만 추출
    patterns = [
        r'/item/(\d+)\.html',
        r'/item/(\d+)$',
        r'productId=(\d+)',
        r'/(\d+)\.html',
    ]
    
    for pattern in patterns:
        match = re.search(pattern, url)
        if match:
            return match.group(1)
    
    # 패턴이 맞지 않으면 쿼리 파라미터 제거한 URL 반환
    return url.split('?')[0].strip()

class AliExpressPostingSystem:
    def __init__(self):
        self.config = None
        self.gemini_model = None
        self.aliexpress_client = None
        self.immediate_mode = False

    def extract_thumbnail_url_safely(self, job_data):
        """큐 파일에서 썸네일 URL 안전하게 추출"""
        
        # 디버깅 정보 출력
        print(f"[🔍 DEBUG] 큐 데이터 키 목록: {list(job_data.keys())}")
        print(f"[🔍 DEBUG] has_thumbnail_url: {job_data.get('has_thumbnail_url')}")
        
        # 1차: 직접 접근
        thumbnail_url = job_data.get('thumbnail_url')
        if thumbnail_url and thumbnail_url.strip():
            print(f"[✅] 썸네일 URL 추출 성공 (직접): {thumbnail_url}")
            return thumbnail_url.strip()
        
        # 2차: 첫 번째 상품의 이미지 URL 사용
        try:
            keywords = job_data.get('keywords', [])
            if keywords and len(keywords) > 0:
                first_keyword = keywords[0]
                products_data = first_keyword.get('products_data', [])
                if products_data and len(products_data) > 0:
                    analysis_data = products_data[0].get('analysis_data', {})
                    image_url = analysis_data.get('image_url')
                    if image_url and image_url.strip():
                        print(f"[✅] 썸네일 URL 추출 성공 (상품 이미지): {image_url}")
                        return image_url.strip()
        except Exception as e:
            print(f"[⚠️] 상품 이미지 URL 추출 중 오류: {e}")
        
        print(f"[❌] 썸네일 URL 추출 실패")
        return None
        
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
            
            # 워드프레스 API
            "wp_url": os.getenv("WP_URL"),
            "wp_api_base": os.getenv("WP_API_BASE"),
            "wp_user": os.getenv("WP_USER"),
            "wp_app_pass": os.getenv("WP_APP_PASS"),
        }
        
        # 필수 설정 확인
        required_keys = ["aliexpress_app_key", "aliexpress_app_secret", "gemini_api_key", 
                        "wp_url", "wp_api_base", "wp_user", "wp_app_pass"]
        
        missing_keys = [key for key in required_keys if not self.config.get(key)]
        if missing_keys:
            print(f"[❌] 필수 환경 변수가 누락되었습니다: {missing_keys}")
            return False
        
        # Gemini API 초기화
        try:
            genai.configure(api_key=self.config["gemini_api_key"])
            self.gemini_model = genai.GenerativeModel('gemini-1.5-pro-latest')
            print("[✅] Gemini API 설정 완료")
        except Exception as e:
            print(f"[❌] Gemini API 설정 실패: {e}")
            return False
        
        # 알리익스프레스 클라이언트 초기화
        try:
            self.aliexpress_client = iop.IopClient(
                'https://api-sg.aliexpress.com/sync',
                self.config["aliexpress_app_key"],
                self.config["aliexpress_app_secret"]
            )
            print("[✅] 알리익스프레스 API 클라이언트 초기화 완료")
        except Exception as e:
            print(f"[❌] 알리익스프레스 API 클라이언트 초기화 실패: {e}")
            return False
        
        print("[✅] 모든 설정 로드 완료")
        return True

    def call_php_function(self, function_name, *args):
        """PHP 함수 호출 (분할 큐 시스템용)"""
        try:
            # PHP 스크립트 생성
            php_code = f"""<?php
require_once '/var/www/novacents/tools/queue_utils.php';

$result = {function_name}({', '.join(f'"{arg}"' if isinstance(arg, str) else str(arg) for arg in args)});
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>"""
            
            # 임시 PHP 파일 생성
            temp_file = f"/tmp/php_call_{int(time.time())}.php"
            with open(temp_file, 'w', encoding='utf-8') as f:
                f.write(php_code)
            
            # PHP 실행
            result = subprocess.run(['php', temp_file], capture_output=True, text=True, encoding='utf-8')
            
            # 임시 파일 삭제
            os.unlink(temp_file)
            
            if result.returncode == 0:
                try:
                    return json.loads(result.stdout)
                except json.JSONDecodeError as e:
                    print(f"[❌] PHP 응답 JSON 파싱 실패: {e}")
                    print(f"[DEBUG] PHP 출력: {result.stdout}")
                    return None
            else:
                print(f"[❌] PHP 실행 실패: {result.stderr}")
                return None
                
        except Exception as e:
            print(f"[❌] PHP 함수 호출 중 오류: {e}")
            return None

    def load_queue_split(self):
        """분할 큐 시스템에서 pending 작업 로드"""
        try:
            print("[📋] 분할 큐 시스템에서 대기 중인 작업을 로드합니다...")
            
            # PHP 함수 호출: get_pending_queues_split($limit)
            pending_jobs = self.call_php_function('get_pending_queues_split', MAX_POSTS_PER_RUN)
            
            if pending_jobs is None:
                print("[❌] 분할 큐 로드 실패")
                return []
            
            if not isinstance(pending_jobs, list):
                print(f"[❌] 예상치 못한 응답 형태: {type(pending_jobs)}")
                return []
            
            print(f"[📋] 분할 큐에서 {len(pending_jobs)}개의 대기 중인 작업을 발견했습니다.")
            return pending_jobs
            
        except Exception as e:
            print(f"[❌] 분할 큐 로드 중 오류 발생: {e}")
            return []
        finally:
            gc.collect()
            
    def update_queue_status_split(self, queue_id, status, error_message=None):
        """분할 큐 시스템에서 작업 상태 업데이트"""
        try:
            # PHP 함수 호출: update_queue_status_split($queue_id, $new_status, $error_message)
            result = self.call_php_function('update_queue_status_split', queue_id, status, error_message)
            
            if result:
                print(f"[✅] 분할 큐 상태 업데이트 성공: {queue_id} -> {status}")
                return True
            else:
                print(f"[❌] 분할 큐 상태 업데이트 실패: {queue_id}")
                return False
                
        except Exception as e:
            print(f"[❌] 분할 큐 상태 업데이트 중 오류: {e}")
            return False
            
    def remove_queue_split(self, queue_id):
        """분할 큐 시스템에서 작업 제거 (즉시 발행 등)"""
        try:
            # PHP 함수 호출: remove_queue_split($queue_id)
            result = self.call_php_function('remove_queue_split', queue_id)
            
            if result:
                print(f"[🗑️] 분할 큐에서 작업 제거 성공: {queue_id}")
                return True
            else:
                print(f"[❌] 분할 큐 작업 제거 실패: {queue_id}")
                return False
                
        except Exception as e:
            print(f"[❌] 분할 큐 작업 제거 중 오류: {e}")
            return False

    def load_queue(self):
        """레거시 큐 파일에서 pending 작업 로드 (호환성)"""
        try:
            if not os.path.exists(QUEUE_FILE):
                print(f"[⚠️] 레거시 큐 파일이 없습니다. 분할 시스템을 사용합니다.")
                return self.load_queue_split()
                
            with open(QUEUE_FILE, "r", encoding="utf-8") as f:
                queue_data = json.load(f)
                
            # pending 상태인 작업만 필터링
            pending_jobs = []
            for job in queue_data:
                if job.get("status") == "pending":
                    pending_jobs.append(job)
                    
            print(f"[📋] 레거시 큐에서 {len(pending_jobs)}개의 대기 중인 작업을 발견했습니다.")
            return pending_jobs[:MAX_POSTS_PER_RUN]
            
        except Exception as e:
            print(f"[❌] 레거시 큐 로드 중 오류 발생: {e}")
            return self.load_queue_split()
            
    def save_queue(self, queue_data):
        """레거시 큐 파일 저장 (호환성)"""
        try:
            with open(QUEUE_FILE, "w", encoding="utf-8") as f:
                json.dump(queue_data, f, ensure_ascii=False, indent=2)
            print(f"[✅] 레거시 큐 파일 저장 완료: {len(queue_data)}개 작업")
        except Exception as e:
            print(f"[❌] 레거시 큐 파일 저장 실패: {e}")

    def update_job_status(self, queue_data, job_id, status, error_message=None):
        """레거시 큐에서 작업 상태 업데이트 (호환성)"""
        try:
            for job in queue_data:
                if job.get("queue_id") == job_id:
                    job["status"] = status
                    job["processed_at"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                    if error_message:
                        job["last_error"] = error_message
                        job["attempts"] = job.get("attempts", 0) + 1
                    break
            
            self.save_queue(queue_data)
            print(f"[📝] 레거시 큐에서 작업 상태 업데이트: {job_id} -> {status}")
            
        except Exception as e:
            print(f"[❌] 레거시 큐 상태 업데이트 실패: {e}")

    def remove_completed_job(self, queue_data, job_id):
        """레거시 큐에서 완료된 작업 제거 (호환성)"""
        try:
            original_count = len(queue_data)
            
            # 해당 job_id를 가진 작업 제거
            queue_data = [job for job in queue_data if job.get("queue_id") != job_id]
            
            self.save_queue(queue_data)
            print(f"[🗑️] 레거시 큐에서 작업 ID {job_id}를 제거했습니다.")
            
            # 메모리 정리
            removed_count = original_count - len(queue_data)
            if removed_count > 0:
                gc.collect()
            
        except Exception as e:
            print(f"[❌] 레거시 큐 작업 제거 실패: {e}")

    def analyze_product_with_aliexpress_api(self, url):
        """알리익스프레스 API를 사용하여 상품 분석"""
        try:
            print(f"[🔍] 알리익스프레스 상품 분석 시작: {url}")
            
            # URL에서 상품 ID 추출
            product_id_match = re.search(r'/item/(\d+)\.html', url)
            if not product_id_match:
                return {"success": False, "message": "유효한 알리익스프레스 상품 URL이 아닙니다."}
            
            product_id = product_id_match.group(1)
            
            # 알리익스프레스 API 요청
            request = iop.IopRequest('/aliexpress/affiliate/productdetail/get', 'GET')
            request.add_api_param('product_ids', product_id)
            request.add_api_param('fields', 'product_id,product_title,product_main_image_url,target_sale_price,target_original_price,evaluate_rate,lastest_volume,first_level_category_name,promotion_link')
            request.add_api_param('target_currency', 'KRW')
            request.add_api_param('target_language', 'ko')
            request.add_api_param('country', 'KR')
            
            response = self.aliexpress_client.execute(request)
            
            if response.type == "nil" and response.body:
                response_dict = json.loads(response.body)
                
                if 'aliexpress_affiliate_productdetail_get_response' in response_dict:
                    result = response_dict['aliexpress_affiliate_productdetail_get_response']
                    
                    if 'resp_result' in result and result['resp_result']['result']['products']['product']:
                        product_data = result['resp_result']['result']['products']['product'][0]
                        
                        # 상품 정보 추출
                        product_info = {
                            "success": True,
                            "platform": "알리익스프레스",
                            "product_id": product_data.get('product_id', ''),
                            "title": product_data.get('product_title', '상품명 없음'),
                            "price": f"₩{int(float(product_data.get('target_sale_price', '0'))):,}",
                            "original_price": f"₩{int(float(product_data.get('target_original_price', '0'))):,}",
                            "image_url": product_data.get('product_main_image_url', ''),
                            "original_url": url,
                            "affiliate_link": product_data.get('promotion_link', url),
                            "rating": f"{product_data.get('evaluate_rate', '0')}%",
                            "sales_volume": f"{product_data.get('lastest_volume', '0')}개 판매",
                            "category": product_data.get('first_level_category_name', '카테고리 정보 없음'),
                            "analyzed_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                        }
                        
                        print(f"[✅] 상품 분석 성공: {product_info['title']}")
                        return product_info
            
            return {"success": False, "message": "알리익스프레스 API 응답에서 상품 정보를 찾을 수 없습니다."}
            
        except Exception as e:
            error_msg = f"알리익스프레스 상품 분석 중 오류: {str(e)}"
            print(f"[❌] {error_msg}")
            return {"success": False, "message": error_msg}

    def generate_content_with_template(self, job_data):
        """선택된 프롬프트 템플릿으로 콘텐츠 생성"""
        try:
            # 작업 데이터에서 필요한 정보 추출
            title = job_data.get("title", "")
            category_name = job_data.get("category_name", "")
            prompt_type = job_data.get("prompt_type", "essential_items")
            keywords = job_data.get("keywords", [])
            user_details = job_data.get("user_details", {})
            
            print(f"[🤖] AI 콘텐츠 생성 시작 - 템플릿: {prompt_type}")
            
            # 프롬프트 템플릿 시스템 초기화
            template_system = PromptTemplates(
                title=title,
                category_name=category_name,
                keywords=keywords,
                user_details=user_details
            )
            
            # 선택된 템플릿으로 프롬프트 생성
            prompt = template_system.get_prompt(prompt_type)
            
            if not prompt:
                return {"success": False, "message": f"지원하지 않는 프롬프트 타입: {prompt_type}"}
            
            # Gemini API로 콘텐츠 생성
            response = self.gemini_model.generate_content(
                prompt,
                generation_config={
                    "temperature": 0.8,
                    "top_p": 0.9,
                    "top_k": 40,
                    "max_output_tokens": 8192,
                }
            )
            
            if response and response.text:
                content = response.text.strip()
                
                # 콘텐츠 후처리
                content = self.post_process_content(content, keywords)
                
                return {
                    "success": True,
                    "content": content,
                    "template_used": prompt_type,
                    "generated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                }
            else:
                return {"success": False, "message": "Gemini API에서 응답을 받지 못했습니다."}
                
        except Exception as e:
            error_msg = f"콘텐츠 생성 중 오류: {str(e)}"
            print(f"[❌] {error_msg}")
            return {"success": False, "message": error_msg}

    def post_process_content(self, content, keywords):
        """생성된 콘텐츠 후처리"""
        try:
            # 키워드별 상품 정보로 상품 링크 버튼 생성
            for keyword_data in keywords:
                keyword_name = keyword_data.get("name", "")
                products_data = keyword_data.get("products_data", [])
                
                if products_data:
                    # 상품 정보가 있는 경우 상품 카드 HTML 생성
                    product_html = self.generate_product_cards_html(products_data)
                    
                    # 키워드 관련 텍스트 뒤에 상품 카드 삽입
                    keyword_pattern = f"({re.escape(keyword_name)})"
                    replacement = f"\\1\n\n{product_html}\n"
                    content = re.sub(keyword_pattern, replacement, content, count=1)
            
            # 알리익스프레스 키워드 링크 추가
            keyword_links = load_aliexpress_keyword_links()
            if keyword_links:
                content = self.add_aliexpress_keyword_links(content, keyword_links)
            
            return content
            
        except Exception as e:
            print(f"[⚠️] 콘텐츠 후처리 중 오류: {e}")
            return content

    def generate_product_cards_html(self, products_data):
        """상품 데이터를 기반으로 상품 카드 HTML 생성"""
        try:
            cards_html = '<div style="display: grid; gap: 20px; margin: 30px 0;">\n'
            
            for product in products_data:
                analysis_data = product.get("analysis_data", {})
                if not analysis_data:
                    continue
                
                title = analysis_data.get("title", "상품명 없음")
                price = analysis_data.get("price", "가격 정보 없음")
                image_url = analysis_data.get("image_url", "")
                affiliate_link = analysis_data.get("affiliate_link", product.get("url", ""))
                rating = analysis_data.get("rating_display", analysis_data.get("rating", "평점 정보 없음"))
                sales_vol = analysis_data.get("lastest_volume", "판매량 정보 없음")
                
                card_html = f'''
<div style="border: 2px solid #eee; padding: 25px; border-radius: 12px; background: #f9f9f9; box-shadow: 0 4px 8px rgba(0,0,0,0.1); margin-bottom: 25px;">
    <div style="display: grid; grid-template-columns: 300px 1fr; gap: 25px; align-items: start;">
        <div style="text-align: center;">
            <img src="{image_url}" alt="{title}" style="width: 100%; max-width: 300px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
        </div>
        <div style="display: flex; flex-direction: column; gap: 15px;">
            <div style="margin-bottom: 10px; text-align: center;">
                <img src="https://novacents.com/tools/images/Ali_black_logo.webp" alt="AliExpress" style="width: 200px; height: 48px; object-fit: contain;">
            </div>
            <h3 style="color: #1c1c1c; margin: 0 0 15px 0; font-size: 18px; font-weight: 600; line-height: 1.4; word-break: keep-all; text-align: center;">{title}</h3>
            <div style="background: linear-gradient(135deg, #e62e04 0%, #ff9900 100%); color: white; padding: 12px 25px; border-radius: 8px; font-size: 28px; font-weight: 700; text-align: center; margin-bottom: 15px; box-shadow: 0 4px 12px rgba(230,46,4,0.3);">
                <strong>{price}</strong>
            </div>
            <div style="color: #1c1c1c; font-size: 16px; display: flex; align-items: center; gap: 8px; margin-bottom: 10px; justify-content: center;">
                <span style="color: #ff9900;">{rating}</span>
            </div>
            <p style="color: #1c1c1c; font-size: 16px; margin: 0 0 10px 0; text-align: center;"><strong>📦 {sales_vol}</strong></p>
        </div>
    </div>
    <div style="text-align: center; margin-top: 25px; width: 100%;">
        <a href="{affiliate_link}" target="_blank" rel="nofollow" style="text-decoration: none;">
            <picture>
                <source media="(max-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-mobile.png">
                <img src="https://novacents.com/tools/images/aliexpress-button-pc.png" alt="알리익스프레스에서 구매하기" style="max-width: 100%; height: auto; cursor: pointer;">
            </picture>
        </a>
    </div>
</div>

<style>
@media (max-width: 1600px) {{
    div[style*="grid-template-columns: 300px 1fr"] {{
        display: block !important;
        grid-template-columns: none !important;
        gap: 15px !important;
    }}
    img[style*="max-width: 300px"] {{
        width: 90% !important;
        max-width: none !important;
        margin-bottom: 20px !important;
    }}
    div[style*="gap: 15px"] {{
        gap: 10px !important;
    }}
    div[style*="text-align: center"] img[alt="AliExpress"] {{
        display: block;
        margin: 0 !important;
    }}
    div[style*="text-align: center"]:has(img[alt="AliExpress"]) {{
        text-align: left !important;
        margin-bottom: 8px !important;
    }}
    h3[style*="text-align: center"] {{
        text-align: left !important;
        font-size: 16px !important;
        margin-bottom: 8px !important;
    }}
    div[style*="font-size: 28px"] {{
        font-size: 24px !important;
        padding: 10px 20px !important;
        margin-bottom: 8px !important;
    }}
    div[style*="justify-content: center"][style*="gap: 8px"] {{
        justify-content: flex-start !important;
        font-size: 14px !important;
        margin-bottom: 8px !important;
        gap: 6px !important;
    }}
    p[style*="text-align: center"] {{
        text-align: left !important;
        font-size: 14px !important;
        margin-bottom: 8px !important;
    }}
    div[style*="margin-top: 25px"] {{
        margin-top: 15px !important;
    }}
}}
@media (max-width: 480px) {{
    img[style*="width: 90%"] {{
        width: 100% !important;
    }}
    h3[style*="font-size: 16px"] {{
        font-size: 14px !important;
    }}
    div[style*="font-size: 24px"] {{
        font-size: 20px !important;
    }}
}}
</style>
'''
                cards_html += card_html
            
            cards_html += '</div>\n'
            return cards_html
            
        except Exception as e:
            print(f"[⚠️] 상품 카드 HTML 생성 중 오류: {e}")
            return ""

    def add_aliexpress_keyword_links(self, content, keyword_links):
        """콘텐츠에 알리익스프레스 키워드 링크 추가"""
        try:
            # 키워드별로 링크 추가
            for keyword, link_url in keyword_links.items():
                # 키워드가 콘텐츠에 있는 경우 링크로 변환 (첫 번째 발견된 것만)
                pattern = r'\b' + re.escape(keyword) + r'\b'
                replacement = f'<a href="{link_url}" target="_blank" rel="nofollow" style="color: #e62e04; font-weight: bold; text-decoration: underline;">{keyword}</a>'
                content = re.sub(pattern, replacement, content, count=1)
            
            return content
            
        except Exception as e:
            print(f"[⚠️] 키워드 링크 추가 중 오류: {e}")
            return content

    def generate_seo_metadata(self, title, content, keywords):
        """SEO 메타데이터 생성"""
        try:
            # 키워드 리스트 생성
            keyword_names = []
            for keyword_data in keywords:
                if isinstance(keyword_data, dict):
                    keyword_names.append(keyword_data.get("name", ""))
                else:
                    keyword_names.append(str(keyword_data))
            
            # 포커스 키프레이즈 생성 (첫 번째 키워드 활용)
            focus_keyphrase = f"{keyword_names[0]} 추천" if keyword_names else "알리익스프레스 상품 추천"
            
            # 메타 설명 생성
            meta_description = f"{title} - {', '.join(keyword_names[:3])} 등 엄선된 상품들을 알리익스프레스에서 만나보세요. 가격 비교부터 리뷰까지 한번에!"[:155]
            
            # 태그 생성
            tags = keyword_names[:8]  # 최대 8개 태그
            tags.extend(["알리익스프레스", "해외직구", "상품추천"])
            tags = list(set(tags))[:10]  # 중복 제거 후 최대 10개
            
            return {
                "focus_keyphrase": focus_keyphrase,
                "meta_description": meta_description,
                "tags": tags
            }
            
        except Exception as e:
            print(f"[⚠️] SEO 메타데이터 생성 중 오류: {e}")
            return {
                "focus_keyphrase": "알리익스프레스 상품 추천",
                "meta_description": title[:155],
                "tags": ["알리익스프레스", "해외직구", "상품추천"]
            }

    def ensure_tags_exist(self, tags):
        """워드프레스에 태그가 존재하는지 확인하고 없으면 생성"""
        try:
            auth = (self.config["wp_user"], self.config["wp_app_pass"])
            headers = {"Content-Type": "application/json"}
            tag_ids = []
            
            for tag_name in tags:
                if not tag_name.strip():
                    continue
                    
                # 기존 태그 검색
                search_url = f"{self.config['wp_api_base']}/tags"
                search_params = {"search": tag_name.strip()}
                
                response = requests.get(search_url, auth=auth, params=search_params, headers=headers, timeout=10)
                
                if response.status_code == 200:
                    existing_tags = response.json()
                    
                    # 정확히 일치하는 태그 찾기
                    found_tag = None
                    for tag in existing_tags:
                        if tag.get("name", "").lower() == tag_name.strip().lower():
                            found_tag = tag
                            break
                    
                    if found_tag:
                        tag_ids.append(found_tag["id"])
                        print(f"[📌] 기존 태그 사용: {tag_name} (ID: {found_tag['id']})")
                    else:
                        # 태그 생성
                        create_url = f"{self.config['wp_api_base']}/tags"
                        create_data = {"name": tag_name.strip()}
                        
                        create_response = requests.post(create_url, auth=auth, json=create_data, headers=headers, timeout=10)
                        
                        if create_response.status_code == 201:
                            new_tag = create_response.json()
                            tag_ids.append(new_tag["id"])
                            print(f"[🆕] 새 태그 생성: {tag_name} (ID: {new_tag['id']})")
                        else:
                            print(f"[⚠️] 태그 생성 실패: {tag_name}")
                
                # API 호출 간격
                time.sleep(0.5)
            
            return tag_ids
            
        except Exception as e:
            print(f"[⚠️] 태그 처리 중 오류: {e}")
            return []

    def publish_to_wordpress(self, job_data, content, seo_data):
        """워드프레스에 게시물 발행"""
        try:
            print(f"[🚀] 워드프레스 게시물 발행 시작...")
            
            # 태그 ID 확보
            tag_ids = self.ensure_tags_exist(seo_data["tags"])
            
            # 게시물 데이터 준비
            post_data = {
                "title": job_data.get("title", "제목 없음"),
                "content": content,
                "status": "publish",
                "categories": [job_data.get("category_id", 356)],  # 기본 카테고리: 스마트 리빙
                "tags": tag_ids,
                "slug": None,  # 워드프레스가 자동 생성
                "meta": {
                    "_yoast_wpseo_focuskw": seo_data["focus_keyphrase"],
                    "_yoast_wpseo_metadesc": seo_data["meta_description"]
                }
            }
            
            # 인증 설정
            auth = (self.config["wp_user"], self.config["wp_app_pass"])
            headers = {"Content-Type": "application/json"}
            
            # 1단계: 게시물 생성
            print(f"[⚙️] 1단계 - 게시물을 생성합니다...")
            
            # 응답 객체를 변수에 저장해서 메모리 관리
            response = requests.post(
                f"{self.config['wp_api_base']}/posts",
                auth=auth,
                json=post_data,
                headers=headers,
                timeout=30
            )
            
            if response.status_code == 201:
                post_info = response.json()
                post_id = post_info.get("id")
                post_url = post_info.get("link", "")
                print(f"[✅] 워드프레스 게시물 생성 성공! (ID: {post_id})")
                
                # 응답 객체 즉시 삭제
                del response
                del post_info
                
                # 2단계: FIFU 썸네일 설정 (auto_post_overseas.py와 동일한 방식)
                thumbnail_url = self.extract_thumbnail_url_safely(job_data)
                
                # 디버깅을 위한 로그 파일 작성
                debug_log_path = '/var/www/novacents/tools/logs/thumbnail_debug.log'
                try:
                    with open(debug_log_path, 'a', encoding='utf-8') as debug_file:
                        debug_file.write(f"\n========== [{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] 썸네일 디버깅 ==========\n")
                        debug_file.write(f"포스트 ID: {post_id}\n")
                        debug_file.write(f"포스트 제목: {job_data.get('title', 'N/A')}\n")
                        debug_file.write(f"thumbnail_url 값: {thumbnail_url}\n")
                        debug_file.write(f"job_data 키 목록: {list(job_data.keys())}\n")
                except Exception as e:
                    print(f"[⚠️] 디버그 로그 작성 실패: {e}")
                
                if thumbnail_url:
                    print(f"[⚙️] 2단계 - FIFU 썸네일을 설정합니다...")
                    fifu_payload = {"meta": {"_fifu_image_url": thumbnail_url}}
                    
                    try:
                        fifu_response = requests.post(
                            f"{self.config['wp_api_base']}/posts/{post_id}", 
                            auth=auth, 
                            json=fifu_payload, 
                            headers=headers, 
                            timeout=20
                        )
                        
                        # 디버깅 로그에 응답 기록
                        try:
                            with open(debug_log_path, 'a', encoding='utf-8') as debug_file:
                                debug_file.write(f"FIFU API 호출 완료\n")
                                debug_file.write(f"응답 상태 코드: {fifu_response.status_code}\n")
                                debug_file.write(f"응답 내용: {fifu_response.text[:500]}...\n")
                                debug_file.write(f"FIFU 설정 성공 여부: {'성공' if fifu_response.status_code in [200, 201] else '실패'}\n")
                        except:
                            pass
                        
                        print("[✅] FIFU 썸네일 설정 완료.")
                    except Exception as e:
                        print(f"[⚠️] FIFU 썸네일 설정 중 오류: {e}")
                        try:
                            with open(debug_log_path, 'a', encoding='utf-8') as debug_file:
                                debug_file.write(f"FIFU 오류 발생: {str(e)}\n")
                        except:
                            pass
                else:
                    print("[⚠️] 썸네일 URL이 없어 FIFU 설정을 건너뜁니다.")
                    try:
                        with open(debug_log_path, 'a', encoding='utf-8') as debug_file:
                            debug_file.write(f"썸네일 URL이 없음 - thumbnail_url 값: '{thumbnail_url}'\n")
                    except:
                        pass
                
                # 3단계: YoastSEO 메타데이터 설정 (auto_post_overseas.py 방식)
                print(f"[⚙️] 3단계 - Yoast SEO 메타데이터를 설정합니다...")
                try:
                    yoast_payload = {
                        "post_id": post_id,
                        "focus_keyphrase": seo_data["focus_keyphrase"],
                        "meta_description": seo_data["meta_description"]
                    }
                    
                    yoast_url = f"{self.config['wp_url'].rstrip('/')}/wp-json/my-api/v1/update-seo"
                    
                    yoast_response = requests.post(
                        yoast_url,
                        auth=auth,
                        json=yoast_payload,
                        headers=headers,
                        timeout=20
                    )
                    
                    if yoast_response.status_code == 200:
                        print("[✅] Yoast SEO 메타데이터 설정 완료.")
                    else:
                        print(f"[⚠️] Yoast SEO 설정 실패: {yoast_response.status_code}")
                        
                except Exception as e:
                    print(f"[⚠️] Yoast SEO 설정 중 오류: {e}")
                
                # 메모리 정리
                gc.collect()
                
                print(f"[🎉] 게시물 발행 완료!")
                print(f"[🔗] 게시물 URL: {post_url}")
                
                return {
                    "success": True,
                    "post_id": post_id,
                    "post_url": post_url,
                    "message": "게시물이 성공적으로 발행되었습니다."
                }
            else:
                error_msg = f"게시물 생성 실패: HTTP {response.status_code}"
                print(f"[❌] {error_msg}")
                try:
                    error_details = response.json()
                    print(f"[DEBUG] 오류 상세: {error_details}")
                except:
                    print(f"[DEBUG] 응답 텍스트: {response.text[:500]}")
                
                return {"success": False, "message": error_msg}
                
        except Exception as e:
            error_msg = f"워드프레스 발행 중 오류: {str(e)}"
            print(f"[❌] {error_msg}")
            traceback.print_exc()
            return {"success": False, "message": error_msg}

    def process_single_job(self, job_data):
        """단일 작업 처리"""
        try:
            queue_id = job_data.get("queue_id", "unknown")
            title = job_data.get("title", "제목 없음")
            
            print(f"\n{'='*60}")
            print(f"[🎯] 작업 처리 시작: {title}")
            print(f"[🆔] 큐 ID: {queue_id}")
            print(f"{'='*60}")
            
            # 작업 상태를 processing으로 변경
            if not self.immediate_mode:
                self.update_queue_status_split(queue_id, "processing")
            
            # 1단계: 콘텐츠 생성
            print(f"[📝] 1단계: AI 콘텐츠 생성")
            content_result = self.generate_content_with_template(job_data)
            
            if not content_result.get("success"):
                error_msg = content_result.get("message", "콘텐츠 생성 실패")
                print(f"[❌] 콘텐츠 생성 실패: {error_msg}")
                
                if not self.immediate_mode:
                    self.update_queue_status_split(queue_id, "failed", error_msg)
                return {"success": False, "message": error_msg}
            
            content = content_result["content"]
            print(f"[✅] 콘텐츠 생성 완료 ({len(content)}자)")
            
            # 2단계: SEO 메타데이터 생성
            print(f"[🔍] 2단계: SEO 메타데이터 생성")
            seo_data = self.generate_seo_metadata(
                job_data.get("title", ""),
                content,
                job_data.get("keywords", [])
            )
            print(f"[✅] SEO 메타데이터 생성 완료")
            
            # 3단계: 워드프레스 발행
            print(f"[🚀] 3단계: 워드프레스 발행")
            publish_result = self.publish_to_wordpress(job_data, content, seo_data)
            
            if publish_result.get("success"):
                print(f"[🎉] 작업 완료 성공!")
                print(f"[🔗] 게시물 URL: {publish_result.get('post_url', 'N/A')}")
                
                # 작업 완료 처리
                if self.immediate_mode:
                    # 즉시 발행 모드: 큐에서 제거
                    self.remove_queue_split(queue_id)
                    print(f"[🗑️] 즉시 발행 완료로 큐에서 제거됨")
                else:
                    # 일반 모드: completed 상태로 변경
                    self.update_queue_status_split(queue_id, "completed")
                    print(f"[✅] 큐 상태 업데이트: completed")
                
                return {"success": True, "post_url": publish_result.get("post_url", "")}
            else:
                error_msg = publish_result.get("message", "워드프레스 발행 실패")
                print(f"[❌] 워드프레스 발행 실패: {error_msg}")
                
                if not self.immediate_mode:
                    self.update_queue_status_split(queue_id, "failed", error_msg)
                return {"success": False, "message": error_msg}
                
        except Exception as e:
            error_msg = f"작업 처리 중 예상치 못한 오류: {str(e)}"
            print(f"[❌] {error_msg}")
            traceback.print_exc()
            
            # 오류 발생 시 상태 업데이트
            if not self.immediate_mode:
                queue_id = job_data.get("queue_id", "unknown")
                self.update_queue_status_split(queue_id, "failed", error_msg)
            
            return {"success": False, "message": error_msg}

    def run_batch_processing(self):
        """배치 처리 실행 (분할 큐 시스템 사용)"""
        try:
            print(f"\n{'='*80}")
            print(f"{'🚀 알리익스프레스 자동 포스팅 시스템 시작 🚀':^80}")
            print(f"{'='*80}")
            print(f"⏰ 시작 시간: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"📊 최대 처리량: {MAX_POSTS_PER_RUN}개")
            print(f"🔄 작업 간격: {POST_DELAY_SECONDS}초")
            print(f"{'='*80}")
            
            # 대기 중인 작업 로드
            pending_jobs = self.load_queue_split()
            
            if not pending_jobs:
                print("[📋] 처리할 작업이 없습니다.")
                return
            
            print(f"[📋] {len(pending_jobs)}개의 작업을 처리합니다.")
            
            successful_count = 0
            failed_count = 0
            
            for i, job_data in enumerate(pending_jobs):
                print(f"\n[📊] 진행률: {i + 1}/{len(pending_jobs)}")
                
                # 작업 처리
                result = self.process_single_job(job_data)
                
                if result.get("success"):
                    successful_count += 1
                    print(f"[✅] 작업 {i + 1} 성공!")
                else:
                    failed_count += 1
                    print(f"[❌] 작업 {i + 1} 실패: {result.get('message', 'Unknown error')}")
                
                # 다음 작업까지 대기 (마지막 작업이 아닌 경우)
                if i < len(pending_jobs) - 1:
                    print(f"[⏳] {POST_DELAY_SECONDS}초 대기 중...")
                    time.sleep(POST_DELAY_SECONDS)
                
                # 메모리 정리
                gc.collect()
            
            # 결과 요약
            print(f"\n{'='*80}")
            print(f"{'📊 처리 결과 요약':^80}")
            print(f"{'='*80}")
            print(f"✅ 성공: {successful_count}개")
            print(f"❌ 실패: {failed_count}개")
            print(f"📊 총 처리: {successful_count + failed_count}개")
            print(f"⏰ 완료 시간: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"{'='*80}")
            
        except Exception as e:
            print(f"[❌] 배치 처리 중 오류 발생: {e}")
            traceback.print_exc()

    def run_immediate_processing(self, queue_id):
        """즉시 발행 처리"""
        try:
            print(f"\n{'='*60}")
            print(f"{'🚀 즉시 발행 모드 🚀':^60}")
            print(f"{'='*60}")
            print(f"🆔 큐 ID: {queue_id}")
            print(f"⏰ 시작 시간: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"{'='*60}")
            
            self.immediate_mode = True
            
            # 특정 큐 작업 로드
            job_data = self.call_php_function('load_queue_split', queue_id)
            
            if not job_data:
                print(f"[❌] 큐 ID {queue_id}를 찾을 수 없습니다.")
                return
            
            # 작업 처리
            result = self.process_single_job(job_data)
            
            if result.get("success"):
                print(f"[🎉] 즉시 발행 성공!")
                print(f"[🔗] 게시물 URL: {result.get('post_url', 'N/A')}")
            else:
                print(f"[❌] 즉시 발행 실패: {result.get('message', 'Unknown error')}")
            
            print(f"⏰ 완료 시간: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
            
        except Exception as e:
            print(f"[❌] 즉시 발행 중 오류 발생: {e}")
            traceback.print_exc()

def main():
    """메인 함수"""
    parser = argparse.ArgumentParser(description="알리익스프레스 자동 포스팅 시스템")
    parser.add_argument("--immediate", type=str, help="즉시 발행할 큐 ID")
    parser.add_argument("--batch", action="store_true", help="배치 처리 모드")
    
    args = parser.parse_args()
    
    # 시스템 초기화
    system = AliExpressPostingSystem()
    
    if not system.load_configuration():
        print("[❌] 시스템 초기화 실패")
        sys.exit(1)
    
    try:
        if args.immediate:
            # 즉시 발행 모드
            system.run_immediate_processing(args.immediate)
        elif args.batch:
            # 배치 처리 모드
            system.run_batch_processing()
        else:
            # 기본값: 배치 처리
            system.run_batch_processing()
            
    except KeyboardInterrupt:
        print("\n[⚠️] 사용자에 의해 중단되었습니다.")
    except Exception as e:
        print(f"[❌] 시스템 실행 중 오류: {e}")
        traceback.print_exc()
    finally:
        print("[👋] 알리익스프레스 자동 포스팅 시스템을 종료합니다.")

if __name__ == "__main__":
    main()