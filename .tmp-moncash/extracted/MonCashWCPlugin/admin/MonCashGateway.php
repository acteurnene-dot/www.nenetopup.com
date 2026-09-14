<?php

require_once plugin_dir_path( __FILE__ ).'../includes/tierce_libs/Crypt/RSA.php';
require_once plugin_dir_path( __FILE__ ).'../includes/config.php';

class MonCashGateway extends WC_Payment_Gateway {

    private $base_uri = "";

    public function __construct(){
        $this->id = "mon_cash_pay";
        $this->title = "Mon Cash Pay";

        $this->description = "A simple way to pay with Digicel Mon Cash account on Woo Commerce Site";

        $this->has_fields = false;
        $this->method_title = "Mon Cash Pay";
        $this->method_description = "A simple way to pay with Digicel Mon Cash account on Woo Commerce Site";

        $this->supports = array(
            'products'
        );

        $this->init_form_fields();

        $this->init_settings();
        $this->base_uri = ($this->settings['env'] == "sandbox") ? BASE_SANDBOX_MC_URI : BASE_MC_URI;
        $this->icon = $this->base_uri."resources/assets/images/MC_button.png";

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

         add_action( 'woocommerce_api_mc_call_back', array( $this, 'webhook_mcCallBack' ) );
 
    }

    public function urlsafe_b64encode($string) {
        $data = base64_encode($string);
        $data = str_replace(array('+','/','='),array('-','_',''),$data);
        return $data;
    }

    public function urlsafe_b64decode($string) {
        $data = str_replace(array('-','_'),array('+','/'),$string);
        $mod4 = strlen($data) % 4;
        if ($mod4) {
            $data .= substr('====', $mod4);
        }
        return base64_decode($data);
    }

    public function init_form_fields(){
        $this->form_fields = array(
            'env' => array(
                'title'       => 'Environnement',
                'type'        => 'select',
                'options'     => array(
                    'sandbox' => "SANDBOX",
                    'prod'    => "PRODUCTION",
                ),
            ),
            'secret_key' => array(
                'title'       => 'Secret Key',
                'type'        => 'textarea',
                'description' => 'Load the public key provided by Mon Cash.',
            ),

            'business_key' => array(
                'title'       => 'Business Key',
                'type'        => 'textarea',
                'description' => 'Load the business key provided by Mon Cash.',
            ),
            
        );
    }

    public function payment_scripts() {
        // payment scripts action wil be there
    }

    public function process_payment( $order_id ) {
        global $woocommerce, $wpdb;

//        $order_id = 82;
        $api_key = trim($this->settings['secret_key']);
        $business_key = trim($this->settings['business_key']);

//        echo $business_key."---"."$api_key";
        $api_key64 = $this->urlsafe_b64decode($api_key);

        $rsa = new Crypt_RSA();
        $rsa->setPublicKey($api_key64, CRYPT_RSA_PUBLIC_FORMAT_PKCS1);
        $rsa->loadKey($rsa->getPublicKey(), CRYPT_RSA_PUBLIC_FORMAT_PKCS1);
        $rsa->setEncryptionMode(CRYPT_RSA_ENCRYPTION_NONE);
        $order = wc_get_order( $order_id );

        //Retrieving identificaition info
        //        echo "The ID -".$order_id;
        $order_id_enc = trim($order_id);

        $order_id_enc = $this->urlsafe_b64encode($rsa->encrypt(strval($order_id_enc)));
        $amount_enc = $this->urlsafe_b64encode($rsa->encrypt(strval($order->total)));

//        $payment_gateway_id = 'mon_cash_pay';

        $alreadyPaid = false;
        $url = $this->base_uri."Checkout/".$business_key."/Payment/Order";
        $data = array(
            'orderId' => $order_id_enc,
        );
        $myvars = http_build_query($data);

        $ch = curl_init( $url );
        curl_setopt( $ch, CURLOPT_POST, 1);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $myvars);
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt( $ch, CURLOPT_HEADER, 0);
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        $response = curl_exec( $ch );
        $response = json_decode($response);

        if($response->success){
            $alreadyPaid = true;
        }


        if(!$alreadyPaid){

    //        $url_to_sender = "MC_Sender.php?order_id=".$order_id_enc."&amount=".$amount_enc."&business_key=".$business_key; Not used anymore

            $url = $this->base_uri."Checkout/Rest/".$business_key;

            $data = array(
                'amount' => $amount_enc,
                'orderId' => $order_id_enc,
            );
            $myvars = http_build_query($data);

            $ch = curl_init( $url );
            curl_setopt( $ch, CURLOPT_POST, 1);
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $myvars);
            curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt( $ch, CURLOPT_HEADER, 0);
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
            $response = curl_exec( $ch );
            $response = json_decode($response);

    //        echo json_encode($response);exit;
            if($response->success) {
                return array(
                    'result' => 'success',
                    'totalAchat'=> $order->total,
                    'redirect'=> $this->base_uri."Payment/Redirect?token=".$response->token,
                );
            }else{
                wc_add_notice("Error occured while processing the order on MonCash","error");
                /*$theFile = fopen("testrezo.txt", "w");
                fwrite($theFile, json_encode($response));
                var_dump($theFile);
    //            exit;
                return array(
                    'result' => 'failed',
                    'totalAchat'=> $order->total,
                    'redirect___'=> $response
                );*/
            }

            /*return array(
                'result' => 'success',
                'totalAchat'=> $order->total,
    //            'redirect' => $this->get_return_url( $order )
    //            'redirect' => $url_to_sender,
    //            'site_URI'=> get_site_url(),
                'redirect'=> plugins_url()."/mon-cash-pay/admin/".$url_to_sender,
            );*/
        }else{
            wc_add_notice("You already pay for this purchase! If you need help or if it's an error please contact the website","notice");
        }

        
    }

    public function webhook_mcCallBack(){
        global $woocommerce, $wpdb;

        $transactionId = $this->urlsafe_b64decode($_GET['transactionId']);
        $api_key = trim($this->settings['secret_key']);
        $api_key64 = base64_decode($api_key);
        $rsa = new Crypt_RSA();
        $rsa->setPublicKey($api_key64, CRYPT_RSA_PUBLIC_FORMAT_PKCS1);
        $rsa->loadKey($rsa->getPublicKey(), CRYPT_RSA_PUBLIC_FORMAT_PKCS1);
        $rsa->setEncryptionMode(CRYPT_RSA_ENCRYPTION_NONE);

        $transactionId = $rsa->decrypt($transactionId);
//        echo $transactionId;exit;

        $transactionId_enc = $this->urlsafe_b64encode($rsa->encrypt(strval($transactionId)));
//        echo $transactionId_enc;exit;
        $business_key = trim($this->settings['business_key']);

        $url = $this->base_uri."Checkout/".$business_key."/Payment/Transaction";

//        echo $url;exit;

        $data = array(
            'transactionId' => $transactionId_enc,
        );
        $myvars = http_build_query($data);

//        Grabbing transactionINFO
        $ch = curl_init( $url );
        curl_setopt( $ch, CURLOPT_POST, 1);
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $myvars);
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt( $ch, CURLOPT_HEADER, 0);
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        $response = curl_exec( $ch );
        $response = json_decode($response);
        var_dump($response);
        if($response->payment_status){
            $order = wc_get_order($response->reference);

            $order->payment_complete();
            wc_reduce_stock_levels($response->reference);
            $order->update_status('completed');
            $woocommerce->cart->empty_cart();

            $order->add_order_note( 'The order with id '.$order->get_order_number().' is paid via Mon Cash! Thank you!', true );
        }
        exit;

    }

    
}