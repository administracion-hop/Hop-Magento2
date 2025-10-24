<?php
declare(strict_types=1);

namespace Hop\Envios\Plugin\Framework\View\Page\Config\Renderer;

use Magento\Framework\View\Page\Config\Renderer;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Asset\GroupedCollection;
use Hop\Envios\Helper\Data as Helper; // tu helper con isAmastyOscEnabled()

class InjectAmastyFlag
{
    public function __construct(
        private Helper $helper,
        private Repository $assetRepo,
        private GroupedCollection $pageAssets
    ) {}

    /**
     * Si Amasty OSC NO está habilitado (según tu helper), inyecta un JS
     * que setea una variable global para desactivar mixins/overrides.
     *
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
