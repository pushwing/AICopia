<?php

namespace App\Libraries;

/**
 * 사이트 발송 이메일 중앙 관리
 * - sendVerify()     이메일 인증 (회원가입 / 재발송)
 * - sendInquiry()    문의 알림 (관리자 수신용)
 */
class Mailer
{
    private string $siteName;
    private string $siteUrl;
    private array  $settings;

    public function __construct(array $settings = [])
    {
        $this->siteName = $settings['site_name'] ?? '쇼핑몰';
        $this->siteUrl  = rtrim(base_url(), '/');
        $this->settings = $settings;
    }

    // ------------------------------------------------------------------ //
    //  Public senders
    // ------------------------------------------------------------------ //

    public function sendVerify(array $user, string $token): void
    {
        $verifyUrl = $this->siteUrl . '/auth/verify/' . $token;

        $subject = "[{$this->siteName}] 이메일 주소를 인증해주세요";

        $body = $this->layout(
            title: '이메일 주소를 인증해주세요',
            content:
                '<p style="margin:0 0 12px;font-size:15px;color:#374151;">안녕하세요,<br>' .
                '<strong>' . esc($this->siteName) . '</strong>에 가입해 주셔서 감사합니다!</p>' .
                '<p style="margin:0 0 24px;font-size:14px;color:#6b7280;">' .
                '아래 버튼을 클릭하면 이메일 인증이 완료됩니다.<br>' .
                '링크는 <strong>24시간</strong> 동안 유효합니다.' .
                '</p>' .
                $this->button('이메일 인증하기', $verifyUrl) .
                '<p style="margin:24px 0 0;font-size:12px;color:#9ca3af;word-break:break-all;">' .
                '버튼이 작동하지 않는다면 아래 링크를 복사해 브라우저에 붙여넣으세요.<br>' .
                '<span style="color:#6b7280;">' . esc($verifyUrl) . '</span>' .
                '</p>',
            footer: '본인이 요청하지 않았다면 이 메일을 무시하셔도 됩니다.'
        );

        if (! env('EMAIL_SMTP_HOST')) {
            log_message('info', "VERIFY_EMAIL | to={$user['email']} | url={$verifyUrl}");
            return;
        }

        $this->dispatch($user['email'], $subject, $body, "VERIFY_EMAIL | to={$user['email']}");
    }

    public function sendInquiry(string $toEmail, array $form): void
    {
        $subject  = $form['subject'] ?: '새 문의가 도착했습니다';
        $name     = esc($form['name'] ?? '');
        $email    = esc($form['email'] ?? '');
        $phone    = esc($form['phone'] ?? '');
        $message  = nl2br(esc($form['message'] ?? ''));
        $adminUrl = $this->siteUrl . '/admin/inquiries';

        $rows = '';
        if ($name)  $rows .= $this->row('이름',   $name);
        if ($email) $rows .= $this->row('이메일', "<a href='mailto:{$email}' style='color:#3b82f6;text-decoration:none;'>{$email}</a>");
        if ($phone) $rows .= $this->row('연락처', $phone);

        $body = $this->layout(
            title: '새 문의가 접수되었습니다',
            content:
                '<p style="margin:0 0 16px;">' .
                '<span style="display:inline-block;background:#eff6ff;color:#1d4ed8;font-size:12px;' .
                'font-weight:600;padding:3px 10px;border-radius:4px;letter-spacing:.3px;">' .
                esc($subject) . '</span></p>' .
                '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:16px;">' .
                $rows .
                '</table>' .
                '<div style="background:#f9fafb;border-left:3px solid #d1d5db;border-radius:0 4px 4px 0;' .
                'padding:12px 14px;font-size:13px;color:#374151;line-height:1.7;">' .
                $message .
                '</div>' .
                '<p style="margin:20px 0 0;">' .
                $this->button('어드민에서 확인하기', $adminUrl, '#374151') .
                '</p>',
            footer: '이 메일은 ' . esc($this->siteName) . ' 문의 폼에서 자동 발송되었습니다.'
        );

        try {
            $this->dispatch($toEmail, '[문의] ' . $subject, $body, "INQUIRY | to={$toEmail}");
        } catch (\Throwable) {
            // 이메일 발송 실패해도 문의 저장은 유지
        }
    }

    public function sendRestockAlert(string $toEmail, array $product, ?string $aiMessage = null): void
    {
        $productUrl = $this->siteUrl . '/shop/' . esc($product['slug']);

        // AI 개인화 문구가 있으면 사용, 없으면 기본 문구
        $intro = ($aiMessage !== null && trim($aiMessage) !== '')
            ? nl2br(esc(trim($aiMessage)))
            : '관심 상품이 재입고되었습니다!';

        $body = $this->layout(
            title: '재입고 알림',
            content:
                '<p style="margin:0 0 16px;font-size:15px;color:#374151;line-height:1.6;">' . $intro . '</p>' .
                '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">' .
                $this->row('상품명', esc($product['name'])) .
                '</table>' .
                $this->button('지금 구매하기', $productUrl),
            footer: '본 알림은 재입고 알림을 신청하신 분께만 발송됩니다.'
        );

        try {
            $this->dispatch($toEmail, "[{$this->siteName}] 재입고 알림 — {$product['name']}", $body, "RESTOCK | to={$toEmail}");
        } catch (\Throwable) {
            // 발송 실패해도 알림 발송 처리는 계속
        }
    }

    // ------------------------------------------------------------------ //
    //  HTML helpers
    // ------------------------------------------------------------------ //

    private function layout(string $title, string $content, string $footer = ''): string
    {
        $year  = date('Y');
        $name  = esc($this->siteName);
        $url   = esc($this->siteUrl);

        return <<<HTML
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,'Malgun Gothic','Apple SD Gothic Neo',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f3f4f6">
  <tr>
    <td align="center" style="padding:40px 16px;">

      <!-- Card -->
      <table width="560" cellpadding="0" cellspacing="0" border="0"
             style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb;">

        <!-- Header -->
        <tr>
          <td bgcolor="#1a2e4a" style="padding:28px 40px;text-align:center;">
            <a href="{$url}" style="text-decoration:none;">
              <span style="font-size:20px;font-weight:600;color:#ffffff;letter-spacing:.5px;">{$name}</span>
            </a>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:36px 40px 28px;">
            <h1 style="margin:0 0 20px;font-size:20px;font-weight:600;color:#111827;line-height:1.35;">{$title}</h1>
            {$content}
          </td>
        </tr>

        <!-- Divider -->
        <tr>
          <td style="padding:0 40px;">
            <div style="border-top:1px solid #f0f0f0;"></div>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td bgcolor="#f9fafb" style="padding:20px 40px;text-align:center;">
            <p style="margin:0 0 4px;font-size:12px;color:#9ca3af;line-height:1.6;">{$footer}</p>
            <p style="margin:0;font-size:11px;color:#d1d5db;">
              &copy; {$year} <a href="{$url}" style="color:#9ca3af;text-decoration:none;">{$name}</a>
            </p>
          </td>
        </tr>

      </table>
      <!-- /Card -->

    </td>
  </tr>
</table>
</body>
</html>
HTML;
    }

    private function button(string $label, string $url, string $bg = '#1a2e4a'): string
    {
        return "<table cellpadding='0' cellspacing='0' border='0'>" .
               "<tr><td bgcolor='{$bg}' style='border-radius:8px;'>" .
               "<a href='" . esc($url) . "' " .
               "style='display:inline-block;padding:13px 28px;font-size:14px;font-weight:600;" .
               "color:#ffffff;text-decoration:none;border-radius:8px;letter-spacing:.2px;'>" .
               esc($label) . "</a>" .
               "</td></tr></table>";
    }

    private function row(string $label, string $value): string
    {
        return "<tr>" .
               "<td style='padding:5px 0;font-size:13px;color:#9ca3af;white-space:nowrap;width:64px;'>{$label}</td>" .
               "<td style='padding:5px 0 5px 8px;font-size:13px;color:#374151;'>{$value}</td>" .
               "</tr>";
    }

    // ------------------------------------------------------------------ //
    //  Public: SMTP test (throws on failure)
    // ------------------------------------------------------------------ //

    public function sendSmtpTest(string $to): void
    {
        $s    = $this->settings;
        $host = ($s['smtp_host'] ?? '') ?: env('EMAIL_SMTP_HOST', '');
        if (! $host) {
            throw new \RuntimeException('SMTP 호스트가 설정되지 않았습니다.');
        }

        $subject = "[{$this->siteName}] SMTP 테스트 메일";
        $body    = $this->layout(
            title:   'SMTP 테스트 성공',
            content: '<p style="font-size:15px;color:#374151;">SMTP 설정이 정상적으로 작동합니다.<br>' .
                     '이 메일이 수신되었다면 발신 설정이 올바르게 구성된 것입니다.</p>',
            footer:  '이 메일은 관리자 패널에서 SMTP 연결 확인을 위해 발송되었습니다.'
        );

        $emailSvc = $this->buildEmailSvc();
        $emailSvc->setTo($to);
        $emailSvc->setSubject($subject);
        $emailSvc->setMessage($body);

        if (! $emailSvc->send()) {
            throw new \RuntimeException($emailSvc->printDebugger(['headers']));
        }
    }

    // ------------------------------------------------------------------ //
    //  SMTP dispatch
    // ------------------------------------------------------------------ //

    private function dispatch(string $to, string $subject, string $html, string $logTag): void
    {
        $emailSvc = $this->buildEmailSvc();
        $emailSvc->setTo($to);
        $emailSvc->setSubject($subject);
        $emailSvc->setMessage($html);

        try {
            if (! $emailSvc->send()) {
                log_message('error', "{$logTag}_FAIL | " . $emailSvc->printDebugger(['headers']));
            }
        } catch (\Throwable $e) {
            log_message('error', "{$logTag}_EXCEPTION | " . $e->getMessage());
        }
    }

    private function buildEmailSvc(): \CodeIgniter\Email\Email
    {
        $s        = $this->settings;
        $host     = ($s['smtp_host']   ?? '') ?: env('EMAIL_SMTP_HOST', '');
        $user     = ($s['smtp_user']   ?? '') ?: env('EMAIL_SMTP_USER', '');
        $pass     = ($s['smtp_pass']   ?? '') ?: env('EMAIL_SMTP_PASS', '');
        $port     = (int)(($s['smtp_port']   ?? '') ?: env('EMAIL_SMTP_PORT', 587));
        $crypto   = ($s['smtp_crypto'] ?? '') ?: env('EMAIL_SMTP_CRYPTO', 'tls');
        $from     = ($s['smtp_from']   ?? '') ?: env('EMAIL_FROM_ADDRESS', $user ?: 'noreply@example.com');

        $svc = \Config\Services::email();
        $svc->initialize([
            'protocol'   => 'smtp',
            'SMTPHost'   => $host,
            'SMTPUser'   => $user,
            'SMTPPass'   => $pass,
            'SMTPPort'   => $port,
            'SMTPCrypto' => $crypto,
            'mailType'   => 'html',
            'charset'    => 'utf-8',
        ]);
        $svc->setFrom($from, $this->siteName);
        return $svc;
    }
}
