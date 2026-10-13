<?php

declare(strict_types=1);

namespace phpMyFAQ\Captcha;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Turnstile::class)]
final class TurnstileTest extends TestCase
{
    public function testAValidTokenIsAccepted(): void
    {
        $turnstile = $this->createTurnstile('secret-key', '{"success":true,"hostname":"support.mailvio.com"}');

        self::assertTrue($turnstile->checkCaptchaCode('valid-token'));
    }

    public function testTheTokenIsPostedToSiteverifyTogetherWithTheSecretKey(): void
    {
        $turnstile = $this->createTurnstile('secret-key', '{"success":true}');

        $turnstile->checkCaptchaCode('token-123');

        self::assertSame(Turnstile::SITEVERIFY_URL, $turnstile->requestedUrl);
        self::assertSame(['secret' => 'secret-key', 'response' => 'token-123'], $turnstile->postedFields);
    }

    public function testATokenCloudflareRejectsIsRefused(): void
    {
        $turnstile = $this->createTurnstile('secret-key', '{"success":false,"error-codes":["timeout-or-duplicate"]}');

        self::assertFalse($turnstile->checkCaptchaCode('already-used-token'));
    }

    public function testAnUnreachableSiteverifyEndpointRefusesTheToken(): void
    {
        $turnstile = $this->createTurnstile('secret-key', false);

        self::assertFalse($turnstile->checkCaptchaCode('valid-token'));
    }

    public function testAMalformedSiteverifyResponseRefusesTheToken(): void
    {
        $turnstile = $this->createTurnstile('secret-key', 'not json');

        self::assertFalse($turnstile->checkCaptchaCode('valid-token'));
    }

    public function testAnEmptyTokenIsRefusedWithoutCallingCloudflare(): void
    {
        $turnstile = $this->createTurnstile('secret-key', '{"success":true}');

        self::assertFalse($turnstile->checkCaptchaCode(''));
        self::assertSame('', $turnstile->requestedUrl);
    }

    public function testAMissingSecretKeyRefusesEveryTokenWithoutCallingCloudflare(): void
    {
        $turnstile = $this->createTurnstile('', '{"success":true}');

        self::assertFalse($turnstile->checkCaptchaCode('valid-token'));
        self::assertSame('', $turnstile->requestedUrl);
    }

    public function testLoggedInUsersSkipTheCheck(): void
    {
        $turnstile = $this->createTurnstile('secret-key', false);

        self::assertSame($turnstile, $turnstile->setUserIsLoggedIn(true));
        self::assertTrue($turnstile->isUserIsLoggedIn());
        self::assertTrue($turnstile->checkCaptchaCode(''));
        self::assertSame('', $turnstile->requestedUrl);
    }

    public function testVisitorsAreNotLoggedInByDefault(): void
    {
        self::assertFalse(new Turnstile('secret-key')->isUserIsLoggedIn());
    }

    private function createTurnstile(string $secretKey, string|false $siteverifyResponse): Turnstile
    {
        return new class($secretKey, $siteverifyResponse) extends Turnstile {
            public string $requestedUrl = '';

            /** @var array<string, string> */
            public array $postedFields = [];

            public function __construct(
                string $secretKey,
                private readonly string|false $siteverifyResponse,
            ) {
                parent::__construct($secretKey);
            }

            protected function postForm(string $url, array $fields): string|false
            {
                $this->requestedUrl = $url;
                $this->postedFields = $fields;

                return $this->siteverifyResponse;
            }
        };
    }
}
