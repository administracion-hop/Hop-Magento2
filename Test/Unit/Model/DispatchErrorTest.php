<?php

namespace Hop\Envios\Test\Unit\Model;

use Hop\Envios\Model\DispatchError;
use PHPUnit\Framework\TestCase;

/**
 * Clasificación del rechazo de credenciales (tarea 12908).
 */
class DispatchErrorTest extends TestCase
{
    /**
     * @return array [mensaje, código esperado]
     */
    public static function messageCases(): array
    {
        return [
            'corte del módulo por rechazo del inicio de sesión' => ['Hop rechazó las credenciales de inicio de sesión (Nombre de usuario y/o contraseña incorrecta).', DispatchError::CODE_CREDENTIALS],
            // Hop responde así también cuando acepta el inicio de sesión pero rechaza la llamada
            // (por ejemplo, un vendedor que no corresponde al cliente): no es de credenciales.
            '401 de una llamada no es credenciales' => ['Hubo un error al enviar su pedido a Hop: Authorization fail. ', null],
            'Hop no responde no es credenciales' => ['Sin respuesta de la API de Hop (error de conexión o timeout).', null],
            'otro error conocido sigue igual' => ['El código postal de origen es inválido', DispatchError::CODE_ORIGIN_ZIP],
        ];
    }

    /**
     * @dataProvider messageCases
     */
    public function testClassify($message, $expected): void
    {
        $this->assertSame($expected, DispatchError::classify($message));
    }

    public function testCredentialsAreNotRetriedByTime(): void
    {
        $this->assertNull(DispatchError::retryDelayMinutes(1, 401, DispatchError::CODE_CREDENTIALS));
        $this->assertSame(1, DispatchError::retryDelayMinutes(1, 503, null), 'el reintento por errores del servidor no cambia');
    }

    public function testCredentialsCanBeRetriedByHand(): void
    {
        $this->assertTrue(DispatchError::isRetryable(DispatchError::CODE_CREDENTIALS));
        $this->assertNotNull(DispatchError::getInstruction(DispatchError::CODE_CREDENTIALS));
    }

    public function testReferenceInUseCannotBeRetried(): void
    {
        $this->assertFalse(DispatchError::isRetryable(DispatchError::CODE_REFERENCE_IN_USE));
        $this->assertFalse(DispatchError::isRetryable(DispatchError::CODE_MULTIBULTO_PARTIAL));
        $this->assertTrue(DispatchError::isRetryable(DispatchError::CODE_ORIGIN_ZIP));
        $this->assertTrue(DispatchError::isRetryable(null));
    }
}
