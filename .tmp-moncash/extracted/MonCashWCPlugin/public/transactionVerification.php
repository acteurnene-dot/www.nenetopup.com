<?php
/**
 * Created by PhpStorm.
 * User: rulxphilome.alexis
 * Date: 4/24/2019
 * Time: 8:58 AM
 */
require_once dirname( __FILE__ ).'/../includes/tierce_libs/Crypt/RSA.php';
require_once dirname( __FILE__ ).'/../includes/config.php';
require_once dirname( __FILE__ )."/../../../../wp-load.php";

echo "<h1>Mon Cash Order verification</h1>";
echo "<img src='".BASE_MC_URI."resources/assets/images/MC_button.png' />";

/*
 *
 * To Refactor*/
function urlsafe_b64encode($string) {
    $data = base64_encode($string);
    $data = str_replace(array('+','/','='),array('-','_',''),$data);
    return $data;
}

function urlsafe_b64decode($string) {
    $data = str_replace(array('-','_'),array('+','/'),$string);
    $mod4 = strlen($data) % 4;
    if ($mod4) {
        $data .= substr('====', $mod4);
    }
    return base64_decode($data);
}

/*
 *
 * To Refactor*/


$order_id = trim($_GET['orderId']);
$order_id_uncoded = $order_id;


$api_key = trim($_GET['apk']);
$business_key = trim($_GET['bk']);

$api_key64 = urlsafe_b64decode($api_key);

$rsa = new Crypt_RSA();
$rsa->setPublicKey($api_key64, CRYPT_RSA_PUBLIC_FORMAT_PKCS1);
$rsa->loadKey($rsa->getPublicKey(), CRYPT_RSA_PUBLIC_FORMAT_PKCS1);
$rsa->setEncryptionMode(CRYPT_RSA_ENCRYPTION_NONE);
$order_id_enc = trim($order_id);

$order_id_enc = urlsafe_b64encode($rsa->encrypt(strval($order_id_enc)));

$url = BASE_MC_URI."Checkout/".$business_key."/Payment/Order";

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
//var_dump($response);
if($response->success){
    $order = wc_get_order($order_id_uncoded);

    $order->payment_complete();
    wc_reduce_stock_levels($order_id_uncoded);
    $order->update_status('completed');
    $woocommerce->cart->empty_cart();

    $order->add_order_note( 'The order with id '.$order->get_order_number().' is paid via Mon Cash! Thank you!', true );
}
$toredirec =  "Location: ".($_SERVER['HTTP_REFERER']);
header($toredirec);

//var_dump($response);