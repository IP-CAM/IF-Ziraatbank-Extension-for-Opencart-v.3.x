<?php

class ControllerExtensionPaymentIfZiraatbank extends Controller
{
    public function index()
    {
        $this->load->language('extension/payment/if_ziraatbank');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $orderId = (int)$order_info['order_id'];

        $paymentMethod = $this->config->get('payment_if_ziraatbank_payment_method');

        $data['payment_method'] = $paymentMethod;

        // if ($order_info['customer_id'] != 61) {
        //return '<div class="text-center">Error: payment method not defined: ' . $paymentMethod . '</div>';
        //}

        switch ($paymentMethod) {

            case '3D_PAY_HOSTING':

                $responseObject = $this->curl_request('INIT', [
                    'module'      => 'ZIRAATBANK',
                    'method'      => $paymentMethod,
                    'licence_key' => urlencode($this->config->get('payment_if_ziraatbank_licence_key')),
                    'ok_url'      => urlencode($this->url->link('extension/payment/if_ziraatbank/callback', ['status' => 'ok'], true)),
                    'fail_url'    => urlencode($this->url->link('extension/payment/if_ziraatbank/callback', ['status' => 'fail'], true)),
                    'test'        => ( ! ! $this->config->get('payment_if_ziraatbank_test')),
                    'extra_info'  => [
                        'order_id' => $orderId
                    ],
                    'data'        => [

                        'store_key'     => urlencode($this->config->get('payment_if_ziraatbank_store_key')),
                        'lang_code'     => in_array($order_info['language_code'], ['tr', 'tr-tr', 'TR-tr']) ? 'tr' : 'en',
                        'total'         => $order_info['total'],
                        'currency_code' => $order_info['currency_code'],
                        'order_id'      => $orderId,

                        // @todo bu kısım form'dan alınacak.
                        // 'installment' => 10

                    ]
                ]);

                if ($responseObject === false) {
                    return '<div class="text-center">Error: ödeme sistemi cevap vermiyor. Lütfen daha sonra tekrar deneyiniz.</div>';
                }

                if ($responseObject->type != 'form') {
                    return '<div class="text-center">Error: ödeme sistemi hatalı işleme izin vermedi. Lütfen daha sonra tekrar deneyiniz.</div>';
                }

                $data['action'] = $responseObject->action;
                $data['inputs'] = (array)$responseObject->inputs;

                break;

            default:

                return '<div class="text-center">Error: payment method not defined: ' . $this->config->get('payment_if_ziraatbank_payment_method') . '</div>';

        }

        return $this->load->view('extension/payment/if_ziraatbank', $data);
    }

    public function callback()
    {
        $this->load->model('checkout/order');

        $status = isset($this->request->get['status']) ? $this->request->get['status'] : null;

        switch ($status) {

            case 'ok':

                $transactionId = $this->request->post['transaction_id'];
                $transactionHash = $this->request->post['transaction_hash'];

                $validate_response = $this->validate_transaction($this->config->get('payment_if_ziraatbank_payment_method'), $transactionId, $transactionHash);

                if ($validate_response === false) {

                    $this->session->data['error'] = 'Bilinmeyen bir hata meydana geldi.';

                    $this->response->redirect($this->url->link('checkout/checkout', '', true));

                } else {

                    if ($validate_response->success) {

                        $this->model_checkout_order->addOrderHistory($validate_response->extra_info->order_id, $this->config->get('payment_if_ziraatbank_order_status_id'));

                        $this->response->redirect($this->url->link('checkout/success', '', true));

                    } else {

                        $this->session->data['error'] = isset($validate_response->message) ? $validate_response->message : 'Bilinmeyen bir hata meydana geldi.';

                        $this->response->redirect($this->url->link('checkout/checkout', '', true));

                    }

                }

                break;

            case 'fail':

                $this->session->data['error'] = isset($this->request->post['message']) ? $this->request->post['message'] : 'Bilinmeyen bir hata meydana geldi.';

                $this->response->redirect($this->url->link('checkout/checkout', '', true));

                break;

            default:

                $this->session->data['error'] = 'Bilinmeyen bir hata meydana geldi.';

                $this->response->redirect($this->url->link('checkout/checkout', '', true));

                break;

        }
    }

    private function validate_transaction($paymentMethod, $id, $hash)
    {
        return $this->curl_request('VALIDATE', [
            'module'           => 'ZIRAATBANK',
            'method'           => $paymentMethod,
            'licence_key'      => urlencode($this->config->get('payment_if_ziraatbank_licence_key')),
            'test'             => ( ! ! $this->config->get('payment_if_ziraatbank_test')),
            'transaction_id'   => $id,
            'transaction_hash' => $hash
        ]);
    }

    private function curl_request($action, $data)
    {
        $curl = curl_init('https://backend.ifyazilim.com/payment/process');

        curl_setopt($curl, CURLOPT_PORT, 443);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', [
            'action=' . $action,
            'data=' . json_encode($data)
        ]));

        $response = curl_exec($curl);

        curl_close($curl);

        if ( ! $response) {

            $this->log->write('IfZiraatbankPayment failed: ' . curl_error($curl) . '(' . curl_errno($curl) . ')');

            return false;

        }

        return json_decode($response);
    }
}