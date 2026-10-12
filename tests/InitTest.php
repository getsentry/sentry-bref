<?php

declare(strict_types=1);

namespace Sentry\Bref\Test;

use Bref\Bref;
use PHPUnit\Framework\TestCase;
use Sentry\Bref\Bref\BrefSubscriber;

class InitTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['BREF_RUNTIME']);
        Bref::reset();
    }

    public function testDoesNothingOutsideTheBrefRuntime(): void
    {
        Bref::reset();

        require __DIR__ . '/../src/init.php';

        $this->assertSame([], $this->subscribers());
    }

    public function testSubscribesInTheBrefRuntime(): void
    {
        Bref::reset();
        $_SERVER['BREF_RUNTIME'] = 'Bref\FunctionRuntime\Main';

        require __DIR__ . '/../src/init.php';

        $this->assertCount(1, $this->subscribers());
        $this->assertInstanceOf(BrefSubscriber::class, $this->subscribers()[0]);
    }

    /**
     * @return object[]
     */
    private function subscribers(): array
    {
        $subscribers = new \ReflectionProperty(Bref::events(), 'subscribers');

        return $subscribers->getValue(Bref::events());
    }
}
