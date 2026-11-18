<?php

namespace Hop\Envios\Test\Unit\Model\Health;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\Health\AccountResolver;
use Hop\Envios\Model\Health\Checks;
use Hop\Envios\Model\Health\CredentialState;
use Hop\Envios\Model\Health\ResultStorage;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;

/**
 * Estado de las credenciales: escribe solo cuando cambia y, al recuperarse, reprograma los
 * despachos que fallaron por credenciales.
 */
class CredentialStateTest extends TestCase
{
    /** @var array */
    private $cache = [];

    /** @var array|null */
    private $row = null;

    /** @var array */
    private $saved = [];


    /** @var CredentialState */
    private $state;

    protected function setUp(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn ($id) => $this->cache[$id] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id) {
            $this->cache[$id] = $data;
            return true;
        });
        $cache->method('remove')->willReturnCallback(function ($id) {
            unset($this->cache[$id]);
            return true;
        });

        $storage = $this->createMock(ResultStorage::class);
        $storage->method('load')->willReturnCallback(fn () => $this->row);
        $storage->method('save')->willReturnCallback(function ($scope, $code, $status, $message) {
            $this->saved[] = [$code, $status, $message];
        });

        $resolver = $this->createMock(AccountResolver::class);
        $resolver->method('getStoreIdsForAccount')->willReturn([1, 2]);

        $this->state = new CredentialState($cache, $storage, $resolver, $this->createMock(LoggerInterface::class));
    }

    public function testRejectionIsStored(): void
    {
        $this->state->record('acct:a', CredentialState::REJECTED, 'Nombre de usuario y/o contraseña incorrecta');
        $this->assertSame([[Checks::CREDENTIALS, Checks::SEVERITY_ERROR, 'Nombre de usuario y/o contraseña incorrecta']], $this->saved);
        $this->assertSame(CredentialState::REJECTED, $this->state->getState('acct:a'));
    }

    public function testRecoveryIsStored(): void
    {
        $this->state->record('acct:a', CredentialState::REJECTED, 'x');
        $this->state->record('acct:a', CredentialState::OK);
        $this->assertSame([Checks::CREDENTIALS, Checks::STATUS_OK, null], end($this->saved));
        $this->assertSame(CredentialState::OK, $this->state->getState('acct:a'));
    }

    public function testSameStateWritesNothing(): void
    {
        $this->state->record('acct:a', CredentialState::REJECTED, 'x');
        $this->saved = [];

        $this->state->record('acct:a', CredentialState::REJECTED, 'x');
        $this->assertSame([], $this->saved, 'el estado no cambia: no hay escrituras');
    }

    public function testStateSurvivesCacheFlush(): void
    {
        $this->row = ['status' => Checks::SEVERITY_ERROR];
        $this->assertSame(CredentialState::REJECTED, $this->state->getState('acct:a'));
        // Ya estaba rechazado en la tabla: registrar rechazado de nuevo no escribe.
        $this->state->record('acct:a', CredentialState::REJECTED, 'x');
        $this->assertSame([], $this->saved);
    }

    public function testForgottenAccountIsWrittenAgain(): void
    {
        $this->state->record('acct:a', CredentialState::REJECTED, 'x');
        // Runner::cleanUp borró la fila de la cuenta y la olvida en la caché.
        $this->state->forget('acct:a');
        $this->saved = [];

        $this->state->record('acct:a', CredentialState::REJECTED, 'x');
        $this->assertCount(1, $this->saved, 'la fila se vuelve a escribir');
    }

    public function testFailuresNeverBreakTheCaller(): void
    {
        $storage = $this->createMock(ResultStorage::class);
        $storage->method('load')->willThrowException(new \Exception('sin base'));
        $state = new CredentialState(
            $this->createMock(CacheInterface::class),
            $storage,
            $this->createMock(AccountResolver::class),
            $this->createMock(LoggerInterface::class)
        );
        $state->record('acct:a', CredentialState::OK);
        $this->addToAssertionCount(1);
    }
}
