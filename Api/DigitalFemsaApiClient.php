<?php

namespace DigitalFemsa\Payments\Api;

use DigitalFemsa\Api\ChargesApi;
use DigitalFemsa\Api\CustomersApi;
use DigitalFemsa\Api\OrdersApi;
use DigitalFemsa\Api\PaymentMethodsApi;
use DigitalFemsa\Api\WebhooksApi;
use DigitalFemsa\ApiException;
use DigitalFemsa\Configuration;
use DigitalFemsa\HeaderSelector;
use DigitalFemsa\Model\ChargeOrderResponse;
use DigitalFemsa\Model\ChargeRequest;
use DigitalFemsa\Model\ChargeResponse;
use DigitalFemsa\Model\ChargeUpdateRequest;
use DigitalFemsa\Model\Customer;
use DigitalFemsa\Model\CustomerResponse;
use DigitalFemsa\Model\GetWebhooksResponse;
use DigitalFemsa\Model\OrderRefundRequest;
use DigitalFemsa\Model\OrderRequest;
use DigitalFemsa\Model\OrderResponse;
use DigitalFemsa\Model\OrderUpdateRequest;
use DigitalFemsa\Model\UpdateCustomer;
use DigitalFemsa\Model\UpdateCustomerPaymentMethodsResponse;
use DigitalFemsa\Model\WebhookRequest;
use DigitalFemsa\Model\WebhookResponse;
use DigitalFemsa\Model\WebhookUpdateRequest;
use DigitalFemsa\Payments\Helper\Data as HelperData;
use GuzzleHttp\Client;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\HTTP\Header as HttpHeader;

class DigitalFemsaApiClient
{
    /**
     * @var Configuration
     */
    private Configuration $config;

    /**
     * @var HelperData
     */
    private HelperData $helperData;

    /**
     * @var Client
     */
    private Client $client;

    /**
     * @var OrdersApi
     */
    private OrdersApi $orderInstance;

    /**
     * @var CustomersApi
     */
    private CustomersApi $customerInstance;
    /**
     * @var ChargesApi
     */
    private ChargesApi $chargeInstance;

    private PaymentMethodsApi $customerPaymentMethods;

    private WebhooksApi $webhooks;

    private ChargesApi $charges;

    protected ProductMetadataInterface $productMetadata;

    protected HttpHeader $httpHeader;

    public function __construct(
        Client     $client,
        HelperData $helperData,
        ProductMetadataInterface $productMetadata,
        HttpHeader $httpHeader
    )
    {
        $this->client = $client;
        $this->helperData = $helperData;
        $this->productMetadata = $productMetadata;
        $this->httpHeader = $httpHeader;
        $headerSelector = new HeaderSelector();
        $userAgentHeaders = $headerSelector->getFemsaUserAgent();

        $existingUserAgent = json_decode($userAgentHeaders['X-DigitalFemsa-Client-User-Agent'], true);

        $integrationParams = [
            'integration_type' => 'plugin',
            'integration_name' => 'spin-magento',
            'integration_version' => '1.0.10',
            'ecommerce_version' => $this->productMetadata->getVersion(),
            'device_type' => $this->getDeviceType()
        ];

        $finalUserAgent = array_merge($existingUserAgent, $integrationParams);
        $this->config = Configuration::getDefaultConfiguration()->setAccessToken($this->helperData->getPrivateKey())->setUserAgent(json_encode($finalUserAgent));
        $this->orderInstance = new OrdersApi($this->client, $this->config);
        $this->customerInstance = new CustomersApi($this->client, $this->config);
        $this->chargeInstance = new ChargesApi($this->client, $this->config);
        $this->customerPaymentMethods = new PaymentMethodsApi($this->client, $this->config);
        $this->webhooks = new WebhooksApi($this->client, $this->config);
        $this->charges = new ChargesApi($this->client, $this->config);
    }

    public function getDeviceType(): ?string
    {
        $userAgent = $this->httpHeader->getHttpUserAgent();

        if (empty($userAgent)) {
            return 'Server';
        }

        $isMobile = preg_match(
            "/(android|iphone|ipad|tablet|mobile|phone|webos|blackberry)/i",
            $userAgent
        );

        return $isMobile ? 'Mobile' : 'Desktop';
    }

    /**
     * @param array $orderData
     * @return OrderResponse
     * @throws ApiException
     */
    public function createOrder(array $orderData): OrderResponse
    {
        $orderRequest = new OrderRequest($orderData);

        return $this->orderInstance->createOrder($orderRequest);
    }

    /**
     * @param string $id
     * @param array $orderData
     * @return OrderResponse
     * @throws ApiException
     */
    public function updateOrder(string $id, array $orderData): OrderResponse
    {
        $orderUpdateRequest = new OrderUpdateRequest($orderData);

        return $this->orderInstance->updateOrder($id, $orderUpdateRequest);
    }

    /**
     * @param string $id
     * @return OrderResponse
     * @throws ApiException
     */
    public function getOrderByID(string $id): OrderResponse
    {
        return $this->orderInstance->getOrderById($id);
    }

    /**
     * @param string $id
     * @return CustomerResponse
     * @throws ApiException
     */
    public function findCustomerByID(string $id): CustomerResponse
    {
        return $this->customerInstance->getCustomerById($id);
    }

    /**
     * @param string $id
     * @param array $customerData
     * @return CustomerResponse
     * @throws ApiException
     */
    public function updateCustomer(string $id, array $customerData): CustomerResponse
    {
        $customerRequest = new UpdateCustomer($customerData);

        return $this->customerInstance->updateCustomer($id, $customerRequest);
    }

    /**
     * @param array $customerData
     * @return CustomerResponse
     * @throws ApiException
     */
    public function createCustomer(array $customerData): CustomerResponse
    {
        $customerRequest = new Customer($customerData);

        return $this->customerInstance->createCustomer($customerRequest);
    }

    /**
     * @param string $customerID
     * @param string $paymentMethodID
     * @return UpdateCustomerPaymentMethodsResponse
     * @throws ApiException
     */
    public function deleteCustomerPaymentMethod(string $customerID, string $paymentMethodID): UpdateCustomerPaymentMethodsResponse
    {
        return $this->customerPaymentMethods->deleteCustomerPaymentMethods($customerID, $paymentMethodID);
    }

    /**
     * @param string $orderID
     * @param array $chargeData
     * @return ChargeOrderResponse
     * @throws ApiException
     */
    public function createOrderCharge(string $orderID, array $chargeData): ChargeOrderResponse
    {
        $chargeRequest = new ChargeRequest($chargeData);

        return $this->chargeInstance->ordersCreateCharge($orderID, $chargeRequest);
    }

    /**
     * @throws ApiException
     */
    public function orderRefund(string $orderID, array $orderRefundData)
    {
        $orderRefundRequest = new OrderRefundRequest($orderRefundData);

        return $this->orderInstance->orderRefund($orderID, $orderRefundRequest);
    }

    /**
     * @return GetWebhooksResponse
     * @throws ApiException
     */
    public function getWebhooks(): GetWebhooksResponse
    {
        return $this->webhooks->getWebhooks();
    }

    /**
     * @param array $webhookData
     * @return WebhookResponse
     * @throws ApiException
     */
    public function createWebhook(array $webhookData): WebhookResponse
    {
        $webhookRequest = new WebhookRequest($webhookData);
        return $this->webhooks->createWebhook($webhookRequest);
    }

    /**
     * @param string $webhookID
     * @param array $webhookData
     * @return WebhookResponse
     * @throws ApiException
     */
    public function updateWebhook(string $webhookID, array $webhookData): WebhookResponse
    {
        $webhookRequest = new WebhookUpdateRequest($webhookData);
        return $this->webhooks->updateWebhook($webhookID, $webhookRequest);
    }

    /**
     * @param string $chargeId
     * @param array $charge
     * @return ChargeResponse
     * @throws ApiException
     */
    public function updateCharge(string $chargeId, array $charge): ChargeResponse
    {
        $charge = new ChargeUpdateRequest($charge);
        return $this->charges->updateCharge($chargeId, $charge);
    }
}
