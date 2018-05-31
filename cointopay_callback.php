<?php
require('includes/application_top.php');

$token = MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET;
if ($token == '' || $_GET['token'] != $token)
    throw new Exception('Token: ' . $_GET['token'] . ' do not match');

global $db;

$order_id = $_REQUEST['CustomerReferenceNr'];

$order = $db->Execute("select orders_id from " . TABLE_ORDERS . " where orders_id = '" . intval($order_id) . "' limit 1");
if (!$order || !$order->fields['orders_id'])
    throw new Exception('Order #' . $order_id . ' does not exists');

$redirect_url = '';
if($_REQUEST['status']== 'paid' && $_REQUEST['notenough'] == 0 ){
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
echo 'OK';
