<?php
/**
 * @copyright 2010-2013 JTL-Software GmbH
 * @package jtl\Connector\Shopware\Controller
 */

namespace jtl\Connector\Shopware\Controller;

use jtl\Connector\Core\Utilities\Money;
use jtl\Connector\Formatter\ExceptionFormatter;
use jtl\Connector\Model\Identity;
use jtl\Connector\Payment\PaymentTypes;
use jtl\Connector\Result\Action;
use jtl\Connector\Shopware\Model\CustomerOrderItem;
use jtl\Connector\Shopware\Utilities\IdConcatenator;
use jtl\Connector\Shopware\Utilities\Locale as LocaleUtil;
use jtl\Connector\Shopware\Utilities\Mmc;
use jtl\Connector\Shopware\Utilities\Payment as PaymentUtil;
use jtl\Connector\Shopware\Utilities\PaymentStatus as PaymentStatusUtil;
use jtl\Connector\Shopware\Utilities\Salutation;
use jtl\Connector\Shopware\Utilities\Status as StatusUtil;
use jtl\Connector\Core\Logger\Logger;
use jtl\Connector\Core\Model\QueryFilter;
use jtl\Connector\Core\Rpc\Error;
use jtl\Connector\Core\Utilities\DataConverter;
use \jtl\Connector\Core\Utilities\Language as LanguageUtil;

/**
 * CustomerOrder Controller
 * @access public
 */
class CustomerOrder extends DataController
{
    /**
     * Pull
     *
     * @param \jtl\Connector\Core\Model\QueryFilter $queryFilter
     * @return \jtl\Connector\Result\Action
     */
    public function pull(QueryFilter $queryFilter)
    {
        $action = new Action();
        $action->setHandled(true);

        try {
            $result = array();
            $limit = $queryFilter->isLimit() ? $queryFilter->getLimit() : 100;

            $shopMapper = Mmc::getMapper('Shop');
            $mapper = Mmc::getMapper('CustomerOrder');
            $productMapper = Mmc::getMapper('Product');
            $orders = $mapper->findAll($limit);

            foreach ($orders as $orderSW) {
                try {
                    // CustomerOrders
                    $order = Mmc::getModel('CustomerOrder');
                    $order->map(true, DataConverter::toObject($orderSW, true));

                    // PaymentModuleCode
                    $code = PaymentUtil::map(null, $orderSW['payment']['name']);
                    $paymentModuleCode = ($code !== null) ? $code : $orderSW['payment']['name'];
                    $order->setPaymentModuleCode($paymentModuleCode);

                    // Billsafe
                    if ($paymentModuleCode === PaymentTypes::TYPE_BILLSAFE
                        && isset($orderSW['attribute']['swagBillsafeIban'])
                        && isset($orderSW['attribute']['swagBillsafeBic'])) {
                        $order->setPui(sprintf(
                            'Bitte bezahlen Sie %s %s an folgendes Konto: %s Verwendungszweck: BTN %s',
                            $orderSW['invoiceAmount'],
                            $order->getCurrencyIso(),
                            sprintf('IBAN: %s, BIC: %s', $orderSW['attribute']['swagBillsafeIban'], $orderSW['attribute']['swagBillsafeBic']),
                            $orderSW['transactionId']
                        ));
                    }

                    // CustomerOrderStatus
                    $customerOrderStatus = StatusUtil::map(null, $orderSW['status']);
                    if ($customerOrderStatus !== null) {
                        $order->setStatus($customerOrderStatus);
                    }

                    // PaymentStatus
                    $paymentStatus = PaymentStatusUtil::map(null, $orderSW['cleared']);
                    if ($paymentStatus !== null) {
                        $order->setPaymentStatus($paymentStatus);
                    }

                    // Locale
                    $shop = $shopMapper->find((int) $orderSW['languageIso']);
                    //$localeSW = LocaleUtil::get((int) $orderSW['languageIso']);
                    //if ($localeSW !== null) {
                    if ($shop !== null) {
                        //$order->setLanguageISO(LanguageUtil::map($localeSW->getLocale()));
                        $order->setLanguageISO(LanguageUtil::map($shop->getLocale()->getLocale()));
                    }

                    foreach ($orderSW['details'] as $detailSW) {

                        // Tax Free
                        if ((int) $orderSW['taxFree'] == 1) {
                            $detailSW['taxRate'] = 0.0;
                        }

                        switch ((int) $orderSW['net']) {
                            case 0: // price is gross
                                $detailSW['priceGross'] = $detailSW['price'];
                                $detailSW['price'] = Money::AsNet($detailSW['price'], $detailSW['taxRate']);
                                break;
                            case 1: // price is net
                                $detailSW['priceGross'] = round(Money::AsGross($detailSW['price'], $detailSW['taxRate']), 2);
                                break;
                        }

                        // Type
                        $detailSW['type'] = CustomerOrderItem::TYPE_PRODUCT;
                        if ($detailSW['articleId'] == 0 && ($detailSW['articleNumber'] === 'sw-payment' || $detailSW['articleNumber'] === 'sw-payment-absolute')) {
                            $detailSW['type'] = CustomerOrderItem::TYPE_SURCHARGE;
                        }

                        $orderItem = Mmc::getModel('CustomerOrderItem');
                        $orderItem->map(true, DataConverter::toObject($detailSW, true));

                        $detail = $productMapper->findDetailBy(array('number' => $detailSW['articleNumber']));
                        if ($detail !== null) {
                            //throw new \Exception(sprintf('Cannot find detail with number (%s)', $detailSW['articleNumber']));
                            $orderItem->setProductId(new Identity(IdConcatenator::link([$detail->getId(), $detailSW['articleId']])));
                        }

                        /*
                        if ($detail->getKind() == 2) {    // is Child
                            $orderItem->setProductId(new Identity(sprintf('%s_%s', $detail->getId(), $detailSW['articleId'])));
                        }
                        */

                        $order->addItem($orderItem);
                    }

                    $this->addPos($order, 'setBillingAddress', 'CustomerOrderBillingAddress', $orderSW['billing']);
                    $this->addPos($order, 'setShippingAddress', 'CustomerOrderShippingAddress', $orderSW['shipping']);

                    // Street and Salutation
                    if ($order->getBillingAddress() !== null) {
                        $order->getBillingAddress()->setStreet(sprintf('%s %s', $orderSW['billing']['street'], $orderSW['billing']['streetNumber']))
                            ->setSalutation(Salutation::toConnector($orderSW['billing']['salutation']))
                            ->setEmail($orderSW['customer']['email']);
                    }

                    if ($order->getShippingAddress() !== null) {
                        $street = sprintf('%s %s', $orderSW['shipping']['street'], $orderSW['shipping']['streetNumber']);

                        // DHL Packstation
                        $dhlPropertyInfos = array(
                            array('name' => 'Postnummer', 'prop' => 'swagDhlPostnumber', 'serialized' => false),
                            array('name' => 'Packstation', 'prop' => 'swagDhlPackstation', 'serialized' => true),
                            array('name' => 'Postoffice', 'prop' => 'swagDhlPostoffice', 'serialized' => true)
                        );

                        $dhlInfos = array();
                        foreach ($dhlPropertyInfos as $dhlPropertyInfo) {
                            $this->addDHLInfo($orderSW, $dhlInfos, $dhlPropertyInfo);
                        }

                        $extraAddressLine = $order->getShippingAddress()->getExtraAddressLine();
                        if (count($dhlInfos) > 0) {
                            $extraAddressLine .= sprintf(' (%s)', implode(' - ', $dhlInfos));
                        }

                        $order->getShippingAddress()->setStreet($street)
                            ->setExtraAddressLine($extraAddressLine)
                            ->setSalutation(Salutation::toConnector($orderSW['shipping']['salutation']))
                            ->setEmail($orderSW['customer']['email']);
                    }

                    // Adding shipping item
                    $shippingPrice = (isset($orderSW['invoiceShippingNet'])) ? (float) $orderSW['invoiceShippingNet'] : 0.0;
                    $shippingPriceGross = (isset($orderSW['invoiceShipping'])) ? (float) $orderSW['invoiceShipping'] : 0.0;
                    $item = Mmc::getModel('CustomerOrderItem');
                    $item->setType(CustomerOrderItem::TYPE_SHIPPING)
                        ->setId(new Identity(sprintf('%s_ship', $orderSW['id'])))
                        ->setCustomerOrderId($order->getId())
                        ->setName('Shipping')
                        ->setPrice($shippingPrice)
                        ->setPriceGross($shippingPriceGross)
                        ->setQuantity(1)
                        ->setVat(self::calcShippingVat($order));

                    $order->addItem($item);

                    // Attributes
                    for ($i = 1; $i <= 6; $i++) {
                        if (isset($orderSW['attribute']["attribute{$i}"]) && strlen($orderSW['attribute']["attribute{$i}"]) > 0) {
                            $customerOrderAttr = Mmc::getModel('CustomerOrderAttr');
                            $customerOrderAttr->map(true, DataConverter::toObject($orderSW['attribute']));
                            $customerOrderAttr->setKey("attribute{$i}")
                                ->setValue($orderSW['attribute']["attribute{$i}"]);

                            $order->addAttribute($customerOrderAttr);
                        }
                    }

                    // CustomerOrderPaymentInfo
                    if (isset($orderSW['customer']['debit']['id']) && (int) $orderSW['customer']['debit']['id'] > 0) {
                        $customerOrderPaymentInfo = Mmc::getModel('CustomerOrderPaymentInfo');
                        $customerOrderPaymentInfo->map(true, DataConverter::toObject($orderSW['customer']['debit']));
                        $customerOrderPaymentInfo->setCustomerOrderId($order->getId());

                        $order->setPaymentInfo($customerOrderPaymentInfo);
                    }

                    // Payment Data
                    if (isset($orderSW['customer']['paymentData']) && is_array($orderSW['customer']['paymentData'])) {
                        $customerOrderPaymentInfo = $order->getPaymentInfo();
                        if ($customerOrderPaymentInfo === null) {
                            $customerOrderPaymentInfo = Mmc::getModel('CustomerOrderPaymentInfo');
                            $customerOrderPaymentInfo->setCustomerOrderId($order->getId())
                                ->setAccountHolder(sprintf(
                                    '%s %s',
                                    $orderSW['billing']['firstName'],
                                    $orderSW['billing']['lastName']
                                ));
                        }

                        foreach ($orderSW['customer']['paymentData'] as $dataSW) {
                            if (isset($dataSW['bic']) && strlen($dataSW['bic']) > 0
                                && isset($dataSW['iban']) && strlen($dataSW['iban']) > 0) {
                                $customerOrderPaymentInfo->setBic($dataSW['bic'])
                                    ->setIban($dataSW['iban']);
                                break;
                            }
                        }

                        $order->setPaymentInfo($customerOrderPaymentInfo);
                    }

                    $result[] = $order;
                } catch (\Exception $exc) {
                    Logger::write(ExceptionFormatter::format($exc), Logger::WARNING, 'controller');
                }
            }

            $action->setResult($result);
        } catch (\Exception $exc) {
            $err = new Error();
            $err->setCode($exc->getCode());
            $err->setMessage($exc->getMessage());
            $action->setError($err);
        }

        return $action;
    }

    /**
     * Check if dhl postnumber, postoffice or packstation is available
     * Add it or our street information
     *
     * @param array $orderSW
     * @param array $dhlInfo
     * @param array $dhlInfoPropertyInfo
     */
    public function addDHLInfo(array $orderSW, array &$dhlInfos, array $dhlInfoPropertyInfo)
    {
        $property = $dhlInfoPropertyInfo['prop'];
        $name = $dhlInfoPropertyInfo['name'];

        if (isset($orderSW['customer']['shipping']['attribute'][$property])
            && $orderSW['customer']['shipping']['attribute'][$property] !== null
            && strlen($orderSW['customer']['shipping']['attribute'][$property]) > 0) {

            if ($dhlInfoPropertyInfo['serialized']) {
                $obj = @unserialize($orderSW['customer']['shipping']['attribute'][$property]);
                if ($obj !== false) {
                    $number = isset($obj->officeNumber) ? $obj->officeNumber : $obj->stationNumber;
                    if (strlen(trim($obj->zip)) > 0 && strlen(trim($obj->city)) > 0) {
                        $value = sprintf('%s %s, %s', $obj->zip, $obj->city, $number);
                        $dhlInfos[] = sprintf('%s: %s', $name, $value);
                    }
                }
            } else {
                $dhlInfos[] = sprintf('%s: %s', $name, $orderSW['customer']['shipping']['attribute'][$property]);
            }
        }
    }

    public static function calcShippingVat(\jtl\Connector\Shopware\Model\CustomerOrder &$order)
    {
        return max(array_map(function ($item) { return $item->getVat(); }, $order->getItems()));
    }
}
