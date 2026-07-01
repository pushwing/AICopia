#!/bin/bash

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"

frankenphp php-server \
    --listen :8200 \
    --root "$PROJECT_DIR/public"
