<?php

namespace Services\PaymentGateway;

class StripeSCA
{

    CONST GATEWAY_NAME = 'Stripe\PaymentIntents';

    private $transaction_data;

    private $gateway;

    private $extra_params = ['paymentMethod', 'payment_intent'];

    public function __construct($gateway)
    {
        $this->gateway = $gateway;
        $this->options = [];
    }

    private function createTransactionData($order_total, $order_email, $event, $ticket_order)
    {

        $returnUrl = route('showEventCheckoutPaymentReturn', [
            'event_id' => $event->id,
            'is_payment_successful' => 1,
        ]);

        // fetching the payment gateway config
        $account_payment_gateway = $ticket_order['account_payment_gateway']->config;
        // get the booking fees configured for that order
        $fees = $ticket_order['total_booking_fee'];

        /**
         * If an account is configured in the payment gateway as a transfer destination, use it.
         */
        if (array_key_exists('transfer_data_destination_id',$account_payment_gateway) || !empty($account_payment_gateway['transfer_data_destination_id'])) {
            if (array_key_exists('application_fee_amount', $account_payment_gateway) || !empty($account_payment_gateway['application_fee_amount'])){
                $gateway_fees = floatval($account_payment_gateway['application_fee_amount']);
            }else {
                $gateway_fees = 0;
            }
            $this->transaction_data = [
                'amount' => $order_total,
                'currency' => $event->currency->code,
                'description' => 'Order for customer: ' . $order_email,
                'paymentMethod' => $this->options['paymentMethod'],
                'receipt_email' => $order_email,
                'returnUrl' => $returnUrl,
                'confirm' => true,
                // Add the configured payment gateway fees to the existing fees.
                'application_fee_amount' => $gateway_fees + $fees ,
                // Pass the destination ID
                'destination' => $account_payment_gateway['transfer_data_destination_id']
            ];
        }else{
            $this->transaction_data = [
                'amount' => $order_total,
                'currency' => $event->currency->code,
                'description' => 'Order for customer: ' . $order_email,
                'paymentMethod' => $this->options['paymentMethod'],
                'receipt_email' => $order_email,
                'returnUrl' => $returnUrl,
                'confirm' => true
            ];
        }


        return $this->transaction_data;
    }

    public function startTransaction($order_total, $order_email, $event, $ticket_order)
    {
        $this->createTransactionData($order_total, $order_email, $event, $ticket_order);
        $response = $this->gateway->authorize($this->transaction_data)->send();

        return $response;
    }

    public function getTransactionData()
    {
        return $this->transaction_data;
    }

    public function extractRequestParameters($request)
    {
        foreach ($this->extra_params as $param) {
            if (!empty($request->get($param))) {
                $this->options[$param] = $request->get($param);
            }
        }
    }

    public function completeTransaction($data)
    {
        if (array_key_exists('payment_intent', $data)) {
            $intentData = [
                'paymentIntentReference' => $data['payment_intent'],
            ];
        } else {
            $intentData = [
                'paymentIntentReference' => $this->options['payment_intent'],
            ];
        }

        $paymentIntent = $this->gateway->fetchPaymentIntent($intentData);
        $response = $paymentIntent->send();

        if ($response->requiresConfirmation()) {
            $confirmResponse = $this->gateway->confirm($intentData)->send();
            if ($confirmResponse->isSuccessful()) {
                $response = $this->gateway->capture($intentData)->send();
            }
        } else {
            $response = $this->gateway->capture($intentData)->send();
        }

        return $response;
    }

    public function getAdditionalData($response)
    {

        $additionalData['payment_intent'] = $response->getPaymentIntentReference();
        return $additionalData;
    }

    public function storeAdditionalData()
    {
        return true;
    }

    public function refundTransaction($order, $refund_amount, $refund_application_fee)
    {

        $request = $this->gateway->cancel([
            'transactionReference' => $order->transaction_id,
            'amount' => $refund_amount,
            'refundApplicationFee' => $refund_application_fee,
            'paymentIntentReference' => $order->payment_intent
        ]);

        $response = $request->send();

        if ($response->isCancelled()) {
            $refundResponse['successful'] = true;
        } else {
            $refundResponse['successful'] = false;
            $refundResponse['error_message'] = $response->getMessage();
        }

        return $refundResponse;
    }

}
