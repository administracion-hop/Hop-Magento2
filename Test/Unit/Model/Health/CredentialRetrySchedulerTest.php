<?php

namespace Hop\Envios\Test\Unit\Model\Health;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\Health\AccountResolver;
use Hop\Envios\Model\Health\CredentialRetryScheduler;
use Hop\Envios\Model\Health\CredentialState;
use Hop\Envios\Model\HopEnviosRepository;
use PHPUnit\Framework\TestCase;

/**
 * Reprogramación de los despachos que esperan credenciales, desde la tarea de despacho.
 */
class CredentialRetrySchedulerTest extends TestCase
{
    /** @var int[] vistas de tienda con despachos esperando */
    private $waiting = [];

    /** @var array estado por cuenta */
    private $states = [];

    /** @var array */
    private $scheduled = [];

    private function scheduler(): CredentialRetryScheduler
    {
        $repository = $this->createMock(HopEnviosRepository::class);
        $repository->method('getStoresWaitingForCredentials')->willReturnCallback(fn () => $this->waiting);
        $repository->method('scheduleCredentialRetries')->willReturnCallback(function ($storeIds) {
            $this->scheduled[] = $storeIds;
            return count($storeIds);
        });
        $resolver = $this->createMock(AccountResolver::class);
        $resolver->method('getAccountKey')->willReturnCallback(fn ($storeId) => 'acct:' . ($storeId === 3 ? 'b' : 'a'));
        $state = $this->createMock(CredentialState::class);
        $state->method('getState')->willReturnCallback(fn ($key) => $this->states[$key] ?? null);
        return new CredentialRetryScheduler($repository, $resolver, $state, $this->createMock(LoggerInterface::class));
    }

    public function testNothingWaitingIsOneQuery(): void
    {
        $this->assertSame(0, $this->scheduler()->run());
        $this->assertSame([], $this->scheduled);
    }

    public function testOnlyStoresWhoseAccountWorks(): void
    {
        $this->waiting = [1, 3];
        $this->states = ['acct:a' => CredentialState::OK, 'acct:b' => CredentialState::REJECTED];
        $this->scheduler()->run();
        $this->assertSame([[1]], $this->scheduled, 'la vista 3 sigue con credenciales rechazadas');
    }

    public function testUnknownStateWaits(): void
    {
        $this->waiting = [1];
        $this->scheduler()->run();
        $this->assertSame([], $this->scheduled, 'sin un inicio de sesión aceptado no se reintenta');
    }

    public function testAccountAlreadyGoodIsScheduled(): void
    {
        // Alguien cargó una contraseña mal y volvió a la correcta: la cuenta correcta ya figuraba
        // como buena, y sus pedidos igual se reprograman.
        $this->waiting = [1];
        $this->states = ['acct:a' => CredentialState::OK];
        $this->assertSame(1, $this->scheduler()->run());
    }
}
