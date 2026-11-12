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
     * @return array [mensaje, HTTP, código esperado]
     */
    public static function failureCases(): array
    {
        return [
            '401 manda sobre el texto' => ['Hubo un error al enviar su pedido a Hop: package.height', 401, DispatchError::CODE_CREDENTIALS],
            'texto de Hop con token inválido (pedidos viejos, sin HTTP)' => ['Hubo un error al enviar su pedido a Hop: Authorization fail. ', null, DispatchError::CODE_CREDENTIALS],
            'corte del módulo' => ['Hop rechazó las credenciales de inicio de sesión (Nombre de usuario y/o contraseña incorrecta).', 401, DispatchError::CODE_CREDENTIALS],
            'Hop no responde no es credenciales' => ['Sin respuesta de la API de Hop (error de conexión o timeout).', 0, null],
            'error del servidor no es credenciales' => ['Hubo un error al enviar su pedido a Hop: Server Error', 500, null],
            'otro error conocido sigue igual' => ['El código postal de origen es inválido', 422, DispatchError::CODE_ORIGIN_ZIP],
        ];
    }

    /**
     * @dataProvider failureCases
     */
    public function testClassifyFailure($message, $httpStatus, $expected): void
    {
        $this->assertSame($expected, DispatchError::classifyFailure($message, $httpStatus));
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
