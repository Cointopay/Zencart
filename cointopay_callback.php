<?php
require('includes/application_top.php');

$token = MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET;
if ($token == '' || $_GET['token'] != $token)
    throw new Exception('Token: ' . $_GET['token'] . ' do not match');

global $db;

$order_id = $_REQUEST['CustomerReferenceNr'];
$gkey = MODULE_PAYMENT_COINTOPAY_MERCHANT_ID;
$ctpStrStart = '<style>
body, html {
  height: 100%;
  margin: 0;
}
.middle {
	width: 50%;
	padding: 8%;
	margin: auto;
	background: #f9f9f9;
	border-radius: 10px;
	transform: translate(0%,50%);
}
@media only screen and (max-width: 991px) {
  .middle {
	width: 98%;
  }
}
</style>
<center><div class="middle">';
$ctpStrEnd = '</div></center>';

if (isset($_GET['ConfirmCode']))
{
    $data = [
        'mid' => $gkey,
        'TransactionID' =>  $_GET['TransactionID'] ,
        'ConfirmCode' => $_GET['ConfirmCode']
    ];
	$transactionData = fn_cointopay_transactiondetail($data);
	if (200 !== $transactionData['status_code']){
		echo $ctpStrStart."<h2>".$transactionData['message']."</h2>".$ctpStrEnd;exit;
	}
	else {
		if ($transactionData['data']['Security'] != $_GET['ConfirmCode']){
			echo $ctpStrStart."<h2>Data mismatch! ConfirmCode doesn't match.</h2>".$ctpStrEnd;
			exit;
		}
		elseif ($transactionData['data']['CustomerReferenceNr'] != $_GET['CustomerReferenceNr']){
			echo $ctpStrStart."<h2>Data mismatch! CustomerReferenceNr doesn't match.</h2>".$ctpStrEnd;
			exit;
		}
		elseif ($transactionData['data']['TransactionID'] != $_GET['TransactionID']){
			echo $ctpStrStart."<h2>Data mismatch! TransactionID doesn't match.</h2>".$ctpStrEnd;
			exit;
		}
		elseif (isset($_GET['AltCoinID']) && $transactionData['data']['AltCoinID'] != $_GET['AltCoinID']){
			echo $ctpStrStart."<h2>Data mismatch! AltCoinID doesn't match.</h2>".$ctpStrEnd;
			exit;
		}
		elseif (isset($_GET['MerchantID']) && $transactionData['data']['MerchantID'] != $_GET['MerchantID']){
			echo $ctpStrStart."<h2>Data mismatch! MerchantID doesn't match.</h2>".$ctpStrEnd;
			exit;
		}
		elseif (isset($_GET['CoinAddressUsed']) && $transactionData['data']['coinAddress'] != $_GET['CoinAddressUsed']){
			echo $ctpStrStart."<h2>Data mismatch! coinAddress doesn't match.</h2>".$ctpStrEnd;
			exit;
		}
		elseif (isset($_GET['SecurityCode']) && $transactionData['data']['SecurityCode'] != $_GET['SecurityCode']){
			echo $ctpStrStart."<h2>Data mismatch! SecurityCode doesn't match.</h2>".$ctpStrEnd;
			exit;
		}
		elseif (isset($_GET['inputCurrency']) && $transactionData['data']['inputCurrency'] != $_GET['inputCurrency']){
			echo $ctpStrStart."<h2>Data mismatch! inputCurrency doesn't match.</h2>".$ctpStrEnd;
			exit;
		}
		elseif ($transactionData['data']['Status'] != $_GET['status']){
			if ($transactionData['data']['Status'] == 'expired') {
				echo $ctpStrStart."<h2>Your order status is ".$transactionData['data']['Status'].". <a href='https://cointopay.com/invoice/".$_GET['ConfirmCode']."' target='_blank'>Invoice link</a></h2>".$ctpStrEnd;
				exit;
			}
			elseif ($transactionData['data']['Status'] == 'underpaid') {
				echo $ctpStrStart."<h2>Your order status is ".$transactionData['data']['Status'].". <a href='https://cointopay.com/invoice/".$_GET['ConfirmCode']."' target='_blank'>Invoice link</a></h2>".$ctpStrEnd;
				exit;
			} else {
				echo $ctpStrStart."<h2>Data mismatch! status doesn't match. Your order status is ".$transactionData['data']['Status'].".</h2>".$ctpStrEnd;
				exit;
			}
		}
		
	}
	
    $response = validateOrder($data);
    if (is_string($response)) {
		throw new Exception($response);
	}
    if ($response['Status'] !== $_REQUEST['status'])
    {
        echo $ctpStrStart."<h2>We have detected different order status. Your order status is ". $response['Status'].".</h2>".$ctpStrEnd;
        exit;
    }

    if ($response['CustomerReferenceNr'] == $_REQUEST['CustomerReferenceNr'])
    {
        $order = $db->Execute("select orders_id from " . TABLE_ORDERS . " where orders_id = '" . intval($order_id) . "' limit 1");
        if (!$order || !$order->fields['orders_id'])
            throw new Exception('Order #' . $order_id . ' does not exists');

        $redirect_url = '';
        if ($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 0 )
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
        else {
            $ctp_order_status = NULL;
            $redirect_url  = zen_href_link(FILENAME_CHECKOUT_PAYMENT);
        }
        //udate order status
        if ($ctp_order_status)
            $db->Execute("update ". TABLE_ORDERS. " set orders_status = " . $ctp_order_status . " where orders_id = ". intval($order_id));

        $date =  (new \DateTime())->format('Y-m-d H:i:s');
        if ($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 0){


            $db->Execute("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('$order_id', '$ctp_order_status', '$date', '1', 'Payment completed notification from Cointopay')");
        }

        if ($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 1){

            $db->Execute("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('$order_id', '$ctp_order_status', '$date', '1', 'Payment failed notification from Cointopay because notenough')");
        }

        if ($_REQUEST['status']== 'failed'){

            $db->Execute("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('$order_id', '$ctp_order_status', '$date', '1', 'Payment failed notification from Cointopay')");
        }
		if ($_REQUEST['status']== 'expired'){

            echo $ctpStrStart."<h2>Your order status is ".$_REQUEST['status'].". <a href='https://cointopay.com/invoice/".$_GET['ConfirmCode']."' target='_blank'>Invoice link</a></h2>".$ctpStrEnd;
			exit;
        }
		if ($_REQUEST['status']== 'underpaid'){

            echo $ctpStrStart."<h2>Your order status is ".$_REQUEST['status'].". <a href='https://cointopay.com/invoice/".$_GET['ConfirmCode']."' target='_blank'>Invoice link</a></h2>".$ctpStrEnd;
			exit;
        }
        zen_redirect($redirect_url);
    }
    else
    {
        echo $ctpStrStart."<h2>order id not match.</h2>".$ctpStrEnd;
        exit;
    }
}
else
{
    echo $ctpStrStart."<h2>ConfirmCode not match.</h2>".$ctpStrEnd;
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
    $results = json_decode($response, true);
    return $results;
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

