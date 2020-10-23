<?php

namespace Elgentos\ServerSideAnalytics\Observer;

use Elgentos\ServerSideAnalytics\Model\GAClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class SaveGaUserId implements ObserverInterface
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CookieManagerInterface
     */
    private $cookieManager;

    /**
     * @var GAClient
     */
    private $gaclient;

    /**
     * SaveGaUserId constructor.
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param CookieManagerInterface $cookieManager
     * @param GAClient $gaclient
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        CookieManagerInterface $cookieManager,
        GAClient $gaclient
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->cookieManager = $cookieManager;
        $this->gaclient = $gaclient;
    }

    /**
     * When Order object is saved add the GA User Id if available in the cookies.
     *
     * @param Observer $observer
     */

    public function execute(Observer $observer)
    {
        if (!$this->scopeConfig->getValue(
            GAClient::GOOGLE_ANALYTICS_SERVERSIDE_ENABLED,
            ScopeInterface::SCOPE_STORE
        )) {
            return;
        }

        if (!$this->scopeConfig->getValue(
            GAClient::GOOGLE_ANALYTICS_SERVERSIDE_UA,
            ScopeInterface::SCOPE_STORE
        )) {
            $this->logger->info('No Google Analytics account number has been found in the ServerSideAnalytics configuration.');
            return;
        }

        $order = $observer->getEvent()->getOrder();

        $gaCookie = explode('.', $this->cookieManager->getCookie('_ga'));

        if (empty($gaCookie) || count($gaCookie) < 4) {
            return;
        }

        list(
            $gaCookieVersion,
            $gaCookieDomainComponents,
            $gaCookieUserId,
            $gaCookieTimestamp
            ) = $gaCookie;

        if (!$gaCookieUserId || !$gaCookieTimestamp) {
            return;
        }

        $client = $this->gaclient;

        if ($gaCookieVersion != 'GA' . $client->getVersion()) {
            $this->logger->info('Google Analytics cookie version differs from Measurement Protocol API version; please upgrade.');
            return;
        }

        $gaUserId = implode('.', [$gaCookieUserId, $gaCookieTimestamp]);

        $order->setData('ga_user_id', $gaUserId);
    }
}
