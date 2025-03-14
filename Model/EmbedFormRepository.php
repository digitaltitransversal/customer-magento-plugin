<?php

namespace DigitalFemsa\Payments\Model;

use DigitalFemsa\Model\OrderResponse;
use DigitalFemsa\ApiException;
use DigitalFemsa\Payments\Logger\Logger as DigitalFemsaLogger;
use DigitalFemsa\Payments\Api\Data\DigitalFemsaQuoteInterface;
use DigitalFemsa\Payments\Api\EmbedFormRepositoryInterface;
use Exception;
use Magento\Framework\Exception\NoSuchEntityException;
use DigitalFemsa\Payments\Api\DigitalFemsaApiClient;
use DigitalFemsa\Payments\Exception\DigitalFemsaException;
use DigitalFemsa\Payments\Helper\Data as DigitalFemsaHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
class EmbedFormRepository implements EmbedFormRepositoryInterface
{
    /**
     * @var DigitalFemsaLogger
     */
    private DigitalFemsaLogger $_digitalFemsaLogger;
    /**
     * @var DigitalFemsaQuoteInterface
     */
    private DigitalFemsaQuoteInterface $digitalFemsaQuoteInterface;
    /**
     * @var DigitalFemsaApiClient
     */
    protected DigitalFemsaApiClient $digitalFemsaOrderApi;
    /**
     * @var DigitalFemsaQuoteFactory
     */
    private $digitalDigitalFemsaQuoteFactory;
    /**
     * @var DigitalFemsaQuoteRepositoryFactory
     */
    private $digitalDigitalFemsaQuoteRepositoryFactory;
    /**
     * @var DigitalFemsaHelper
     */
    private DigitalFemsaHelper $_digitalFemsaHelper;
    /**
     * @var CheckoutSession
     */
    private CheckoutSession $checkoutSession;

    

    /**
     * @param DigitalFemsaLogger $digitalFemsaLogger
     * @param DigitalFemsaQuoteInterface $digitalFemsaQuoteInterface
     * @param DigitalFemsaApiClient $digitalFemsaOrderApi
     * @param DigitalFemsaQuoteFactory $digitalDigitalFemsaQuoteFactory
     * @param DigitalFemsaQuoteRepositoryFactory $digitalDigitalFemsaQuoteRepositoryFactory
     * @param DigitalFemsaHelper $digitalFemsaHelper
     * @param CheckoutSession $checkoutSession

     */
    public function __construct(
        DigitalFemsaLogger                  $digitalFemsaLogger,
        DigitalFemsaQuoteInterface          $digitalFemsaQuoteInterface,
        DigitalFemsaApiClient               $digitalFemsaOrderApi,
        DigitalFemsaQuoteFactory            $digitalDigitalFemsaQuoteFactory,
        DigitalFemsaQuoteRepositoryFactory  $digitalDigitalFemsaQuoteRepositoryFactory,
        DigitalFemsaHelper                  $digitalFemsaHelper,
        CheckoutSession                     $checkoutSession


    )
    {
        $this->_digitalFemsaLogger = $digitalFemsaLogger;
        $this->digitalFemsaQuoteInterface = $digitalFemsaQuoteInterface;
        $this->digitalDigitalFemsaQuoteRepositoryFactory = $digitalDigitalFemsaQuoteRepositoryFactory;
        $this->digitalDigitalFemsaQuoteFactory = $digitalDigitalFemsaQuoteFactory;
        $this->digitalFemsaOrderApi = $digitalFemsaOrderApi;
        $this->_digitalFemsaHelper = $digitalFemsaHelper;
        $this->checkoutSession = $checkoutSession;


    }

    /**
     * ValidateOrderParameters
     *
     * @param mixed $orderParameters
     * @param mixed $orderTotal
     * @return void
     * @throws DigitalFemsaException
     */
    private function validateOrderParameters($orderParameters, $orderTotal)
    {
        //Currency
        if (strtoupper($orderParameters['currency']) !== 'MXN') {
            throw new DigitalFemsaException(
                __('Este medio de pago no acepta moneda extranjera')
            );
        }

        //Minimum amount per quote
        $total = 0;
        foreach ($orderParameters['line_items'] as $lineItem) {
            $total += $lineItem['unit_price'] * $lineItem['quantity'];
        }

        if ($this->_digitalFemsaHelper->isStoreCreditEnabled()) {
            $quote = $this->checkoutSession->getQuote();
            $storeCredit = $quote->getCustomerBalanceAmount() ?? 0;
            $total = $total - $this->_digitalFemsaHelper->convertToApiPrice($storeCredit);
        }
        if ($total < DigitalFemsaQuoteInterface::MINIMUM_AMOUNT_PER_QUOTE * 100) {
            throw new DigitalFemsaException(
                __('Para utilizar este medio de pago el monto debe ser superior a $' .
                   DigitalFemsaQuoteInterface::MINIMUM_AMOUNT_PER_QUOTE .
                   ' después de aplicar el crédito de tienda')
            );
        }

        if ($total < DigitalFemsaQuoteInterface::MINIMUM_AMOUNT_PER_QUOTE * 100) {
            throw new DigitalFemsaException(
                __('Para utilizar este medio de pago
                debe ingresar una compra superior a $' . DigitalFemsaQuoteInterface::MINIMUM_AMOUNT_PER_QUOTE)
            );
        }

        //Shipping contact validations
        if (strlen($orderParameters["shipping_contact"]["phone"]) < 10 ||
            strlen($orderParameters["shipping_contact"]["address"]["phone"]) < 10
        ) {
            throw new DigitalFemsaException(__('Télefono no válido. 
                El télefono debe tener al menos 10 carácteres. 
                Los caracteres especiales se desestimaran, solo se puede ingresar como 
                primer carácter especial: +'));
        }

        if (strlen($orderParameters["shipping_contact"]["address"]["postal_code"]) !== 5) {
            throw new DigitalFemsaException(__("Código Postal invalido. Debe tener 5 dígitos"));
        }

        //cash validations
        if (in_array('cash', $orderParameters["checkout"]["allowed_payment_methods"]) &&
            $orderTotal > 10000
        ) {
            throw new DigitalFemsaException(__('El monto máximo para pagos con Efectivo es de $10.000'));
        }
    }

    /**
     * Generate
     *
     * @param int $quoteId
     * @param array $orderParams
     * @param float $orderTotal
     * @return OrderResponse
     * @throws DigitalFemsaException
     */
    public function generate($quoteId, $orderParams, $orderTotal): OrderResponse
    {
        //Validate params
        $this->validateOrderParameters($orderParams, $orderTotal);

        $femsaQuoteRepo = $this->digitalDigitalFemsaQuoteRepositoryFactory->create();

        $femsaQuote = null;
        $femsaOrder = null;
        $hasToCreateNewOrder = false;
        try {
            $femsaQuote = $femsaQuoteRepo->getByid($quoteId);
            $femsaOrder = $this->digitalFemsaOrderApi->getOrderByID($femsaQuote->getFemsaOrderId());

            if (!empty($femsaOrder)) {
                $checkoutParams = $orderParams['checkout'];
                $femsaCheckout = $femsaOrder->getCheckout();
                if (!empty($femsaOrder->getPaymentStatus()) ||
                    time() >= $femsaCheckout->getExpiresAt() ||

                    //detect changes in checkout params
                    $checkoutParams['allowed_payment_methods'] != (array)$femsaCheckout->getAllowedPaymentMethods()
                ) {
                    $hasToCreateNewOrder = true;
                }
            }
        } catch (NoSuchEntityException $e) {
            $femsaQuote = null;
            $femsaOrder = null;
            $hasToCreateNewOrder = true;
        } catch (ApiException $e) {
            $femsaQuote = null;
            $femsaOrder = null;
            $hasToCreateNewOrder = true;
        }

        try {
            /**
             * Creates new DigitalFemsa order-checkout if:
             *   1- Not exist row in map table digitalfemsa_quote
             *   2- Exist row in map table and:
             *      2.1- DigitalFemsa order has payment_status OR
             *      2.2- DigitalFemsa order checkout has expired
             *      2.3- checkout parameters has changed
             */
            if ($hasToCreateNewOrder) {
                $this->_digitalFemsaLogger->info('EmbedFormRepository::generate Creates DigitalFemsa order', $orderParams);
                //Creates checkout order
                $femsaOrder = $this->digitalFemsaOrderApi->createOrder($orderParams);

                //Save map DigitalFemsa order and quote
                $femsaQuote = $this->digitalDigitalFemsaQuoteFactory->create();
                $femsaQuote->setQuoteId($quoteId);
                $femsaQuote->setFemsaOrderId($femsaOrder->getId());
                $femsaQuoteRepo->save($femsaQuote);
            } else {
                $this->_digitalFemsaLogger->info('EmbedFormRepository::generate  Updates DigitalFemsa order', $orderParams);
                //If map between DigitalFemsa order and quote exist, then just updated DigitalFemsa order

                unset($orderParams['customer_info']);
                $femsaOrder = $this->digitalFemsaOrderApi->updateOrder($femsaQuote->getFemsaOrderId(), $orderParams);
            }

            return $femsaOrder;
        } catch (Exception $e) {
            $this->_digitalFemsaLogger->error('EmbedFormRepository::generate Error: ' . $e->getMessage());
            throw new DigitalFemsaException(__($e->getMessage()));
        }
    }
}
