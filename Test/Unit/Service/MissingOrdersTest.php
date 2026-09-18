<?php
namespace DigitalFemsa\Payments\Test\Unit\Service;

use DigitalFemsa\Model\ChargeResponse;
use DigitalFemsa\Payments\Api\DigitalFemsaApiClient;
use DigitalFemsa\Payments\Exception\QuoteNotFoundException;
use DigitalFemsa\Payments\Helper\Data as DigitalFemsaData;
use DigitalFemsa\Payments\Logger\Logger as DigitalFemsaLogger;
use DigitalFemsa\Payments\Model\WebhookRepository;
use DigitalFemsa\Payments\Service\MissingOrders;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\History as StatusHistory;
use Magento\Sales\Model\OrderFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MissingOrdersTest extends TestCase
{
    private MissingOrders $missingOrders;
    private MockObject $webhookRepository;
    private MockObject $digitalFemsaLogger;
    private MockObject $quoteManagement;
    private MockObject $femsaApiClient;
    private MockObject $cartRepository;
    private MockObject $orderFactory;
    private MockObject $orderRepository;

    /** @var array<string, int> track calls to key methods */
    private array $callCounts;

    protected function setUp(): void
    {
        $this->callCounts = [
            'cartRepository_get' => 0,
            'quoteManagement_submit' => 0,
            'femsaApi_updateCharge' => 0,
            'order_save' => 0,
            'quote_setStoreId' => 0,
        ];

        $this->webhookRepository = $this->createMock(WebhookRepository::class);
        $this->digitalFemsaLogger = $this->createMock(DigitalFemsaLogger::class);
        $this->quoteManagement = $this->createMock(QuoteManagement::class);
        $this->femsaApiClient = $this->createMock(DigitalFemsaApiClient::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);

        $this->orderFactory = $this->getMockBuilder(OrderFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->method('save')->willReturnCallback(function ($order) {
            $this->callCounts['order_save']++;
            return $order;
        });

        $utilHelper = $this->createMock(DigitalFemsaData::class);
        $utilHelper->method('splitName')->willReturnCallback(function (string $fullName) {
            $parts = explode(' ', $fullName, 2);
            return [
                'firstname' => $parts[0] ?? '',
                'lastname' => $parts[1] ?? '',
            ];
        });

        $this->missingOrders = new MissingOrders(
            $this->webhookRepository,
            $this->digitalFemsaLogger,
            $this->quoteManagement,
            $this->femsaApiClient,
            $this->cartRepository,
            $utilHelper,
            $this->orderFactory,
            $this->orderRepository
        );
    }

    private function buildEvent(
        string $femsaOrderId = 'ord_abc123',
        string $paymentMethod = 'cash_payment',
        ?string $quoteId = '999',
        ?string $storeId = '1'
    ): array {
        return [
            'type' => 'order.paid',
            'data' => [
                'object' => [
                    'id' => $femsaOrderId,
                    'customer_info' => [
                        'email' => 'test@example.com',
                        'customer_id' => 'cus_123',
                    ],
                    'metadata' => [
                        'order_id' => '100000001',
                        'quote_id' => $quoteId,
                        'store' => $storeId,
                    ],
                    'charges' => [
                        'data' => [
                            [
                                'id' => 'chrg_123',
                                'payment_method' => [
                                    'object' => $paymentMethod,
                                ],
                            ],
                        ],
                    ],
                    'shipping_contact' => [
                        'receiver' => 'John Doe',
                        'phone' => '5512345678',
                        'address' => [
                            'street1' => 'Calle 1',
                            'street2' => '',
                            'city' => 'CDMX',
                            'state' => 'CDMX',
                            'country' => 'mx',
                            'postal_code' => '06600',
                        ],
                        'metadata' => [
                            'region_id' => '123',
                            'company' => 'Test Co',
                        ],
                    ],
                    'fiscal_entity' => [
                        'name' => 'John Doe',
                        'phone' => '5512345678',
                        'address' => [
                            'street1' => 'Calle 1',
                            'street2' => '',
                            'city' => 'CDMX',
                            'state' => 'CDMX',
                            'country' => 'mx',
                            'postal_code' => '06600',
                        ],
                        'metadata' => [
                            'region_id' => '123',
                            'company' => 'Test Co',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createMockOrder(?int $orderId = null): MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($orderId);
        return $order;
    }

    private function createMockQuote(): MockObject
    {
        $payment = $this->createMock(QuotePayment::class);
        $payment->method('importData')->willReturnSelf();
        $payment->method('setAdditionalInformation')->willReturnSelf();

        $shippingAddress = $this->createMock(QuoteAddress::class);
        $shippingAddress->method('addData')->willReturnSelf();

        $billingAddress = $this->createMock(QuoteAddress::class);
        $billingAddress->method('addData')->willReturnSelf();

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['setCustomerEmail', 'getCustomerEmail'])
            ->onlyMethods(['getPayment', 'getShippingAddress', 'getBillingAddress', 'getReservedOrderId', 'getId', 'setStoreId'])
            ->getMock();
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('getShippingAddress')->willReturn($shippingAddress);
        $quote->method('getBillingAddress')->willReturn($billingAddress);
        $quote->method('getReservedOrderId')->willReturn('100000001');
        $quote->method('getId')->willReturn(999);
        $quote->method('getCustomerEmail')->willReturn('test@example.com');

        return $quote;
    }

    private function setupFullRecoveryMocks(MockObject $quote): MockObject
    {
        $noOrder = $this->createMockOrder(null);
        $noOrder->method('load')->willReturnSelf();
        $this->orderFactory->method('create')
            ->willReturn($noOrder);

        $statusHistory = $this->createMock(StatusHistory::class);
        $statusHistory->method('setIsCustomerNotified')->willReturnSelf();

        $savedStoreId = null;
        $newOrder = $this->createMock(Order::class);
        $newOrder->method('setStoreId')->willReturnCallback(function ($id) use ($newOrder, &$savedStoreId) {
            $savedStoreId = $id;
            return $newOrder;
        });
        $newOrder->method('addCommentToStatusHistory')->willReturn($statusHistory);
        $newOrder->method('getRealOrderId')->willReturn('100000001');

        $this->quoteManagement->method('submit')
            ->with($quote)
            ->willReturnCallback(function () use ($newOrder) {
                $this->callCounts['quoteManagement_submit']++;
                return $newOrder;
            });

        return $newOrder;
    }

    // --- Order already exists by femsa order id ---

    public function testReturnsEarlyWhenOrderAlreadyExistsByFemsaId(): void
    {
        $event = $this->buildEvent();
        $existingOrder = $this->createMockOrder(42);

        $this->webhookRepository->method('findByMetadataOrderId')
            ->with($event)
            ->willReturn($existingOrder);

        $this->cartRepository->method('get')->willReturnCallback(function () {
            $this->callCounts['cartRepository_get']++;
            return $this->createMockQuote();
        });

        $this->missingOrders->recover_order($event);

        $this->assertSame(0, $this->callCounts['cartRepository_get'], 'No debió buscar el quote');
        $this->assertSame(0, $this->callCounts['quoteManagement_submit'], 'No debió crear orden');
    }

    // --- No quote_id in metadata (not a Magento order) ---

    public function testReturnsEarlyWhenQuoteIdIsMissing(): void
    {
        $event = $this->buildEvent();
        // Remove quote_id from metadata
        unset($event['data']['object']['metadata']['quote_id']);

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $loggedMessages = [];
        $this->digitalFemsaLogger->method('info')
            ->willReturnCallback(function ($msg) use (&$loggedMessages) {
                $loggedMessages[] = $msg;
            });

        $this->cartRepository->method('get')->willReturnCallback(function () {
            $this->callCounts['cartRepository_get']++;
            return $this->createMockQuote();
        });

        $this->missingOrders->recover_order($event);

        $this->assertSame(0, $this->callCounts['cartRepository_get'], 'No debió buscar el quote');
        $this->assertSame(0, $this->callCounts['quoteManagement_submit'], 'No debió crear orden');
        $this->assertNotEmpty($loggedMessages, 'Debió loguear que no hay quote_id');
        $this->assertStringContainsString('no quote_id', $loggedMessages[0]);
    }

    public function testReturnsEarlyWhenMetadataIsMissing(): void
    {
        $event = $this->buildEvent();
        // Remove metadata entirely
        unset($event['data']['object']['metadata']);

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $this->cartRepository->method('get')->willReturnCallback(function () {
            $this->callCounts['cartRepository_get']++;
            return $this->createMockQuote();
        });

        $this->missingOrders->recover_order($event);

        $this->assertSame(0, $this->callCounts['cartRepository_get'], 'No debió buscar el quote');
        $this->assertSame(0, $this->callCounts['quoteManagement_submit'], 'No debió crear orden');
    }

    // --- Order already exists by increment id ---

    public function testReturnsEarlyWhenOrderAlreadyExistsByIncrementId(): void
    {
        $event = $this->buildEvent();

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $quote = $this->createMockQuote();
        $this->cartRepository->method('get')->with('999')->willReturn($quote);

        $existingOrder = $this->createMockOrder(42);
        $existingOrder->method('load')->willReturnSelf();
        $this->orderFactory->method('create')->willReturn($existingOrder);

        $this->quoteManagement->method('submit')->willReturnCallback(function () {
            $this->callCounts['quoteManagement_submit']++;
            return $this->createMock(Order::class);
        });

        $this->missingOrders->recover_order($event);

        $this->assertSame(0, $this->callCounts['quoteManagement_submit'], 'No debió crear orden si ya existe por incrementId');
    }

    // --- Successful recovery ---

    public function testRecoverOrderCreatesOrderSuccessfully(): void
    {
        $event = $this->buildEvent();

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $quote = $this->createMockQuote();
        $this->cartRepository->method('get')->with('999')->willReturn($quote);

        $newOrder = $this->setupFullRecoveryMocks($quote);

        $capturedChargeArgs = null;
        $chargeResponse = $this->createMock(ChargeResponse::class);
        $this->femsaApiClient->method('updateCharge')
            ->willReturnCallback(function ($chargeId, $data) use (&$capturedChargeArgs, $chargeResponse) {
                $capturedChargeArgs = ['chargeId' => $chargeId, 'data' => $data];
                $this->callCounts['femsaApi_updateCharge']++;
                return $chargeResponse;
            });

        $this->missingOrders->recover_order($event);

        $this->assertSame(1, $this->callCounts['quoteManagement_submit'], 'Debió crear la orden');
        $this->assertSame(1, $this->callCounts['order_save'], 'Debió guardar la orden');
        $this->assertSame(1, $this->callCounts['femsaApi_updateCharge'], 'Debió actualizar la referencia en Femsa');
        $this->assertSame('chrg_123', $capturedChargeArgs['chargeId']);
        $this->assertSame(['reference_id' => '100000001'], $capturedChargeArgs['data']);
    }

    // --- Recovery with cash_payment (no card additional info) ---

    public function testRecoverOrderWithCashPaymentHasNoCardInfo(): void
    {
        $event = $this->buildEvent(paymentMethod: 'cash_payment');

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $capturedAdditionalInfo = null;

        $payment = $this->createMock(QuotePayment::class);
        $payment->method('importData')->willReturnSelf();
        $payment->method('setAdditionalInformation')
            ->willReturnCallback(function ($info) use (&$capturedAdditionalInfo) {
                $capturedAdditionalInfo = $info;
            });

        $shippingAddress = $this->createMock(QuoteAddress::class);
        $shippingAddress->method('addData')->willReturnSelf();
        $billingAddress = $this->createMock(QuoteAddress::class);
        $billingAddress->method('addData')->willReturnSelf();

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['setCustomerEmail', 'getCustomerEmail'])
            ->onlyMethods(['getPayment', 'getShippingAddress', 'getBillingAddress', 'getReservedOrderId', 'getId', 'setStoreId'])
            ->getMock();
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('getShippingAddress')->willReturn($shippingAddress);
        $quote->method('getBillingAddress')->willReturn($billingAddress);
        $quote->method('getReservedOrderId')->willReturn('100000001');
        $quote->method('getId')->willReturn(999);
        $quote->method('getCustomerEmail')->willReturn('test@example.com');

        $this->cartRepository->method('get')->willReturn($quote);

        $this->setupFullRecoveryMocks($quote);

        $this->missingOrders->recover_order($event);

        $this->assertNotNull($capturedAdditionalInfo, 'Debió setear additional information');
        $this->assertSame('cash', $capturedAdditionalInfo['payment_method']);
        $this->assertArrayNotHasKey('cc_type', $capturedAdditionalInfo);
        $this->assertArrayNotHasKey('cc_last_4', $capturedAdditionalInfo);
        $this->assertArrayNotHasKey('cc_exp_month', $capturedAdditionalInfo);
    }

    // --- NoSuchEntityException (quote not found) ---

    public function testRecoverOrderSwallowsNoSuchEntityException(): void
    {
        $event = $this->buildEvent();

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $this->cartRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('Quote not found')));

        $loggedMessages = [];
        $this->digitalFemsaLogger->method('error')
            ->willReturnCallback(function ($msg) use (&$loggedMessages) {
                $loggedMessages[] = $msg;
            });

        $this->missingOrders->recover_order($event);

        $this->assertSame(0, $this->callCounts['quoteManagement_submit'], 'No debió intentar crear orden');
        $this->assertNotEmpty($loggedMessages, 'Debió loguear el error');
        $this->assertStringContainsString('Quote not found', $loggedMessages[0]);
    }

    // --- Generic exception is rethrown ---

    public function testRecoverOrderRethrowsGenericException(): void
    {
        $event = $this->buildEvent();

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $this->cartRepository->method('get')
            ->willThrowException(new \Exception('DB connection failed'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('DB connection failed');

        $this->missingOrders->recover_order($event);
    }

    // --- LocalizedException is rethrown ---

    public function testRecoverOrderRethrowsLocalizedException(): void
    {
        $event = $this->buildEvent();

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $this->cartRepository->method('get')
            ->willThrowException(new LocalizedException(__('Something localized')));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Something localized');

        $this->missingOrders->recover_order($event);
    }

    // --- BE-849: cartRepository->get returning null throws QuoteNotFoundException ---

    public function testThrowsQuoteNotFoundWhenCartRepositoryReturnsNull(): void
    {
        $event = $this->buildEvent();

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $this->cartRepository->method('get')->willReturn(null);

        $this->expectException(QuoteNotFoundException::class);
        $this->expectExceptionMessage('Quote not found for quote_id 999');

        $this->missingOrders->recover_order($event);
    }

    // --- Femsa API failure doesn't break recovery ---

    public function testRecoverOrderContinuesWhenFemsaUpdateFails(): void
    {
        $event = $this->buildEvent();

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $quote = $this->createMockQuote();
        $this->cartRepository->method('get')->willReturn($quote);

        $this->setupFullRecoveryMocks($quote);

        $this->femsaApiClient->method('updateCharge')
            ->willThrowException(new \Exception('Femsa API timeout'));

        $loggedMessages = [];
        $this->digitalFemsaLogger->method('error')
            ->willReturnCallback(function ($msg) use (&$loggedMessages) {
                $loggedMessages[] = $msg;
            });

        $this->missingOrders->recover_order($event);

        $this->assertSame(1, $this->callCounts['quoteManagement_submit'], 'Debió crear la orden');
        $this->assertSame(1, $this->callCounts['order_save'], 'Debió guardar la orden');
        $this->assertNotEmpty($loggedMessages, 'Debió loguear el error de Femsa API');
        $this->assertStringContainsString('updating femsa charge', $loggedMessages[0]);
    }

    // --- Real-world scenario: ord_31dDed24E1CfSf6A3 (Bug 1 — bundle product) ---
    // Verifies that a webhook for a bundle order loads the original quote
    // instead of reconstructing it, preserving bundle selections.

    public function testRecoverOrderWithRealBundlePayloadLoadsQuoteNotReconstructs(): void
    {
        $event = [
            'type' => 'order.paid',
            'data' => [
                'object' => [
                    'id' => 'ord_31dDed24E1CfSf6A3',
                    'customer_info' => [
                        'customer_id' => 'cus_31dDed4STFr5kQp5a',
                        'name' => 'Laura  Tlapapal Pérez',
                        'email' => 'tlapapalperezlaura@gmail.com',
                        'phone' => '2461756112',
                    ],
                    'metadata' => [
                        'plugin' => 'Magento',
                        'magento_version' => '2.4.8-p5',
                        'plugin_digitalfemsa_version' => '1.0.13',
                        'store' => '1',
                        'remote_ip' => '127.0.0.1',
                        'quote_id' => '80657',
                        'is_virtual' => false,
                        'customer_id' => '14754',
                        'applied_rule_ids' => '266',
                    ],
                    'line_items' => [
                        'data' => [
                            [
                                'unit_price' => 235000,
                                'metadata' => [
                                    'product_type' => 'bundle',
                                    'product_id' => '2719',
                                ],
                                'name' => 'Novamil RICE',
                                'quantity' => 2,
                                'sku' => '8100005697pack8100005697',
                                'tags' => ['bundle'],
                            ],
                        ],
                    ],
                    'shipping_lines' => [
                        'data' => [
                            [
                                'amount' => 0,
                                'carrier' => 'DHL - Envío a domicilio 3 a 4 días hábiles - DHL - EXPRESS DOMESTIC',
                                'method' => 'ecloud_t1_ecloud_t1-EXPRESS DOMESTIC',
                            ],
                        ],
                    ],
                    'tax_lines' => [
                        'data' => [
                            [
                                'description' => 'Tax',
                                'amount' => 0,
                            ],
                        ],
                    ],
                    'charges' => [
                        'data' => [
                            [
                                'id' => '6a8dd5b1e4165b001a004920',
                                'payment_method' => [
                                    'object' => 'cash_payment',
                                ],
                            ],
                        ],
                    ],
                    'shipping_contact' => [
                        'phone' => '2461756112',
                        'receiver' => 'Laura   Tlapapal Pérez',
                        'metadata' => [
                            'company' => null,
                            'region_id' => '605',
                            'save_in_address_book' => '0',
                        ],
                        'address' => [
                            'country' => 'mx',
                            'residential' => true,
                            'street1' => 'Calle Quinatzi',
                            'street2' => '9',
                            'city' => 'Tlaxcala ',
                            'state' => 'Tlaxcala',
                            'postal_code' => '90163',
                        ],
                    ],
                    'fiscal_entity' => [
                        'name' => 'Laura   Tlapapal Pérez',
                        'metadata' => [
                            'company' => null,
                            'region_id' => '605',
                            'save_in_address_book' => '0',
                        ],
                        'address' => [
                            'country' => 'mx',
                            'street1' => 'Calle Quinatzi',
                            'street2' => '9',
                            'city' => 'Tlaxcala ',
                            'state' => 'Tlaxcala',
                            'postal_code' => '90163',
                        ],
                    ],
                ],
            ],
        ];

        $notFoundOrder = $this->createMockOrder(null);
        $this->webhookRepository->method('findByMetadataOrderId')->willReturn($notFoundOrder);

        $quote = $this->createMockQuote();
        $capturedQuoteId = null;
        $this->cartRepository->method('get')
            ->willReturnCallback(function ($quoteId) use ($quote, &$capturedQuoteId) {
                $capturedQuoteId = $quoteId;
                $this->callCounts['cartRepository_get']++;
                return $quote;
            });

        $newOrder = $this->setupFullRecoveryMocks($quote);

        $capturedChargeArgs = null;
        $chargeResponse = $this->createMock(ChargeResponse::class);
        $this->femsaApiClient->method('updateCharge')
            ->willReturnCallback(function ($chargeId, $data) use (&$capturedChargeArgs, $chargeResponse) {
                $capturedChargeArgs = ['chargeId' => $chargeId, 'data' => $data];
                $this->callCounts['femsaApi_updateCharge']++;
                return $chargeResponse;
            });

        $this->missingOrders->recover_order($event);

        // Bug 1: quote must be loaded by ID, not reconstructed
        $this->assertSame('80657', $capturedQuoteId, 'Debió cargar la quote 80657 por ID');
        $this->assertSame(1, $this->callCounts['cartRepository_get'], 'Debió llamar cartRepository->get una vez');

        // Order must be created from the loaded quote
        $this->assertSame(1, $this->callCounts['quoteManagement_submit'], 'Debió crear la orden desde la quote cargada');
        $this->assertSame(1, $this->callCounts['order_save'], 'Debió guardar la orden');

        // Femsa reference must be updated with the real charge ID
        $this->assertSame(1, $this->callCounts['femsaApi_updateCharge'], 'Debió actualizar la referencia en Femsa');
        $this->assertSame('6a8dd5b1e4165b001a004920', $capturedChargeArgs['chargeId']);
        $this->assertSame(['reference_id' => '100000001'], $capturedChargeArgs['data']);
    }
}
