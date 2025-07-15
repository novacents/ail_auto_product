#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
온라인서버의 큐를 가져와서 출력하는 스크립트
"""

import json

# 온라인서버의 큐 파일 읽기
queue_file = '/var/www/novacents/tools/product_queue.json'

try:
    with open(queue_file, 'r', encoding='utf-8') as f:
        queue_data = json.load(f)
    
    # JSON 형태로 출력 (로컬서버에서 복사해서 사용할 수 있도록)
    print(json.dumps(queue_data, ensure_ascii=False, indent=4))

except Exception as e:
    print(f"오류: {e}")