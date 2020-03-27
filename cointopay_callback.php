<?php
require('includes/application_top.php');

$token = MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET;
if ($token == '' || $_GET['token'] != $token)
    throw new Exception('Token: ' . $_GET['token'] . ' do not match');

global $db;

$order_id = $_REQUEST['CustomerReferenceNr'];
$gkey = MODULE_PAYMENT_COINTOPAY_MERCHANT_ID;
$api_key = MODULE_PAYMENT_COINTOPAY_API_KEY;

if(isset($_GET['ConfirmCode']))
{
    $data = [
        'mid' => $gkey,
        'TransactionID' =>  $_GET['TransactionID'] ,
        'ConfirmCode' => $_GET['ConfirmCode']
    ];
	$transactionData = fn_cointopay_transactiondetail($data);
	if(200 !== $transactionData['status_code']){
		echo $transactionData['message'];exit;
	}
	$value_data = "MerchantID=" . $transactionData['data']['MerchantID'] . "&AltCoinID=" . $transactionData['data']['AltCoinID'] . "&TransactionID=" . $_GET['TransactionID'] . "&coinAddress=" . $transactionData['data']['coinAddress'] . "&CustomerReferenceNr=" . 
	$_GET['CustomerReferenceNr'] . "&SecurityCode=" . $transactionData['data']['SecurityCode'] . "&inputCurrency=" . $transactionData['data']['inputCurrency'];
	$ConfirmCode = fn_cointopay_calculateRFC2104HMAC($api_key, $value_data);
	if($ConfirmCode !== $_GET['ConfirmCode']){
		echo 'Data mismatch! Data doesn\'t match with Cointopay.';exit;
	}
    $response = validateOrder($data);

    if($response->Status !== $_GET['status'])
    {
        echo "We have detected different order status. Your order has been halted.";
        exit;
    }
    if($response->CustomerReferenceNr == $_GET['CustomerReferenceNr'])
    {
        $order = $db->Execute("select orders_id from " . TABLE_ORDERS . " where orders_id = '" . intval($order_id) . "' limit 1");
        if (!$order || !$order->fields['orders_id'])
            throw new Exception('Order #' . $order_id . ' does not exists');

        $redirect_url = '';
        if($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 0 )
        {
            $ctp_order_status = MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID;
            $redirect_url  = zen_href_link('checkout_success');
        }elseif ($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 1) {
            $ctp_order_status = MODULE_PAYMENT_COINTOPAY_PAIDNOTENOUGH_STATUS_ID;
            $redirect_url  = zen_href_link('index');
        }elseif ($_REQUEST['status']== 'failed') {
            $ctp_order_status = MODULE_PAYMENT_COINTOPAY_FAILED_STATUS_ID;
            $redirect_url  = zen_href_link('index');
        }
        else{
            $ctp_order_status = NULL;
            $redirect_url  = zen_href_link(FILENAME_CHECKOUT_PAYMENT);
        }
        //udate order status
        if ($ctp_order_status)
            $db->Execute("update ". TABLE_ORDERS. " set orders_status = " . $ctp_order_status . " where orders_id = ". intval($order_id));

        $date =  (new \DateTime())->format('Y-m-d H:i:s');
        if($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 0){


            $db->Execute("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('$order_id', '$ctp_order_status', '$date', '1', 'Payment completed notification from Cointopay')");
        }

        if($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 1){

            $db->Execute("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('$order_id', '$ctp_order_status', '$date', '1', 'Payment failed notification from Cointopay because notenough')");
        }

        if($_REQUEST['status']== 'failed'){

            $db->Execute("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('$order_id', '$ctp_order_status', '$date', '1', 'Payment failed notification from Cointopay')");
        }
        zen_redirect($redirect_url);
    }
    else
    {
        echo "order id not match";
        exit;
    }
}
else
{
    echo "ConfirmCode not match";
    exit;
}


function  validateOrder($data)
{
    //https://cointopay.com/v2REAPI?MerchantID=14351&Call=QA&APIKey=_&output=json&TransactionID=230196&ConfirmCode=YGBMWCNW0QSJVSPQBCHWEMV7BGBOUIDQCXGUAXK6PUA
    $params = array(
        "authentication:1",
        'cache-control: no-cache',
    );
    $ch = curl_init();
    curl_setopt_array($ch, array(
            CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
            //CURLOPT_USERPWD => $this->apikey,
            CURLOPT_POSTFIELDS => 'MerchantID='.$data['mid'].'&Call=QA&APIKey=_&output=json&TransactionID='.$data['TransactionID'].'&ConfirmCode='.$data['ConfirmCode'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $params,
            CURLOPT_USERAGENT => 1,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC
        )
    );
    $response = curl_exec($ch);
    $results = json_decode($response);
    if($results->CustomerReferenceNr)
    {
        return $results;
    }
    echo $response;
}
function  fn_cointopay_transactiondetail($data)
{
       $params = array(
       "authentication:1",
       'cache-control: no-cache',
       );
       $ch = curl_init();
       curl_setopt_array($ch, array(
       CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
       //CURLOPT_USERPWD => $this->apikey,
       CURLOPT_POSTFIELDS => 'Call=Transactiondetail&MerchantID='.$data['mid'].'&output=json&ConfirmCode='.$data['ConfirmCode'].'&APIKey=a',
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_SSL_VERIFYPEER => false,
       CURLOPT_HTTPHEADER => $params,
       CURLOPT_USERAGENT => 1,
       CURLOPT_HTTPAUTH => CURLAUTH_BASIC
       )
       );
       $response = curl_exec($ch);
       $results = json_decode($response, true);
       /*if($results->CustomerReferenceNr)
       {
           return $results;
       }*/
       return $results;
       exit();
}
function fn_cointopay_calculateRFC2104HMAC($key, $data)
{
	$s = hash_hmac('sha256', $data, $key, true);

	return strtoupper(fn_cointopay_base64url_encode($s));
}
function fn_cointopay_base64url_encode($data) {
	return strtoupper(rtrim(strtr(base64_encode($data), '+/', '-_'), '='));
}

