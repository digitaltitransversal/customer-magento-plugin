<?php

namespace DigitalFemsa\Payments\Service;

use DigitalFemsa\Payments\Api\DigitalFemsaApiClient;
use DigitalFemsa\Payments\Exception\QuoteNotFoundException;
use DigitalFemsa\Payments\Helper\Data as DigitalFemsaData;
use DigitalFemsa\Payments\Logger\Logger as DigitalFemsaLogger;
use DigitalFemsa\Payments\Model\Ui\EmbedForm\ConfigProvider;
use DigitalFemsa\Payments\Model\WebhookRepository;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Exception;

class MissingOrders
{
    private WebhookRepository $webhookRepository;
    private DigitalFemsaLogger $_digitalFemsaLogger;
    private QuoteManagement $quoteManagement;
    private DigitalFemsaApiClient $femsaApiClient;
    protected CartRepositoryInterface $_cartRepository;
    private DigitalFemsaData $utilHelper;
    private OrderFactory $orderFactory;
    private OrderRepositoryInterface $orderRepository;

    public function __construct(
        WebhookRepository $webhookRepository,
        DigitalFemsaLogger $digitalFemsaLogger,
        QuoteManagement $quoteManagement,
        DigitalFemsaApiClient $femsaApiClient,
        CartRepositoryInterface $cartRepository,
        DigitalFemsaData $utilHelper,
        OrderFactory $orderFactory,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->webhookRepository = $webhookRepository;
        $this->_digitalFemsaLogger = $digitalFemsaLogger;
        $this->quoteManagement = $quoteManagement;
        $this->femsaApiClient = $femsaApiClient;
        $this->_cartRepository = $cartRepository;
        $this->utilHelper = $utilHelper;
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
    }

    /**
     * @throws LocalizedException
     * @throws QuoteNotFoundException when the quote referenced by the webhook metadata cannot be loaded
     */
    public function recover_order(array $event){
        try {
            //check order en order with external id
            $femsaOrderFound = $this->webhookRepository->findByMetadataOrderId($event);

            if ($femsaOrderFound->getId() != null || !empty($femsaOrderFound->getId()) ) {
                $this->_digitalFemsaLogger->info('order is ready', ['order' => $femsaOrderFound, 'is_set', isset($femsaOrderFound)]);
                return;
            }
            $femsaOrder = $event['data']['object'];
            $femsaCustomer = $femsaOrder['customer_info'] ?? [];
            $metadata = $femsaOrder['metadata'] ?? [];

            if (empty($metadata['quote_id'])) {
                $this->_digitalFemsaLogger->info('recover_order: no quote_id in metadata, skipping (not a Magento order)');
                return;
            }

            $quoteId = $metadata['quote_id'];
            $storeId = $metadata['store'] ?? null;
            // CartRepositoryInterface::get() declares non-null + NoSuchEntityException,
            // but real installs with plugins/around-interceptors may return null instead of throwing (BE-849).
            $quoteCreated = $this->_cartRepository->get($quoteId);

            /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
            if (!$quoteCreated) {
                throw new QuoteNotFoundException('Quote not found for quote_id ' . $quoteId);
            }

            $quoteCreated->setStoreId($storeId);

            $orderFounded = $this->orderFactory->create()->load($quoteCreated->getReservedOrderId(), OrderInterface::INCREMENT_ID);
            if ($orderFounded->getId() != null || !empty($orderFounded->getId()) ) {
                $this->_digitalFemsaLogger->info('order is ready', ['order' => $orderFounded, 'is_set', isset($orderFounded)]);
                return;
            }
            $quoteCreated->setCustomerEmail($femsaCustomer['email'] ?? $quoteCreated->getCustomerEmail());
            $quoteCreated->getPayment()->importData(['method' => ConfigProvider::CODE]);
            $chargeData = $femsaOrder['charges']['data'][0] ?? null;
            $paymentMethodObject = $chargeData['payment_method']['object'] ?? 'null';
            $txnId = $chargeData['id'] ?? null;

            $additionalInformation = [
                'order_id' =>  $femsaOrder["id"],
                'quote_id'=> $quoteCreated->getId(),
                'payment_method' => $this->getPaymentMethod($paymentMethodObject),
                'digitalfemsa_customer_id' => $femsaCustomer["customer_id"] ?? null
            ];
            if ($txnId) {
                $additionalInformation['txn_id'] = $txnId;
            }
            $additionalInformation = array_merge($additionalInformation, $this->getAdditionalInformation($chargeData));
            $quoteCreated->getPayment()->setAdditionalInformation($additionalInformation);
            $this->saveMissingFieldsQuote($quoteCreated, $femsaOrder);
            $order = $this->quoteManagement->submit($quoteCreated);
            $order->setStoreId($storeId);

            $order->addCommentToStatusHistory("Missing Order from femsa ". "<a href='". ConfigProvider::URL_PANEL_PAYMENTS ."/".$femsaOrder["id"]. "' target='_blank'>".$femsaOrder["id"]."</a>")
                ->setIsCustomerNotified(true);

            $this->orderRepository->save($order);
            if ($txnId) {
                $this->updateFemsaReference($txnId,  $order->getRealOrderId());
            }
            return ;

        } catch (QuoteNotFoundException $e) {
            throw $e;
        } catch (NoSuchEntityException $e){
            $this->_digitalFemsaLogger->error($e->getMessage());
            return;
        }
        catch (Exception | LocalizedException $e) {
            $this->_digitalFemsaLogger->error('recovery order '.$e->getMessage());
            throw  $e;
        }
    }

    private function saveMissingFieldsQuote(Quote  $quoteCreated, array $femsaOrder){
        $shippingContact = $femsaOrder["shipping_contact"] ?? [];
        $shippingAddressData = $shippingContact["address"] ?? [];
        $shippingMetadata = $shippingContact["metadata"] ?? [];

        $shippingNameReceiver = $this->utilHelper->splitName($shippingContact["receiver"] ?? "");
        $shipping_address = [
            'firstname'    => $shippingNameReceiver["firstname"] ?? "",
            'lastname'     => $shippingNameReceiver["lastname"] ?? "",
            'street' => [ $shippingAddressData["street1"] ?? "", $shippingAddressData["street2"] ?? ""],
            'city' => $shippingAddressData["city"] ?? "",
            'country_id' => strtoupper($shippingAddressData["country"] ?? ($femsaOrder["fiscal_entity"]["address"]["country"] ?? "")),
            'region' => $shippingAddressData["state"] ?? "",
            'postcode' => $shippingAddressData["postal_code"] ?? "",
            'telephone' =>   $shippingContact["phone"] ?? "5200000000",
            'region_id' => $shippingMetadata["region_id"] ?? null,
            'company'  => $shippingMetadata["company"] ?? "",
        ];

        $fiscalEntity = $femsaOrder["fiscal_entity"] ?? [];
        $fiscalAddress = $fiscalEntity["address"] ?? [];
        $fiscalMetadata = $fiscalEntity["metadata"] ?? [];
        $billingAddressName = $this->utilHelper->splitName($fiscalEntity["name"] ?? "");
        $billing_address = [
            'firstname'    => $billingAddressName["firstname"] ?? "",
            'lastname'     => $billingAddressName["lastname"] ?? "",
            'street' => [ $fiscalAddress["street1"] ?? "" , $fiscalAddress["street2"] ?? "" ],
            'city' => $fiscalAddress["city"] ?? "",
            'country_id' => strtoupper($fiscalAddress["country"] ?? ($shippingAddressData["country"] ?? "")),
            'region' => $fiscalAddress["state"] ?? "",
            'postcode' => $fiscalAddress["postal_code"] ?? "",
            'telephone' => $fiscalEntity["phone"] ??  ($shippingContact["phone"] ?? "5200000000"),
            'region_id' => $fiscalMetadata["region_id"] ?? null,
            'company'  => $fiscalMetadata["company"] ?? ""
        ];

        //Set Address to quote
        $quoteCreated->getBillingAddress()->addData($billing_address);

        $quoteCreated->getShippingAddress()->addData($shipping_address);
    }

    private function getAdditionalInformation(?array $chargeData) :array{
        // DigitalFemsa only supports cash payments; no card additional info to extract.
        return [];
    }

    private function updateFemsaReference(string $chargeId, string $orderId){
        $chargeUpdate= [
            "reference_id"=> $orderId,
        ];
        try {
            $this->femsaApiClient->updateCharge($chargeId,  $chargeUpdate);
        }catch (Exception $e) {
            $this->_digitalFemsaLogger->error("updating femsa charge". $e->getMessage(), ["charge_id"=> $chargeId, "reference_id"=> $orderId]);
        }
    }

    private function getPaymentMethod(string $type) :string {
        if ($type == "cash_payment") {
            return ConfigProvider::PAYMENT_METHOD_CASH;
        }
        return "";
    }
}
