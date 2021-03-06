<?php

/**
 * Copyright (c) 2013, EBANX Tecnologia da Informação Ltda.
 *  All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this
 * list of conditions and the following disclaimer.
 *
 * Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * Neither the name of EBANX nor the names of its
 * contributors may be used to endorse or promote products derived from
 * this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

class WC_Gateway_Ebanx extends WC_Payment_Gateway
{
  public function __construct()
  {
    $this->id           = 'ebanx';
    $this->icon         = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/ebanx_direct.png';
    $this->has_fields   = false;
    $this->method_title = __('EBANX Express', 'woocommerce');

    // Load the settings
    $this->init_form_fields();
    $this->init_settings();

    // Define user set variables
    $this->title               = $this->get_option('title');
    $this->description         = $this->get_option('description');
    $this->merchant_key        = $this->get_option('merchant_key');
    $this->test_mode           = ($this->get_option('test_mode') == 'yes');
    $this->enable_cc           = true;

    // Images
    $this->icon_boleto = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/icon_boleto.png';
    $this->icon_tef    = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/icon_tef.png';
    $this->icon_cc     = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/icon_cc.png';

    // Set EBANX configs
    \Ebanx\Config::set(array(
        'integrationKey' => $this->merchant_key
      , 'testMode'       => $this->test_mode
      , 'directMode'     => true
    ));

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
    add_action('woocommerce_receipt_ebanx', array(&$this, 'receipt_page'));
    add_action('woocommerce_checkout_update_order_meta', 'checkout_fields_save');
    add_action( 'init', 'wptuts_register_token_table', 1 );
    add_action( 'switch_blog', 'wptuts_register_token_table' );
    register_activation_hook( __FILE__, 'wptuts_create_tables' );
  }

  /**
   * Render the administration form fields
   * @return void
   */
  public function init_form_fields()
  {
    $this->form_fields = array(
      'enabled' => array(
        'title'   => __('Enable/Disable', 'woocommerce'),
        'type'    => 'checkbox',
        'label'   => __('Enable EBANX Credit Card payment gateway', 'woocommerce'),
        'default' => 'yes'
      ),
      'test_mode' => array(
        'title'   => __('Test mode', 'woocommerce'),
        'type'    => 'checkbox',
        'label'   => __('Enable test mode', 'woocommerce'),
        'default' => 'yes',
        'description' => ''
      ),
      'merchant_key' => array(
        'title'   => __('Merchant key', 'woocommerce'),
        'type'    => 'text',
        'default' => '',
        'description' => ''
      ),
      'title' => array(
        'title'    => __('Title', 'woocommerce'),
        'type'     => 'text',
        'default'  => __('EBANX - Cartão de Crédito', 'woocommerce'),
        'desc_tip' => true,
        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce')
      ),
      'description' => array(
        'title'   => __('Customer message', 'woocommerce'),
        'type'    => 'textarea',
        'default' => __('Pagamentos para clientes do Brasil.', 'woocommerce'),
        'description' => __('Give the customer instructions for paying via EBANX.', 'woocommerce')
      ),
    );
  }

  function checkout_fields_save($order_id)
  {
    global $woocommerce;

    if (isset($_POST['installments_number']))
    {
      update_post_meta($order_id, 'installments_number', esc_attr($_POST['installments_number']));
      update_post_meta($order_id, 'installments_card', esc_attr($_POST['installments_card']));
    }
  }


  /**
   * Process the payment and return the result
   * @return array
   */
  public function process_payment($order_id)
  {
   $order = new WC_Order($order_id);

    return array(
      'result'   => 'success',
      'redirect' => $order->get_checkout_payment_url(true)
    );
  }

  /**
   * Receipt page content
   * @return void
   */
  public function receipt_page($order)
  {
    echo $this->generate_ebanx_form($order);
  }

  /**
   * Returns the assets path
   * @param  string $filename The asset name
   * @return string
   */
  protected function getAssetPath($filename)
  {
    return dirname(__FILE__) . '/assets/' . $filename;
  }

  /**
   * Returns the path to a template file
   * @param  string $template The template name
   * @return string
   */
  protected function getTemplatePath($template)
  {
    return dirname(__FILE__) . '/view/' . $template;
  }

  /**
   * Renders the EBANX checkout page
   * @param  int $order_id The order ID
   * @return boolean
   */
  protected function _renderCheckout($order_id)
  {
    global $woocommerce;

    // Loads the current order
    $order = new WC_Order($order_id);

    $ebanxDocument = (isset($order->billing_cpf)) ? $order->billing_cpf: '';
    $personType    = (isset($_POST['ebanx']['person_type'])) ? $_POST['ebanx']['person_type'] : 'personal';
    $responsible   = $order->billing_first_name . ' ' . $order->billing_last_name;
    $companyName   = $order->billing_company ?: '';

    if (isset($order->billing_birthdate))
    {
      $dateParts = explode('/', $order->billing_birthdate);
      $birthDate = array(
          'day'   => $dateParts[0]
        , 'month' => $dateParts[1]
        , 'year'  => $dateParts[2]
      );
    }
    else
    {
      $birthDate = $this->getBirthdateFromRequest();
    }

    $orderCountry       = $order->billing_country;
    $template           = $this->getCheckoutTemplate($orderCountry);

    // Template vars
    $checkoutUrl        = $woocommerce->cart->get_checkout_url();
    $installmentOptions = $this->calculateInstallmentOptions($order->order_total);

    echo eval(' ?>' . $template . '<?php ');
  }

  /**
   * Gets the birthdate from the payment request
   * @return array
   */
  protected function getBirthdateFromRequest($formatDate = false)
  {
    // If person is business
    if (isset($_POST['ebanx']['person_type']) && $_POST['ebanx']['person_type'] == 'business')
    {
      $date = array(
          'day'   => (isset($_POST['ebanx']['responsible_birth_day']))   ? $_POST['ebanx']['responsible_birth_day'] : 0
        , 'month' => (isset($_POST['ebanx']['responsible_birth_month'])) ? $_POST['ebanx']['responsible_birth_month'] : 0
        , 'year'  => (isset($_POST['ebanx']['responsible_birth_year']))  ? $_POST['ebanx']['responsible_birth_year'] : 0
      );
    }
    else
    {
      $date = array(
          'day'   => (isset($_POST['ebanx']['birth_day']))   ? $_POST['ebanx']['birth_day'] : 0
        , 'month' => (isset($_POST['ebanx']['birth_month'])) ? $_POST['ebanx']['birth_month'] : 0
        , 'year'  => (isset($_POST['ebanx']['birth_year']))  ? $_POST['ebanx']['birth_year'] : 0
      );
    }

    // Return empty date if nothing was supplied
    if ($date['day'] == 0)
    {
      return '13/06/1986';
    }

    if ($formatDate)
    {
      $date = str_pad($date['day'], 2, '0', STR_PAD_LEFT) . '/' .
              str_pad($date['month'], 2, '0', STR_PAD_LEFT) . '/' .
              str_pad($date['year'],   2, '0', STR_PAD_LEFT);
    }

    return $date;
  }

  /**
   * Returns the template file for a country
   * @param  string $orderCountry Template for payments in a country
   * @return string
   */
  protected function getCheckoutTemplate($orderCountry)
  {
    $tplDir  = dirname(__FILE__) . '/view/checkout/';
    $tplPath = $tplDir . 'checkout_br.php';

    if (file_exists($tplPath))
    {
      return file_get_contents($tplPath);
    }

    // Default template ("error template")
    return file_get_contents($tplDir . 'checkout_default.php');
  }

  protected function calculateInstallmentOptions($orderTotal)
  {
    $options = array();
    $options[1] = $orderTotal;

    for ($i = 2; $i <= $this->max_installments; $i++)
    {
      $total = $this->calculateTotalWithInterest($orderTotal, $i);

      // Enforce minimum 30 moneys for installments
      if ($total / $i >= 30)
      {
        $options[$i] = $total;
      }
      else
      {
        break;
      }
    }

    return $options;
  }

  /**
   * Generates the EBANX button link
   * @return string
   */
  public function generate_ebanx_form($order_id)
  {
    global $woocommerce;
    $servername = DB_HOST;
    $username = DB_USER;
    $password = DB_PASSWORD;
    $database = DB_NAME;

    // Set EBANX configs
    \Ebanx\Config::set(array(
        'integrationKey' => $this->merchant_key
      , 'testMode'       => $this->test_mode
      , 'directMode'     => true
    ));

    // Loads the current order
    $order = new WC_Order($order_id);

    // If is GET, do nothing, otherwise process the request
    if ($_SERVER['REQUEST_METHOD'] === 'GET')
    {
      $this->_renderCheckout($order_id);
      return;
    }

    $order = new WC_Order($order_id);

    $streetNumber  = isset($order->billing_number) ? $order->billing_number : '1';
    $paymentMethod = (isset($_POST['ebanx']['method'])) ? $_POST['ebanx']['method'] : '';
    $countryCode   = $order->billing_country;

    // Append timestamp on test mode
    $orderId = ($this->test_mode) ? $order_id . time() : $order_id;

    $params = array(
        'mode'      => 'full'
      , 'operation' => 'request'
      , 'payment'   => array(
            'merchant_payment_code' => $orderId
          , 'order_number'      => $order_id
          , 'amount_total'      => $order->order_total
          , 'currency_code'     => get_woocommerce_currency()
          , 'name'              => $order->billing_first_name . ' ' . $order->billing_last_name
          , 'email'             => $order->billing_email
          , 'birth_date'        => $this->getBirthdateFromRequest(true)
          , 'address'           => $order->billing_address_1
          , 'street_number'     => $streetNumber
          , 'city'              => $order->billing_city
          , 'state'             => $order->billing_state
          , 'zipcode'           => $order->billing_postcode
          , 'country'           => $order->billing_country
          , 'phone_number'      => $order->billing_phone
          , 'payment_type_code' => $_POST['ebanx']['cc_type']
          , 'document'          => $order->billing_cpf
        )
    );

    $ccExpiration = str_pad($_POST['ebanx']['cc_expiration_month'], 2, '0', STR_PAD_LEFT) . '/'
                    . $_POST['ebanx']['cc_expiration_year'];

    try
    {
      $token = \Ebanx\Ebanx::doToken([
                    'payment_type_code' => $_POST['ebanx']['cc_type']
                  , 'creditcard' => [
                        'card_number'   => $_POST['ebanx']['cc_number']
                      , 'card_name'     => $_POST['ebanx']['cc_name']
                      , 'card_due_date' => $ccExpiration
                      , 'card_cvv'      => $_POST['ebanx']['cc_cvv']
                    ]
                ]
      );


    }
    catch(Exception $e)
    {
      $_SESSION['ebanxError'] = $e->getMessage();
      $this->_renderCheckout($order_id);
      return;  
    }

    if($token->status == "ERROR")
    {
      $_SESSION['ebanxError'] = "Erro ao processar pagamento: ". $token->status_message;
      $this->_renderCheckout($order_id);
      return;      
    }
    
    $customer = wp_get_current_user();
    $customer_id = $customer->data->ID;
    $order_id_from_object = $order->id;
    $currency_code = $params['payment']['currency_code'];
    $payment_type_code = $params['payment']['payment_type_code'];
    $birth_date = $params['payment']['birth_date'];

    try {
        $conn = new PDO("mysql:host=$servername;dbname=$database", $username, $password);
        // set the PDO error mode to exception
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    catch(PDOException $e)
    {
        echo "Connection failed: " . $e->getMessage();
    }

    try
    {
      $params['payment']['creditcard'] = array(
          'token'     => $token->token
      );
      $response = \Ebanx\Ebanx::doRequest($params);

      if ($response->status == 'SUCCESS')
      {
        $sql = "CREATE TABLE IF NOT EXISTS `ebanx_token` (
                `id` INT AUTO_INCREMENT NOT NULL,
                `data` datetime NOT NULL,
                `token` varchar(200),
                `customer_id` varchar(200),
                `order_id` varchar(200),
                `currency_code` varchar(200),
                `birth_date` varchar(200),
                `payment_type_code` varchar(200),
                PRIMARY KEY (`id`)) ";
        $conn->query($sql);

        date_default_timezone_set('America/Sao_Paulo');
        $month = date('m');
        $year = date('Y');
        $day = date('d');

        if ($day > '28' && $month == '02')
        {
          $day = '28';
        }
        else if ($day == '31')
        {
          $day = '01';
        }

        $date = $year . '-' . $month . '-' . $day;

        $sql = "INSERT INTO ebanx_token (data, token, customer_id, order_id, currency_code, birth_date, payment_type_code)
                VALUES ('$date', '$token->token', '$customer_id', '$order_id_from_object', '$currency_code', '$birth_date', '$payment_type_code')";
        $conn->query($sql);

        // Clear cart
        $woocommerce->cart->empty_cart();

        if ($paymentMethod == 'boleto')
        {
          $boletoUrl = $response->payment->boleto_url;
          $orderUrl  = $order->get_checkout_order_received_url($order);

          $tplDir = dirname(__FILE__) . '/view/';

          $template = file_get_contents($tplDir . 'success/boleto.php');
          echo eval(' ?>' . $template . '<?php ');
        }
        else if ($paymentMethod == 'pagoefectivo')
        {
          $cipUrl   = $response->payment->cip_url;
          $cipCode  = $response->payment->cip_code;
          $orderUrl = $order->get_checkout_order_received_url($order);

          $tplDir = dirname(__FILE__) . '/view/';

          $template = file_get_contents($tplDir . 'success/pagoefectivo.php');
          echo eval(' ?>' . $template . '<?php ');
        }
        else if ($paymentMethod == 'tef')
        {
          wp_redirect($response->redirect_url);
        }
        else
        {
          wp_redirect($this->get_return_url($order));
        }
      }
      else
      {
        $_SESSION['ebanxError'] = $this->getEbanxErrorMessage($response->status_code, $countryCode);
        $this->_renderCheckout($order_id);
      }
    }
    catch (Exception $e)
    {
      $_SESSION['ebanxError'] = $e->getMessage();
      $this->_renderCheckout($order_id);
    }
    $conn = null;
  }

  /**
   * Returns user friendly error messages
   * @param string $errorCode The error code
   * @param string $countryCode The country code
   * @return string
   */
  protected function getEbanxErrorMessage($errorCode, $countryCode)
  {
    $countryCode = strtolower($countryCode);

    switch ($countryCode)
    {
      case 'pe':
        $lang = 'es';
        break;
      default:
        $lang = 'pt';
        break;
    }

    $messages = array(
        'BP-DR-13' => array(
            'pt' => 'É necessário fornecer seu nome.'
          , 'es' => 'Por favor introduzca su nombre.'
        )
      , 'BP-DR-15' => array(
            'pt' => 'É necessário fornecer seu email.'
          , 'es' => 'Por favor introduzca su correo electrónico.'
        )
      , 'BP-DR-17' => array(
            'pt' => 'O email fornecido não é válido.'
          , 'es' => 'La dirección de correo electrónico no es válida.'
        )
      , 'BP-DR-19' => array(
            'pt' => 'É necessário fornecer sua data de nascimento.'
          , 'es' => 'Usted debe proporcionar su fecha de nacimiento.'
        )
      , 'BP-DR-20' => array(
            'pt' => 'A data de nascimento não é válida.'
          , 'es' => 'La fecha de nacimiento no es válida.'
        )
      , 'BP-DR-21' => array(
            'pt' => 'É preciso ser maior de 16 anos para comprar.'
          , 'es' => 'Usted debe ser mayor de 16 años para comprar.'
        )
      , 'BP-DR-22' => array(
            'pt' => 'É preciso fornecer um CPF e/ou CNPJ válido.'
          , 'es' => 'Debe proporcionar un RUC válido.'
        )
      , 'BP-DR-23' => array(
            'pt' => 'É preciso fornecer um CPF e/ou CNPJ válido.'
          , 'es' => 'Debe proporcionar un RUC válido.'
        )
      , 'BP-DR-35' => array(
            'pt' => 'Por favor revise os detalhes de pagamento.'
          , 'es' => 'Por favor revise los detalles del pago.'
        )
      , 'BP-DR-39' => array(
            'pt' => 'O CPF é inválido ou está irregular na Receita Federal.'
          , 'es' => 'El RUC es inválido o irregular en IRS.'
        )
      , 'BP-DR-43' => array(
            'pt' => 'É necessário informar o nome do responsável.'
          , 'es' => 'Debe proporcionar el nombre del responsable.'
        )
      , 'BP-DR-44' => array(
            'pt' => 'É necessário informar o CPF do responsável.'
          , 'es' => 'Debe proporcionar el RUC del responsable.'
        )
    );

    if (isset($messages[$errorCode][$lang]))
    {
      return $messages[$errorCode][$lang];
    }

    return 'Unknown error. Please contact the store administrator.';
  }

  /**
   * Calculates the order total with interest
   * @param  float  $orderTotal   The order total
   * @param  int    $installments The installments number
   * @return float
   */
  protected function calculateTotalWithInterest($orderTotal, $installments)
  {
    switch ($this->interest_mode) {
      case 'compound':
        $total = $orderTotal * pow((1.0 + floatval($this->interest_rate / 100)), $installments);
        break;
      case 'simple':
        $total = (floatval($this->interest_rate / 100) * floatval($orderTotal) * intval($installments)) + floatval($orderTotal);
        break;
      default:
        throw new Exception("Interest mode {$interestMode} is unsupported.");
        break;
    }

    return $total;
  }
}