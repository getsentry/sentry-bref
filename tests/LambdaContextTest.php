<?php

declare(strict_types=1);

namespace Sentry\Bref\Test;

use Bref\Context\Context;
use PHPUnit\Framework\TestCase;
use Sentry\Bref\LambdaContext;
use Sentry\Bref\Test\Support\CapturingTransport;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;

use function Sentry\captureMessage;

class LambdaContextTest extends TestCase
{
    private CapturingTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new CapturingTransport();
        SentrySdk::init()->bindClient(ClientBuilder::create([
            'dsn' => 'https://public@example.com/1',
            'default_integrations' => false,
        ])->setTransport($this->transport)->getClient());
    }

    protected function tearDown(): void
    {
        unset($_SERVER['LAMBDA_INVOCATION_CONTEXT']);
        LambdaContext::end();
        SentrySdk::init();
    }

    public function testAddsTheInvocationThatBrefPassesToTheProcess(): void
    {
        // e.g. a PHP-FPM request
        $_SERVER['LAMBDA_INVOCATION_CONTEXT'] = json_encode(new Context('request-id', (time() + 60) * 1000, 'fake-invoked-function-arn', 'trace-id'));

        LambdaContext::startFromEnvironment();
        captureMessage('Error in a PHP-FPM request');

        $contexts = $this->transport->errors()[0]->getContexts();
        $this->assertSame('request-id', $contexts['aws.lambda']['aws_request_id']);
        $this->assertSame('fake-invoked-function-arn', $contexts['aws.lambda']['invoked_function_arn']);
    }

    public function testDoesNothingOutsideAnInvocation(): void
    {
        LambdaContext::startFromEnvironment();
        captureMessage('Error');

        $this->assertArrayNotHasKey('aws.lambda', $this->transport->errors()[0]->getContexts());
    }
}
