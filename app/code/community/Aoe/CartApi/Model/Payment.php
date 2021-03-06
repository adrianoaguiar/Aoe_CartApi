<?php

class Aoe_CartApi_Model_Payment extends Aoe_CartApi_Model_Resource
{
    /**
     * Hash of external/internal attribute codes
     *
     * @var string[]
     */
    protected $attributeMap = [];

    /**
     * Hash of external attribute codes and their data type
     *
     * @var string[]
     */
    protected $attributeTypeMap = [];

    /**
     * Dispatch API call
     */
    public function dispatch()
    {
        $resource = $this->loadQuote()->getPayment();

        switch ($this->getActionType() . $this->getOperation()) {
            case self::ACTION_TYPE_ENTITY . self::OPERATION_RETRIEVE:
                $this->_render($this->prepareResource($resource));
                break;
            case self::ACTION_TYPE_ENTITY . self::OPERATION_CREATE:
            case self::ACTION_TYPE_ENTITY . self::OPERATION_UPDATE:
                $this->updateResource($resource, $this->getRequest()->getBodyParams());
                $this->saveQuote();
                $this->_render($this->prepareResource($resource));
                break;
            case self::ACTION_TYPE_ENTITY . self::OPERATION_DELETE:
                $resource->delete();
                $this->getResponse()->setMimeType($this->getRenderer()->getMimeType());
                $this->getResponse()->setHttpResponseCode(204);
                break;
            default:
                $this->_critical(self::RESOURCE_METHOD_NOT_ALLOWED);
        }
    }

    /**
     * @param Mage_Sales_Model_Quote_Payment $resource
     *
     * @return array
     */
    public function prepareResource(Mage_Sales_Model_Quote_Payment $resource)
    {
        // Store current state
        $actionType = $this->getActionType();
        $operation = $this->getOperation();

        // Change state
        $this->setActionType(self::ACTION_TYPE_ENTITY);
        $this->setOperation(self::OPERATION_RETRIEVE);

        // Get a filter instance
        $filter = $this->getFilter();

        // Get raw outbound data
        $data = $this->loadResourceAttributes($resource, $filter->getAttributesToInclude());

        // Fire event
        $data = new Varien_Object($data);
        Mage::dispatchEvent('aoe_cartapi_payment_prepare', ['data' => $data, 'filter' => $filter, 'resource' => $resource]);
        $data = $data->getData();

        // Filter outbound data
        $data = $filter->out($data);

        // Fix data types
        $data = $this->fixTypes($data);

        // Add null values for missing data
        foreach ($this->getFilter()->getAttributesToInclude() as $code) {
            if (!array_key_exists($code, $data)) {
                $data[$code] = null;
            }
        }

        // Sort the result by key
        ksort($data);

        // Restore old state
        $this->setActionType($actionType);
        $this->setOperation($operation);

        // Return prepared outbound data
        return $data;
    }

    /**
     * Update the resource model
     *
     * @param Mage_Sales_Model_Quote_Payment $resource
     * @param array                          $data
     *
     * @return Mage_Sales_Model_Quote_Item
     */
    public function updateResource(Mage_Sales_Model_Quote_Payment $resource, array $data)
    {
        // Store current state
        $actionType = $this->getActionType();
        $operation = $this->getOperation();

        // Change state
        $this->setActionType(self::ACTION_TYPE_ENTITY);
        $this->setOperation(self::OPERATION_UPDATE);

        // Get a filter instance
        $filter = $this->getFilter();

        // Fire event - before filter
        $data = new Varien_Object($data);
        Mage::dispatchEvent('aoe_cartapi_payment_update_before', ['data' => $data, 'filter' => $filter, 'resource' => $resource]);
        $data = $data->getData();

        // Filter raw incoming data
        $data = $filter->in($data);

        // Clean up input format to what Magento expects
        if (isset($data['data']) && is_array($data['data'])) {
            $base = $data;
            unset($base['data']);
            $data = array_merge($data['data'], $base);
        } else {
            unset($data['data']);
        }

        // Map data keys
        $data = $this->mapAttributes($data);

        // Manual data setting
        $quote = $resource->getQuote();
        if ($quote->isVirtual()) {
            $quote->getBillingAddress()->setPaymentMethod(isset($data['method']) ? $data['method'] : null);
        } else {
            $quote->getShippingAddress()->setPaymentMethod(isset($data['method']) ? $data['method'] : null);

            // Shipping totals may be affected by payment method
            $quote->getShippingAddress()->setCollectShippingRates(true);
        }

        // Define validation checks
        $data['checks'] = Mage_Payment_Model_Method_Abstract::CHECK_USE_CHECKOUT
            | Mage_Payment_Model_Method_Abstract::CHECK_USE_FOR_COUNTRY
            | Mage_Payment_Model_Method_Abstract::CHECK_USE_FOR_CURRENCY
            | Mage_Payment_Model_Method_Abstract::CHECK_ORDER_TOTAL_MIN_MAX;

        // Update model
        $resource->importData($data);

        // Fire event - after
        $data = new Varien_Object($data);
        Mage::dispatchEvent('aoe_cartapi_payment_update_after', ['data' => $data, 'filter' => $filter, 'resource' => $resource]);

        // Restore old state
        $this->setActionType($actionType);
        $this->setOperation($operation);

        // Return updated resource
        return $resource;
    }
}
