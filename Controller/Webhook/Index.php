<?php
namespace DigitalFemsa\Payments\Controller\Webhook;

use DigitalFemsa\Payments\Exception\EntityNotFoundException;
use DigitalFemsa\Payments\Exception\QuoteNotFoundException;
use DigitalFemsa\Payments\Logger\Logger as DigitalFemsaLogger;
use DigitalFemsa\Payments\Model\WebhookRepository;
use DigitalFemsa\Payments\Service\MissingOrders;
use Laminas\Http\Response;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Json\Helper\Data;
use Magento\Framework\App\Request\InvalidRequestException;


class Index extends Action implements CsrfAwareActionInterface
{
    private const EVENT_WEBHOOK_PING = 'webhook_ping';
    private const EVENT_ORDER_PENDING_PAYMENT = 'order.pending_payment';
    private const EVENT_ORDER_PAID = 'order.paid';
    private const EVENT_ORDER_EXPIRED = 'order.expired';
    private const EVENT_ORDER_CANCELED = 'order.canceled';
    /**
     * @var JsonFactory
     */
    protected JsonFactory $resultJsonFactory;
    /**
     * @var RawFactory
     */
    protected RawFactory $resultRawFactory;
    /**
     * @var Data
     */
    protected Data $helper;

    /**
     * @var DigitalFemsaLogger
     */
    private DigitalFemsaLogger $_digitalFemsaLogger;
    /**
     * @var WebhookRepository
     */
    private WebhookRepository $webhookRepository;

    private MissingOrders $missingOrder;

    /**
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param RawFactory $resultRawFactory
     * @param Data $helper
     * @param DigitalFemsaLogger $digitalFemsaLogger
     * @param WebhookRepository $webhookRepository
     * @param MissingOrders $_missingOrders
     */
    public function __construct(
        Context             $context,
        JsonFactory         $resultJsonFactory,
        RawFactory          $resultRawFactory,
        Data                $helper,
        DigitalFemsaLogger  $digitalFemsaLogger,
        WebhookRepository   $webhookRepository,
        MissingOrders       $_missingOrders
    ) {
        parent::__construct($context);
        $this->_digitalFemsaLogger = $digitalFemsaLogger;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->resultRawFactory = $resultRawFactory;
        $this->helper = $helper;
        $this->webhookRepository = $webhookRepository;
        $this->missingOrder = $_missingOrders;
    }

    /**
     * Create CSRF Validation Exception
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * CSRF Validation
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Execute
     *
     * @return int|ResponseInterface|ResultInterface
     */
    public function execute()
    {
        $response = Response::STATUS_CODE_200;

        try {
            $body = $this->helper->jsonDecode($this->getRequest()->getContent());

            if (!$body || $this->getRequest()->getMethod() !== 'POST') {
                $errorResponse = [
                    'error' => 'Invalid request data',
                    'message' => 'The request data is either empty or the request method is not POST.'
                ];
                return $this->sendJsonResponse($errorResponse, Response::STATUS_CODE_400);
            }

            $event = $body['type'];

            $this->_digitalFemsaLogger->info('Controller Index :: execute body json ', ['event' => $event]);

            switch ($event) {
                case self::EVENT_WEBHOOK_PING:
                    break;
                case self::EVENT_ORDER_PENDING_PAYMENT:
                    $this->missingOrder->recover_order($body);
                    $order = $this->webhookRepository->findByMetadataOrderId($body);
                    if (!$order->getId()) {
                        $errorResponse = [
                            'error' => 'Order not found',
                            'message' => 'The requested order does not exist.'
                        ];
                        return $this->sendJsonResponse($errorResponse, Response::STATUS_CODE_404);
                    }
                    break;
                case self::EVENT_ORDER_PAID:
                    $this->missingOrder->recover_order($body);
                    $this->webhookRepository->payOrder($body);
                    break;
                
                case self::EVENT_ORDER_EXPIRED:
                case self::EVENT_ORDER_CANCELED:
                    $this->webhookRepository->expireOrder($body);
                    break;
            }

        } catch (QuoteNotFoundException $e) {
            // Quote does not exist — the order is not recoverable and retrying will not resolve it.
            // Return 204 (No Content) so Femsa stops retrying this webhook.
            $this->_digitalFemsaLogger->info('Controller Index :: Quote not recoverable, acknowledging webhook: ' . $e->getMessage());
            $resultRaw = $this->resultRawFactory->create();
            return $resultRaw->setHttpResponseCode(Response::STATUS_CODE_204);
        } catch (EntityNotFoundException $e) {
            $errorResponse = [
                'error' => 'Entity Not Found',
                'message' => $e->getMessage(),
            ];
            return $this->sendJsonResponse($errorResponse, Response::STATUS_CODE_404);
        } catch (\Exception $e) {
            $this->_digitalFemsaLogger->error('Controller Index :: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            $errorResponse = [
                'error' => 'Internal Server Error',
                'message' => $e->getMessage(),
            ];
            return $this->sendJsonResponse($errorResponse, Response::STATUS_CODE_500);
        } catch (\Throwable $e) {
            $this->_digitalFemsaLogger->error('Controller Index :: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            $errorResponse = [
                'error' => 'Internal Server Error',
                'message' => $e->getMessage(),
            ];
            return $this->sendJsonResponse($errorResponse, Response::STATUS_CODE_500);
        }
        
        $resultRaw = $this->resultRawFactory->create();
        return $resultRaw->setHttpResponseCode($response);
    }

    private function sendJsonResponse($data, $httpStatusCode)
    {
        $resultRaw = $this->resultRawFactory->create();
        $resultRaw->setHttpResponseCode($httpStatusCode);
        $resultRaw->setHeader('Content-Type', 'application/json', true);
        $resultRaw->setContents( $this->helper->jsonEncode(($data)));

        return $resultRaw;
    }
}
