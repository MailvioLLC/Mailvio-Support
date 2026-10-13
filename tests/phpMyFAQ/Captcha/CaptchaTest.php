<?php

namespace phpMyFAQ\Captcha;

use phpMyFAQ\Configuration;
use phpMyFAQ\Strings;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CaptchaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Strings::init();
    }

    public function testGetInstanceWithGoogleRecaptchaEnabled(): void
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('get')->willReturn(true);

        $captcha = Captcha::getInstance($configuration);

        $this->assertInstanceOf(GoogleRecaptcha::class, $captcha);
    }

    public function testGetInstanceWithGoogleRecaptchaDisabled(): void
    {
        $configuration = $this->createMock(Configuration::class);
        $configuration->method('get')->willReturn(false);

        $captcha = Captcha::getInstance($configuration);

        $this->assertInstanceOf(BuiltinCaptcha::class, $captcha);
    }

    public function testGetInstanceWithTurnstileEnabled(): void
    {
        $configuration = $this->createStub(Configuration::class);
        $configuration
            ->method('get')
            ->willReturnMap([
                ['security.enableTurnstile',         true],
                ['security.enableGoogleReCaptchaV2', false],
            ]);

        $captcha = Captcha::getInstance($configuration);

        $this->assertInstanceOf(Turnstile::class, $captcha);
    }
}
