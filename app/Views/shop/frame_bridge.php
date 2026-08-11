<?php
/**
 * @var string $targetUrl
 *
 * PG 결제 레이어(iframe) 안에서 세션이 비어 보일 때 쓰는 탈출 페이지.
 * 최상위 창을 같은 URL로 이동시켜 재요청하면, 그 요청은 최상위 이동이라
 * SameSite=Lax 세션 쿠키가 정상적으로 실린다.
 */
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>이동 중...</title>
</head>
<body>
이동 중입니다. 자동으로 넘어가지 않으면 <a id="bridgeLink" href="<?= esc($targetUrl, 'url') ?>">여기를 눌러주세요</a>.
<script>
(top !== self ? top : self).location.href = <?= json_encode($targetUrl, JSON_THROW_ON_ERROR) ?>;
</script>
</body>
</html>
