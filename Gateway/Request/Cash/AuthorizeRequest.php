<?php
namespace DigitalFemsa\Payments\Gateway\Request\Cash;

use DigitalFemsa\Payments\Helper\Data as DigitalFemsaHelper;
use DigitalFemsa\Payments\Logger\Logger as DigitalFemsaLogger;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

class AuthorizeRequest implements BuilderInterface
{

    private SubjectReader $subjectReader;

    protected DigitalFemsaHelper $_digitalFemsaHelper;

    private DigitalFemsaLogger $_digitalFemsaLogger;

    public function __construct(
        SubjectReader       $subjectReader,
        DigitalFemsaHelper  $digitalFemsaHelper,
        DigitalFemsaLogger  $digitalFemsaLogger
    ) {
        $this->_digitalFemsaHelper = $digitalFemsaHelper;
        $this->_digitalFemsaLogger = $digitalFemsaLogger;
        $this->_digitalFemsaLogger->info('Request Cash AuthorizeRequest :: __construct');

        $this->subjectReader = $subjectReader;
    }

    public function build(array $buildSubject): array
    {
        $this->_digitalFemsaLogger->info('Request Cash AuthorizeRequest :: build');

        $paymentDO = $this->subjectReader->readPayment($buildSubject);
        $order = $paymentDO->getOrder();
        
        $expiry_date = $this->_digitalFemsaHelper->getExpiredAt();
        $amount = $this->_digitalFemsaHelper->getOrderAmountAfterStoreCredit($order);

        $request['metadata'] = [
            'plugin' => 'Magento',
            'plugin_version' => $this->_digitalFemsaHelper->getMageVersion(),
            'plugin_digitalfemsa_version' => $this->_digitalFemsaHelper->pluginVersion(),
            'order_id'       => $order->getOrderIncrementId(),
            'soft_validations'  => 'true'
        ];

        $request['payment_method_details'] = $this->getChargeCash($amount, $expiry_date);
        $request['CURRENCY'] = $order->getCurrencyCode();
        $request['TXN_TYPE'] = 'A';

        return $request;
    }

    public function getChargeCash($amount, $expiry_date): array
    {
        return [
            'payment_method' => [
                'type' => 'cash',
                'expires_at' => $expiry_date
            ],
            'amount' => $amount
        ];
    }
}
