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

        $html = $helper->renderCaptcha(
            $this->createStub(CaptchaInterface::class),
            'ask',
            'Please enter the captcha code',
        );

        self::assertStringNotContainsString('Please enter the captcha code', $html);
        self::assertStringContainsString('<div class="col-sm-9 offset-sm-3">', $html);
        self::assertStringContainsString(
            '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>',
            $html,
        );
        self::assertStringContainsString(
            '<div class="cf-turnstile" data-sitekey="0x4AAAAAAFVnDcG63T6jyXKV"></div>',
            $html,
        );
    }

    public function testLoadsTheScriptOnlyOnceForSeveralWidgetsOnOnePage(): void
    {
        $helper = new TurnstileHelper($this->createConfiguration(true, 'site-key'));
        $captcha = $this->createStub(CaptchaInterface::class);

        $html =
            $helper->renderCaptcha($captcha, 'ask', 'Captcha') . $helper->renderCaptcha($captcha, 'ticket', 'Captcha');

        self::assertSame(1, substr_count($html, TurnstileHelper::SCRIPT_URL));
        self::assertSame(2, substr_count($html, 'class="cf-turnstile"'));
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
