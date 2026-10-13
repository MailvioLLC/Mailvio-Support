<?php

declare(strict_types=1);

namespace phpMyFAQ\Captcha\Helper;

use phpMyFAQ\Captcha\CaptchaInterface;
use phpMyFAQ\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TurnstileHelper::class)]
final class TurnstileHelperTest extends TestCase
{
    public function testRendersTheWidgetWithTheConfiguredSiteKey(): void
    {
        $helper = new TurnstileHelper($this->createConfiguration(true, '0x4AAAAAAFVnDcG63T6jyXKV'));

        $html = $helper->renderCaptcha($this->createStub(CaptchaInterface::class), 'ask', 'Captcha');

        self::assertStringContainsString('<label class="col-sm-3 col-form-label">Captcha</label>', $html);
        self::assertStringContainsString(
            '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>',
            $html,
        );
        self::assertStringContainsString(
            '<div class="cf-turnstile" data-sitekey="0x4AAAAAAFVnDcG63T6jyXKV"></div>',
            $html,
        );
    }

    public function testEscapesTheSiteKey(): void
    {
        $helper = new TurnstileHelper($this->createConfiguration(true, '"><script>alert(1)</script>'));

        $html = $helper->renderCaptcha($this->createStub(CaptchaInterface::class), 'ask', 'Captcha');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('data-sitekey="&quot;&gt;&lt;script&gt;', $html);
    }

    public function testRendersNothingWhenCaptchasAreDisabled(): void
    {
        $helper = new TurnstileHelper($this->createConfiguration(false, 'site-key'));

        self::assertSame('', $helper->renderCaptcha($this->createStub(CaptchaInterface::class), 'ask', 'Captcha'));
    }

    public function testRendersNothingForAuthenticatedUsers(): void
    {
        $helper = new TurnstileHelper($this->createConfiguration(true, 'site-key'));

        self::assertSame('', $helper->renderCaptcha(
            $this->createStub(CaptchaInterface::class),
            'ask',
            'Captcha',
            auth: true,
        ));
    }

    private function createConfiguration(bool $captchaEnabled, string $siteKey): Configuration
    {
        $configuration = $this->createStub(Configuration::class);
        $configuration
            ->method('get')
            ->willReturnMap([
                ['spam.enableCaptchaCode',    $captchaEnabled],
                ['security.turnstileSiteKey', $siteKey],
            ]);

        return $configuration;
    }
}
