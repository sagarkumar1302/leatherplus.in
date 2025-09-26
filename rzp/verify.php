<?php

ob_start();

//Required PHPMailer FIles
require '../plugins/phpmailer/src/Exception.php';
require '../plugins/phpmailer/src/PHPMailer.php';
// require '../plugins/phpmailer/src/SMTP.php';

require('config.php');

session_start();

require('Razorpay.php');

use Razorpay\Api\Api;
use Razorpay\Api\Errors\SignatureVerificationError;

//Required Config Files
require_once('../lib/config/config.php');

//Required Libraries
require_once('../lib/helpers/urlhelpers.php');
require_once('../lib/database/databaseops.php');
require_once('../lib/notifications/emailnotifications.php');
require_once('../lib/notifications/smsnotifications.php');

require_once('../plugins/shiprocket/Shiprocket.php');
require_once('../services/shipway_api.php');
require_once('../services/aisensy_api.php'); // 👈 Aisensy API helper

//Create Objects
$url = new UrlHelpers();
$database = new DatabaseOps();
$emailnotif = new EmailNotifications();
$smsnotif = new SMSNotifications();
$ship_rocket = new Shiprocket();

//Declare Variables
$connStatus = $database->createConnection();

if ($connStatus == true) {
    $result_settnotifs = $database->getData("SELECT * FROM settings_notifs");
    if ($result_settnotifs != false) {
        while ($settnotifs = mysqli_fetch_array($result_settnotifs)) {
            if ($settnotifs['settnotif_var'] == "sms_orderplaced") $sms_orderplaced = $settnotifs['settnotif_value'];
            if ($settnotifs['settnotif_var'] == "email_orderplaced") $email_orderplaced = $settnotifs['settnotif_value'];
            if ($settnotifs['settnotif_var'] == "admin_email") $admin_email = $settnotifs['settnotif_value'];
            if ($settnotifs['settnotif_var'] == "admin_phone") $admin_phone = $settnotifs['settnotif_value'];
        }
    }
}

$email_orderplaced = str_replace("{{cust_name}}", $_SESSION['user_name'], $email_orderplaced);
$email_orderplaced = str_replace("{{order_number}}", $_SESSION['ordernum'], $email_orderplaced);
$sms_orderplaced = str_replace("{{cust_name}}", $_SESSION['user_name'], $sms_orderplaced);
$sms_orderplaced = str_replace("{{order_number}}", $_SESSION['ordernum'], $sms_orderplaced);

$success = true;
$error = "Payment Failed";

if (!empty($_POST['razorpay_payment_id'])) {
    $api = new Api($keyId, $keySecret);
    try {
        $attributes = array(
            'razorpay_order_id' => $_SESSION['razorpay_order_id'],
            'razorpay_payment_id' => $_POST['razorpay_payment_id'],
            'razorpay_signature' => $_POST['razorpay_signature']
        );
        $api->utility->verifyPaymentSignature($attributes);
    } catch (SignatureVerificationError $e) {
        $success = false;
        $error = 'Razorpay Error : ' . $e->getMessage();
    }
}

if ($success === true) {
    // Fetch order details
    $result_orderdetails = $database->getData("SELECT * FROM orders WHERE order_num = '" . $_SESSION['ordernum'] . "'");
    $ordr = mysqli_fetch_array($result_orderdetails);

    if ($ordr) {
        $fullname = $ordr['order_fullname'];
        $phone = $ordr['order_phone'];
        $firstName = strtok($fullname, ' ');

        // 🟢 Aisensy API call
        $aisensy_data = [
            "apiKey" => "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpZCI6IjY4YjZhYzcyZGZkZGU0MGMzMWNlZGM3ZSIsIm5hbWUiOiJTdGFyIE9ubGluZSBJbmMiLCJhcHBOYW1lIjoiQWlTZW5zeSIsImNsaWVudElkIjoiNjhiNmFjNzJkZmRkZTQwYzMxY2VkYzc5IiwiYWN0aXZlUGxhbiI6IkZSRUVfRk9SRVZFUiIsImlhdCI6MTc1NjgwMjE2Mn0.u9B4-RskS_j2QezAZt09rmI7O7-x76t-fB_lX7HCpws",
            "campaignName" => "Leatherplus",
            "destination" => "+91" . $phone,
            "userName" => $firstName,
            "templateParams" => [$firstName, "Leather Plus"],
            "media" => [
                "url" => "https://leatherplus.in/views/app/assets/images/Desktop_Banner.jpg",
                "filename" => "file"
            ]
        ];
        $aisensy_response = aisensyApiPost($aisensy_data);
        if (isset($aisensy_response['error'])) {
            error_log("Aisensy API Error on Prepaid Success: " . ($aisensy_response['message'] ?? 'Unknown error'));
        }
    }

    $html = "<p>Your payment was successful</p>
             <p>Payment ID: {$_POST['razorpay_payment_id']}</p>";

    if ($connStatus == true) {
        $order_product_array = [];
        $result_orderdetails = $database->getData("SELECT * FROM orders WHERE order_num = '" . $_SESSION['ordernum'] . "'");
        if ($result_orderdetails != false) {
            while ($ordr = mysqli_fetch_array($result_orderdetails)) {
                $products = json_decode($ordr['order_details'], true);
                $shipway_products = []; // 🟢 For Shipway API

                foreach ($products as $prod_arry) {
                    $prod_id = (int) $prod_arry['product_id'];
                    $qty = (int) $prod_arry['qty'];
                    $result_prod_details = $database->getData("SELECT * FROM products WHERE prod_id = " . $prod_id);
                    if ($result_prod_details != false) {
                        while ($prod_details = mysqli_fetch_array($result_prod_details)) {
                            $price = $prod_details['prod_saleprice'] > 0 ? $prod_details['prod_saleprice'] : $prod_details['prod_regularprice'];
                            $order_product_array[] = [
                                "name" => $prod_details['prod_title'],
                                "sku" => $prod_details['prod_sku'],
                                "units" => $qty,
                                "selling_price" => $price,
                                "discount" => "",
                                "tax" => "",
                                "hsn" => 42033000
                            ];

                            // 🟢 Build Shipway product array
                            $shipway_products[] = [
                                "product" => htmlspecialchars($prod_details['prod_title']),
                                "price" => strval($price),
                                "product_code" => $prod_details['prod_sku'] ?? $prod_details['prod_id'],
                                "product_quantity" => strval($qty),
                                "discount" => "0",
                                "tax_rate" => "5",
                                "tax_title" => "IGST"
                            ];
                        }
                    }
                }

                // Shiprocket Payload (existing)
                $order_details_for_ship = array(
                    "order_id" => $_SESSION['ordernum'],
                    "order_date" => $ordr['order_date'],
                    "pickup_location" => "Star Online Inc.",
                    "channel_id" => "1556708",
                    "comment" => "From leatherplus.in",
                    "billing_customer_name" => $ordr['order_fullname'],
                    "billing_last_name" => "",
                    "billing_address" => $ordr['order_address'],
                    "billing_address_2" => "",
                    "billing_city" => $ordr['order_city'],
                    "billing_pincode" => $ordr['order_postcode'],
                    "billing_state" => $ordr['order_state'],
                    "billing_country" => $ordr['order_country'],
                    "billing_email" => $ordr['order_email'],
                    "billing_phone" => $ordr['order_phone'],
                    "shipping_is_billing" => true,
                    "order_items" => $order_product_array,
                    "payment_method" => "Prepaid",
                    "shipping_charges" => 0,
                    "giftwrap_charges" => 0,
                    "transaction_charges" => 0,
                    "total_discount" => 0,
                    "sub_total" => $ordr['order_total'],
                    "length" => 10,
                    "breadth" => 15,
                    "height" => 20,
                    "weight" => 2.5
                );

                // 🟢 Shipway Payload
                $shipway_data = [
                    "order_id" => $_SESSION['ordernum'],
                    "products" => $shipway_products,
                    "discount" => "0",
                    "shipping" => "0",
                    "order_total" => strval($ordr['order_total']),
                    "gift_card_amt" => "0",
                    "taxes" => strval($ordr['order_tax']),
                    "payment_type" => "P",
                    "email" => $ordr['order_email'],
                    "billing_address" => $ordr['order_address'],
                    "billing_address2" => $ordr['order_company'],
                    "billing_city" => $ordr['order_city'],
                    "billing_state" => $ordr['order_state'],
                    "billing_country" => $ordr['order_country'],
                    "billing_firstname" => strtok($ordr['order_fullname'], ' '),
                    "billing_lastname" => trim(str_replace(strtok($ordr['order_fullname'], ' '), '', $ordr['order_fullname'])),
                    "billing_phone" => $ordr['order_phone'],
                    "billing_zipcode" => $ordr['order_postcode'],
                    "shipping_address" => $ordr['order_address'],
                    "shipping_city" => $ordr['order_city'],
                    "shipping_state" => $ordr['order_state'],
                    "shipping_country" => $ordr['order_country'],
                    "shipping_firstname" => strtok($ordr['order_fullname'], ' '),
                    "shipping_lastname" => trim(str_replace(strtok($ordr['order_fullname'], ' '), '', $ordr['order_fullname'])),
                    "shipping_phone" => $ordr['order_phone'],
                    "shipping_zipcode" => $ordr['order_postcode'],
                    "order_weight" => "110",
                    "box_length" => "20",
                    "box_breadth" => "15",
                    "box_height" => "10",
                    "order_date" => date("Y-m-d H:i:s"),
                ];

                // 🟢 Call Shipway API
                $shipway_response = shipwayApiPost(
                    'https://app.shipway.com/api/v2orders',
                    $shipway_data,
                    'info@leatherplus.in',
                    'y983VSB2Tn34tW3xv0u4687rVk1keKq8'
                );
                if (isset($shipway_response['error'])) {
                    error_log("Shipway Push Order Error: " . ($shipway_response['message'] ?? 'Unknown error'));
                } else {
                    $awb = $shipway_response['awb'] ?? null;
                    if ($awb) {
                        $database->runQuery("UPDATE `orders` SET `awb` = '$awb', `order_status` = 'Processing' WHERE `order_num` = '" . $_SESSION['ordernum'] . "'");
                    }
                }
            }
        }

        $result_updatedetails = $database->runQuery("UPDATE orders SET order_paystatus = 1 WHERE order_num = '" . $_SESSION['ordernum'] . "'");
        if ($result_updatedetails != false) {
            $synced_ship = $ship_rocket->generateOrder($order_details_for_ship);
            header('location:' . $url->baseUrl('views/app/thank-you?order=' . $_SESSION['ordernum']));
            exit;
        }
    }
} else {
    $html = "<p>Your payment failed</p><p>{$error}</p>";
}

echo $html;
