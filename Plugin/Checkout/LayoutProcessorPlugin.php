<?php

namespace Hop\Envios\Plugin\Checkout;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Hop\Envios\Helper\Data;

class LayoutProcessorPlugin
{
    protected $scopeConfig;
    protected $helper;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Data $helper
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->helper = $helper;
    }

    /**
     * @param \Magento\Checkout\Block\Checkout\LayoutProcessor $subject
     * @param array $jsLayout
     * @return array
     */
    public function afterProcess(
        \Magento\Checkout\Block\Checkout\LayoutProcessor $subject,
        array $jsLayout
    ) {
        $showVat = $this->helper->IsVAtShowToFrontend();
        $frontendVisibility = $this->helper->IsVAtShowToFrontend();
        $useHopVat = $this->helper->useCustomerTaxvat();

        $shouldShow = false;
        $isRequired = false;

        switch ($showVat) {
            case 'opt':
                $shouldShow = true;
                $isRequired = false;
                break;
            case 'req':
                $shouldShow = true;
                $isRequired = true;
                break;
        }

        if (!$frontendVisibility) {
            $shouldShow = false;
        }

        if (!$shouldShow) {
            return $jsLayout;
        }

        $shippingFields = &$jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'];

        if (isset($shippingFields['vat_id'])) {
            $shippingFields['vat_id']['label'] = __('DNI');
            $shippingFields['vat_id']['validation'] = [
                'min_text_length' => 7,
                'max_text_length' => 8,
                'required-entry' => $isRequired,
                'validate-number' => true,
                'validate-digits' => true
            ];
        } elseif ($useHopVat && $shouldShow)  {
            $shippingFields['vat_id'] = [
                'component' => 'Magento_Ui/js/form/element/abstract',
                'config' => [
                    'customScope' => 'shippingAddress',
                    'template' => 'ui/form/field',
                    'elementTmpl' => 'ui/form/element/input',
                    'id' => 'vat_id',
                ],
                'dataScope' => 'shippingAddress.vat_id',
                'label' => __('DNI'),
                'provider' => 'checkoutProvider',
                'sortOrder' => 85,
                'visible' => true,
                'validation' => [
                    'min_text_length' => 7,
                    'max_text_length' => 8,
                    'required-entry' => $isRequired,
                    'validate-number' => true,
                    'validate-digits' => true
                ],
                'required' => $isRequired,
            ];
        }

        if (isset($jsLayout['components']['checkout']['children']['steps']['children']['billing-step'])) {
            $paymentGroups = &$jsLayout['components']['checkout']['children']['steps']['children']['billing-step']
                ['children']['payment']['children']['payments-list']['children'];

            foreach ($paymentGroups as $paymentGroup => &$groupConfig) {
                if (isset($groupConfig['component']) && $groupConfig['component'] === 'Magento_Checkout/js/view/billing-address') {
                    $formFields = &$groupConfig['children']['form-fields']['children'];

                    if (isset($formFields['vat_id'])) {
                        $formFields['vat_id']['label'] = __('DNI');
                        $formFields['vat_id']['validation'] = [
                            'min_text_length' => 7,
                            'max_text_length' => 20,
                            'required-entry' => $isRequired,
                            'validate-number' => true,
                            'validate-digits' => true
                        ];
                    } elseif ($useHopVat && $shouldShow)  {
                        $formFields['vat_id'] = [
                            'component' => 'Magento_Ui/js/form/element/abstract',
                            'config' => [
                                'customScope' => 'billingAddress' . $paymentGroup,
                                'template' => 'ui/form/field',
                                'elementTmpl' => 'ui/form/element/input',
                                'id' => 'vat_id_' . $paymentGroup,
                            ],
                            'dataScope' => 'billingAddress' . $paymentGroup . '.vat_id',
                            'label' => __('DNI'),
                            'provider' => 'checkoutProvider',
                            'sortOrder' => 85,
                            'visible' => true,
                            'validation' => [
                                'min_text_length' => 7,
                                'max_text_length' => 20,
                                'required-entry' => $isRequired,
                                'validate-number' => true,
                                'validate-digits' => true
                            ],
                            'required' => $isRequired,
                        ];
                    }
                }
            }
        }

        return $jsLayout;
    }
}
