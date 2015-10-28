<?php

require('../../../wp-load.php');

$servername = DB_HOST;
$username = DB_USER;
$password = DB_PASSWORD;
$database = DB_NAME;

$args = array(
    'status' => '',
    'customer_id' => '3',
    'customer_note' => 'Cobranca',
    'order_id' => 0
);

$ebanx = new WC_Gateway_Ebanx;

\Ebanx\Config::set(array(
    'integrationKey' => $ebanx->merchant_key
  , 'testMode'       => $ebanx->test_mode
  , 'directMode'     => true
));

$data = date('d');

try {
    $conn = new PDO("mysql:host=$servername;dbname=$database", $username, $password);
    // set the PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // echo "Connected successfully 2\n"; 
    $sql = "SELECT * FROM ebanx_token WHERE DATE_FORMAT(data,'%d') = $data";
    //$sql = "SELECT * FROM ebanx_token";
        $result = $conn->query($sql);
    //$result = $result->fetch (PDO::FETCH_ASSOC);
    while ($data = $result->fetch (PDO::FETCH_ASSOC)) {

        $args = array(
            'status' => '',
            'customer_id' => $data['customer_id'],
            'customer_note' => '',
            'created_via'   => 'EBANX Recurring cron',
            'order_id' => 0
        );

        $order = new WC_Order($data['order_id']);
        $streetNumber  = isset($order->billing_number) ? $order->billing_number : '1';

        $newOrder = wc_create_order($args);
        update_post_meta($newOrder->id, '_order_total', $order->order_total);

        $params = array(
            'mode'      => 'full'
          , 'operation' => 'request'
          , 'payment'   => array(
                'merchant_payment_code' => $newOrder->id
              , 'order_number'      => $newOrder->id
              , 'amount_total'      => $order->order_total
              , 'currency_code'     => $data['currency_code']
              , 'name'              => $order->billing_first_name . ' ' . $order->billing_last_name
              , 'email'             => $order->billing_email
              , 'birth_date'        => $data['birth_date']
              , 'address'           => $order->billing_address_1
              , 'street_number'     => $streetNumber
              , 'city'              => $order->billing_city
              , 'state'             => $order->billing_state
              , 'zipcode'           => $order->billing_postcode
              , 'country'           => $order->billing_country
              , 'phone_number'      => $order->billing_phone
              , 'payment_type_code' => $data['payment_type_code']
              , 'document'          => $order->billing_cpf
              , 'creditcard' => array(
                    'token' => $data['token']
                )
            )
        );

        $response = \Ebanx\Ebanx::doRequest($params);

        if (isset($response->status) && $response->status == 'SUCCESS')
        {
            if ($response->payment->status == 'CA')
            {
                $newOrder->add_order_note('Payment failed.');
                $newOrder->cancel_order();
                echo "OK: Payment {$response->hash} was cancelled via IPN\n";
            }
            if ($response->payment->status == 'CO')
            {
                $newOrder->add_order_note('Payment confirmed.');
                $newOrder->update_status('completed');
                echo "OK: Payment {$response->hash} was confirmed via IPN\n";
            }            
        }
    }
    // var_dump($result);
    $conn = null;
}
catch(PDOException $e)
{
    echo "Connection failed: " . $e->getMessage();
}
