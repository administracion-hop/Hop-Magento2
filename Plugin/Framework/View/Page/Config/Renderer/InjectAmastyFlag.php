<?php
declare(strict_types=1);

namespace Hop\Envios\Plugin\Framework\View\Page\Config\Renderer;

use Magento\Framework\View\Page\Config\Renderer;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Asset\GroupedCollection;
use Hop\Envios\Helper\Data as Helper;
use ParagonIE\Sodium\Core\Curve25519\H;

class InjectAmastyFlag
{
    /**
     * @var Helper
     */
    private Helper $helper;

    /**
     * @var Repository
     */
    private Repository $assetRepo;

    /**
     * @var GroupedCollection
     */
    private GroupedCollection $pageAssets;

    /**
     * @param Helper $helper
     * @param Repository $assetRepo
     * @param GroupedCollection $pageAssets
     */
    public function __construct(
        Helper $helper,
        Repository $assetRepo,
        GroupedCollection $pageAssets
    ) {
        $this->helper = $helper;
        $this->assetRepo = $assetRepo;
        $this->pageAssets = $pageAssets;
    }

    /**
     * @param Renderer $subject
     * @param array $resultGroups
     * @return array
     */
    public function beforeRenderAssets(Renderer $subject, $resultGroups = [])
    {
        $file = $this->helper->isAmastyOscEnabled()
            ? 'Hop_Envios::js/hopAmastyCheckoutEnabled.js'
            : 'Hop_Envios::js/hopAmastyCheckoutDisabled.js';

        $asset = $this->assetRepo->createAsset($file);
        $this->pageAssets->insert($file, $asset, 'requirejs/require.js');

        return [$resultGroups];
    }
}
