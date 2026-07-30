#!/bin/bash

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"

# --no-compress: Homebrew frankenphp 빌드에 Brotli 인코더가 빠져 있어 압축 사용 시 기동 실패
frankenphp php-server \
    --listen :8303 \
    --root "$PROJECT_DIR/public" \
    --no-compress
