<?php

use App\Libraries\PG\PGFactory;
use App\Libraries\PG\PGInterface;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class PGFactoryTest extends CIUnitTestCase
{
    public function testMakeReturnsInterfaceForEveryProvider(): void
    {
        foreach (PGFactory::providers() as $provider) {
            $this->assertInstanceOf(
                PGInterface::class,
                PGFactory::make($provider),
                "{$provider} 어댑터가 PGInterface를 구현해야 합니다."
            );
        }
    }

    public function testMakeThrowsOnUnknownProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PGFactory::make('unknown_pg');
    }

    public function testProvidersReturnsSevenItems(): void
    {
        $this->assertCount(7, PGFactory::providers());
    }

    public function testLabelsKeysMatchProviders(): void
    {
        $this->assertSame(
            PGFactory::providers(),
            array_keys(PGFactory::labels()),
            'labels() 키가 providers()와 일치해야 합니다.'
        );
    }

    public function testLabelsValuesAreNonEmptyStrings(): void
    {
        foreach (PGFactory::labels() as $key => $label) {
            $this->assertIsString($label);
            $this->assertNotEmpty($label, "{$key} 레이블이 비어있으면 안 됩니다.");
        }
    }
}
