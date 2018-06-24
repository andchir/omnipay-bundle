<?php

namespace Andchir\OmnipayBundle\Service;

use Omnipay\Common\GatewayInterface;
use Omnipay\Omnipay as OmnipayCore;
use Omnipay\Omnipay;
use Psr\Log\LoggerInterface;

class OmnipayService
{
    /** @var GatewayInterface */
    protected $gateway;
    /** @var array */
    protected $config;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @param $gatewayName
     * @return bool
     */
    public function create($gatewayName)
    {
        if (!isset($this->config['gateways'][$gatewayName])) {
            return false;
        }
        $this->gateway = Omnipay::create($gatewayName);
        $this->setGatewayParameters();
        return true;
    }

    /**
     * @return GatewayInterface
     */
    public function getGateway()
    {
        return $this->gateway;
    }

    /**
     * Set gateway parameters
     */
    public function setGatewayParameters()
    {
        $gatewayName = $this->gateway->getShortName();
        $parameters = isset($this->config['gateways'][$gatewayName])
            ? $this->config['gateways'][$gatewayName]
            : [];
        foreach($parameters as $paramName => $value){
            $methodName = 'set' . $paramName;
            if (!empty($value) && method_exists($this->gateway, $methodName)) {//case-insensitive
                call_user_func(array($this->gateway, $methodName), $value);
            }
        }
    }

    /**
     * @param $optionName
     * @param $optionValue
     */
    public function setConfigOption($optionName, $optionValue)
    {
        $gatewayName = $this->gateway->getShortName();
        $this->config['gateways'][$gatewayName][$optionName] = $optionValue;
    }

    /**
     * @param $options
     */
    public function setConfigOptions($options)
    {
        foreach ($options as $optionName => $optionValue) {
            $this->setConfigOption($optionName, $optionValue);
        }
    }

    /**
     * @param $type
     * @return mixed|string
     */
    public function getConfigUrl($type)
    {
        return isset($this->config[$type.'_url'])
            ? $this->config[$type.'_url']
            : '';
    }

    /**
     * @param $optionName
     * @return string
     */
    public function getConfigOption($optionName)
    {
        $gatewayName = $this->gateway->getShortName();
        return isset($this->config['gateways'][$gatewayName][$optionName])
            ? $this->config['gateways'][$gatewayName][$optionName]
            : '';
    }

    /**
     * @return array
     */
    public function getGatewayParameters()
    {
        return $this->gateway->getParameters();
    }

    /**
     * @return array
     */
    public function getGatewayDefaultParameters()
    {
        return $this->gateway->getDefaultParameters();
    }

    /**
     * @param $paymentId
     * @param $description
     * @param $options
     * @return \Omnipay\Common\Message\RequestInterface
     */
    public function createPurchase($paymentId, $description, $options)
    {
        $purchase = $this->gateway->purchase($options);
        $purchase->setTransactionId($paymentId);
        $purchase->setDescription($description);
        if ($this->getConfigOption('returnUrl')) {
            $purchase->setReturnUrl($this->getConfigOption('returnUrl'));
        }
        if ($this->getConfigOption('cancelUrl')) {
            $purchase->setCancelUrl($this->getConfigOption('cancelUrl'));
        }
        if ($this->getConfigOption('notifyUrl')) {
            $purchase->setNotifyUrl($this->getConfigOption('notifyUrl'));
        }
        return $purchase;
    }

    /**
     * @param $message
     * @param $source
     */
    public function logInfo($message, $source)
    {
        $this->logger->info($message, ['omnipay' => $source]);
    }

    /**
     * Number to decimal
     * @param $number
     * @return string
     */
    public static function toDecimal( $number )
    {
        $number = number_format($number, 2, '.', '');
        return $number;
    }
}
