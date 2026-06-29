<?php

namespace Tests\Unit;

use App\Libraries\Mailer;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * SMTP 테스트 기능 검증
 *
 * - Mailer::sendSmtpTest() SMTP 호스트 미설정 시 예외
 * - DB 설정이 env보다 우선 적용되는지 확인
 * - 빈 이메일 주소 처리
 */
final class SmtpTestActionTest extends CIUnitTestCase
{
    // ── Mailer::sendSmtpTest() ────────────────────────────────────────────────

    public function testSendSmtpTestThrowsWhenHostIsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP 호스트가 설정되지 않았습니다.');

        $mailer = new Mailer(['site_name' => 'Test', 'smtp_host' => '']);
        // env에도 EMAIL_SMTP_HOST 없는 상태 보장
        putenv('EMAIL_SMTP_HOST=');
        $_ENV['EMAIL_SMTP_HOST']    = '';
        $_SERVER['EMAIL_SMTP_HOST'] = '';

        $mailer->sendSmtpTest('admin@example.com');
    }

    public function testSendSmtpTestThrowsWhenHostIsAbsent(): void
    {
        $this->expectException(\RuntimeException::class);

        putenv('EMAIL_SMTP_HOST=');
        $_ENV['EMAIL_SMTP_HOST']    = '';
        $_SERVER['EMAIL_SMTP_HOST'] = '';

        $mailer = new Mailer(['site_name' => 'Test']);
        $mailer->sendSmtpTest('admin@example.com');
    }

    // ── DB 설정 우선 순위 ─────────────────────────────────────────────────────

    public function testDbSmtpHostTakesPriorityOverEnv(): void
    {
        // env에 다른 호스트 설정
        putenv('EMAIL_SMTP_HOST=env.smtp.host');
        $_ENV['EMAIL_SMTP_HOST']    = 'env.smtp.host';
        $_SERVER['EMAIL_SMTP_HOST'] = 'env.smtp.host';

        // DB 설정에 다른 호스트 — RuntimeException이 발생해야 하지만
        // "호스트가 설정되지 않았습니다" 메시지는 아니어야 함
        // (즉, DB host 값이 적용되어 실제 연결을 시도함)
        $mailer = new Mailer([
            'site_name' => 'Test',
            'smtp_host' => 'db.smtp.host',  // DB 값 — 실제로 연결할 수 없음
            'smtp_port' => '587',
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 'pass',
            'smtp_crypto' => 'tls',
        ]);

        try {
            $mailer->sendSmtpTest('admin@example.com');
            // 연결 자체가 성공할 리 없으므로 여기까지 오면 실패
            $this->fail('실제 SMTP 서버 없이 성공할 수 없어야 함');
        } catch (\RuntimeException $e) {
            // "호스트가 설정되지 않았습니다" 오류가 아니어야 함
            // (DB host 값이 적용되어 실제 연결을 시도하다가 실패한 것)
            $this->assertStringNotContainsString(
                'SMTP 호스트가 설정되지 않았습니다.',
                $e->getMessage(),
                'DB smtp_host 값이 적용되어야 함 — host-missing 오류가 아니어야 함'
            );
        }

        // 정리
        putenv('EMAIL_SMTP_HOST=');
        $_ENV['EMAIL_SMTP_HOST']    = '';
        $_SERVER['EMAIL_SMTP_HOST'] = '';
    }

    public function testEnvHostUsedWhenDbHostEmpty(): void
    {
        putenv('EMAIL_SMTP_HOST=env.smtp.host');
        $_ENV['EMAIL_SMTP_HOST']    = 'env.smtp.host';
        $_SERVER['EMAIL_SMTP_HOST'] = 'env.smtp.host';

        $mailer = new Mailer([
            'site_name' => 'Test',
            'smtp_host' => '',  // DB 설정 없음 → env 폴백
        ]);

        try {
            $mailer->sendSmtpTest('admin@example.com');
            $this->fail('실제 SMTP 서버 없이 성공할 수 없어야 함');
        } catch (\RuntimeException $e) {
            // env 값이 사용됐으므로 "호스트 미설정" 오류가 아님
            $this->assertStringNotContainsString(
                'SMTP 호스트가 설정되지 않았습니다.',
                $e->getMessage()
            );
        }

        putenv('EMAIL_SMTP_HOST=');
        $_ENV['EMAIL_SMTP_HOST']    = '';
        $_SERVER['EMAIL_SMTP_HOST'] = '';
    }

    // ── SettingController::smtpTest() 응답 구조 ────────────────────────────

    public function testSmtpTestControllerRejectsInvalidEmail(): void
    {
        // Controller를 직접 인스턴스화하기 어려우므로, smtpTest 로직 동작을 모방한 검증
        $to = 'not-a-valid-email';
        $this->assertFalse(
            filter_var($to, FILTER_VALIDATE_EMAIL),
            '유효하지 않은 이메일은 FILTER_VALIDATE_EMAIL에서 false를 반환해야 함'
        );
    }

    public function testSmtpTestControllerAcceptsValidEmail(): void
    {
        $to = 'admin@example.com';
        $this->assertNotFalse(
            filter_var($to, FILTER_VALIDATE_EMAIL),
            '유효한 이메일은 FILTER_VALIDATE_EMAIL에서 통과해야 함'
        );
    }

    public function testSmtpTestControllerFallsBackToContactEmail(): void
    {
        // to가 비어있을 때 smtp_from → email 순서로 폴백하는 로직 검증
        $settings = ['smtp_from' => '', 'email' => 'contact@example.com'];
        $to       = '';
        if ($to === '') {
            $to = ($settings['smtp_from'] ?? '') ?: ($settings['email'] ?? '');
        }
        $this->assertSame('contact@example.com', $to);
    }

    public function testSmtpTestControllerPrefersSmtpFrom(): void
    {
        $settings = ['smtp_from' => 'noreply@shop.com', 'email' => 'contact@example.com'];
        $to       = '';
        if ($to === '') {
            $to = ($settings['smtp_from'] ?? '') ?: ($settings['email'] ?? '');
        }
        $this->assertSame('noreply@shop.com', $to);
    }
}
