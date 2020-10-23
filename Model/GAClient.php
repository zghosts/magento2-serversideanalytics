<?php
namespace Elgentos\ServerSideAnalytics\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Psr\Log\LoggerInterface;
use TheIconic\Tracking\GoogleAnalytics\Analytics;

class GAClient
{

    const GOOGLE_ANALYTICS_SERVERSIDE_ENABLED        = 'google/serverside_analytics/enabled';
    const GOOGLE_ANALYTICS_SERVERSIDE_UA             = 'google/serverside_analytics/ua';
    const GOOGLE_ANALYTICS_SERVERSIDE_DEBUG_MODE     = 'google/serverside_analytics/debug_mode';
    const GOOGLE_ANALYTICS_SERVERSIDE_ENABLE_LOGGING = 'google/serverside_analytics/enable_logging';

    /**
     * Analytics object which holds transaction data
     *
     * @var Analytics analytics
     */
    protected $analytics;

    /* Google Analytics Measurement Protocol API version */
    protected $version = '1';

    /* Count how many products are added to the Analytics object */
    protected $productCounter = 0;

    /**
     * @var State
     */
    private $state;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Elgentos_ServerSideAnalytics_Model_GAClient constructor.
     * @param State $state
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        State $state,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->state = $state;
        $this->scopeConfig = $scopeConfig;

        $this->analytics = new Analytics(true);

        if ($this->state->getMode() === State::MODE_DEVELOPER
            || $this->scopeConfig->isSetFlag(self::GOOGLE_ANALYTICS_SERVERSIDE_DEBUG_MODE)
        ) {
            // $this->analytics = new Analytics(true, true);
            // for dev/staging envs where dev mode is off but we don't want to send events
            $this->analytics->setDebug(true);
        }
        $this->logger = $logger;
    }

    /**
     * @param DataObject $data
     * @throws \Exception
     */
    public function setTrackingData(DataObject $data)
    {
        if (!$data->getTrackingId()) {
            throw new \Exception('No tracking ID set for GA client.');
        }

        if (!$data->getClientId() && !$data->getUserId()) {
            throw new \Exception('No client ID or user ID is set for GA client; at least one is necessary.');
        }

        $this->analytics->setProtocolVersion($this->version)
            ->setTrackingId($data->getTrackingId()) // 'UA-26293624-12'
            ->setIpOverride($data->getIpOverride()); // '123'

        if ($data->getClientId()) {
            $this->analytics->setClientId($data->getClientId()); // '2133506694.1448249699'
        }

        if ($data->getUserId()) {
            $this->analytics->setUserId($data->getUserId());
        }

        if ($data->getUserAgentOverride()) {
            $this->analytics->setUserAgentOverride($data->getUserAgentOverride());
        }

        if ($data->getDocumentPath()) {
            $this->analytics->setDocumentPath($data->getDocumentPath());
        }
    }

    /**
     * @param $data
     */
    public function setTransactionData($data)
    {
        $this->analytics
            ->setTransactionId($data->getTransactionId())
            ->setAffiliation($data->getAffiliation())
            ->setRevenue($data->getRevenue())
            ->setTax($data->getTax())
            ->setShipping($data->getShipping());

        if ($data->getCouponCode()) {
            $this->analytics->setCouponCode($data->getCouponCode());
        }
    }

    /**
     * @param $products
     */
    public function addProducts($products)
    {
        foreach ($products as $product) {
            $this->addProduct($product);
        }
    }

    /**
     * @param $data
     */
    public function addProduct($data)
    {
        $this->productCounter++;
        $this->analytics->addProduct($data->getData());
    }

    /**
     * @throws \Exception
     */
    public function firePurchaseEvent()
    {
        if (!$this->analytics->getTransactionId()) {
            throw new \Exception(__('No tracking ID set for transaction'));
        }

        if (!$this->analytics->getClientId() && !$this->analytics->getUserId()) {
            throw new \Exception(__(
                'No client ID or user ID set for transaction %s',
                $this->analytics->getTransactionId()
            ));
        }

        if (!$this->analytics->getTrackingId()) {
            throw new \Exception(__(
                'No tracking ID set for transaction %s',
                $this->analytics->getTransactionId()
            ));
        }

        if (!$this->productCounter) {
            throw new \Exception(__(
                'No products have been added to transaction %s',
                $this->analytics->getTransactionId()
            ));
        }

        $this->analytics->setProductActionToPurchase();

        $response = $this->analytics->setEventCategory('Checkout')
            ->setEventAction('Purchase')
            ->sendEvent();

        // @codingStandardsIgnoreStart
        if ($this->state->getMode() === State::MODE_DEVELOPER
            || $this->scopeConfig->isSetFlag(self::GOOGLE_ANALYTICS_SERVERSIDE_DEBUG_MODE)
        ) {
            $this->logger->info('elgentos_serversideanalytics_debug_response: ', [$response->getDebugResponse()]);
        }
        if ($this->scopeConfig->isSetFlag(self::GOOGLE_ANALYTICS_SERVERSIDE_ENABLE_LOGGING)) {
            $this->logger->info('elgentos_serversideanalytics_requests: ', [$response->getRequestUrl()]);
        }
        // @codingStandardsIgnoreEnd
    }

    /**
     * @return string
     */
    public function getVersion(): string
    {
        return $this->version;
    }
}
