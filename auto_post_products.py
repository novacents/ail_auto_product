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
            # 응답 객체 명시적 삭제
            del response
        except Exception as e:
            print(f"[❌] 텔레그램 알림 전송 중 오류: {e}")
        finally:
            # 메모리 정리
            if 'data' in locals():
                del data
            
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
        
    def call_php_function(self, function_name, *args):
        """PHP queue_utils.php 함수 호출"""
        try:
            # PHP 스크립트 경로
            php_script = "/var/www/queue_utils.php"
            
            if not os.path.exists(php_script):
                print(f"[❌] PHP 스크립트를 찾을 수 없습니다: {php_script}")
                return None
            
            # PHP 함수 호출을 위한 wrapper 스크립트 생성
            wrapper_code = f"""<?php
require_once '{php_script}';

$function_name = '{function_name}';
$args = json_decode('{json.dumps(list(args), ensure_ascii=False)}', true);

try {{
    $result = call_user_func_array($function_name, $args);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}} catch (Exception $e) {{
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}}
?>"""
            
            # 임시 파일에 wrapper 스크립트 저장
            temp_file = f"/tmp/php_wrapper_{int(time.time())}.php"
            with open(temp_file, 'w', encoding='utf-8') as f:
                f.write(wrapper_code)
            
            # PHP 실행
            result = subprocess.run(
                ['php', temp_file],
                capture_output=True,
                text=True,
                timeout=30
            )
            
            # 임시 파일 삭제
            os.unlink(temp_file)
            
            if result.returncode == 0:
                response = json.loads(result.stdout)
                if isinstance(response, dict) and 'error' in response:
                    print(f"[❌] PHP 함수 오류: {response['error']}")
                    return None
                return response
            else:
                print(f"[❌] PHP 실행 오류: {result.stderr}")
                return None
                
        except Exception as e:
            print(f"[❌] PHP 함수 호출 중 오류: {e}")
            return None
        finally:
            # 메모리 정리
            gc.collect()
        
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
                print(f"[✅] 큐 상태 업데이트 성공: {queue_id} -> {status}")
                return True
            else:
                print(f"[❌] 큐 상태 업데이트 실패: {queue_id}")
                return False
                
        except Exception as e:
            print(f"[❌] 큐 상태 업데이트 중 오류: {e}")
            return False
        finally:
            gc.collect()
    
    def remove_job_from_queue_split(self, queue_id):
        """분할 큐 시스템에서 즉시 발행 후 작업 제거"""
        try:
            # pending에서 completed로 이동
            success = self.update_queue_status_split(queue_id, 'completed')
            
            if success:
                print(f"[🗑️] 작업 ID {queue_id}를 completed로 이동했습니다.")
                return True
            else:
                print(f"[❌] 작업 제거 실패: {queue_id}")
                return False
                
        except Exception as e:
            print(f"[❌] 분할 큐에서 작업 제거 중 오류: {e}")
            return False
        finally:
            gc.collect()
    
    # 레거시 큐 함수들 (호환성 유지)
    def load_queue(self):
        """레거시 큐 파일에서 pending 작업 로드 (호환성)"""
        try:
            if not os.path.exists(QUEUE_FILE):
                print(f"[⚠️] 레거시 큐 파일이 없습니다. 분할 시스템을 사용합니다.")
                return self.load_queue_split()
                
            with open(QUEUE_FILE, "r", encoding="utf-8") as f:
                queue_data = json.load(f)
                
            # pending 상태인 작업만 필터링
            pending_jobs = [job for job in queue_data if job.get("status") == "pending"]
            
            print(f"[📋] 레거시 큐에서 {len(pending_jobs)}개의 대기 중인 작업을 발견했습니다.")
            
            # 전체 큐 데이터는 메모리에서 제거
            del queue_data
            gc.collect()  # 가비지 컬렉션 강제 실행
            
            return pending_jobs
            
        except Exception as e:
            print(f"[❌] 레거시 큐 로드 중 오류 발생: {e}")
            print("[🔄] 분할 시스템으로 전환합니다.")
            return self.load_queue_split()
            
    def save_queue(self, queue_data):
        """레거시 큐 파일 저장 (호환성)"""
        try:
            with open(QUEUE_FILE, "w", encoding="utf-8") as f:
                json.dump(queue_data, f, ensure_ascii=False, indent=4)
            print("[✅] 레거시 큐 파일이 성공적으로 저장되었습니다.")
        except Exception as e:
            print(f"[❌] 레거시 큐 저장 중 오류 발생: {e}")
            
    def update_job_status(self, job_id, status, error_message=None):
        """작업 상태 업데이트 (분할 시스템 우선)"""
        # 분할 시스템 먼저 시도
        success = self.update_queue_status_split(job_id, status, error_message)
        
        if success:
            return True
        
        # 레거시 시스템 폴백
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
            
            # 메모리 정리
            del queue_data
            gc.collect()
            
        except Exception as e:
            print(f"[❌] 레거시 작업 상태 업데이트 중 오류: {e}")
    
    def remove_job_from_queue(self, job_id):
        """즉시 발행 후 큐에서 작업 제거 (분할 시스템 우선)"""
        # 분할 시스템 먼저 시도
        success = self.remove_job_from_queue_split(job_id)
        
        if success:
            return True
        
        # 레거시 시스템 폴백
        try:
            with open(QUEUE_FILE, "r", encoding="utf-8") as f:
                queue_data = json.load(f)
            
            # 해당 job_id를 가진 항목 제거
            queue_data = [job for job in queue_data if job.get("queue_id") != job_id]
            
            self.save_queue(queue_data)
            print(f"[🗑️] 레거시 큐에서 작업 ID {job_id}를 제거했습니다.")
            
            # 메모리 정리
            del queue_data
            gc.collect()
            
        except Exception as e:
            print(f"[❌] 레거시 큐에서 작업 제거 중 오류: {e}")
    
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
            
            # temp_data는 더 이상 필요 없으므로 삭제
            del temp_data
            
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
                    
                    # 응답 데이터 정리
                    del response
                    del result
                    
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
        finally:
            # 메모리 정리
            if 'request' in locals():
                del request
            if 'response' in locals():
                del response
            gc.collect()
    
    def get_aliexpress_product_details(self, product_id):
        """알리익스프레스 상품 상세 정보 조회 (메모리 최적화)"""
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
                    
                    # 🔧 메모리 최적화: original_data 제거
                    formatted_product = {
                        "product_id": product_id,
                        "title": product.get("product_title", "상품명 없음"),
                        "price": f"₩{krw_price:,}",
                        "image_url": product.get("product_main_image_url", ""),
                        "rating_display": rating_display,
                        "lastest_volume": volume_display
                        # "original_data": product  # 제거됨 - 메모리 절약
                    }
                    
                    print(f"[✅] 상품 정보 조회 성공: {formatted_product['title']}")
                    
                    # 응답 데이터 정리
                    del product
                    del products
                    del result
                    del response
                    
                    return formatted_product
            
            print(f"[⚠️] 상품 정보를 찾을 수 없습니다")
            return None
            
        except Exception as e:
            print(f"[❌] 상품 정보 조회 중 오류: {e}")
            return None
        finally:
            # 메모리 정리
            if 'request' in locals():
                del request
            if 'response' in locals():
                del response
            gc.collect()
    
    def process_aliexpress_products(self, job_data):
        """알리익스프레스 상품 처리 (큐 데이터 강제 우선 사용 + 디버깅 강화)"""
        print("[🌏] 알리익스프레스 상품 처리를 시작합니다...")
        
        processed_products = []
        
        for keyword_data in job_data["keywords"]:
            keyword = keyword_data["name"]
            aliexpress_links = keyword_data.get("aliexpress", [])
            # 🔧 키워드별 products_data 올바르게 접근
            products_data = keyword_data.get("products_data", [])
            
            print(f"[📋] 키워드 '{keyword}' 처리 중...")
            print(f"[🔍] 알리익스프레스 링크: {len(aliexpress_links)}개")
            print(f"[🔍] 큐 products_data: {len(products_data)}개")
            
            # 디버깅: 큐 데이터 구조 확인
            if products_data:
                for i, product_data in enumerate(products_data):
                    print(f"[🔍] 큐 상품 {i+1}: URL={product_data.get('url', 'N/A')[:50]}...")
                    if product_data.get('analysis_data'):
                        analysis = product_data['analysis_data']
                        print(f"[🔍]   제목: {analysis.get('title', 'N/A')[:50]}...")
                        print(f"[🔍]   가격: {analysis.get('price', 'N/A')}")
                        print(f"[🔍]   평점: {analysis.get('rating_display', 'N/A')}")
            
            # 🚀 큐에 저장된 완성된 상품 데이터 강제 우선 사용
            for i, link in enumerate(aliexpress_links):
                if link.strip():
                    print(f"[🔍] 처리 중인 링크: {link[:50]}...")
                    
                    # 1순위: 키워드의 products_data에서 해당 상품 데이터 찾기 (강화된 매칭)
                    queue_product = None
                    link_normalized = normalize_url(link.strip())
                    
                    for product_data in products_data:
                        queue_url = product_data.get('url', '')
                        queue_url_normalized = normalize_url(queue_url)
                        
                        # URL 정규화 후 매칭 (상품 ID 기반)
                        if link_normalized and queue_url_normalized and link_normalized == queue_url_normalized:
                            queue_product = product_data
                            print(f"[✅] 큐 데이터 매칭 성공 (상품 ID: {link_normalized})")
                            break
                        # 부분 URL 매칭도 시도
                        elif link.strip() in queue_url or queue_url in link.strip():
                            queue_product = product_data
                            print(f"[✅] 큐 데이터 매칭 성공 (부분 매칭)")
                            break
                    
                    # 🔒 큐 데이터가 있으면 무조건 사용 (API 호출 차단)
                    if queue_product and queue_product.get('analysis_data'):
                        analysis_data = queue_product['analysis_data']
                        product_info = {
                            "product_id": analysis_data.get("product_id", ""),
                            "title": analysis_data.get("title", f"{keyword} 관련 상품"),
                            "price": analysis_data.get("price", "가격 확인 필요"),
                            "image_url": analysis_data.get("image_url", ""),
                            "rating_display": analysis_data.get("rating_display", "평점 정보 없음"),
                            "lastest_volume": analysis_data.get("lastest_volume", "판매량 정보 없음"),
                            "affiliate_url": analysis_data.get("affiliate_link", ""),
                            "keyword": keyword
                        }
                        processed_products.append(product_info)
                        print(f"[✅] 큐 데이터 사용: {analysis_data.get('title', 'N/A')[:50]}...")
                        print(f"[✅]   가격: {analysis_data.get('price', 'N/A')}")
                        print(f"[✅]   평점: {analysis_data.get('rating_display', 'N/A')}")
                        continue
                    
                    # 🚀 큐에 products_data가 있지만 analysis_data가 없는 경우도 강제로 큐 데이터 활용
                    elif products_data:
                        # 첫 번째 큐 상품의 기본 정보라도 사용
                        first_queue_product = products_data[0]
                        if first_queue_product.get('analysis_data'):
                            analysis_data = first_queue_product['analysis_data']
                            product_info = {
                                "product_id": analysis_data.get("product_id", ""),
                                "title": analysis_data.get("title", f"{keyword} 관련 상품"),
                                "price": analysis_data.get("price", "가격 확인 필요"),
                                "image_url": analysis_data.get("image_url", ""),
                                "rating_display": analysis_data.get("rating_display", "평점 정보 없음"),
                                "lastest_volume": analysis_data.get("lastest_volume", "판매량 정보 없음"),
                                "affiliate_url": analysis_data.get("affiliate_link", ""),
                                "keyword": keyword
                            }
                            processed_products.append(product_info)
                            print(f"[⚠️] 매칭 실패로 첫 번째 큐 데이터 사용: {analysis_data.get('title', 'N/A')[:50]}...")
                            continue
                    
                    # 마지막 폴백: API 호출 (큐 데이터가 전혀 없는 경우에만)
                    print(f"[❌] 큐 데이터 없음, API 호출: {link[:50]}...")
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
                    
                    # API 호출 간 딜레이 (큐 데이터 사용 시에는 제외)
                    time.sleep(2)
                    
                    # 주기적 메모리 정리
                    gc.collect()
        
        print(f"[✅] 알리익스프레스 상품 처리 완료: {len(processed_products)}개 (큐 데이터 강제 우선 사용)")
        return processed_products
    
    def generate_content_with_gemini(self, job_data, products):
        """🚀 Gemini API로 4가지 프롬프트 템플릿 기반 블로그 콘텐츠 생성 (메모리 최적화)"""
        try:
            # 프롬프트 타입 추출 (기본값: essential_items)
            prompt_type = job_data.get('prompt_type', 'essential_items')
            title = job_data.get('title', '')
            
            # 키워드 정보 정리
            keywords = [kw["name"] for kw in job_data.get("keywords", [])]
            
            # 사용자 상세 정보 추출
            user_details = job_data.get('user_details', {})
            has_user_details = job_data.get('has_user_details', False)
            
            # 🎯 키워드별 products_data에서 generated_html 정보 추출
            queue_html_content = ""
            total_queue_products = 0
            for keyword_data in job_data.get("keywords", []):
                products_data = keyword_data.get("products_data", [])
                total_queue_products += len(products_data)
                
                if products_data:
                    keyword_name = keyword_data.get("name", "")
                    queue_html_content += f"\n**키워드 '{keyword_name}' 큐 상품 HTML:**\n"
                    for i, product in enumerate(products_data[:2]):  # 각 키워드당 최대 2개만 참고
                        if product.get('analysis_data'):
                            queue_html_content += f"상품 {i+1}: {product['analysis_data'].get('title', 'N/A')}\n"
                        if product.get('generated_html'):
                            # HTML 미리보기만 추가 (전체 HTML은 너무 큼)
                            queue_html_content += f"HTML: {product['generated_html'][:200]}...\n"
            
            mode_text = "즉시 발행" if self.immediate_mode else "큐 처리"
            print(f"[🤖] Gemini AI로 '{title}' 콘텐츠를 생성합니다... ({mode_text})")
            print(f"[🎯] 프롬프트 타입: {prompt_type}")
            print(f"[📝] 사용자 상세 정보: {'포함' if has_user_details else '없음'}")
            print(f"[🔗] 전체 큐 상품 데이터: {total_queue_products}개")
            
            # 상품 정보 추가 (프롬프트에 포함할 상품 요약)
            product_summaries = []
            for product in products:
                summary = f"- {product['title']} (가격: {product['price']}, 평점: {product['rating_display']}, 판매량: {product['lastest_volume']})"
                product_summaries.append(summary)
            
            # 상품 정보를 포함한 상세 정보 구성
            enhanced_user_details = user_details.copy() if user_details else {}
            enhanced_user_details['product_summaries'] = product_summaries
            enhanced_user_details['queue_products_count'] = total_queue_products
            
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
            
            # 큐 HTML 정보 추가
            if queue_html_content:
                prompt += f"\n{queue_html_content}\n"
            
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

**큐 데이터 활용:**
- 위에 제공된 큐의 상품 HTML 정보를 참고하여 내용 작성
- 상품 카드는 별도로 삽입되므로 본문에서는 자연스러운 언급만

**절대 금지사항:**
- 상품 링크나 버튼 HTML 코드 포함 금지 (별도 삽입)
- 허위 정보나 과장된 표현 금지
- 다른 쇼핑몰 언급 금지 (알리익스프레스 전용)

위 조건을 모두 준수하여 높은 품질의 블로그 글을 작성해주세요.
"""
            
            # Gemini API 호출
            response = self.gemini_model.generate_content(prompt)
            base_content = response.text
            
            # 응답 객체 즉시 삭제
            del response
            
            if not base_content or len(base_content.strip()) < 1500:
                print("[❌] Gemini가 충분한 길이의 콘텐츠를 생성하지 못했습니다.")
                return None
            
            # HTML 코드 블록 표시 제거
            base_content = base_content.replace('```html', '').replace('```', '').strip()
            
            # 본문 글자 크기 18px 적용
            base_content = f'<div style="font-size: 18px; line-height: 1.6;">{base_content}</div>'
            
            # 상품 카드 삽입 (큐 데이터 우선 활용)
            final_content = self.insert_product_cards(base_content, products, job_data)
            
            # 불필요한 변수 정리
            del prompt
            del enhanced_user_details
            del product_summaries
            gc.collect()
            
            print(f"[✅] Gemini AI가 {len(base_content)}자의 {prompt_type} 스타일 콘텐츠를 생성했습니다.")
            return final_content
            
        except Exception as e:
            print(f"[❌] Gemini 콘텐츠 생성 중 오류: {e}")
            return None
        finally:
            # 메모리 정리
            gc.collect()
    
    def insert_product_cards(self, content, products, job_data):
        """상품 카드를 콘텐츠에 삽입하고 키워드별 '관련 상품 더보기' 버튼 추가 (큐 HTML 우선 사용)"""
        final_content = content
        
        # 🔗 알리익스프레스 키워드 링크 매핑 로드
        keyword_links = load_aliexpress_keyword_links()
        
        print(f"[🔗] 상품 카드 삽입 시작: API 상품 {len(products)}개")
        print(f"[🔗] 키워드 링크 매핑: {len(keyword_links)}개")
        
        # 키워드별로 상품 그룹화
        keyword_groups = {}
        for i, product in enumerate(products):
            keyword = product.get('keyword', '')
            if keyword not in keyword_groups:
                keyword_groups[keyword] = []
            keyword_groups[keyword].append((i, product))
        
        # 키워드별 큐 상품 매핑 생성 (올바른 구조로 수정)
        keyword_products_map = {}
        for keyword_data in job_data.get("keywords", []):
            keyword = keyword_data["name"]
            products_data = keyword_data.get("products_data", [])
            if products_data:
                keyword_products_map[keyword] = products_data
        
        # 각 키워드 그룹별로 처리
        for keyword, product_group in keyword_groups.items():
            print(f"[📋] 키워드 '{keyword}' 그룹 처리: {len(product_group)}개 상품")
            
            # 키워드 그룹의 상품들을 순차적으로 삽입 (큐 HTML 우선 사용)
            queue_products = keyword_products_map.get(keyword, [])
            print(f"[🔍] 키워드 '{keyword}'의 큐 상품: {len(queue_products)}개")
            
            for idx, (original_index, product) in enumerate(product_group):
                # 🎯 큐의 generated_html 우선 사용 (해당 상품 URL로 매칭)
                card_html = ""
                queue_html_found = False
                
                # 해당 키워드의 큐 데이터에서 매칭되는 HTML 찾기
                product_title = product.get('title', '')
                product_url = product.get('affiliate_url', '')
                
                for queue_product in queue_products:
                    if queue_product.get('generated_html'):
                        # URL 또는 제목으로 매칭 확인
                        queue_analysis = queue_product.get('analysis_data', {})
                        if (queue_analysis.get('affiliate_link') and product_url and 
                            queue_analysis['affiliate_link'] in product_url) or \
                           (queue_analysis.get('title') and product_title and 
                            queue_analysis['title'][:30] in product_title):
                            card_html = queue_product['generated_html']
                            queue_html_found = True
                            print(f"[✅] 큐의 generated_html 사용: {keyword} - 상품 {idx+1} ({queue_analysis.get('title', 'N/A')[:30]}...)")
                            break
                
                # 큐 HTML을 찾지 못한 경우 폴백
                if not queue_html_found:
                    card_html = self.generate_product_card_html(product)
                    print(f"[⚠️] API 데이터로 카드 생성: {keyword} - 상품 {idx+1} ({product_title[:30]}...)")
                
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
            
            # ✨ 키워드 그룹의 마지막 상품 다음에 '관련 상품 더보기' 버튼 삽입
            if keyword and keyword in keyword_links:
                more_products_html = f'''
<div style="text-align: center; margin: 30px 0; padding: 20px 0;">
    <a href="{keyword_links[keyword]}" target="_blank" rel="noopener noreferrer nofollow" style="display: inline-block; width: 100%; max-width: 800px;">
        <picture>
            <source media="(min-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-more-products-pc.png">
            <img src="https://novacents.com/tools/images/aliexpress-more-products-mobile.png" alt="알리익스프레스 {keyword} 관련 상품 더보기" style="width: 100%; height: auto; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
        </picture>
    </a>
</div>'''
                
                # 마지막으로 삽입된 상품 카드 다음에 '더보기' 버튼 추가
                final_content += more_products_html
                print(f"[🎯] '{keyword}' 키워드에 알리익스프레스 '관련 상품 더보기' 버튼 추가")
            elif keyword:
                print(f"[⚠️] '{keyword}' 키워드에 대한 알리익스프레스 링크 매핑이 없음")
        
        return final_content
    
    def generate_product_card_html(self, product):
        """개별 상품 카드 HTML 생성 (폴백용)"""
        # 상품 이미지 처리
        image_html = ""
        if product.get('image_url') and product['image_url'].startswith('http'):
            image_html = f'''
            <div style="text-align: center; margin-bottom: 15px;">
                <img src="{product['image_url']}" alt="{product['title']}" style="max-width: 400px; height: auto; border-radius: 8px; border: 1px solid #ddd;">
            </div>'''
        
        # 어필리에이트 버튼 HTML (반응형 - 1600px 기준)
        button_html = f'''
        <div class="affiliate-button-container" style="width: 100%; max-width: 800px; margin: 15px auto; text-align: center;">
            <a href="{product['affiliate_url']}" target="_blank" rel="noopener" style="display: inline-block; width: 100%;">
                <picture>
                    <source media="(min-width: 1600px)" srcset="https://novacents.com/tools/images/aliexpress-button-pc.png">
                    <img src="https://novacents.com/tools/images/aliexpress-button-mobile.png" alt="알리익스프레스에서 {product.get('keyword', '상품')} 구매하기" style="width: 100%; height: auto; max-width: 800px; border-radius: 8px;">
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
    
    def generate_focus_keyphrase_with_gemini(self, title, content, keywords):
        """🎯 Gemini API로 SEO 최적화된 초점 키프레이즈 생성 (메모리 최적화)"""
        print(f"[🤖] Gemini AI로 초점 키프레이즈를 생성합니다...")
        
        # 폴백 키프레이즈
        fallback_keyphrase = f"{keywords[0]} 추천" if keywords else "알리익스프레스 추천"
        
        try:
            # 콘텐츠 요약 생성 (너무 길면 잘라내기)
            content_summary = content[:1000] if len(content) > 1000 else content
            keywords_text = ", ".join(keywords) if keywords else ""
            
            prompt = f"""당신은 전문 SEO 콘텐츠 전략가입니다. 주어진 글 제목과 본문을 분석해서, 이 글의 핵심 주제를 가장 잘 나타내는 '초점 키프레이즈'를 딱 하나만 추출해 주세요.

[글 정보]
제목: {title}
주요 키워드: {keywords_text}
본문 요약: {content_summary}

[규칙]
1. 사용자가 이 글을 찾기 위해 검색할 것 같은 가장 가능성 높은 검색어여야 합니다.
2. 3-5개 단어로 구성된 롱테일 키워드 형태가 좋습니다.
3. 제목이나 본문에 자연스럽게 포함된 표현을 우선 고려하세요.
4. 다른 설명은 붙이지 말고, 오직 키프레이즈만 출력하세요.

예시: "여름 물놀이 필수템", "알리익스프레스 추천 상품", "2025년 인기 아이템"
"""
            
            response = self.gemini_model.generate_content(prompt)
            keyphrase = response.text.strip()
            
            # 응답 객체 즉시 삭제
            del response
            del prompt
            
            # 유효성 검사
            if not keyphrase or len(keyphrase) > 30 or '\n' in keyphrase:
                print(f"[⚠️] 생성된 키프레이즈가 유효하지 않음, 폴백 사용: {fallback_keyphrase}")
                return fallback_keyphrase
            
            print(f"[✅] 초점 키프레이즈 생성 완료: {keyphrase}")
            return keyphrase
            
        except Exception as e:
            print(f"[❌] 초점 키프레이즈 생성 실패: {e}, 폴백 사용: {fallback_keyphrase}")
            return fallback_keyphrase
        finally:
            # 메모리 정리
            gc.collect()
    
    def generate_meta_description_with_gemini(self, title, content, focus_keyphrase):
        """🎯 Gemini API로 SEO 최적화된 메타 설명 생성 (메모리 최적화)"""
        print(f"[🤖] Gemini AI로 메타 설명을 생성합니다...")
        
        # 폴백 메타 설명
        fallback_description = f"{focus_keyphrase}에 대한 완벽 가이드! 상품 정보부터 구매 팁까지 2025년 최신 정보를 확인하세요."
        
        try:
            # 콘텐츠 요약 생성 (너무 길면 잘라내기)
            content_summary = content[:1000] if len(content) > 1000 else content
            
            prompt = f"""당신은 전문 SEO 카피라이터입니다. 글 제목과 본문을 분석해서 해당 정보를 바탕으로 구글 검색 결과에 표시될 '메타 설명'을 작성해 주세요.

[글 정보]
제목: {title}
초점 키프레이즈: {focus_keyphrase}
본문 요약: {content_summary}

[규칙]
1. 반드시 '{focus_keyphrase}'를 자연스럽게 포함해야 합니다.
2. 전체 글자 수는 공백 포함 150자 내로 맞춰주세요.
3. 사용자의 호기심을 자극하고, 글을 클릭해서 읽고 싶게 만드는 매력적인 문구로 작성해 주세요.
4. 다른 설명 없이, 완성된 메타 설명 문장만 출력해 주세요.

예시: "여름 물놀이 필수템 완벽 가이드! 2025년 인기 상품부터 구매 팁까지 알리익스프레스 추천 아이템을 확인하세요."
"""
            
            response = self.gemini_model.generate_content(prompt)
            description = response.text.strip()
            
            # 응답 객체 즉시 삭제
            del response
            del prompt
            
            # 유효성 검사
            if not description or len(description) > 160 or len(description) < 100:
                print(f"[⚠️] 생성된 메타 설명이 유효하지 않음, 폴백 사용: {fallback_description}")
                return fallback_description
            
            print(f"[✅] 메타 설명 생성 완료 ({len(description)}자)")
            return description
            
        except Exception as e:
            print(f"[❌] 메타 설명 생성 실패: {e}, 폴백 사용: {fallback_description}")
            return fallback_description
        finally:
            # 메모리 정리
            gc.collect()
    
    def generate_seo_optimized_tags_with_gemini(self, title, content, keywords):
        """🎯 Gemini API로 SEO 최적화된 태그 생성 (메모리 최적화)"""
        print(f"[🤖] Gemini AI로 SEO 최적화 태그를 생성합니다...")
        
        # 폴백 태그
        fallback_tags = keywords[:3] + ["알리익스프레스", "추천", "구매가이드"] if keywords else ["알리익스프레스", "추천", "구매가이드"]
        
        try:
            # 콘텐츠 요약 생성 (너무 길면 잘라내기)
            content_summary = content[:1000] if len(content) > 1000 else content
            keywords_text = ", ".join(keywords) if keywords else ""
            
            prompt = f"""당신은 전문 SEO 전략가입니다. 주어진 글의 제목과 본문을 분석해서 해당 글에 관련된 '핵심키워드, 주요 키워드, 관련 키워드, 롱테일 키워드'를 추출하여 워드프레스 태그로 사용할 키워드들을 생성해주세요.

[글 정보]
제목: {title}
기본 키워드: {keywords_text}
본문 요약: {content_summary}

[규칙]
1. 검색에서 실제로 사용될 가능성이 높은 키워드들로 구성하세요.
2. 너무 일반적이거나 너무 구체적이지 않은 적절한 수준의 키워드를 선택하세요.
3. 8-12개의 키워드를 쉼표(,)로 구분하여 나열하세요.
4. 각 키워드는 1-3개 단어로 구성하세요.
5. 결과는 오직 '키워드1,키워드2,키워드3' 형식으로만 출력하고 다른 설명은 절대 추가하지 마세요.

예시: "물놀이용품,여름필수템,휴가준비,수영용품,알리익스프레스,해외직구,추천상품,2025년,물놀이,여행용품"
"""
            
            response = self.gemini_model.generate_content(prompt)
            tags_string = response.text.strip()
            
            # 응답 객체 즉시 삭제
            del response
            del prompt
            
            # 태그 파싱
            if tags_string:
                tags = [tag.strip() for tag in tags_string.split(',') if tag.strip()]
                tags = tags[:12]  # 최대 12개로 제한
                
                if len(tags) >= 5:  # 최소 5개 이상이어야 유효
                    print(f"[✅] SEO 최적화 태그 {len(tags)}개 생성 완료")
                    return tags
            
            print(f"[⚠️] 생성된 태그가 유효하지 않음, 폴백 사용")
            return fallback_tags
            
        except Exception as e:
            print(f"[❌] SEO 태그 생성 실패: {e}, 폴백 사용")
            return fallback_tags
        finally:
            # 메모리 정리
            gc.collect()
    
    def generate_seo_optimized_slug_with_gemini(self, title, content):
        """🎯 Gemini API로 SEO 최적화된 한글 슬러그 생성 (메모리 최적화)"""
        print(f"[🤖] Gemini AI로 SEO 최적화 한글 슬러그를 생성합니다...")
        
        # 폴백 슬러그 (제목 기반)
        fallback_slug = re.sub(r'[^가-힣a-zA-Z0-9\s]', '', title).replace(' ', '-')[:50]
        
        try:
            # 콘텐츠 요약 생성 (너무 길면 잘라내기)
            content_summary = content[:800] if len(content) > 800 else content
            
            prompt = f"""당신은 SEO 전문가입니다. 주어진 글 제목과 본문을 분석해서, 구글 검색 SEO에 가장 적합한 한글 슬러그를 생성해주세요.

[글 정보]
제목: {title}
본문 요약: {content_summary}

[규칙]
1. 한글과 영문, 숫자, 하이픈(-)만 사용하세요.
2. 글의 핵심 주제를 잘 나타내는 3-6개 단어로 구성하세요.
3. 단어 사이는 하이픈(-)으로 연결하세요.
4. 전체 길이는 30자 이내로 제한하세요.
5. 검색 친화적이고 기억하기 쉬운 형태로 만드세요.
6. 다른 설명 없이, 완성된 슬러그만 출력하세요.

좋은 예시: "여름-물놀이-필수템", "알리익스프레스-추천-상품", "2025-휴가-준비물"
나쁜 예시: "2025년-놓치면-후회할-여름휴가-피서-물놀이-필수템-총정리"
"""
            
            response = self.gemini_model.generate_content(prompt)
            slug = response.text.strip()
            
            # 응답 객체 즉시 삭제
            del response
            del prompt
            
            # 슬러그 정리 및 유효성 검사
            if slug:
                # 특수문자 제거 (한글, 영문, 숫자, 하이픈만 유지)
                cleaned_slug = re.sub(r'[^가-힣a-zA-Z0-9\-]', '', slug)
                # 연속된 하이픈 제거
                cleaned_slug = re.sub(r'-+', '-', cleaned_slug)
                # 시작과 끝의 하이픈 제거
                cleaned_slug = cleaned_slug.strip('-')
                
                if cleaned_slug and len(cleaned_slug) <= 40 and len(cleaned_slug) >= 10:
                    print(f"[✅] SEO 최적화 슬러그 생성 완료: {cleaned_slug}")
                    return cleaned_slug
            
            print(f"[⚠️] 생성된 슬러그가 유효하지 않음, 폴백 사용: {fallback_slug}")
            return fallback_slug
            
        except Exception as e:
            print(f"[❌] SEO 슬러그 생성 실패: {e}, 폴백 사용: {fallback_slug}")
            return fallback_slug
        finally:
            # 메모리 정리
            gc.collect()
    
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
                
                # 응답 객체 삭제
                del res
                if 'create_res' in locals():
                    del create_res
                
            except requests.exceptions.RequestException as e:
                print(f"[❌] 태그 API 요청 중 오류 ('{tag_name}'): {e}")
        
        print(f"[✅] {len(tag_ids)}개의 태그 ID를 확보했습니다.")
        
        # 메모리 정리
        gc.collect()
        
        return tag_ids
    
    def post_to_wordpress(self, job_data, content):
        """워드프레스에 글 발행 (FIFU, YoastSEO, 태그 포함) - 메모리 최적화"""
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
            
            # 🎯 Gemini AI로 SEO 최적화 데이터 생성
            print(f"[🤖] SEO 최적화를 위한 데이터를 생성합니다...")
            
            # 1. 초점 키프레이즈 생성
            focus_keyphrase = self.generate_focus_keyphrase_with_gemini(
                job_data['title'], content, keywords
            )
            
            # 2. 메타 설명 생성
            meta_description = self.generate_meta_description_with_gemini(
                job_data['title'], content, focus_keyphrase
            )
            
            # 3. SEO 최적화 태그 생성
            seo_tags = self.generate_seo_optimized_tags_with_gemini(
                job_data['title'], content, keywords
            )
            
            # 4. SEO 최적화 슬러그 생성
            seo_slug = self.generate_seo_optimized_slug_with_gemini(
                job_data['title'], content
            )
            
            # 5. 워드프레스 태그 등록
            tag_ids = self.ensure_tags_on_wordpress(seo_tags)
            
            # 게시물 데이터
            post_data = {
                "title": job_data["title"],
                "content": content,
                "status": "publish",
                "categories": [job_data["category_id"]],
                "tags": tag_ids,
                "slug": seo_slug  # 🎯 SEO 최적화된 한글 슬러그
            }
            
            # 1단계: 게시물 생성
            print(f"[⚙️] 1단계 - 게시물을 생성합니다...")
            response = requests.post(api_url, json=post_data, headers=headers, auth=auth, timeout=30)
            
            if response.status_code == 201:
                post_info = response.json()
                post_id = post_info.get("id")
                post_url = post_info.get("link", "")
                print(f"[✅] 워드프레스 게시물 생성 성공! (ID: {post_id})")
                
                # 응답 객체 즉시 삭제
                del response
                del post_info
                
                # 2단계: FIFU 썸네일 설정 (auto_post_overseas.py와 동일한 방식)
                thumbnail_url = job_data.get('thumbnail_url')
                if thumbnail_url:
                    print(f"[⚙️] 2단계 - FIFU 썸네일을 설정합니다...")
                    fifu_payload = {"meta": {"_fifu_image_url": thumbnail_url}}
                    requests.post(f"{self.config['wp_api_base']}/posts/{post_id}", auth=auth, json=fifu_payload, headers=headers, timeout=20)
                    print("[✅] FIFU 썸네일 설정 완료.")
                else:
                    print("[⚠️] 썸네일 URL이 없어 FIFU 설정을 건너뜁니다.")
                
                # 3단계: YoastSEO 메타데이터 설정 (auto_post_overseas.py 방식)
                print(f"[⚙️] 3단계 - Yoast SEO 메타데이터를 설정합니다...")
                try:
                    yoast_payload = {
                        "post_id": post_id,
                        "focus_keyphrase": focus_keyphrase,
                        "meta_description": meta_description
                    }
                    yoast_url = f"{self.config['wp_url'].rstrip('/')}/wp-json/my-api/v1/update-seo"
                    
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
                    
                    # 응답 객체 삭제
                    del yoast_response
                    
                except Exception as e:
                    print(f"[⚠️] Yoast SEO 설정 중 오류 (무시하고 계속): {e}")
                
                # 발행 로그 저장
                self.save_published_log(job_data, post_url)
                
                print(f"[🎉] 모든 작업 완료! 발행된 글 주소: {post_url}")
                print(f"[📊] SEO 정보 - 슬러그: {seo_slug}, 키프레이즈: {focus_keyphrase}, 태그: {len(seo_tags)}개")
                
                # 메모리 정리
                gc.collect()
                
                return post_url
            else:
                print(f"[❌] 워드프레스 발행 실패: {response.status_code}")
                print(f"응답: {response.text}")
                return None
                
        except Exception as e:
            print(f"[❌] 워드프레스 발행 중 오류: {e}")
            return None
        finally:
            # 메모리 정리
            gc.collect()
            
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
        """단일 작업 처리 (메모리 최적화)"""
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
                
                # 🎉 성공 시 워드프레스 발행 성공 메시지 출력 (keyword_processor.php가 파싱)
                if self.immediate_mode:
                    print(f"즉시 발행 완료: {post_url}")
                else:
                    print(f"워드프레스 발행 성공: {post_url}")
                
                # 작업 완료 후 메모리 정리
                del products
                del content
                gc.collect()
                
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
        finally:
            # 메모리 정리
            gc.collect()
            
    def run_immediate_mode(self, temp_file):
        """🚀 즉시 발행 모드 실행 (메모리 최적화)"""
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
        
        # 5. 메모리 정리
        del job_data
        gc.collect()
        
        # 6. 완료 메시지
        if success:
            completion_message = f"[🎉] 즉시 발행 완료!"
            self.log_message(completion_message)
            print("=" * 60)
            print("🚀 알리익스프레스 즉시 발행 성공")
            print("=" * 60)
            return True
        else:
            error_message = f"[❌] 즉시 발행 실패!"
            self.log_message(error_message)
            print("=" * 60)
            print("❌ 알리익스프레스 즉시 발행 실패")
            print("=" * 60)
            return False
            
    def run(self):
        """메인 실행 함수 (큐 모드) - 메모리 최적화 및 분할 시스템"""
        print("=" * 60)
        print("🌏 알리익스프레스 전용 어필리에이트 자동화 시스템 시작 (분할 큐 시스템)")
        print("=" * 60)
        
        # 1. 설정 로드
        if not self.load_configuration():
            print("[❌] 설정 로드 실패. 프로그램을 종료합니다.")
            return
            
        # 2. 분할 큐에서 작업 로드
        pending_jobs = self.load_queue_split()
        
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
            
            # 작업 완료 후 메모리 정리
            del job
            gc.collect()
            
            if success and processed_count < len(pending_jobs):
                print(f"[⏳] {POST_DELAY_SECONDS}초 대기 중...")
                time.sleep(POST_DELAY_SECONDS)
                
        # 4. 완료 메시지
        remaining_jobs = len(pending_jobs) - processed_count
        completion_message = f"[🎉] 분할 큐 시스템 자동화 완료! 처리: {processed_count}개, 남은 작업: {remaining_jobs}개"
        
        self.log_message(completion_message)
        self.send_telegram_notification(completion_message)
        
        # 5. 메모리 정리
        del pending_jobs
        gc.collect()
        
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
            # 분할 큐 모드
            system.run()
            
    except KeyboardInterrupt:
        print("\n[⏹️] 사용자에 의해 프로그램이 중단되었습니다.")
        sys.exit(1)
    except Exception as e:
        print(f"\n[❌] 예상치 못한 오류가 발생했습니다: {e}")
        print(traceback.format_exc())
        sys.exit(1)
    finally:
        # 프로그램 종료 시 최종 메모리 정리
        gc.collect()
        print("[🧹] 메모리 정리 완료")


if __name__ == "__main__":
    main()