<?php
/**
 * 403 Forbidden — CodeIgniter4 가 $code, $message 를 넘겨준다.
 * 파일명이 error_{상태코드}.php 면 ExceptionHandler::determineView() 가
 * 자동으로 골라 쓰므로 Config/Exceptions.php 에 별도 매핑이 필요 없다.
 *
 * $message 는 예외 원문(BaseExceptionHandler::collectVars())이라 운영에서 노출하면
 * 내부 경로·쿼리 등이 새어 나간다. 개발 환경에서만 보여 주고 운영에서는 안내 문구로 대체한다.
 */
$aivCode    = $code ?? 403;
$aivHeading = '접근 권한이 없습니다';
$aivMessage = '이 페이지에 접근할 수 있는 권한이 없습니다.';

if (ENVIRONMENT !== 'production' && ! empty($message)) {
    $aivMessage = $message;
}

include __DIR__ . '/_partials/page.php';
