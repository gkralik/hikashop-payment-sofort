<?php
/**
 * @author    Gregor Kralik
 * @copyright 2016 Gregor Kralik
 * @license   GNU LGPL http://www.gnu.org/licenses/lgpl.html
 */
defined('_JEXEC') or die('Restricted access');
?><?php

/**
 * SOFORT Überweisung payment provider for Hikashop
 *
 * @property $payment_params Payment parameters.
 * @property $plugin_params  Plugin params.
 * @property $currency       Currency information.
 * @property $url_itemid     Item url component.
 * @property $redirect_url   Redirect URL.
 */
class plgHikashoppaymentSofort_ueberweisung extends hikashopPaymentPlugin
{
    const USER_VARIABLE_IDX_ORDER_ID = 0;
    const USER_VARIABLE_IDX_METHOD_ID = 1;

    /**
     * @var array Accepted currencies.
     */
    public $accepted_currencies = [
        'EUR',
    ];

    /**
     * @var bool Does this plugin respond to multiple events?
     */
    public $multiple = true;

    /**
     * @var string Plugin name.
     */
    public $name = 'sofort_ueberweisung';

    /**
     * @var array Plugin configuration. Shown by Hikashop when configuring the payment plugin.
     */
    public $pluginConfig = [
        'sofort_config_key'         => ['Sofort config key', 'input'],
        'sofort_notification_email' => ['Sofort notification email', 'input'],
        'debug'                     => ['DEBUG', 'boolean', '0'],
        'pending_status'            => ['PENDING_STATUS', 'orderstatus'],
        'verified_status'           => ['VERIFIED_STATUS', 'orderstatus'],
        'cancel_url'                => ['CANCEL_URL', 'input'],
        'return_url'                => ['RETURN_URL', 'input'],
    ];

    /**
     * plgHikashoppaymentSofort_ueberweisung constructor.
     *
     * @param object $subject
     * @param array  $config
     */
    public function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        // register Sofort classes
        JLoader::registerNamespace('Sofort', dirname(__FILE__) . '/library');
    }

    /**
     * Create a SOFORT transaction and redirect the user.
     *
     * @param object $order
     * @param object $methods
     * @param int    $method_id
     *
     * @return bool
     */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);

        // set up return URL
        if (empty($this->payment_params->return_url)) {
            $returnUrl = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end&order_id='
                . $order->order_id . $this->url_itemid;
        } else {
            $returnUrl = $this->payment_params->return_url;
        }

        // set up notification URL
        $notificationUrl = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment='
            . $this->name . '&tmpl=component'; // &lang=en

        // set up cancel URL
        if (empty($this->payment_params->cancel_url)) {
            $cancelUrl = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order&task=cancel_order';
        } else {
            $cancelUrl = $this->payment_params->cancel_url;
        }

        // get total amount
        $amount = round($order->cart->full_total->prices[0]->price_value_with_tax,
            (int) $this->currency->currency_locale['int_frac_digits']);


        // set up transaction
        $transaction = new \Sofort\SofortLib\Sofortueberweisung($this->payment_params->sofort_config_key);
        $transaction->setApiVersion('2.0');
        $transaction->setAmount($amount);
        $transaction->setCurrencyCode($this->currency->currency_code);
        $transaction->setReason($order->order_number);
        $transaction->setUserVariable([
            static::USER_VARIABLE_IDX_ORDER_ID  => $order->order_id,
            static::USER_VARIABLE_IDX_METHOD_ID => $method_id
        ]);
        $transaction->setSuccessUrl($returnUrl);
        $transaction->setAbortUrl($cancelUrl);
        $transaction->setTimeoutUrl($cancelUrl);
        $transaction->setNotificationUrl($notificationUrl);

        if (!empty($this->payment_params->sofort_notification_email)) {
            $transaction->setNotificationEmail($this->payment_params->sofort_notification_email);
        }

        // send the transaction request
        $transaction->sendRequest();

        if ($transaction->isError()) {
            return false;
        }

        // all is well, redirect the customer
        $this->redirect_url = $transaction->getPaymentUrl();

        return $this->showPage('end');
    }

    /**
     * Process a payment notification.
     *
     * Relevant data is read from the request body.
     *
     * @param object|array $statuses
     *
     * @return bool
     */
    public function onPaymentNotification(&$statuses)
    {
        // get plugin parameters
        $this->pluginParams();

        // read notification
        $notification = new \Sofort\SofortLib\Notification();
        $notification->getNotification(file_get_contents('php://input'));

        $transactionId = $notification->getTransactionId();

        // get transaction details
        $transactionData = new \Sofort\SofortLib\TransactionData($this->plugin_params->sofort_config_key);
        $transactionData->addTransaction($transactionId);
        $transactionData->setApiVersion('2.0');
        $transactionData->sendRequest();

        // extract user variables
        $methodId = $transactionData->getUserVariable(static::USER_VARIABLE_IDX_METHOD_ID);
        $orderId = $transactionData->getUserVariable(static::USER_VARIABLE_IDX_ORDER_ID);

        // get parameters
        $this->pluginParams($methodId);
        $this->payment_params =& $this->plugin_params;

        global $Itemid;
        $this->url_itemid = empty($Itemid) ? '' : '&Itemid=' . $Itemid;
        $cancelUrl = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=order&task=cancel_order' . $this->url_itemid;

        if (empty($this->payment_params)) {
            $this->redirect_url = $cancelUrl;

            return false;
        }

        // fetch order
        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder)) {
            $this->redirect_url = $cancelUrl;

            return false;
        }

        // write history
        $history = new stdClass();
        $history->history_data = 'SOFORT transaction ID: ' . $transactionId;
        $history->notified = 0;

        if ($transactionData->getStatus() == 'pending') {
            $email = new stdClass();
            $email->subject = JText::sprintf('PAYMENT_NOTIFICATION_FOR_ORDER', 'Sofort', $transactionData->getStatus(),
                $dbOrder->order_number);
            $email->body = str_replace('<br/>', "\r\n",
                    JText::sprintf('PAYMENT_NOTIFICATION_STATUS', 'Sofort', $transactionData->getStatus())) . "\r\n\r\n"
                . $transactionData->getStatusReason();

            $orderStatus = $this->payment_params->pending_status;
            $this->modifyOrder($orderId, $orderStatus, $history, $email);

            return false;
        }

        if ($transactionData->getStatus() != 'received'
            && !($transactionData->getStatus() == 'untraceable' // if it is a test, act as if the payment was successful
                && $transactionData->isTest()
                && $this->plugin_params->debug == 1)
        ) {
            $orderStatus = 'created';

            $email = new stdClass();
            $email->body =
                str_replace('<br/>', "\r\n", JText::sprintf('PAYMENT_NOTIFICATION_STATUS', 'Sofort', $orderStatus))
                . ' ' . JText::_('STATUS_NOT_CHANGED') . "\r\n\r\n" . $transactionData->getStatusReason();
            $email->subject =
                JText::sprintf('PAYMENT_NOTIFICATION_FOR_ORDER', 'Sofort', $orderStatus, $dbOrder->order_number);

            $this->modifyOrder($orderId, $orderStatus, $history, $email);

            return false;
        }

        $orderStatus = $this->payment_params->verified_status;
        $history->history_data = 'SOFORT transaction ID: ' . $transactionId;
        $history->notified = 1;

        $email = new stdClass();
        $email->subject = JText::sprintf('PAYMENT_NOTIFICATION_FOR_ORDER', 'Sofort', $transactionData->getStatus(),
            $dbOrder->order_number);
        $email->body =
            str_replace('<br/>', "\r\n", JText::sprintf('PAYMENT_NOTIFICATION_STATUS', 'Sofort', $orderStatus)) . ' '
            . JText::sprintf('ORDER_STATUS_CHANGED', $orderStatus) . "\r\n\r\n" . $transactionData->getStatusReason();

        $this->modifyOrder($orderId, $orderStatus, $history, $email);

        return true;
    }

    /**
     * Get default values for plugin configuration.
     *
     * @param object $element
     */
    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name = 'SOFORT';
        $element->payment_description = 'Pay with SOFORT Überweisung';
        $element->payment_images = '';

        $element->payment_params->service_type = 'B';
        $element->payment_params->pending_status = 'created';
        $element->payment_params->verified_status = 'confirmed';
    }
}
