<?php

namespace Elgentos\ServerSideAnalytics\Observer;

use Elgentos\ServerSideAnalytics\Model\GAClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Tax\Model\Config;
use Psr\Log\LoggerInterface;

class SendPurchaseEvent implements ObserverInterface
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
     * @var GAClient
     */
    private $gaclient;
    /**
     * @var ManagerInterface
     */
    private $event;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        GAClient $gaclient,
        ManagerInterface $event
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->gaclient = $gaclient;
        $this->event = $event;
    }

    /**
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        if (!$this->scopeConfig->getValue(GAClient::GOOGLE_ANALYTICS_SERVERSIDE_ENABLED, ScopeInterface::SCOPE_STORE)) {
            return;
        }
        $ua = $this->scopeConfig->getValue(GAClient::GOOGLE_ANALYTICS_SERVERSIDE_UA, ScopeInterface::SCOPE_STORE);
        if (!$ua) {
            $this->logger->info('No Google Analytics account number has been found in the ServerSideAnalytics configuration.');
            return;
        }

        /** @var Order\Payment $payment */
        $payment = $observer->getPayment();
        /** @var Order\Invoice $invoice */
        $invoice = $observer->getInvoice();
        /** @var Order $order */
        $order = $payment->getOrder();

        if (!$order->getData('ga_user_id')) {
            return;
        }

        /** @var GAClient $client */
        $client = $this->gaclient;

        $uas = explode(',', $ua);
        $uas = array_filter($uas);
        $uas = array_map('trim', $uas);

        $products = [];
        /** @var Order\Invoice\Item $item */
        foreach ($invoice->getAllItems() as $item) {
            if (!$item->isDeleted() && !$item->getOrderItem()->getParentItemId()) {
                $product = new DataObject([
                    'sku' => $item->getSku(),
                    'name' => $item->getName(),
                    'price' => $this->getPaidProductPrice($item->getOrderItem()),
                    'quantity' => $item->getOrderItem()->getQtyOrdered(),
                    'position' => $item->getId()
                ]);
                $this->event->dispatch(
                    'elgentos_serversideanalytics_product_item_transport_object',
                    ['product' => $product, 'item' => $item]
                );
                $products[] = $product;
            }
        }

        $trackingDataObject = new DataObject([
            'client_id' => $order->getData('ga_user_id'),
            'ip_override' => $order->getRemoteIp(),
            'document_path' => '/checkout/onepage/success/'
        ]);

        $client->setTransactionData(
            new DataObject(
                [
                    'transaction_id' => $order->getIncrementId(),
                    'affiliation' => $order->getStoreName(),
                    'revenue' => $invoice->getBaseGrandTotal(),
                    'tax' => $invoice->getTaxAmount(),
                    'shipping' => ($this->getPaidShippingCosts($invoice) ?? 0),
                    'coupon_code' => $order->getCouponCode()
                ]
            )
        );

        $client->addProducts($products);

        foreach ($uas as $ua) {
            try {
                $trackingDataObject->setData('tracking_id', $ua);
                $client->setTrackingData($trackingDataObject);
                $this->event->dispatch(
                    'elgentos_serversideanalytics_tracking_data_transport_object',
                    ['tracking_data_object' => $trackingDataObject]
                );
                $client->firePurchaseEvent();
            } catch (\Exception $e) {
                $this->logger->info($e);
            }
        }
    }

    /**
     * Get the actual price the customer also saw in it's cart.
     *
     * @param Order\Item $orderItem
     *
     * @return float
     */
    private function getPaidProductPrice(Order\Item $orderItem)
    {
        if ($this->scopeConfig->getValue('tax/display/type') == Config::DISPLAY_TYPE_EXCLUDING_TAX) {
            return $orderItem->getPrice();
        }

        return $orderItem->getPriceInclTax();
    }

    /**
     * @param Order\Invoice $invoice
     *
     * @return float
     */
    private function getPaidShippingCosts(Order\Invoice $invoice)
    {
        if ($this->scopeConfig->getValue('tax/display/type') == Config::DISPLAY_TYPE_EXCLUDING_TAX) {
            return $invoice->getShippingAmount();
        }

        return $invoice->getShippingInclTax();
    }
}
