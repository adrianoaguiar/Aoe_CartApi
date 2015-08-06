<?php

/**
 * Data Helper
 */
class Aoe_CartApi_Helper_Data extends Mage_Core_Helper_Data
{
    /**
     * @var Mage_Sales_Model_Quote
     */
    protected $quote;

    /**
     * @return Mage_Sales_Model_Quote
     */
    public function loadQuote($forceReload = false)
    {
        if (!$this->quote || $forceReload) {
            /** @var Mage_Checkout_Model_Session $session */
            $session = Mage::getSingleton('checkout/session');
            $quote = $session->getQuote();

            Mage::dispatchEvent('aoe_cartapi_load_quote_before', ['quote' => $quote]);

            // Email sync to be compatible with OPC and XMLconnect
            if ($quote->hasData('customer_email') && !$quote->getBillingAddress()->hasData('email')) {
                // Copy quote email to missing billing email
                $quote->getBillingAddress()->setEmail($quote->getCustomerEmail());
            } elseif (!$quote->hasData('customer_email') && $quote->getBillingAddress()->hasData('email')) {
                // Copy billing email to missing quote email
                $quote->setCustomerEmail($quote->getBillingAddress()->getEmail());
            } elseif ($quote->hasData('customer_email') && $quote->getBillingAddress()->hasData('email') && $quote->getCustomerEmail() !== $quote->getBillingAddress()->getEmail()) {
                // Sync quote email to match billing email
                $quote->setCustomerEmail($quote->getBillingAddress()->getEmail());
            }

            Mage::dispatchEvent('aoe_cartapi_load_quote_after', ['quote' => $quote]);

            $this->quote = $quote;
        }

        return $this->quote;
    }

    /**
     * @return Mage_Sales_Model_Quote
     */
    public function saveQuote()
    {
        $quote = $this->loadQuote();

        Mage::dispatchEvent('aoe_cartapi_save_quote_before', ['quote' => $quote]);

        $quote->getBillingAddress();

        // Email sync to be compatible with OPC and XMLconnect
        if ($quote->hasData('customer_email') && !$quote->getBillingAddress()->hasData('email')) {
            // Copy quote email to missing billing email
            $quote->getBillingAddress()->setEmail($quote->getCustomerEmail());
        } elseif (!$quote->hasData('customer_email') && $quote->getBillingAddress()->hasData('email')) {
            // Copy billing email to missing quote email
            $quote->setCustomerEmail($quote->getBillingAddress()->getEmail());
        } elseif ($quote->hasData('customer_email') && $quote->getBillingAddress()->hasData('email') && $quote->getCustomerEmail() !== $quote->getBillingAddress()->getEmail()) {
            // Sync billing email to match quote email
            $quote->getBillingAddress()->setEmail($quote->getCustomerEmail());
        }

        $quote->getShippingAddress()->setCollectShippingRates(true);

        $quote->collectTotals();

        $quote->save();

        /** @var Mage_Checkout_Model_Session $session */
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($quote->getId());

        Mage::dispatchEvent('aoe_cartapi_save_quote_after', ['quote' => $quote]);

        return $quote;
    }

    public function validateQuote(Mage_Sales_Model_Quote $quote)
    {
        $errors = [];

        if (!$quote->isVirtual()) {
            $address = $quote->getShippingAddress();

            if ($address->getSameAsBilling()) {
                // Copy data from billing address
                $address->importCustomerAddress($quote->getBillingAddress()->exportCustomerAddress());
                $address->setSameAsBilling(1);
            }

            $addressValidation = $address->validate();
            if ($addressValidation !== true) {
                $errors['shipping_address'] = $addressValidation;
            }

            $method = $address->getShippingMethod();
            $rate = $address->getShippingRateByCode($method);
            if (!$method || !$rate) {
                $errors['shipping_method'] = [$this->__('Please specify a valid shipping method.')];
            }
        }

        $addressValidation = $quote->getBillingAddress()->validate();
        if ($addressValidation !== true) {
            $errors['billing_address'] = $addressValidation;
        }

        try {
            if (!$quote->getPayment()->getMethod() || !$quote->getPayment()->getMethodInstance()) {
                $errors['payment'] = [$this->__('Please select a valid payment method.')];
            }
        } catch (Mage_Core_Exception $e) {
            $errors['payment'] = [$this->__('Please select a valid payment method.')];
        }

        return $errors;
    }

    /**
     * Remap attribute keys
     *
     * @param array $map
     * @param array $data
     *
     * @return array
     */
    public function mapAttributes(array $map, array &$data)
    {
        $out = [];

        foreach ($data as $key => &$value) {
            if (isset($map[$key])) {
                $key = $map[$key];
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Reverse remap the attribute keys
     *
     * @param array $map
     * @param array $data
     *
     * @return array
     */
    public function unmapAttributes(array $map, array &$data)
    {
        return $this->mapAttributes(array_flip($map), $data);
    }

    public function fixAddressData(array $data, $oldCountryId, $oldRegionId)
    {
        if (array_key_exists('country_id', $data) && !array_key_exists('region', $data)) {
            $data['region'] = $oldRegionId;
        }

        if (array_key_exists('region', $data)) {
            // Clear previous region_id
            $data['region_id'] = null;

            // Grab country_id
            $countryId = (array_key_exists('country_id', $data) ? $data['country_id'] : $oldCountryId);

            /** @var Mage_Directory_Model_Region $regionModel */
            $regionModel = Mage::getModel('directory/region');
            if (is_numeric($data['region'])) {
                $regionModel->load($data['region']);
                if ($regionModel->getId() && (empty($countryId) || $regionModel->getCountryId() == $countryId)) {
                    $data['region'] = $regionModel->getName();
                    $data['region_id'] = $regionModel->getId();
                    $data['country_id'] = $regionModel->getCountryId();
                }
            } elseif (!empty($countryId)) {
                $regionModel->loadByCode($data['region'], $countryId);
                if (!$regionModel->getId()) {
                    $regionModel->loadByName($data['region'], $countryId);
                }
                if ($regionModel->getId()) {
                    $data['region'] = $regionModel->getName();
                    $data['region_id'] = $regionModel->getId();
                }
            }
        }

        return $data;
    }
}
