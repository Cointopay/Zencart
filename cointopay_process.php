<?php

/**
 * cointopay_process.php callback handler for Cointopay IPN notifications
 *
 * @copyright Copyright 2003-2026 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: torvista 2026 Mar 13 Modified in v2.2.1 $
 *
 * @var $currencies currencies
 * @var $db queryFactoryResult|queryFactory
 * @var $messageStack messageStack
 * @var $zco_notifier Zencart\Traits\NotifierManager
 */


require 'includes/application_top.php';

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

$token = zen_config('MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET');
if ($token == '' || $_POST['token'] != $token) {
   echo $ctpStrStart . '<h2>Token: ' . $_POST['token'] . ' do not match</h2>' . $ctpStrEnd;
   exit;
}

global $db;

$order_id = $_POST['CustomerReferenceNr'];
$gkey = zen_config('MODULE_PAYMENT_COINTOPAY_MERCHANT_ID');


if (isset($_POST['ConfirmCode'])) {
    $data = [
        'mid' => $gkey,
        'TransactionID' =>  $_POST['TransactionID'] ,
        'ConfirmCode' => $_POST['ConfirmCode']
    ];
    $transactionData = fn_cointopay_transactiondetail($data);
    if (200 !== $transactionData['status_code']) {
        echo $ctpStrStart . "<h2>" . $transactionData['message'] . "</h2>" . $ctpStrEnd;
        exit;
    } else {
        if ($transactionData['data']['Security'] != $_POST['ConfirmCode']) {
            echo $ctpStrStart . "<h2>Data mismatch! ConfirmCode doesn't match.</h2>" . $ctpStrEnd;
            exit;
        } elseif ($transactionData['data']['CustomerReferenceNr'] != $_POST['CustomerReferenceNr']) {
            echo $ctpStrStart . "<h2>Data mismatch! CustomerReferenceNr doesn't match.</h2>" . $ctpStrEnd;
            exit;
        } elseif ($transactionData['data']['TransactionID'] != $_POST['TransactionID']) {
            echo $ctpStrStart . "<h2>Data mismatch! TransactionID doesn't match.</h2>" . $ctpStrEnd;
            exit;
        } elseif (isset($_POST['AltCoinID']) && $transactionData['data']['AltCoinID'] != $_POST['AltCoinID']) {
            echo $ctpStrStart . "<h2>Data mismatch! AltCoinID doesn't match.</h2>" . $ctpStrEnd;
            exit;
        } elseif (isset($_POST['MerchantID']) && $transactionData['data']['MerchantID'] != $_POST['MerchantID']) {
            echo $ctpStrStart . "<h2>Data mismatch! MerchantID doesn't match.</h2>" . $ctpStrEnd;
            exit;
        } elseif (isset($_POST['CoinAddressUsed']) && $transactionData['data']['coinAddress'] != $_POST['CoinAddressUsed']) {
            echo $ctpStrStart . "<h2>Data mismatch! coinAddress doesn't match.</h2>" . $ctpStrEnd;
            exit;
        } elseif (isset($_POST['SecurityCode']) && $transactionData['data']['SecurityCode'] != $_POST['SecurityCode']) {
            echo $ctpStrStart . "<h2>Data mismatch! SecurityCode doesn't match.</h2>" . $ctpStrEnd;
            exit;
        } elseif (isset($_POST['inputCurrency']) && $transactionData['data']['inputCurrency'] != $_POST['inputCurrency']) {
            echo $ctpStrStart . "<h2>Data mismatch! inputCurrency doesn't match.</h2>" . $ctpStrEnd;
            exit;
        } elseif ($transactionData['data']['Status'] != $_POST['status']) {
            if ($transactionData['data']['Status'] == 'expired') {
                echo $ctpStrStart . "<h2>Your order status is " . $transactionData['data']['Status'] . ". <a href='https://cointopay.com/invoice/" . $_POST['ConfirmCode'] . "' target='_blank'>Invoice link</a></h2>" . $ctpStrEnd;
                exit;
            } elseif ($transactionData['data']['Status'] == 'underpaid') {
                echo $ctpStrStart . "<h2>Your order status is " . $transactionData['data']['Status'] . ". <a href='https://cointopay.com/invoice/" . $_POST['ConfirmCode'] . "' target='_blank'>Invoice link</a></h2>" . $ctpStrEnd;
                exit;
            } else {
                echo $ctpStrStart . "<h2>Data mismatch! status doesn't match. Your order status is " . $transactionData['data']['Status'] . ".</h2>" . $ctpStrEnd;
                exit;
            }
        }
    }

    $response = validateOrder($data);
    if (is_string($response)) {
        throw new Exception($response);
    }
    if ($response['Status'] !== $_REQUEST['status']) {
        echo $ctpStrStart . "<h2>We have detected different order status. Your order status is " . $response['Status'] . ".</h2>" . $ctpStrEnd;
        exit;
    }

    if ($response['CustomerReferenceNr'] == $_REQUEST['CustomerReferenceNr']) {
        $order = $db->Execute("select orders_id from " . TABLE_ORDERS . " where orders_id = '" . intval($order_id) . "' limit 1");
        if (!$order || !$order->fields['orders_id']) {
            throw new Exception('Order #' . $order_id . ' does not exists');
        }

        $redirect_url = '';
        if ($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 0) {
            $ctp_order_status = zen_config('MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID');
            $redirect_url  = zen_href_link('checkout_success');
        } elseif ($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 1) {
            $ctp_order_status = zen_config('MODULE_PAYMENT_COINTOPAY_PAIDNOTENOUGH_STATUS_ID');
            $redirect_url  = zen_href_link('index');
        } elseif ($_REQUEST['status']== 'failed') {
            $ctp_order_status = zen_config('MODULE_PAYMENT_COINTOPAY_FAILED_STATUS_ID');
            $redirect_url  = zen_href_link('index');
        } else {
            $ctp_order_status = null;
            $redirect_url  = zen_href_link(FILENAME_CHECKOUT_PAYMENT);
        }
        //udate order status
        if ($ctp_order_status) {
            $db->Execute("update " . TABLE_ORDERS . " set orders_status = " . $ctp_order_status . " where orders_id = " . intval($order_id));
        }

        $date =  (new \DateTime())->format('Y-m-d H:i:s');
        if ($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 0) {
            $db->Execute("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('$order_id', '$ctp_order_status', '$date', '1', 'Payment completed notification from Cointopay')");
        }

        if ($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 1) {
            $db->Execute("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('$order_id', '$ctp_order_status', '$date', '1', 'Payment failed notification from Cointopay because notenough')");
        }

        if ($_REQUEST['status']== 'failed') {
            $db->Execute("insert into " . TABLE_ORDERS_STATUS_HISTORY . " (orders_id, orders_status_id, date_added, customer_notified, comments) values ('$order_id', '$ctp_order_status', '$date', '1', 'Payment failed notification from Cointopay')");
        }
        if ($_REQUEST['status']== 'expired') {
            echo $ctpStrStart . "<h2>Your order status is " . $_REQUEST['status'] . ". <a href='https://cointopay.com/invoice/" . $_POST['ConfirmCode'] . "' target='_blank'>Invoice link</a></h2>" . $ctpStrEnd;
            exit;
        }
        if ($_REQUEST['status']== 'underpaid') {
            echo $ctpStrStart . "<h2>Your order status is " . $_REQUEST['status'] . ". <a href='https://cointopay.com/invoice/" . $_POST['ConfirmCode'] . "' target='_blank'>Invoice link</a></h2>" . $ctpStrEnd;
            exit;
        }
        zen_redirect($redirect_url);
    } else {
        echo $ctpStrStart . "<h2>order id not match.</h2>" . $ctpStrEnd;
        exit;
    }
} else {
    echo $ctpStrStart . "<h2>ConfirmCode not match.</h2>" . $ctpStrEnd;
    exit;
}

function validateOrder($data)
{
    //https://cointopay.com/v2REAPI?MerchantID=14351&Call=QA&APIKey=_&output=json&TransactionID=230196&ConfirmCode=YGBMWCNW0QSJVSPQBCHWEMV7BGBOUIDQCXGUAXK6PUA
    $params = [
        "authentication:1",
        'cache-control: no-cache',
    ];
    $ch = curl_init();
    curl_setopt_array(
        $ch,
        [
            CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
            //CURLOPT_USERPWD => $this->apikey,
            CURLOPT_POSTFIELDS => 'MerchantID=' . $data['mid'] . '&Call=QA&APIKey=_&output=json&TransactionID=' . $data['TransactionID'] . '&ConfirmCode=' . $data['ConfirmCode'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => $params,
            CURLOPT_USERAGENT => 1,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC
        ]
    );
    $response = curl_exec($ch);
    $results = json_decode($response, true);
    return $results;
}
function fn_cointopay_transactiondetail($data)
{
    $params = [
    "authentication:1",
    'cache-control: no-cache',
    ];
    $ch = curl_init();
    curl_setopt_array(
        $ch,
        [
    CURLOPT_URL => 'https://app.cointopay.com/v2REAPI?',
    //CURLOPT_USERPWD => $this->apikey,
    CURLOPT_POSTFIELDS => 'Call=Transactiondetail&MerchantID=' . $data['mid'] . '&output=json&ConfirmCode=' . $data['ConfirmCode'] . '&APIKey=a',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => $params,
    CURLOPT_USERAGENT => 1,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC
    ]
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
