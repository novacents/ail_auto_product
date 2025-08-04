#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import sys
import os
import json
import time
import random
from datetime import datetime, timedelta
import argparse
import subprocess
import traceback
import re
from pathlib import Path
from typing import Dict, List, Optional, Any
import signal

# 필요한 라이브러리 임포트
try:
    import requests
    from requests.adapters import HTTPAdapter
    from urllib3.util.retry import Retry
except ImportError:
    print("[❌] requests 라이브러리가 설치되지 않았습니다.")
    print("다음 명령어로 설치하세요: pip install requests")
    sys.exit(1)

try:
    import openai
except ImportError:
    print("[❌] openai 라이브러리가 설치되지 않았습니다.")
    print("다음 명령어로 설치하세요: pip install openai")
    sys.exit(1)

class WordPressPublisher:
    def __init__(self, immediate_mode=False):
        """
        워드프레스 자동 발행 시스템
        
        Args:
            immediate_mode (bool): 즉시 발행 모드 여부
        """
        self.base_dir = '/var/www/novacents/tools'
        self.queue_file = os.path.join(self.base_dir, 'product_queue.json')
        self.temp_dir = os.path.join(self.base_dir, 'temp')
        self.immediate_mode = immediate_mode
        
        # 워드프레스 설정
        self.wp_config = self.load_wp_config()
        
        # OpenAI 설정
        self.openai_config = self.load_openai_config()
        if self.openai_config:
            openai.api_key = self.openai_config['api_key']
        
        # 세션 설정
        self.session = self.create_session()
        
        # 정리할 임시 파일 목록
        self.temp_files_to_cleanup = []
        
        # 신호 핸들러 등록
        signal.signal(signal.SIGTERM, self.signal_handler)
        signal.signal(signal.SIGINT, self.signal_handler)
        
        self.log_message("[🚀] WordPressPublisher 초기화 완료")
        if self.immediate_mode:
            self.log_message("[⚡] 즉시 발행 모드로 실행")
    
    def signal_handler(self, signum, frame):
        """시그널 핸들러 - 프로세스 종료 시 정리 작업"""
        self.log_message(f"[🛑] 시그널 {signum} 수신 - 정리 작업 시작")
        self.cleanup_temp_files()
        sys.exit(0)
    
    def log_message(self, message, level="INFO"):
        """로그 메시지 출력"""
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        print(f"[{timestamp}] {message}")
        
        # 파일 로그도 저장
        log_file = os.path.join(self.base_dir, 'auto_post.log')
        try:
            with open(log_file, 'a', encoding='utf-8') as f:
                f.write(f"[{timestamp}] [{level}] {message}\n")
        except Exception:
            pass  # 로그 저장 실패해도 계속 진행
    
    def load_wp_config(self):
        """워드프레스 설정 로드"""
        config_file = os.path.join(self.base_dir, 'wp_config.json')
        try:
            with open(config_file, 'r', encoding='utf-8') as f:
                config = json.load(f)
                self.log_message("[✅] 워드프레스 설정 로드 완료")
                return config
        except FileNotFoundError:
            self.log_message("[❌] wp_config.json 파일을 찾을 수 없습니다.")
            return None
        except json.JSONDecodeError as e:
            self.log_message(f"[❌] wp_config.json 파싱 오류: {e}")
            return None
    
    def load_openai_config(self):
        """OpenAI 설정 로드"""
        config_file = os.path.join(self.base_dir, 'openai_config.json')
        try:
            with open(config_file, 'r', encoding='utf-8') as f:
                config = json.load(f)
                self.log_message("[✅] OpenAI 설정 로드 완료")
                return config
        except FileNotFoundError:
            self.log_message("[❌] openai_config.json 파일을 찾을 수 없습니다.")
            return None
        except json.JSONDecodeError as e:
            self.log_message(f"[❌] openai_config.json 파싱 오류: {e}")
            return None
    
    def create_session(self):
        """재시도 정책이 포함된 세션 생성"""
        session = requests.Session()
        
        # 재시도 정책 설정
        retry_strategy = Retry(
            total=3,
            backoff_factor=1,
            status_forcelist=[429, 500, 502, 503, 504],
        )
        
        adapter = HTTPAdapter(max_retries=retry_strategy)
        session.mount("http://", adapter)
        session.mount("https://", adapter)
        
        return session
    
    def load_queue(self):
        """큐 파일 로드"""
        try:
            if not os.path.exists(self.queue_file):
                return []
            
            with open(self.queue_file, 'r', encoding='utf-8') as f:
                queue = json.load(f)
                return queue if isinstance(queue, list) else []
        except Exception as e:
            self.log_message(f"[❌] 큐 파일 로드 실패: {e}")
            return []
    
    def save_queue(self, queue):
        """큐 파일 저장"""
        try:
            with open(self.queue_file, 'w', encoding='utf-8') as f:
                json.dump(queue, f, ensure_ascii=False, indent=2)
            return True
        except Exception as e:
            self.log_message(f"[❌] 큐 파일 저장 실패: {e}")
            return False
    
    def load_job_from_temp_file(self, temp_file_path):
        """임시 파일에서 작업 데이터 로드"""
        try:
            with open(temp_file_path, 'r', encoding='utf-8') as f:
                job_data = json.load(f)
            
            # 정리할 파일 목록에 추가
            self.temp_files_to_cleanup.append(temp_file_path)
            
            return job_data
        except Exception as e:
            self.log_message(f"[❌] 임시 파일 로드 실패 ({temp_file_path}): {e}")
            return None
    
    def cleanup_temp_files(self):
        """임시 파일 정리"""
        for temp_file in self.temp_files_to_cleanup:
            try:
                if os.path.exists(temp_file):
                    os.remove(temp_file)
                    self.log_message(f"[🗑️] 임시 파일 삭제: {temp_file}")
            except Exception as e:
                self.log_message(f"[⚠️] 임시 파일 삭제 실패 ({temp_file}): {e}")
        
        self.temp_files_to_cleanup.clear()
    
    def update_job_status(self, job_id, status, error_message=None):
        """작업 상태 업데이트 (분할 시스템 지원)"""
        try:
            # 분할 시스템 함수 사용
            result = self.call_php_function('update_queue_status_split', {
                'queue_id': job_id,
                'new_status': status,
                'error_message': error_message
            })
            
            if result and result.get('success'):
                self.log_message(f"[✅] 큐 상태 업데이트 성공: {job_id} -> {status}")
                return True
            else:
                error_msg = result.get('error', '알 수 없는 오류') if result else '응답 없음'
                self.log_message(f"[❌] 큐 상태 업데이트 실패: {job_id} -> {status}, 오류: {error_msg}")
                return False
                
        except Exception as e:
            self.log_message(f"[❌] 큐 상태 업데이트 중 예외 발생: {e}")
            return False
    
    def remove_job_from_queue(self, job_id):
        """큐에서 작업 제거 (분할 시스템 지원)"""
        try:
            # 분할 시스템 함수 사용
            result = self.call_php_function('remove_queue_split', {
                'queue_id': job_id
            })
            
            if result and result.get('success'):
                self.log_message(f"[✅] 큐에서 제거 성공: {job_id}")
                return True
            else:
                error_msg = result.get('error', '알 수 없는 오류') if result else '응답 없음'
                raise Exception(f"큐 상태 업데이트 실패: {error_msg}")
                
        except Exception as e:
            self.log_message(f"[❌] 큐에서 제거 실패: {job_id}, 오류: {e}")
            raise Exception(f"큐 상태 업데이트 실패: {job_id}")
    
    def call_php_function(self, function_name, params):
        """PHP 함수 호출을 위한 헬퍼"""
        try:
            # PHP 스크립트를 통해 분할 시스템 함수 호출
            php_script = f'''
<?php
require_once '/var/www/novacents/tools/queue_utils.php';

$params = json_decode('{json.dumps(params)}', true);
$result = ['success' => false];

try {{
    switch ('{function_name}') {{
        case 'update_queue_status_split':
            $success = update_queue_status_split(
                $params['queue_id'], 
                $params['new_status'], 
                $params['error_message']
            );
            $result = ['success' => $success];
            break;
            
        case 'remove_queue_split':
            $success = remove_queue_split($params['queue_id']);
            $result = ['success' => $success];
            break;
            
        default:
            $result = ['success' => false, 'error' => 'Unknown function'];
    }}
}} catch (Exception $e) {{
    $result = ['success' => false, 'error' => $e->getMessage()];
}}

echo json_encode($result);
?>
            '''
            
            # 임시 PHP 파일 생성
            temp_php_file = os.path.join(self.temp_dir, f"temp_php_{int(time.time())}_{random.randint(1000, 9999)}.php")
            os.makedirs(self.temp_dir, exist_ok=True)
            
            with open(temp_php_file, 'w', encoding='utf-8') as f:
                f.write(php_script)
            
            # PHP 실행
            result = subprocess.run(
                ['php', temp_php_file],
                capture_output=True,
                text=True,
                encoding='utf-8'
            )
            
            # 임시 파일 삭제
            try:
                os.remove(temp_php_file)
            except:
                pass
            
            if result.returncode == 0:
                return json.loads(result.stdout)
            else:
                self.log_message(f"[❌] PHP 스크립트 실행 오류: {result.stderr}")
                return None
                
        except Exception as e:
            self.log_message(f"[❌] PHP 함수 호출 실패: {e}")
            return None
    
    def get_pending_jobs(self):
        """대기 중인 작업 조회 (분할 시스템 지원)"""
        try:
            result = self.call_php_function('get_pending_queues_split', {'limit': 1})
            
            if result and result.get('success') and 'data' in result:
                return result['data']
            else:
                # 기존 방식으로 폴백
                queue = self.load_queue()
                pending_jobs = [job for job in queue if job.get('status') != 'completed']
                return pending_jobs[:1]  # 한 번에 하나씩 처리
                
        except Exception as e:
            self.log_message(f"[❌] 대기 작업 조회 실패: {e}")
            return []
    
    def generate_content_with_ai(self, job_data):
        """AI를 사용하여 콘텐츠 생성"""
        if not self.openai_config:
            self.log_message("[❌] OpenAI 설정이 없습니다.")
            return None
        
        try:
            # 상품 정보 추출
            products_info = self.extract_products_info(job_data)
            
            # 프롬프트 생성
            prompt = self.create_prompt(job_data, products_info)
            
            self.log_message("[🤖] AI 콘텐츠 생성 시작...")
            
            # OpenAI API 호출
            response = openai.ChatCompletion.create(
                model=self.openai_config.get('model', 'gpt-3.5-turbo'),
                messages=[
                    {"role": "system", "content": "당신은 전문적인 상품 리뷰 작가입니다. 매력적이고 유익한 상품 소개 글을 작성해주세요."},
                    {"role": "user", "content": prompt}
                ],
                max_tokens=self.openai_config.get('max_tokens', 2000),
                temperature=self.openai_config.get('temperature', 0.7)
            )
            
            content = response.choices[0].message.content
            self.log_message("[✅] AI 콘텐츠 생성 완료")
            
            return content
            
        except Exception as e:
            self.log_message(f"[❌] AI 콘텐츠 생성 실패: {e}")
            return None
    
    def extract_products_info(self, job_data):
        """작업 데이터에서 상품 정보 추출"""
        products = []
        
        keywords = job_data.get('keywords', [])
        for keyword_data in keywords:
            keyword_name = keyword_data.get('name', '')
            
            # products_data에서 정보 추출
            if 'products_data' in keyword_data:
                for product in keyword_data['products_data']:
                    if 'analysis_data' in product:
                        analysis = product['analysis_data']
                        products.append({
                            'keyword': keyword_name,
                            'title': analysis.get('title', ''),
                            'price': analysis.get('price', ''),
                            'description': analysis.get('description', ''),
                            'image_url': analysis.get('image_url', ''),
                            'url': product.get('url', '')
                        })
            
            # 기존 방식 지원 (aliexpress, coupang)
            for platform in ['aliexpress', 'coupang']:
                if platform in keyword_data:
                    for product in keyword_data[platform]:
                        products.append({
                            'keyword': keyword_name,
                            'title': product.get('title', ''),
                            'price': product.get('price', ''),
                            'description': product.get('description', ''),
                            'image_url': product.get('image_url', ''),
                            'url': product.get('url', ''),
                            'platform': platform
                        })
        
        return products
    
    def create_prompt(self, job_data, products_info):
        """AI 생성을 위한 프롬프트 생성"""
        title = job_data.get('title', '상품 소개')
        category = job_data.get('category_name', '')
        prompt_type = job_data.get('prompt_type', 'essential_items')
        
        # 상품 목록 문자열 생성
        products_text = "\n".join([
            f"- {p['title']} ({p['price']}) - {p['url']}"
            for p in products_info[:10]  # 최대 10개 상품
        ])
        
        prompt_templates = {
            'essential_items': f"""
제목: {title}
카테고리: {category}

다음 상품들을 바탕으로 "꼭 필요한 아이템"을 소개하는 블로그 글을 작성해주세요:

{products_text}

요구사항:
1. 매력적인 서론으로 시작
2. 각 상품의 특징과 장점 설명
3. 왜 이 상품이 필수인지 근거 제시
4. 자연스러운 구매 유도
5. HTML 형식으로 작성 (이미지 태그 포함)
6. 2000자 이상 작성
            """,
            
            'friend_review': f"""
제목: {title}
카테고리: {category}

다음 상품들을 "친구가 추천하는" 톤으로 리뷰 글을 작성해주세요:

{products_text}

요구사항:
1. 친근하고 개인적인 톤
2. 실제 사용 후기 느낌
3. 솔직한 장단점 언급
4. 친구에게 말하듯 자연스럽게
5. HTML 형식으로 작성
6. 2000자 이상 작성
            """,
            
            'professional_analysis': f"""
제목: {title}
카테고리: {category}

다음 상품들을 전문적으로 분석한 글을 작성해주세요:

{products_text}

요구사항:
1. 객관적이고 전문적인 분석
2. 기술적 특징 상세 설명
3. 비교 분석 포함
4. 구매 가이드 제공
5. HTML 형식으로 작성
6. 2000자 이상 작성
            """,
            
            'amazing_discovery': f"""
제목: {title}
카테고리: {category}

다음 상품들을 "놀라운 발견"으로 소개하는 흥미진진한 글을 작성해주세요:

{products_text}

요구사항:
1. 호기심을 자극하는 서론
2. 놀라운 기능이나 특징 강조
3. 감탄을 자아내는 표현 사용
4. 발견의 기쁨 전달
5. HTML 형식으로 작성
6. 2000자 이상 작성
            """
        }
        
        return prompt_templates.get(prompt_type, prompt_templates['essential_items'])
    
    def post_to_wordpress(self, job_data, content):
        """워드프레스에 포스트 발행"""
        if not self.wp_config:
            self.log_message("[❌] 워드프레스 설정이 없습니다.")
            return None
        
        try:
            # 포스트 데이터 준비
            post_data = {
                'title': job_data.get('title', '제목 없음'),
                'content': content,
                'status': 'publish',
                'categories': [job_data.get('category_id', 1)],
                'date': datetime.now().isoformat()
            }
            
            # 썸네일 URL이 있는 경우 featured_media 설정
            if job_data.get('thumbnail_url'):
                media_id = self.upload_featured_image(job_data['thumbnail_url'])
                if media_id:
                    post_data['featured_media'] = media_id
            
            # 워드프레스 API 엔드포인트
            api_url = f"{self.wp_config['site_url']}/wp-json/wp/v2/posts"
            
            # 인증 헤더
            headers = {
                'Authorization': f"Bearer {self.wp_config['access_token']}",
                'Content-Type': 'application/json'
            }
            
            self.log_message("[📤] 워드프레스에 포스트 발행 중...")
            
            # API 요청
            response = self.session.post(
                api_url,
                headers=headers,
                json=post_data,
                timeout=30
            )
            
            if response.status_code == 201:
                post_info = response.json()
                post_url = post_info.get('link', '')
                self.log_message(f"[✅] 워드프레스 발행 성공: {post_url}")
                
                # 🎉 keyword_processor.php가 파싱하는 성공 메시지 출력 (백업 파일 패턴 복원)
                print(f"워드프레스 발행 성공: {post_url}")
                
                return post_url
            else:
                self.log_message(f"[❌] 워드프레스 발행 실패: {response.status_code} - {response.text}")
                return None
                
        except Exception as e:
            self.log_message(f"[❌] 워드프레스 발행 중 오류: {e}")
            return None
    
    def upload_featured_image(self, image_url):
        """피처드 이미지 업로드"""
        try:
            # 이미지 다운로드
            response = self.session.get(image_url, timeout=30)
            if response.status_code != 200:
                return None
            
            # 파일명 생성
            timestamp = int(time.time())
            filename = f"featured_image_{timestamp}.jpg"
            
            # 워드프레스 미디어 업로드 API
            api_url = f"{self.wp_config['site_url']}/wp-json/wp/v2/media"
            
            headers = {
                'Authorization': f"Bearer {self.wp_config['access_token']}",
                'Content-Disposition': f'attachment; filename="{filename}"'
            }
            
            files = {
                'file': (filename, response.content, 'image/jpeg')
            }
            
            upload_response = self.session.post(
                api_url,
                headers={'Authorization': headers['Authorization']},
                files=files,
                timeout=30
            )
            
            if upload_response.status_code == 201:
                media_info = upload_response.json()
                media_id = media_info.get('id')
                self.log_message(f"[📷] 피처드 이미지 업로드 성공: {media_id}")
                return media_id
            else:
                self.log_message(f"[❌] 피처드 이미지 업로드 실패: {upload_response.status_code}")
                return None
                
        except Exception as e:
            self.log_message(f"[❌] 피처드 이미지 업로드 오류: {e}")
            return None
    
    def process_job(self, job_data, job_id):
        """단일 작업 처리"""
        try:
            title = job_data.get('title', '제목 없음')
            self.log_message(f"[📝] 작업 시작: {title}")
            
            # 처리 중 상태로 업데이트 (즉시 발행 모드가 아닌 경우만)
            if not self.immediate_mode:
                self.update_job_status(job_id, "processing")
            
            # AI 콘텐츠 생성
            content = self.generate_content_with_ai(job_data)
            if not content:
                raise Exception("AI 콘텐츠 생성 실패")
            
            # 워드프레스 발행
            post_url = self.post_to_wordpress(job_data, content)
            
            if post_url:
                # 성공 처리
                if self.immediate_mode:
                    # immediate 모드에서는 큐 업데이트 불필요 - 임시 파일만 정리됨
                    pass
                else:
                    # 일반 큐 처리인 경우 상태 업데이트
                    self.update_job_status(job_id, "completed")
                
                self.log_message(f"[✅] 작업 완료: {title} -> {post_url}")
                
                # 성공 알림
                self.send_success_notification(title, post_url)
                
                return True
            else:
                raise Exception("워드프레스 발행 실패")
                
        except Exception as e:
            self.log_message(f"[❌] 작업 실패: {e}")
            
            # 실패 처리 (즉시 발행 모드가 아닌 경우만)
            if not self.immediate_mode:
                self.update_job_status(job_id, "failed", str(e))
            
            return False
    
    def send_success_notification(self, title, post_url):
        """성공 알림 전송"""
        try:
            notification_data = {
                'title': title,
                'url': post_url,
                'timestamp': datetime.now().isoformat()
            }
            
            # 알림 파일 저장
            notification_file = os.path.join(self.base_dir, 'notifications.json')
            notifications = []
            
            if os.path.exists(notification_file):
                try:
                    with open(notification_file, 'r', encoding='utf-8') as f:
                        notifications = json.load(f)
                except:
                    notifications = []
            
            notifications.append(notification_data)
            
            # 최근 100개만 유지
            notifications = notifications[-100:]
            
            with open(notification_file, 'w', encoding='utf-8') as f:
                json.dump(notifications, f, ensure_ascii=False, indent=2)
                
        except Exception as e:
            self.log_message(f"[⚠️] 알림 저장 실패: {e}")
    
    def run_queue_processing(self):
        """큐 처리 실행 (일반 모드)"""
        if self.immediate_mode:
            self.log_message("[❌] 즉시 발행 모드에서는 큐 처리를 할 수 없습니다.")
            return
        
        self.log_message("[🚀] 큐 처리 시작")
        
        try:
            while True:
                # 대기 중인 작업 조회
                pending_jobs = self.get_pending_jobs()
                
                if not pending_jobs:
                    self.log_message("[😴] 처리할 작업이 없습니다. 5분 후 다시 확인...")
                    time.sleep(300)  # 5분 대기
                    continue
                
                # 첫 번째 작업 처리
                job = pending_jobs[0]
                job_id = job.get('queue_id', job.get('id', str(int(time.time()))))
                
                success = self.process_job(job, job_id)
                
                if success:
                    self.log_message("[🎉] 작업 성공적으로 완료")
                else:
                    self.log_message("[😞] 작업 실패")
                
                # 다음 작업까지 잠시 대기
                time.sleep(30)
                
        except KeyboardInterrupt:
            self.log_message("[🛑] 사용자에 의해 중단됨")
        except Exception as e:
            self.log_message(f"[💥] 예상치 못한 오류: {e}")
            self.log_message(traceback.format_exc())
        finally:
            self.cleanup_temp_files()
    
    def run_immediate_processing(self, temp_file_path):
        """즉시 발행 처리 (즉시 모드)"""
        if not self.immediate_mode:
            self.log_message("[❌] 일반 모드에서는 즉시 발행을 할 수 없습니다.")
            return False
        
        try:
            # 임시 파일에서 작업 데이터 로드
            job_data = self.load_job_from_temp_file(temp_file_path)
            if not job_data:
                self.log_message("[❌] 임시 파일 로드 실패")
                return False
            
            # job_id는 임시 파일의 기본 이름에서 추출
            job_id = os.path.splitext(os.path.basename(temp_file_path))[0]
            
            # 작업 처리
            success = self.process_job(job_data, job_id)
            
            # 임시 파일 정리
            self.cleanup_temp_files()
            
            return success
            
        except Exception as e:
            self.log_message(f"[💥] 즉시 발행 처리 중 오류: {e}")
            self.log_message(traceback.format_exc())
            self.cleanup_temp_files()
            return False

def main():
    """메인 함수"""
    parser = argparse.ArgumentParser(description='워드프레스 자동 발행 시스템')
    parser.add_argument('--immediate', action='store_true', help='즉시 발행 모드')
    parser.add_argument('--temp-file', help='임시 파일 경로 (즉시 발행 모드에서 사용)')
    parser.add_argument('--daemon', action='store_true', help='데몬 모드로 실행')
    
    args = parser.parse_args()
    
    try:
        # 발행기 초기화
        publisher = WordPressPublisher(immediate_mode=args.immediate)
        
        if args.immediate:
            # 즉시 발행 모드
            if not args.temp_file:
                print("[❌] 즉시 발행 모드에서는 --temp-file 옵션이 필요합니다.")
                sys.exit(1)
            
            if not os.path.exists(args.temp_file):
                print(f"[❌] 임시 파일을 찾을 수 없습니다: {args.temp_file}")
                sys.exit(1)
            
            success = publisher.run_immediate_processing(args.temp_file)
            sys.exit(0 if success else 1)
            
        else:
            # 일반 큐 처리 모드
            publisher.run_queue_processing()
            
    except Exception as e:
        print(f"[💥] 프로그램 실행 중 오류: {e}")
        print(traceback.format_exc())
        sys.exit(1)

if __name__ == "__main__":
    main()