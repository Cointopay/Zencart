<?php

class Cointopay extends base
{
    public $code;
    public $title;
    public $enabled;
    public $sort_order;
    public $description;

    private $merchant_id;
    private $security_code;

    public function __construct()
    {
        $this->code             = 'cointopay';
        $this->title            = MODULE_PAYMENT_COINTOPAY_TEXT_TITLE;
        $this->description      = MODULE_PAYMENT_COINTOPAY_TEXT_DESCRIPTION;
        $this->merchant_id      = defined('MODULE_PAYMENT_COINTOPAY_MERCHANT_ID') ? MODULE_PAYMENT_COINTOPAY_MERCHANT_ID : null;
        $this->security_code    = defined('MODULE_PAYMENT_COINTOPAY_SECURITY_CODE') ? MODULE_PAYMENT_COINTOPAY_SECURITY_CODE : null;
        $this->enabled          = defined('MODULE_PAYMENT_COINTOPAY_STATUS') ? ((MODULE_PAYMENT_COINTOPAY_STATUS == 'True') ? true : false) : false;
        $this->sort_order = defined('MODULE_PAYMENT_COINTOPAY_SORT_ORDER') ? (int)MODULE_PAYMENT_COINTOPAY_SORT_ORDER : null;

        if (null === $this->sort_order) {
          return false;
        }
    }

    public function javascript_validation()
    {
        return false;
    }

    public function selection()
    {
        return ['id' => $this->code, 'module' => $this->title];
    }

    public function pre_confirmation_check()
    {
        return false;
    }

    public function confirmation()
    {
        return false;
    }

    public function process_button()
    {
        return false;
    }

    public function before_process()
    {
        return false;
    }

    public function after_process()
    {
        global $insert_id, $db, $order;

        $info = $order->info;

        $configuration = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key='STORE_NAME' limit 1");
        $products = $db->Execute("select oc.products_id, oc.products_quantity, pd.products_name from " . TABLE_ORDERS_PRODUCTS . " as oc left join " . TABLE_PRODUCTS_DESCRIPTION . " as pd on pd.products_id=oc.products_id  where orders_id=" . intval($insert_id));

        $description = [];
        while (!$products->EOF) {
            $description[] = $products->fields['products_quantity'] . ' × ' . $products->fields['products_name'];

            $products->MoveNext();
        }

        $callback = zen_href_link('cointopay_callback.php', $parameters='', $connection='NONSSL', $add_session_id=true, $search_engine_safe=true, $static=true);

        $params = [
          'order_id'         => $insert_id,
          'price'            => number_format($info['total'], 2, '.', ''),
          'currency'         => $info['currency'],
          'callback_url'     => $this->flash_encode($callback . "?token=" . MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET),
          'cancel_url'       => $this->flash_encode($callback . "?token=" . MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET),
          'success_url'      => zen_href_link('checkout_success'),
          'title'            => $configuration->fields['configuration_value'] . ' Order #' . $insert_id,
          'description'      => join(', ', $description)
        ];

        require_once dirname(__FILE__) . "/cointopay/init.php";
        require_once dirname(__FILE__) . "/cointopay/version.php";

        $order = \Cointopay\Merchant\Order::createOrFail($params, [], [
          'merchant_id' => MODULE_PAYMENT_COINTOPAY_MERCHANT_ID,
          'security_code' => MODULE_PAYMENT_COINTOPAY_SECURITY_CODE,
          'user_agent' => 'Cointopay - ZenCart Extension v' . COINTOPAY_ZENCART_EXTENSION_VERSION]);

        $_SESSION['cart']->reset(true);
        zen_redirect($order->shortURL);

        return false;
    }

    public function check()
    {
        global $db;

        if (!isset($this->_check)) {
            $check_query  = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_COINTOPAY_STATUS'");
            $this->_check = $check_query->RecordCount();
        }

        return $this->_check;
    }

    public function install()
    {
        global $db, $messageStack;

        if (defined('MODULE_PAYMENT_COINTOPAY_STATUS')) {
            $messageStack->add_session('Cointopay module already installed.', 'error');
            zen_redirect(zen_href_link(FILENAME_MODULES, 'set=payment&module=cointopay', 'NONSSL'));

            return 'failed';
        }

        $callbackSecret = md5('zencart_' . mt_rand());

        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Sort order of display.', 'MODULE_PAYMENT_COINTOPAY_SORT_ORDER', '0', 'Sort order of display. Lowest is displayed first.', 6, 1, NULL, NULL, now())");

        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Cointopay Module', 'MODULE_PAYMENT_COINTOPAY_STATUS', 'False', 'Enable the Cointopay bitcoin plugin?', '6', '0', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Cointopay Merchant Id', 'MODULE_PAYMENT_COINTOPAY_MERCHANT_ID', '0', 'Your Cointopay Merchant Id', '6', '0', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Cointopay Security Code', 'MODULE_PAYMENT_COINTOPAY_SECURITY_CODE', '0', 'Your Cointopay Security Code', '6', '0', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Paid Order Status', 'MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID', '5', 'Status in your store when Cointopay order status is paid.<br />(\'Paid\' recommended)', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Failed Order Status', 'MODULE_PAYMENT_COINTOPAY_FAILED_STATUS_ID', '6', 'Status in your store when Cointopay order status is failed.<br />(\'Failed\' recommended)', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added) values ('Set Paidnotenough Order Status', 'MODULE_PAYMENT_COINTOPAY_PAIDNOTENOUGH_STATUS_ID', '7', 'Status in your store when Cointopay order status is paidnotenough.<br />(\'Paidnotenough\' recommended)', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("insert into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added, use_function) values ('Callback Secret Key (do not edit)', 'MODULE_PAYMENT_COINTOPAY_CALLBACK_SECRET', '$callbackSecret', '', '6', '6', now(), 'cointopay_censorize')");

        $paid_status =  $db->Execute("select orders_status_id from " . TABLE_ORDERS_STATUS . " where orders_status_id != '5'");
        if ($paid_status) {
            $db->Execute("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name) values ('5', '1', 'Paid')");
        }
        $failed_status =  $db->Execute("select orders_status_id from " . TABLE_ORDERS_STATUS . " where orders_status_id != '6'");
        if ($failed_status) {
            $db->Execute("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name) values ('6', '1', 'Failed')");
        }
        $paidnotenouty_status =  $db->Execute("select orders_status_id from " . TABLE_ORDERS_STATUS . " where orders_status_id != '7'");
        if ($paidnotenouty_status) {
            $db->Execute("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name) values ('7', '1', 'Paidnotenough')");
        }
    }

    public function remove()
    {
        global $db;
        $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key LIKE 'MODULE\_PAYMENT\_COINTOPAY\_%'");
        $db->Execute("delete from " . TABLE_ORDERS_STATUS . " where orders_status_id = '5'");
        $db->Execute("delete from " . TABLE_ORDERS_STATUS . " where orders_status_id = '6'");
        $db->Execute("delete from " . TABLE_ORDERS_STATUS . " where orders_status_id = '7'");
    }

    public function keys()
    {
        return [
          'MODULE_PAYMENT_COINTOPAY_STATUS',
          'MODULE_PAYMENT_COINTOPAY_SORT_ORDER',
          'MODULE_PAYMENT_COINTOPAY_MERCHANT_ID',
          'MODULE_PAYMENT_COINTOPAY_SECURITY_CODE',
          'MODULE_PAYMENT_COINTOPAY_PAID_STATUS_ID',
          'MODULE_PAYMENT_COINTOPAY_FAILED_STATUS_ID',
          'MODULE_PAYMENT_COINTOPAY_PAIDNOTENOUGH_STATUS_ID'
        ];
    }
    public function flash_encode($input)
    {
        return rawurlencode(utf8_encode($input));
    }
}

function cointopay_censorize($value)
{
    return "(hidden for security reasons)";
}