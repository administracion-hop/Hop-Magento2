<?php
namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Model\AuthorizationService;
use Magento\Integration\Api\OauthServiceInterface;
use Psr\Log\LoggerInterface;

class CreateHopIntegration implements DataPatchInterface, PatchRevertableInterface
{
    private $integrationService;
    private $authorizationService;
    private $oauthService;
    private $moduleDataSetup;
    private $logger;

    public function __construct(
        IntegrationServiceInterface $integrationService,
        AuthorizationService $authorizationService,
        OauthServiceInterface $oauthService,
        ModuleDataSetupInterface $moduleDataSetup,
        LoggerInterface $logger
    ) {
        $this->integrationService = $integrationService;
        $this->authorizationService = $authorizationService;
        $this->oauthService = $oauthService;
        $this->moduleDataSetup = $moduleDataSetup;
        $this->logger = $logger;
    }

    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $integrationData = [
            'name' => 'Hop Integration',
            'email' => 'contact@hop.com',
            'endpoint' => 'https://api.hop.com',
            'status' => \Magento\Integration\Model\Integration::STATUS_ACTIVE
        ];

        try {
            $integration = $this->integrationService->create($integrationData);

            $resources = [
                'Magento_Backend::admin',
                'Hop_Envios::webhook_notifications',
                'Magento_Webapi::system',
                'Magento_Integration::integration',
                'Magento_Backend::store',
                'Magento_Backend::web'
            ];

            $this->authorizationService->grantPermissions($integration->getId(), $resources);
            $this->oauthService->createAccessToken($integration->getId());
            $this->moduleDataSetup->endSetup();
        } catch (\Exception $e) {
            $this->logger->error('Error creating Hop integration: ' . $e->getMessage());
        }
        return $this;
    }

    public function revert()
    {
        $this->moduleDataSetup->startSetup();
        $this->moduleDataSetup->endSetup();
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }

    public static function getVersion()
    {
        return '1.0.0';
    }
}