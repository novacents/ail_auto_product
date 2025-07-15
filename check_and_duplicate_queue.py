#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
온라인서버 큐 확인 및 10개로 복제하는 스크립트
"""

import json
import os
from datetime import datetime

# 큐 파일 경로
queue_file = '/var/www/novacents/tools/product_queue.json'

print("🔍 온라인서버 큐 상태 확인 중...")

try:
    # 현재 큐 파일 읽기
    with open(queue_file, 'r', encoding='utf-8') as f:
        current_queue = json.load(f)
    
    print(f"📋 현재 큐 개수: {len(current_queue)}개")
    
    if len(current_queue) == 0:
        print("❌ 큐가 비어있습니다.")
        exit(1)
    
    # 첫 번째 큐를 기준으로 10개 복제
    base_queue = current_queue[0]
    print(f"📄 기준 큐: {base_queue['title']}")
    
    # 10개의 큐 생성
    duplicated_queue = []
    
    for i in range(10):
        # 기존 큐 복사
        new_queue = base_queue.copy()
        new_queue['keywords'] = [keyword.copy() for keyword in base_queue['keywords']]
        
        # 새로운 queue_id 생성 (마지막 숫자만 변경)
        base_id = base_queue['queue_id']
        new_id = base_id[:-5] + f"{48647 + i:05d}"
        new_queue['queue_id'] = new_id
        
        # 상태를 pending으로 초기화
        new_queue['status'] = 'pending'
        new_queue['attempts'] = 0
        new_queue['last_error'] = None
        
        duplicated_queue.append(new_queue)
        print(f"✅ 큐 {i+1}/10 생성: {new_id}")
    
    # 새로운 큐 파일로 저장
    with open(queue_file, 'w', encoding='utf-8') as f:
        json.dump(duplicated_queue, f, ensure_ascii=False, indent=4)
    
    print(f"🎉 온라인서버에 10개 큐 복제 완료!")
    print(f"📁 저장 위치: {queue_file}")
    
    # 생성된 큐 목록 출력
    print(f"\n📋 생성된 큐 목록:")
    for i, queue in enumerate(duplicated_queue, 1):
        print(f"  {i}. {queue['queue_id']} - {queue['title']}")

except FileNotFoundError:
    print(f"❌ 큐 파일을 찾을 수 없습니다: {queue_file}")
except Exception as e:
    print(f"❌ 오류 발생: {e}")