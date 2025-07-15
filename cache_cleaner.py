#!/usr/bin/env python3
"""
캐시 파일 자동 정리 시스템
메모리 사용량 최적화를 위한 도구
"""

import os
import time
import shutil
import json
from datetime import datetime, timedelta

class CacheCleaner:
    """캐시 파일 자동 정리"""
    
    def __init__(self, cache_dir="/var/www/novacents/tools/cache"):
        self.cache_dir = cache_dir
        self.max_age_hours = 2  # 2시간 이상 된 캐시 삭제
        self.max_cache_size_mb = 50  # 최대 50MB 캐시 유지
        
    def clean_old_cache_files(self):
        """오래된 캐시 파일 정리"""
        if not os.path.exists(self.cache_dir):
            return {"cleaned": 0, "size_freed": 0}
        
        cutoff_time = time.time() - (self.max_age_hours * 3600)
        cleaned_count = 0
        size_freed = 0
        
        try:
            for filename in os.listdir(self.cache_dir):
                file_path = os.path.join(self.cache_dir, filename)
                
                # 파일만 처리 (디렉토리 제외)
                if not os.path.isfile(file_path):
                    continue
                    
                # 파일 수정 시간 확인
                file_mtime = os.path.getmtime(file_path)
                
                if file_mtime < cutoff_time:
                    try:
                        file_size = os.path.getsize(file_path)
                        os.remove(file_path)
                        cleaned_count += 1
                        size_freed += file_size
                    except Exception as e:
                        continue
                        
        except Exception as e:
            pass
            
        return {
            "cleaned": cleaned_count,
            "size_freed": size_freed
        }
    
    def limit_cache_size(self):
        """캐시 크기 제한"""
        if not os.path.exists(self.cache_dir):
            return {"removed": 0, "size_freed": 0}
            
        # 캐시 파일 목록과 크기 수집
        cache_files = []
        total_size = 0
        
        try:
            for filename in os.listdir(self.cache_dir):
                file_path = os.path.join(self.cache_dir, filename)
                
                if os.path.isfile(file_path):
                    file_size = os.path.getsize(file_path)
                    file_mtime = os.path.getmtime(file_path)
                    
                    cache_files.append({
                        'path': file_path,
                        'size': file_size,
                        'mtime': file_mtime
                    })
                    total_size += file_size
                    
        except Exception as e:
            return {"removed": 0, "size_freed": 0}
        
        # 크기 제한 확인
        max_size_bytes = self.max_cache_size_mb * 1024 * 1024
        if total_size <= max_size_bytes:
            return {"removed": 0, "size_freed": 0}
        
        # 오래된 파일부터 삭제
        cache_files.sort(key=lambda x: x['mtime'])
        
        removed_count = 0
        size_freed = 0
        current_size = total_size
        
        for file_info in cache_files:
            if current_size <= max_size_bytes:
                break
                
            try:
                os.remove(file_info['path'])
                removed_count += 1
                size_freed += file_info['size']
                current_size -= file_info['size']
            except Exception as e:
                continue
        
        return {
            "removed": removed_count,
            "size_freed": size_freed
        }
    
    def get_cache_stats(self):
        """캐시 통계 정보"""
        if not os.path.exists(self.cache_dir):
            return {
                "total_files": 0,
                "total_size": 0,
                "oldest_file": None,
                "newest_file": None
            }
        
        total_files = 0
        total_size = 0
        oldest_time = None
        newest_time = None
        
        try:
            for filename in os.listdir(self.cache_dir):
                file_path = os.path.join(self.cache_dir, filename)
                
                if os.path.isfile(file_path):
                    total_files += 1
                    total_size += os.path.getsize(file_path)
                    
                    file_mtime = os.path.getmtime(file_path)
                    if oldest_time is None or file_mtime < oldest_time:
                        oldest_time = file_mtime
                    if newest_time is None or file_mtime > newest_time:
                        newest_time = file_mtime
                        
        except Exception as e:
            pass
        
        return {
            "total_files": total_files,
            "total_size": total_size,
            "oldest_file": datetime.fromtimestamp(oldest_time).strftime('%Y-%m-%d %H:%M:%S') if oldest_time else None,
            "newest_file": datetime.fromtimestamp(newest_time).strftime('%Y-%m-%d %H:%M:%S') if newest_time else None
        }
    
    def clean_all(self):
        """전체 캐시 정리 실행"""
        print("🧹 캐시 정리 시작")
        
        # 정리 전 통계
        before_stats = self.get_cache_stats()
        print(f"📊 정리 전: {before_stats['total_files']}개 파일, {before_stats['total_size']/1024/1024:.1f}MB")
        
        # 오래된 파일 정리
        old_result = self.clean_old_cache_files()
        print(f"⏰ 오래된 파일 정리: {old_result['cleaned']}개, {old_result['size_freed']/1024/1024:.1f}MB 해제")
        
        # 크기 제한 정리
        size_result = self.limit_cache_size()
        print(f"📦 크기 제한 정리: {size_result['removed']}개, {size_result['size_freed']/1024/1024:.1f}MB 해제")
        
        # 정리 후 통계
        after_stats = self.get_cache_stats()
        print(f"📊 정리 후: {after_stats['total_files']}개 파일, {after_stats['total_size']/1024/1024:.1f}MB")
        
        total_cleaned = old_result['cleaned'] + size_result['removed']
        total_freed = old_result['size_freed'] + size_result['size_freed']
        
        print(f"✅ 총 정리: {total_cleaned}개 파일, {total_freed/1024/1024:.1f}MB 해제")
        
        return {
            "success": True,
            "total_cleaned": total_cleaned,
            "total_freed": total_freed,
            "before_stats": before_stats,
            "after_stats": after_stats
        }

def main():
    """메인 실행 함수"""
    try:
        cleaner = CacheCleaner()
        result = cleaner.clean_all()
        print(json.dumps(result, ensure_ascii=False, indent=2))
        
    except Exception as e:
        print(json.dumps({
            "success": False,
            "error": str(e)
        }))

if __name__ == "__main__":
    main()